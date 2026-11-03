<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\RememberToken;
use App\Models\Role;
use App\Models\User;
use App\Services\ResetCodeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;

class UserController extends Controller
{
    /**
     * Show the users management page.
     */
    public function index()
    {
        if (Auth::user()->isManagerRole()) {
            $actor = Auth::user();
            $staffRole = $actor->isBranchManager() ? 'branch_staff' : 'commissary_staff';
            $staff = User::with('role')->where('branch_id', $actor->branch_id)
                ->whereHas('role', fn ($roles) => $roles->where('role_name', $staffRole))->orderBy('full_name')->get();
            $recoveryCodes = [];
            foreach ($staff as $employee) {
                if ($actor->canViewRecoveryCodeFor($employee)) {
                    $recoveryCodes[$employee->id] = app(ResetCodeService::class)->forAccount($employee);
                }
            }
            if ($recoveryCodes !== []) {
                AuditLog::create([
                    'user_id' => $actor->id, 'action' => 'account_recovery_codes_viewed', 'table_name' => 'users',
                    'details' => 'Viewed recovery codes for account IDs: '.implode(', ', array_keys($recoveryCodes)),
                    'ip_address' => request()->ip(),
                ]);
            }

            return response()->view('users.manager', compact('staff', 'recoveryCodes'))->header('Cache-Control', 'no-store, private');
        }

        $users = User::with('role')->select('users.*', 'roles.role_name', 'branches.branch_name')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join('branches', 'branches.id', '=', 'users.branch_id')
            ->orderBy('users.status')
            ->orderBy('roles.role_name')
            ->orderBy('users.full_name')
            ->get();

        $roles = Role::orderBy('id')->get();
        $branches = Branch::where('is_active', 1)->orderBy('branch_name')->get();

        $recoveryCodes = [];
        foreach ($users as $user) {
            if (Auth::user()->canViewRecoveryCodeFor($user)) {
                $recoveryCodes[$user->id] = app(ResetCodeService::class)->forAccount($user);
            }
        }
        if ($recoveryCodes !== []) {
            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'account_recovery_codes_viewed',
                'table_name' => 'users',
                'details' => 'Viewed recovery codes for account IDs: '.implode(', ', array_keys($recoveryCodes)),
                'ip_address' => request()->ip(),
            ]);
        }

        return response()->view('users.index', compact('users', 'roles', 'branches', 'recoveryCodes'))
            ->header('Cache-Control', 'no-store, private');
    }

    /**
     * Handle AJAX API actions: save, toggle, reset_password.
     */
    public function api(Request $request): JsonResponse
    {
        $action = $request->input('action');

        if ($action === 'save') {
            return $this->saveUser($request);
        }

        if ($action === 'toggle') {
            return $this->toggleUser($request);
        }

        if ($action === 'reset_password') {
            return $this->resetPassword($request);
        }

        if ($action === 'reset_code') {
            return $this->generateResetCode($request);
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    /**
     * Create or update a user.
     */
    protected function saveUser(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $usernameInput = $request->input('username');
        $username = is_string($usernameInput) ? trim($usernameInput) : '';
        $fullName = trim($request->input('full_name', ''));
        $email = trim($request->input('email', ''));
        $password = $request->input('password') ?? '';
        $roleId = (int) $request->input('role_id', 0);
        $branchId = (int) $request->input('branch_id', 0);

        // Validation
        if ($username === '' || strlen($username) > 50 || ! preg_match('/\A[A-Za-z0-9_.-]+\z/', $username)) {
            return response()->json(['ok' => false, 'error' => 'Username is required and must contain up to 50 letters, numbers, dots, underscores, or hyphens.'], 422);
        }
        if (User::whereRaw('LOWER(username) = ?', [strtolower($username)])->when($id > 0, fn ($query) => $query->where('id', '!=', $id))->exists()) {
            return response()->json(['ok' => false, 'error' => 'That username is already taken.'], 422);
        }
        if ($fullName === '') {
            return response()->json(['ok' => false, 'error' => 'Full name is required.'], 422);
        }
        // Email is optional for accounts that recover through the code hierarchy.
        if ($email !== '' && ! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'Enter a valid email, or leave it blank.'], 422);
        }
        if ($roleId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Please select a role.'], 422);
        }
        if ($branchId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Please select a branch.'], 422);
        }

        // Password required for new users, optional for edits
        if ($id === 0 && strlen($password) < 6) {
            return response()->json(['ok' => false, 'error' => 'Password must be at least 6 characters.'], 422);
        }
        if ($id > 0 && $password !== '' && strlen($password) < 6) {
            return response()->json(['ok' => false, 'error' => 'Password must be at least 6 characters.'], 422);
        }

        // Check duplicate email only when one is supplied (blanks stay NULL).
        if ($email !== '') {
            $dupQuery = User::where('email', $email);
            if ($id > 0) {
                $dupQuery->where('id', '!=', $id);
            }
            if ($dupQuery->exists()) {
                return response()->json(['ok' => false, 'error' => 'A user with that email already exists.'], 422);
            }
        }

        // Verify role exists
        $role = Role::find($roleId);
        if (! $role) {
            return response()->json(['ok' => false, 'error' => 'Invalid role selected.'], 422);
        }

        // The super admin recovers through the email reset link, so an email is
        // mandatory for that role only.
        if ($role->role_name === 'super_admin' && $email === '') {
            return response()->json(['ok' => false, 'error' => 'A super admin account requires an email for recovery.'], 422);
        }

        // Verify branch exists and is active
        $branch = Branch::where('id', $branchId)->where('is_active', 1)->first();
        if (! $branch) {
            return response()->json(['ok' => false, 'error' => 'Invalid or inactive branch selected.'], 422);
        }

        // Only super_admin can create another super_admin
        if ($role->role_name === 'super_admin' && ! Auth::user()->isSuperAdmin()) {
            return response()->json(['ok' => false, 'error' => 'Only super admins can assign the super admin role.'], 422);
        }

        if ($id > 0) {
            // Update existing user
            $user = User::findOrFail($id);
            $data = [
                'username' => $username,
                'full_name' => $fullName,
                'email' => $email !== '' ? $email : null,
                'role_id' => $roleId,
                'branch_id' => $branchId,
            ];

            if ($password !== '') {
                $data['password_hash'] = Hash::make($password);
            }

            $user->update($data);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'updated user',
                'table_name' => 'users',
                'record_id' => $id,
                'details' => "Name: {$fullName}, Role: {$role->role_name}",
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['ok' => true, 'message' => 'User updated successfully.']);
        } else {
            // Create new user
            $user = User::create([
                'username' => $username,
                'full_name' => $fullName,
                'email' => $email !== '' ? $email : null,
                'password_hash' => Hash::make($password),
                'role_id' => $roleId,
                'branch_id' => $branchId,
                'status' => 'active',
            ]);

            AuditLog::create([
                'user_id' => Auth::id(),
                'action' => 'created user',
                'table_name' => 'users',
                'record_id' => $user->id,
                'details' => "Name: {$fullName}, Role: {$role->role_name}",
                'ip_address' => $request->ip(),
            ]);

            return response()->json([
                'ok' => true,
                'message' => 'User created successfully.',
                'id' => $user->id,
                'recovery_code' => Auth::user()->canViewRecoveryCodeFor($user) ? $user->recovery_code : null,
            ])->header('Cache-Control', 'no-store');
        }
    }

    /**
     * Toggle user status (active/disabled).
     */
    protected function toggleUser(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $status = $request->input('status', '');

        if ($id <= 0) {
            return response()->json(['ok' => false, 'error' => 'Invalid user ID.'], 422);
        }
        if (! in_array($status, ['active', 'disabled'], true)) {
            return response()->json(['ok' => false, 'error' => 'Invalid status.'], 422);
        }

        // Prevent disabling yourself
        if ($id === Auth::id() && $status === 'disabled') {
            return response()->json(['ok' => false, 'error' => 'You cannot disable your own account.'], 422);
        }

        User::where('id', $id)->update([
            'status' => $status,
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => "{$status} user",
            'table_name' => 'users',
            'record_id' => $id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'message' => "User {$status} successfully."]);
    }

    /**
     * Reset a user's password.
     */
    protected function resetPassword(Request $request): JsonResponse
    {
        $id = (int) $request->input('id', 0);
        $newPassword = $request->input('new_password', '');

        if ($id <= 0) {
            return response()->json(['ok' => false, 'error' => 'Invalid user ID.'], 422);
        }
        if (strlen($newPassword) < 6) {
            return response()->json(['ok' => false, 'error' => 'Password must be at least 6 characters.'], 422);
        }

        User::where('id', $id)->update([
            'password_hash' => Hash::make($newPassword),
            'failed_attempts' => 0,
            'locked_until' => null,
            // An admin reset also invalidates any outstanding self-service reset link.
            'reset_token' => null,
            'reset_token_expires' => null,
        ]);

        // Revoke persistent authentication for the account.
        RememberToken::where('user_id', $id)->delete();

        AuditLog::create([
            'user_id' => Auth::id(),
            'action' => 'reset password',
            'table_name' => 'users',
            'record_id' => $id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Password reset successfully.']);
    }

    /** Reveal the assigned account code without authorizing a password reset. */
    protected function generateResetCode(Request $request): JsonResponse
    {
        $actor = Auth::user();
        $target = User::with('role')->find((int) $request->input('id', 0));
        abort_unless($target && $actor->canViewRecoveryCodeFor($target), 403);

        $key = 'recovery-reveal:'.$actor->id;
        if (RateLimiter::tooManyAttempts($key, 10)) {
            return response()->json(['ok' => false, 'error' => 'Please wait before viewing another code.'], 429);
        }
        RateLimiter::hit($key, 60);
        $plain = app(ResetCodeService::class)->forAccount($target);
        AuditLog::create([
            'user_id' => $actor->id,
            'action' => 'account_recovery_code_viewed',
            'table_name' => 'users',
            'record_id' => $target->id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'code' => $plain])
            ->header('Cache-Control', 'no-store');
    }
}
