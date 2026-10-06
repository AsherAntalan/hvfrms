<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;

class UserController extends Controller
{
    /**
     * Show the users management page.
     */
    public function index()
    {
        $users = User::select('users.*', 'roles.role_name', 'branches.branch_name')
            ->join('roles', 'roles.id', '=', 'users.role_id')
            ->join('branches', 'branches.id', '=', 'users.branch_id')
            ->orderBy('users.status')
            ->orderBy('roles.role_name')
            ->orderBy('users.full_name')
            ->get();

        $roles = Role::orderBy('id')->get();
        $branches = Branch::where('is_active', 1)->orderBy('branch_name')->get();

        return view('users.index', compact('users', 'roles', 'branches'));
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

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    /**
     * Create or update a user.
     */
    protected function saveUser(Request $request): JsonResponse
    {
        $id       = (int) $request->input('id', 0);
        $fullName = trim($request->input('full_name', ''));
        $email    = trim($request->input('email', ''));
        $password = $request->input('password', '');
        $roleId   = (int) $request->input('role_id', 0);
        $branchId = (int) $request->input('branch_id', 0);

        // Validation
        if ($fullName === '') {
            return response()->json(['ok' => false, 'error' => 'Full name is required.'], 422);
        }
        if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return response()->json(['ok' => false, 'error' => 'A valid email is required.'], 422);
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

        // Check duplicate email
        $dupQuery = User::where('email', $email);
        if ($id > 0) {
            $dupQuery->where('id', '!=', $id);
        }
        if ($dupQuery->exists()) {
            return response()->json(['ok' => false, 'error' => 'A user with that email already exists.'], 422);
        }

        // Verify role exists
        $role = Role::find($roleId);
        if (!$role) {
            return response()->json(['ok' => false, 'error' => 'Invalid role selected.'], 422);
        }

        // Verify branch exists and is active
        $branch = Branch::where('id', $branchId)->where('is_active', 1)->first();
        if (!$branch) {
            return response()->json(['ok' => false, 'error' => 'Invalid or inactive branch selected.'], 422);
        }

        // Only super_admin can create another super_admin
        if ($role->role_name === 'super_admin' && !Auth::user()->isSuperAdmin()) {
            return response()->json(['ok' => false, 'error' => 'Only super admins can assign the super admin role.'], 422);
        }

        if ($id > 0) {
            // Update existing user
            $user = User::findOrFail($id);
            $data = [
                'full_name' => $fullName,
                'email'     => $email,
                'role_id'   => $roleId,
                'branch_id' => $branchId,
            ];

            if ($password !== '') {
                $data['password_hash'] = Hash::make($password);
            }

            $user->update($data);

            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'updated user',
                'table_name' => 'users',
                'record_id'  => $id,
                'details'    => "Name: {$fullName}, Role: {$role->role_name}",
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['ok' => true, 'message' => 'User updated successfully.']);
        } else {
            // Create new user
            $user = User::create([
                'full_name'     => $fullName,
                'email'         => $email,
                'password_hash' => Hash::make($password),
                'role_id'       => $roleId,
                'branch_id'     => $branchId,
                'status'        => 'active',
            ]);

            AuditLog::create([
                'user_id'    => Auth::id(),
                'action'     => 'created user',
                'table_name' => 'users',
                'record_id'  => $user->id,
                'details'    => "Name: {$fullName}, Role: {$role->role_name}",
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['ok' => true, 'message' => 'User created successfully.', 'id' => $user->id]);
        }
    }

    /**
     * Toggle user status (active/disabled).
     */
    protected function toggleUser(Request $request): JsonResponse
    {
        $id     = (int) $request->input('id', 0);
        $status = $request->input('status', '');

        if ($id <= 0) {
            return response()->json(['ok' => false, 'error' => 'Invalid user ID.'], 422);
        }
        if (!in_array($status, ['active', 'disabled'], true)) {
            return response()->json(['ok' => false, 'error' => 'Invalid status.'], 422);
        }

        // Prevent disabling yourself
        if ($id === Auth::id() && $status === 'disabled') {
            return response()->json(['ok' => false, 'error' => 'You cannot disable your own account.'], 422);
        }

        User::where('id', $id)->update([
            'status'          => $status,
            'failed_attempts' => 0,
            'locked_until'    => null,
        ]);

        AuditLog::create([
            'user_id'    => Auth::id(),
            'action'     => "{$status} user",
            'table_name' => 'users',
            'record_id'  => $id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'message' => "User {$status} successfully."]);
    }

    /**
     * Reset a user's password.
     */
    protected function resetPassword(Request $request): JsonResponse
    {
        $id          = (int) $request->input('id', 0);
        $newPassword = $request->input('new_password', '');

        if ($id <= 0) {
            return response()->json(['ok' => false, 'error' => 'Invalid user ID.'], 422);
        }
        if (strlen($newPassword) < 6) {
            return response()->json(['ok' => false, 'error' => 'Password must be at least 6 characters.'], 422);
        }

        User::where('id', $id)->update([
            'password_hash'   => Hash::make($newPassword),
            'failed_attempts' => 0,
            'locked_until'    => null,
        ]);

        AuditLog::create([
            'user_id'    => Auth::id(),
            'action'     => 'reset password',
            'table_name' => 'users',
            'record_id'  => $id,
            'ip_address' => $request->ip(),
        ]);

        return response()->json(['ok' => true, 'message' => 'Password reset successfully.']);
    }
}
