<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Rename it_staff → branch_staff
        DB::table('roles')
            ->where('role_name', 'it_staff')
            ->update([
                'role_name'   => 'branch_staff',
                'description' => 'Branch staff — QR scanning, usage/spoilage recording, branch inventory tasks',
            ]);

        // Add warehouse roles
        DB::table('roles')->insert([
            ['role_name' => 'warehouse_admin', 'description' => 'Warehouse administrator — supervises warehouse operations, receiving, stocking, QR, delivery prep, disposal', 'created_at' => now()],
            ['role_name' => 'warehouse_staff', 'description' => 'Warehouse staff — performs physical receiving, stocking, QR scanning, picking, delivery prep, expired handling', 'created_at' => now()],
        ]);
    }

    public function down(): void
    {
        DB::table('roles')->whereIn('role_name', ['warehouse_admin', 'warehouse_staff'])->delete();

        // Restore it_staff
        DB::table('roles')
            ->where('role_name', 'branch_staff')
            ->update([
                'role_name'   => 'it_staff',
                'description' => 'IT staff — QR scanner web app only (record usage/spoilage for own branch)',
            ]);
    }
};
