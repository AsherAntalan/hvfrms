<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Give admin@hiddenvietnam.com the business `admin` role (Owner-equivalent
 * application access with a distinct, traceable identity). If the account does
 * not exist yet it is created with password `admin123`. The `super_admin` role
 * itself is left intact for any internal developer account.
 */
return new class extends Migration
{
    public function up(): void
    {
        $adminRoleId = DB::table('roles')->where('role_name', 'admin')->value('id');
        if (! $adminRoleId) {
            $adminRoleId = DB::table('roles')->insertGetId([
                'role_name' => 'admin',
                'description' => 'Administrator — same application functions and all-branch access as Owner, with a distinct, traceable account',
                'created_at' => now(),
            ]);
        }

        $mainBranchId = DB::table('branches')->where('branch_type', 'main')->where('is_active', true)->value('id')
            ?? DB::table('branches')->where('is_active', true)->orderBy('id')->value('id');

        $user = DB::table('users')->where('email', 'admin@hiddenvietnam.com')->first();
        if ($user) {
            DB::table('users')->where('id', $user->id)->update([
                'role_id' => $adminRoleId,
                'full_name' => $user->full_name ?: 'Administrator',
            ]);
        } elseif ($mainBranchId) {
            // Only create when a branch exists (skips on a fresh, branch-less
            // migrate such as the test database; the seeder provides the account).
            DB::table('users')->insert([
                'full_name' => 'Administrator',
                'email' => 'admin@hiddenvietnam.com',
                'password_hash' => Hash::make('admin123'),
                'role_id' => $adminRoleId,
                'branch_id' => $mainBranchId,
                'status' => 'active',
                'failed_attempts' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        // Non-destructive: leave the account and its role assignment in place.
    }
};
