<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Introduce the business Admin role (Owner-equivalent application functions,
 * distinct identity) and rename the operational Main Branch roles to the
 * Commissary roles. The rename is in place — role ids are preserved, so every
 * existing user keeps its assignment and all historical performer references
 * stay valid. The internal super_admin (developer) account is untouched.
 *
 * Also widens the branches.branch_type enum to allow a separate `commissary`
 * location (MySQL/MariaDB only; SQLite stores the column as free text).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Rename operational roles in place (ids kept → users keep their role).
        DB::table('roles')->where('role_name', 'main_branch_manager')->update([
            'role_name' => 'commissary_manager',
            'description' => 'Commissary manager — encodes branch orders, reviews/prepares requests, sets expiry, prints labels, manages deliveries, monitors branches',
        ]);
        DB::table('roles')->where('role_name', 'main_branch_staff')->update([
            'role_name' => 'commissary_staff',
            'description' => 'Commissary staff — assists preparation and scans prepared goods out for delivery',
        ]);

        // Add the business Admin role once (Owner-equivalent, separate identity).
        if (! DB::table('roles')->where('role_name', 'admin')->exists()) {
            DB::table('roles')->insert([
                'role_name' => 'admin',
                'description' => 'Administrator — same application functions and all-branch access as Owner, with a distinct, traceable account',
                'created_at' => now(),
            ]);
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE branches MODIFY branch_type ENUM('main','franchise','commissary') NOT NULL DEFAULT 'franchise'");
        }
    }

    public function down(): void
    {
        DB::table('roles')->where('role_name', 'commissary_manager')->update(['role_name' => 'main_branch_manager']);
        DB::table('roles')->where('role_name', 'commissary_staff')->update(['role_name' => 'main_branch_staff']);

        // Remove Admin only if no user is assigned to it (never orphan accounts).
        $adminId = DB::table('roles')->where('role_name', 'admin')->value('id');
        if ($adminId && ! DB::table('users')->where('role_id', $adminId)->exists()) {
            DB::table('roles')->where('id', $adminId)->delete();
        }

        if (DB::getDriverName() === 'mysql') {
            DB::statement("ALTER TABLE branches MODIFY branch_type ENUM('main','franchise') NOT NULL DEFAULT 'franchise'");
        }
    }
};
