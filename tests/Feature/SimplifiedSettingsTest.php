<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\AlertService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class SimplifiedSettingsTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        $role = Role::firstOrCreate(['role_name' => 'admin'], ['description' => 'Admin']);
        $branch = Branch::create(['branch_name' => 'HQ', 'branch_type' => 'main', 'is_active' => true]);

        return User::create(['full_name' => 'Admin', 'username' => 'settingsadmin', 'password_hash' => Hash::make('password123'),
            'role_id' => $role->id, 'branch_id' => $branch->id, 'status' => 'active']);
    }

    public function test_only_expiry_warning_remains_editable_among_alert_thresholds(): void
    {
        $admin = $this->admin();
        foreach (['unusual_sales_sigma' => '5', 'idle_branch_days' => '14', 'order_max_qty_per_line' => '3'] as $key => $value) {
            Setting::set($key, $value);
        }
        $this->actingAs($admin)->get('/settings')->assertOk()
            ->assertSee('name="expiry_warning_days"', false)
            ->assertDontSee('name="unusual_sales_sigma"', false)
            ->assertDontSee('name="idle_branch_days"', false)
            ->assertDontSee('name="order_max_qty_per_line"', false);
        $this->post('/settings', ['expiry_warning_days' => '10', 'unusual_sales_sigma' => '1', 'idle_branch_days' => '1', 'order_max_qty_per_line' => '1'])->assertRedirect(route('settings'));
        $this->assertSame('10', Setting::get('expiry_warning_days'));
        $this->assertSame('5', Setting::get('unusual_sales_sigma'));
        $this->assertSame('14', Setting::get('idle_branch_days'));
        $this->assertSame('3', Setting::get('order_max_qty_per_line'));
        $this->post('/settings', ['expiry_warning_days' => '0'])->assertSessionHasErrors('expiry_warning_days');
        $this->assertSame('10', Setting::get('expiry_warning_days'));
    }

    public function test_consumption_detection_uses_fixed_two_sigma_despite_legacy_setting(): void
    {
        $this->admin();
        Setting::set('unusual_sales_sigma', '5');
        $branch = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $item = Item::create(['item_name' => 'Test Product', 'unit' => 'pack', 'price' => 10, 'is_active' => true]);
        for ($week = 0; $week < 7; $week++) {
            DB::table('stock_transactions')->insert(['item_id' => $item->id, 'branch_id' => $branch->id,
                'type' => 'out', 'quantity' => $week === 0 ? -100 : -10, 'created_at' => now()->startOfWeek()->subWeeks($week)]);
        }
        app(AlertService::class)->generate();
        $this->assertTrue(Alert::where('alert_type', 'unusual_sales')->where('branch_id', $branch->id)->where('item_id', $item->id)->exists());
    }
}
