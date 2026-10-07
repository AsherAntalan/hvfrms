<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // ── Roles ──────────────────────────────────────
        DB::table('roles')->insert([
            ['role_name' => 'super_admin',     'description' => 'Researcher/developer — full system access, audit logs, user management', 'created_at' => now()],
            ['role_name' => 'owner',           'description' => 'Hidden Vietnam main owner — all branches, users, branches, settings, order approval', 'created_at' => now()],
            ['role_name' => 'branch_manager',  'description' => 'Branch manager — dashboard, stock, orders, reports, forecast, alerts for OWN branch only', 'created_at' => now()],
            ['role_name' => 'it_staff',        'description' => 'IT staff — QR scanner web app only (record usage/spoilage for own branch)', 'created_at' => now()],
            ['role_name' => 'warehouse_admin', 'description' => 'Warehouse administrator — supervises warehouse operations, receiving, stocking, QR, delivery prep, disposal', 'created_at' => now()],
            ['role_name' => 'warehouse_staff', 'description' => 'Warehouse staff — performs physical receiving, stocking, QR scanning, picking, delivery prep, expired handling', 'created_at' => now()],
        ]);

        // ── Branches ───────────────────────────────────
        $now = now();
        DB::table('branches')->insert([
            ['branch_name' => 'Tanza',                     'branch_type' => 'main',      'address' => 'Tanza, Cavite',                         'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Silang',                    'branch_type' => 'franchise',  'address' => null,                                    'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => "Dasma - Governor's Drive",  'branch_type' => 'franchise',  'address' => "Governor's Drive, Dasmariñas, Cavite",  'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'La Salle - Dasma',          'branch_type' => 'franchise',  'address' => null,                                    'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Molino 3',                  'branch_type' => 'franchise',  'address' => 'Bacoor, Cavite',                        'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Mendez',                    'branch_type' => 'franchise',  'address' => null,                                    'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Lipa',                      'branch_type' => 'franchise',  'address' => 'Batangas',                              'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Batangas',                  'branch_type' => 'franchise',  'address' => null,                                    'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Rosario',                   'branch_type' => 'franchise',  'address' => 'Batangas',                              'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Muntinlupa',                'branch_type' => 'franchise',  'address' => 'Katarungan Village, Muntinlupa',        'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Trece Martires',            'branch_type' => 'franchise',  'address' => null,                                    'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Marikina',                  'branch_type' => 'franchise',  'address' => 'Marikina Heights, Marikina City',       'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Quezon City',               'branch_type' => 'franchise',  'address' => null,                                    'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Baguio',                    'branch_type' => 'franchise',  'address' => null,                                    'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
            ['branch_name' => 'Indang',                    'branch_type' => 'franchise',  'address' => null,                                    'contact_number' => null, 'is_active' => 1, 'created_at' => $now, 'updated_at' => $now],
        ]);

        // ── Categories ─────────────────────────────────
        DB::table('categories')->insert([
            ['name' => 'Sauces',              'goods_type' => 'wet', 'description' => null, 'created_at' => $now],
            ['name' => 'Sauces (Big Sizes)',   'goods_type' => 'wet', 'description' => null, 'created_at' => $now],
            ['name' => 'Frozen Products',      'goods_type' => 'wet', 'description' => null, 'created_at' => $now],
            ['name' => 'Bread',                'goods_type' => 'dry', 'description' => null, 'created_at' => $now],
            ['name' => 'Juices/Others',        'goods_type' => 'wet', 'description' => null, 'created_at' => $now],
        ]);

        // ── Settings ───────────────────────────────────
        DB::table('settings')->insert([
            ['setting_key' => 'forecast_weeks',      'setting_value' => '8',   'description' => 'Number of weeks to analyze for forecast',        'updated_at' => $now],
            ['setting_key' => 'safety_stock_factor',  'setting_value' => '1.5', 'description' => 'Safety stock multiplier for reorder recommendations', 'updated_at' => $now],
            ['setting_key' => 'unusual_sales_sigma',  'setting_value' => '2',   'description' => 'Z-score threshold for unusual sales alerts',    'updated_at' => $now],
            ['setting_key' => 'idle_branch_days',     'setting_value' => '3',   'description' => 'Days without activity before branch idle alert', 'updated_at' => $now],
            ['setting_key' => 'expiry_warning_days',  'setting_value' => '7',   'description' => 'Days before expiry to trigger warning alert',   'updated_at' => $now],
            ['setting_key' => 'app_name',             'setting_value' => 'Hidden Vietnam FRMS', 'description' => 'Application display name',     'updated_at' => $now],
        ]);

        // ── Demo Users ─────────────────────────────────
        // Passwords: admin123 (super_admin), owner123 (owner), staff123 (it_staff), warehouse123 (warehouse_admin & warehouse_staff)
        DB::table('users')->insert([
            [
                'full_name'     => 'Research Admin',
                'email'         => 'admin@hiddenvietnam.com',
                'password_hash' => '$2y$12$pcK6LnxhEQ8C.zGGHYEYPemMD2agOe0WHkShoylC7jr/rh4WispcS',
                'role_id'       => 1, // super_admin
                'branch_id'     => 1, // Tanza
                'status'        => 'active',
                'failed_attempts' => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'full_name'     => 'System Owner',
                'email'         => 'owner@hiddenvietnam.com',
                'password_hash' => '$2y$12$pow.7AFxPm0Tr87rSsLH8.CYUDCjysHctScqZ294G2z8BF5ArnFC.',
                'role_id'       => 2, // owner
                'branch_id'     => 1, // Tanza
                'status'        => 'active',
                'failed_attempts' => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'full_name'     => 'Tanza Staff',
                'email'         => 'staff.tanza@hiddenvietnam.com',
                'password_hash' => '$2y$12$HVeVaWOQZvfHU5mbA8Bf9OEFMltmrkLzhS23nIZ/bgG1C2jXdp9zC',
                'role_id'       => 4, // it_staff
                'branch_id'     => 1, // Tanza
                'status'        => 'active',
                'failed_attempts' => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'full_name'     => 'Warehouse Admin',
                'email'         => 'whadmin@hiddenvietnam.com',
                'password_hash' => '$2y$12$JpvYwbsu7i7DKKHgfmTls.QKjGm9mCa0Etvf91InKD59gEb.NBjJa',
                'role_id'       => 5, // warehouse_admin
                'branch_id'     => 1, // Tanza (main)
                'status'        => 'active',
                'failed_attempts' => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
            [
                'full_name'     => 'Warehouse Staff',
                'email'         => 'whstaff@hiddenvietnam.com',
                'password_hash' => '$2y$12$JpvYwbsu7i7DKKHgfmTls.QKjGm9mCa0Etvf91InKD59gEb.NBjJa',
                'role_id'       => 6, // warehouse_staff
                'branch_id'     => 1, // Tanza (main)
                'status'        => 'active',
                'failed_attempts' => 0,
                'created_at'    => $now,
                'updated_at'    => $now,
            ],
        ]);
    }
}
