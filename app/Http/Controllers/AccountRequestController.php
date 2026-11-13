<?php

namespace App\Http\Controllers;

use App\Models\AccountRequest;
use App\Models\Role;
use App\Models\User;
use App\Services\AccountRequestService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class AccountRequestController extends Controller
{
    public function __construct(private AccountRequestService $accounts) {}

    public function index(Request $request): Response
    {
        $actor = $request->user();
        abort_unless($actor->status === 'active' && ($actor->isManagerRole() || $actor->canAuthorizeResets()), 403);
        $entries = $this->accounts->visibleTo($actor)->when($request->filled('request'), fn ($query) => $query->whereKey((int) $request->query('request')))
            ->latest()->paginate(20)->withQueryString();

        return response()->view('users.account-requests', compact('actor', 'entries'))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $request->user();
        abort_unless($actor->status === 'active' && $actor->isManagerRole() && $actor->branch?->is_active, 403);
        if (is_string($request->input('username'))) {
            $request->merge(['username' => strtolower(trim($request->input('username')))]);
        }
        if (is_string($request->input('email'))) {
            $request->merge(['email' => strtolower(trim($request->input('email')))]);
        }
        $data = $request->validate([
            'full_name' => ['required', 'string', 'max:150'],
            'email' => ['nullable', 'required_with:password', 'email', 'max:255', 'unique:users,email', 'unique:account_requests,email'],
            'username' => ['required', 'string', 'max:50', 'regex:/\A[A-Za-z0-9_.-]+\z/', 'unique:users,username', 'unique:account_requests,username'],
            'password' => ['nullable', 'required_with:email', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
        ]);
        $entry = AccountRequest::create([
            'requested_by' => $actor->id, 'branch_id' => $actor->branch_id,
            'full_name' => trim($data['full_name']),
            'email' => $data['email'] ?? null,
            'password_hash' => ! empty($data['password']) ? Hash::make($data['password']) : null,
            'username' => strtolower(trim($data['username'])),
            'requested_role' => $actor->isBranchManager() ? 'branch_staff' : 'commissary_staff', 'status' => 'pending',
        ]);
        $this->accounts->audit($actor, $entry, 'account_request_submitted');

        return redirect()->route('account-requests')->with('success', 'Account request submitted for admin approval.');
    }

    public function api(Request $request): JsonResponse
    {
        $actor = $request->user();
        abort_unless($actor->status === 'active' && ($actor->isManagerRole() || $actor->canAuthorizeResets()), 403);
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'action' => ['required', 'in:approve,reject,reveal,replace'],
            'reason' => ['required_if:action,reject', 'nullable', 'string', 'max:500'],
            'current_password' => ['required_if:action,reveal', 'nullable', 'string'],
        ]);

        return DB::transaction(function () use ($actor, $data): JsonResponse {
            $entry = $this->accounts->visibleTo($actor)->whereKey($data['id'])->lockForUpdate()->firstOrFail();
            $action = $data['action'];
            if ($action === 'reveal') {
                // Manager-requested accounts are revealed once by the requesting
                // manager; admin-created accounts are revealed once by any
                // authorizing admin. Current-password confirmation is required below.
                abort_unless($entry->origin === 'admin_created'
                    ? $actor->canAuthorizeResets()
                    : ((int) $entry->requested_by === (int) $actor->id && $this->accounts->validRequester($entry)), 403);
                if (! Hash::check($data['current_password'], $actor->password_hash)) {
                    throw ValidationException::withMessages(['current_password' => 'Current password is incorrect.']);
                }
                $user = User::whereKey($entry->user_id)->lockForUpdate()->first();
                if ($entry->status !== 'approved' || ! $user || $user->status !== 'active' || ! $user->must_change_password
                    || (int) $user->branch_id !== (int) $entry->branch_id || $user->role->role_name !== $entry->requested_role
                    || $entry->credential_revealed_at || ! $entry->credential_expires_at?->isFuture() || ! $entry->temporary_password) {
                    throw ValidationException::withMessages(['id' => 'Credentials are unavailable, already revealed, or expired. Ask admin for a replacement.']);
                }
                $password = $entry->temporary_password;
                $entry->update(['temporary_password' => null, 'credential_revealed_at' => now()]);
                $this->accounts->audit($actor, $entry, 'account_credentials_revealed');

                return response()->json(['ok' => true, 'username' => $user->username, 'temporary_password' => $password,
                    'expires_at' => $entry->credential_expires_at->format('M j, Y g:i A')])->header('Cache-Control', 'no-store, private');
            }
            // Approve/reject/replace are authorizing-admin actions. For a
            // manager-requested entry the authorizer must not be the requesting
            // manager; an admin-created entry has an admin originator, so any
            // authorizing admin (including the creator) may replace it.
            abort_unless($actor->canAuthorizeResets()
                && ($entry->origin === 'admin_created' || (int) $actor->id !== (int) $entry->requested_by), 403);
            if ($action === 'replace') {
                $user = User::whereKey($entry->user_id)->lockForUpdate()->first();
                if ($entry->status !== 'approved' || ! $user || ! $user->must_change_password || $user->status !== 'active'
                    || ! ($entry->origin === 'admin_created' || $this->accounts->validRequester($entry))
                    || (int) $user->branch_id !== (int) $entry->branch_id
                    || $user->role->role_name !== $entry->requested_role) {
                    throw ValidationException::withMessages(['id' => 'Only unused staff onboarding credentials can be replaced.']);
                }
                $this->accounts->issuePassword($entry, $user);
            } else {
                if ($entry->status !== 'pending') {
                    throw ValidationException::withMessages(['id' => 'This request has already been reviewed.']);
                }
                if ($action === 'reject') {
                    $entry->update(['status' => 'rejected', 'rejection_reason' => $data['reason'], 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
                } else {
                    if (! $this->accounts->validRequester($entry) || User::whereRaw('LOWER(username) = ?', [strtolower($entry->username)])->exists()) {
                        throw ValidationException::withMessages(['id' => 'Requester/branch is no longer eligible, or the username is already taken.']);
                    }
                    $role = Role::where('role_name', $entry->requested_role)->firstOrFail();
                    $managerSet = ! empty($entry->password_hash);
                    $user = User::create([
                        'username' => $entry->username, 'full_name' => $entry->full_name, 'email' => $entry->email,
                        'password_hash' => $managerSet ? $entry->password_hash : Hash::make(Str::random(40)), 'role_id' => $role->id,
                        'branch_id' => $entry->branch_id, 'status' => 'active', 'must_change_password' => ! $managerSet,
                    ]);
                    $entry->update(['status' => 'approved', 'user_id' => $user->id, 'reviewed_by' => $actor->id, 'reviewed_at' => now()]);
                    // Only generate a temporary password when the manager did not set one.
                    if (! $managerSet) {
                        $this->accounts->issuePassword($entry, $user);
                    }
                }
            }
            $this->accounts->audit($actor, $entry, 'account_request_'.$action);

            return response()->json(['ok' => true, 'message' => match ($action) {
                'approve' => 'Account created. The employee can sign in with the email and password set by the requesting manager.',
                'replace' => 'Replacement issued. The previous temporary password is invalid.',
                default => 'Account request rejected.',
            }])->header('Cache-Control', 'no-store, private');
        }, 3);
    }
}
