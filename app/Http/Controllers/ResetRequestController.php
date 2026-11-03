<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PasswordResetRequest;
use App\Models\User;
use App\Services\EmailRecoveryService;
use App\Services\ResetCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;

/**
 * Same-branch managers attest to staff identity after email verification.
 * Admin-tier approvers review that verification before authorizing staff resets;
 * higher ranks handle manager/admin recovery. Links go to the verified email.
 */
class ResetRequestController extends Controller
{
    public function __construct(private ResetCodeService $codes) {}

    /**
     * Reset Requests management page, scoped to what the actor may act on.
     */
    public function index(): Response
    {
        $actor = Auth::user();
        $actor->load('role', 'branch');

        abort_unless($actor->isManagerRole() || $actor->canAuthorizeResets(), 403);

        $requestQuery = PasswordResetRequest::with(['user.role', 'user.branch', 'requester', 'approver', 'managerVerifier.role'])
            ->where(function ($query) use ($actor) {
                // Deliverers see what they raised; authorizers see what they can approve/authorize.
                $query->where('requested_by', $actor->id)
                    ->orWhere('approved_by', $actor->id);
                if ($actor->isManagerRole() && ! $actor->canAuthorizeResets()) {
                    $staffRole = $actor->isBranchManager() ? 'branch_staff' : 'commissary_staff';
                    $query->orWhereHas('user', function ($users) use ($actor, $staffRole) {
                        $users->where('branch_id', $actor->branch_id)
                            ->whereHas('role', fn ($roles) => $roles->where('role_name', $staffRole));
                    });
                }
                if ($actor->canAuthorizeResets()) {
                    $query->orWhereHas('user.role', function ($roleQuery) use ($actor) {
                        $roleQuery->whereIn('role_name', $this->targetRoleNamesFor($actor));
                    });
                }
            });

        $requests = $requestQuery
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response()->view('reset-requests.index', compact('actor', 'requests'))->header('Cache-Control', 'no-store, private');
    }

    public function api(Request $request): JsonResponse
    {
        return match ($request->input('action')) {
            'request' => response()->json(['ok' => false, 'error' => 'The account holder must submit username, recovery code, and request email from Account Recovery.'], 422),
            'approve' => $this->approve($request),
            'verify_identity' => $this->verifyIdentity($request),
            'reject' => $this->reject($request),
            'reveal' => $this->reveal($request),
            'account_code' => $this->accountCode($request),

            'delete' => $this->deleteRequest($request),
            default => response()->json(['ok' => false, 'error' => 'Unknown action.'], 400),
        };
    }

    private function verifyIdentity(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'], 'identity_verified' => ['required', 'accepted'],
            'verification_method' => ['required', Rule::in(array_keys(PasswordResetRequest::VERIFICATION_METHODS))],
        ]);
        app(EmailRecoveryService::class)->verifyIdentity((int) $data['id'], Auth::user(), $data['verification_method']);

        return response()->json(['ok' => true, 'message' => 'Identity verified by manager. The request is awaiting admin approval.']);
    }

    private function approve(Request $request): JsonResponse
    {
        $data = $request->validate([
            'id' => ['required', 'integer'],
            'identity_verified' => ['required', 'accepted'],
        ]);
        $sent = app(EmailRecoveryService::class)->approve((int) $data['id'], Auth::user());

        return response()->json([
            'ok' => true,
            'message' => $sent ? 'Approved. A one-time reset link was emailed to the verified request address; it expires in 12 hours.' : 'This request was already approved. No additional email was sent.',
        ]);
    }

    private function reject(Request $request): JsonResponse
    {
        $actor = Auth::user();
        abort_unless($actor->canAuthorizeResets(), 403);
        $data = $request->validate([
            'rejection_reason' => ['required', 'string', Rule::in(array_keys(PasswordResetRequest::REJECTION_REASONS))],
        ]);

        return DB::transaction(function () use ($actor, $request, $data) {
            $reset = $this->lockRequest($request);
            if (! $reset) {
                return response()->json(['ok' => false, 'error' => 'Request not found.'], 404);
            }
            if (! in_array($reset->displayState(), [PasswordResetRequest::STATUS_AWAITING_EMAIL, PasswordResetRequest::STATUS_PENDING, PasswordResetRequest::STATUS_APPROVED], true)) {
                return response()->json(['ok' => false, 'error' => 'Only open requests can be rejected.'], 422);
            }
            if (! $this->mayManage($actor, $reset)) {
                return response()->json(['ok' => false, 'error' => 'You are not authorized to reject this request.'], 403);
            }

            $reset->update([
                'status' => PasswordResetRequest::STATUS_REJECTED,
                'approved_by' => $actor->id,
                'rejected_at' => now(),
                'rejection_reason' => $data['rejection_reason'],
                'code_hash' => null,
                'code_expires_at' => null,
                'verification_token_hash' => null,
                'verification_expires_at' => null,
                'reset_token_hash' => null,
            ]);
            $this->audit($actor, $reset, 'reset_request_rejected', PasswordResetRequest::REJECTION_REASONS[$data['rejection_reason']]);

            return response()->json(['ok' => true, 'message' => 'Request rejected.']);
        });
    }

    /** Reveal an assigned account code without changing its approval or expiry. */
    private function accountCode(Request $request): JsonResponse
    {
        $actor = Auth::user();
        $target = $this->findActiveTarget($request);
        abort_unless($target && $actor->canViewRecoveryCodeFor($target), 403);

        return $this->codeResponse($actor, $target);
    }

    private function codeResponse(User $actor, User $target): JsonResponse
    {
        $key = 'recovery-reveal:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['ok' => false, 'error' => 'Please wait before viewing another code.'], 429);
        }
        RateLimiter::hit($key, 60);
        $plain = $this->codes->forAccount($target);
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'account_recovery_code_viewed',
            'table_name' => 'users',
            'record_id' => $target->id,
            'ip_address' => request()->ip(),
        ]);

        return response()->json(['ok' => true, 'code' => $plain])
            ->header('Cache-Control', 'no-store');
    }

    private function reveal(Request $request): JsonResponse
    {
        $actor = Auth::user();
        $reset = PasswordResetRequest::with('user.role')->find((int) $request->input('id', 0));
        abort_unless($reset && $actor->canViewRecoveryCodeFor($reset->user), 403);

        return $this->codeResponse($actor, $reset->user);
    }

    /** Permanently delete a closed request row. */
    private function deleteRequest(Request $request): JsonResponse
    {
        $actor = Auth::user();

        return DB::transaction(function () use ($actor, $request) {
            $reset = $this->lockRequest($request);
            if (! $reset) {
                return response()->json(['ok' => false, 'error' => 'Request not found.'], 404);
            }
            if (! $this->mayManage($actor, $reset)) {
                return response()->json(['ok' => false, 'error' => 'You are not authorized to delete this request.'], 403);
            }
            if (! $reset->isDeletable()) {
                return response()->json(['ok' => false, 'error' => 'Only closed requests can be deleted.'], 422);
            }

            $this->audit($actor, $reset, 'reset_request_deleted', 'Closed request permanently deleted.');
            $reset->delete();

            return response()->json(['ok' => true, 'message' => 'Request deleted.']);
        });
    }

    // -------------------------------------------------------
    // Helpers
    // -------------------------------------------------------

    /** The actor raised the request, or governs the target (approver/initiator). */
    private function mayManage(User $actor, PasswordResetRequest $reset): bool
    {
        return (int) $reset->requested_by === (int) $actor->id
            || ($reset->user && ($actor->canRequestResetFor($reset->user)
                || ($actor->canAuthorizeResets() && $actor->roleRank() > $reset->user->roleRank())));
    }

    private function lockRequest(Request $request): ?PasswordResetRequest
    {
        $id = (int) $request->input('id', 0);
        if ($id <= 0) {
            return null;
        }

        return PasswordResetRequest::with(['user.role'])->whereKey($id)->lockForUpdate()->first();
    }

    private function findActiveTarget(Request $request): ?User
    {
        $id = (int) $request->input('user_id', 0);
        if ($id <= 0) {
            return null;
        }

        return User::with('role', 'branch')->where('id', $id)->where('status', 'active')->first();
    }

    /** Role names an authorizer can see in the request list (targets they govern). */
    private function targetRoleNamesFor(User $actor): array
    {
        if ($actor->isSuperAdmin()) {
            return ['owner', 'admin', 'branch_manager', 'commissary_manager', 'branch_staff', 'commissary_staff'];
        }

        // Owner / admin: managers and staff they can approve or initiate.
        return ['branch_manager', 'commissary_manager', 'branch_staff', 'commissary_staff'];
    }

    private function audit(User $actor, PasswordResetRequest $reset, string $action, string $detail): void
    {
        $target = $reset->user;
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => $action,
            'table_name' => 'password_reset_requests',
            'record_id' => $reset->id,
            'details' => sprintf(
                'target=%s(#%d) requester=#%d approver=%s code_actor=%s | %s',
                $target?->username ?? 'unknown',
                $reset->user_id,
                $reset->requested_by,
                $reset->approved_by ? '#'.$reset->approved_by : 'none',
                $reset->code_generated_by ? '#'.$reset->code_generated_by : 'none',
                $detail,
            ),
            'ip_address' => request()->ip(),
        ]);
    }
}
