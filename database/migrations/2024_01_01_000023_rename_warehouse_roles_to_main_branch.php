<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Tanza is the Main Branch / commissary. Rename the warehouse roles to Main
 * Branch roles IN PLACE — same role ids, so every user.role_id reference,
 * account, and historical link is preserved. No users deleted, no credentials
 * reset. Additive/data-only; reversible.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('roles')->where('role_name', 'warehouse_admin')->update([
            'role_name' => 'main_branch_manager',
            'description' => 'Main Branch manager — oversees commissary production, order preparation, QR/expiry setup, dispatch, and disposal at Tanza',
        ]);
        DB::table('roles')->where('role_name', 'warehouse_staff')->update([
            'role_name' => 'main_branch_staff',
            'description' => 'Main Branch staff — prepares, scans, and dispatches produced orders at Tanza',
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->where('role_name', 'main_branch_manager')->update([
            'role_name' => 'warehouse_admin',
            'description' => 'Warehouse administrator — supervises warehouse operations, receiving, stocking, QR, delivery prep, disposal',
        ]);
        DB::table('roles')->where('role_name', 'main_branch_staff')->update([
            'role_name' => 'warehouse_staff',
            'description' => 'Warehouse staff — performs physical receiving, stocking, QR scanning, picking, delivery prep, expired handling',
        ]);
    }
};
