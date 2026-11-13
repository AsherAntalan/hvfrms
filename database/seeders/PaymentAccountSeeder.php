<?php

namespace Database\Seeders;

use App\Models\PaymentAccount;
use Illuminate\Database\Seeder;

class PaymentAccountSeeder extends Seeder
{
    public function run(): void
    {
        $defaults = [
            ['type' => 'bank', 'provider' => 'BDO', 'sort_order' => 1],
            ['type' => 'bank', 'provider' => 'BPI', 'sort_order' => 2],
            ['type' => 'ewallet', 'provider' => 'GCash', 'sort_order' => 3],
            ['type' => 'ewallet', 'provider' => 'PayMaya', 'sort_order' => 4],
        ];
        foreach ($defaults as $d) {
            PaymentAccount::firstOrCreate(
                ['type' => $d['type'], 'provider' => $d['provider']],
                ['account_name' => null, 'account_number' => null, 'instructions' => null, 'is_active' => true, 'sort_order' => $d['sort_order']],
            );
        }
    }
}
