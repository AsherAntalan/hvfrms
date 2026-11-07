<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Order;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchMovementHistoryTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $silang = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $dasma = Branch::create(['branch_name' => 'Dasma', 'branch_type' => 'franchise', 'is_active' => true]);

        $users = [];
        foreach (['owner', 'commissary_manager', 'branch_manager'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => ucfirst(str_replace('_', ' ', $name)), 'email' => $name.'@mv.test',
                'password_hash' => Hash::make('password'), 'role_id' => $role->id,
                'branch_id' => $name === 'branch_manager' ? $silang->id : $main->id, 'status' => 'active']);
        }

        $meat = Category::create(['name' => 'Meat', 'goods_type' => 'wet']);
        $drinks = Category::create(['name' => 'Drinks', 'goods_type' => 'wet']);

        $patty = Item::create(['item_name' => 'Beef Patty', 'unit' => 'pack', 'price' => 200, 'category_id' => $meat->id, 'is_active' => true]);
        $juice = Item::create(['item_name' => 'Lychee Juice', 'unit' => 'liter', 'price' => 60, 'category_id' => $drinks->id, 'is_active' => true]);
        $oldSauce = Item::create(['item_name' => 'Retired Sauce', 'unit' => 'liter', 'price' => 50, 'category_id' => $drinks->id, 'is_active' => false]);

        $silangBatch = StockBatch::create(['item_id' => $patty->id, 'branch_id' => $silang->id, 'quantity_in' => 10, 'quantity_left' => 10,
            'qr_code' => 'HV-SIL-4890', 'received_at' => now()]);

        // A real Silang order whose number appears in a transaction reason.
        $silangOrder = Order::create(['order_number' => 'ORD-2026-0005', 'branch_id' => $silang->id, 'ordered_by' => $users['branch_manager']->id,
            'status' => 'delivered', 'total_amount' => 200]);
        // An order that belongs to another branch; its number must never link for Silang.
        $dasmaOrder = Order::create(['order_number' => 'ORD-2026-0099', 'branch_id' => $dasma->id, 'ordered_by' => $users['owner']->id,
            'status' => 'delivered', 'total_amount' => 60]);

        return compact('main', 'silang', 'dasma', 'users', 'meat', 'drinks', 'patty', 'juice', 'oldSauce', 'silangBatch', 'silangOrder', 'dasmaOrder');
    }

    private function tx(int $branchId, int $itemId, string $type, int $quantity, string $reason, int $by, Carbon $at, ?int $batchId = null): StockTransaction
    {
        $t = StockTransaction::create(['item_id' => $itemId, 'branch_id' => $branchId, 'batch_id' => $batchId,
            'type' => $type, 'quantity' => $quantity, 'reason' => $reason, 'performed_by' => $by]);
        StockTransaction::where('id', $t->id)->update(['created_at' => $at]);

        return $t->refresh();
    }

    public function test_manager_sees_only_own_branch_movements_and_forged_branch_param_is_ignored(): void
    {
        $w = $this->world();
        $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'Order ORD-2026-0005 delivered', $w['users']['branch_manager']->id, now());
        $this->tx($w['dasma']->id, $w['patty']->id, 'in', 5, 'Dasma receipt', $w['users']['owner']->id, now());

        // A forged branch_id query parameter must not widen the scope.
        $this->actingAs($w['users']['branch_manager'])
            ->get('/inventory?tab=movements&branch_id='.$w['dasma']->id)->assertOk()
            ->assertViewHas('movements', fn ($movements) => $movements->total() === 1
                && $movements->getCollection()->every(fn ($tx) => $tx->branch_id === $w['silang']->id))
            ->assertSee('Beef Patty')
            ->assertDontSee('Dasma receipt');
    }

    public function test_inactive_products_historical_movements_remain_visible(): void
    {
        $w = $this->world();
        $this->tx($w['silang']->id, $w['oldSauce']->id, 'out', -3, 'Sold / Used', $w['users']['branch_manager']->id, now());

        $this->actingAs($w['users']['branch_manager'])->get('/inventory?tab=movements')->assertOk()
            ->assertSee('Retired Sauce')
            ->assertViewHas('movements', fn ($movements) => $movements->total() === 1);
    }

    public function test_quantity_keeps_stored_sign_with_unit_and_no_double_negative(): void
    {
        $w = $this->world();
        $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'Delivery received', $w['users']['branch_manager']->id, now()->subMinutes(2));
        $this->tx($w['silang']->id, $w['juice']->id, 'out', -2, 'Sold / Used', $w['users']['branch_manager']->id, now());

        $response = $this->actingAs($w['users']['branch_manager'])->get('/inventory?tab=movements')->assertOk();
        $response->assertSee('+1')->assertSee('-2')->assertDontSee('--2')
            ->assertSee('pack')->assertSee('liter');
    }

    public function test_date_range_includes_full_end_date_boundary(): void
    {
        $w = $this->world();
        $tz = config('app.timezone');
        $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'Before window', $w['users']['branch_manager']->id, Carbon::parse('2026-11-04 10:00', $tz));
        $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'End of day edge', $w['users']['branch_manager']->id, Carbon::parse('2026-11-06 23:30', $tz));
        $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'After window', $w['users']['branch_manager']->id, Carbon::parse('2026-11-07 08:00', $tz));

        $this->actingAs($w['users']['branch_manager'])
            ->get('/inventory?tab=movements&from=2026-11-05&to=2026-11-06')->assertOk()
            ->assertViewHas('movements', fn ($movements) => $movements->total() === 1
                && $movements->getCollection()->first()->reason === 'End of day edge');
    }

    public function test_type_and_category_filters_scope_results(): void
    {
        $w = $this->world();
        $this->tx($w['silang']->id, $w['patty']->id, 'in', 2, 'Meat in', $w['users']['branch_manager']->id, now());
        $this->tx($w['silang']->id, $w['juice']->id, 'out', -1, 'Drink out', $w['users']['branch_manager']->id, now());

        $this->actingAs($w['users']['branch_manager'])
            ->get('/inventory?tab=movements&type=out')->assertOk()
            ->assertViewHas('movements', fn ($movements) => $movements->total() === 1 && $movements->getCollection()->first()->type === 'out');

        $this->actingAs($w['users']['branch_manager'])
            ->get('/inventory?tab=movements&category='.$w['meat']->id)->assertOk()
            ->assertViewHas('movements', fn ($movements) => $movements->total() === 1 && $movements->getCollection()->first()->item_id === $w['patty']->id);
    }

    public function test_search_matches_product_batch_and_order_reference(): void
    {
        $w = $this->world();
        $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'Order ORD-2026-0005 delivered', $w['users']['branch_manager']->id, now(), $w['silangBatch']->id);
        $this->tx($w['silang']->id, $w['juice']->id, 'out', -1, 'Sold / Used', $w['users']['branch_manager']->id, now());

        $manager = $w['users']['branch_manager'];
        $this->actingAs($manager)->get('/inventory?tab=movements&search=Lychee')->assertOk()
            ->assertViewHas('movements', fn ($m) => $m->total() === 1 && $m->getCollection()->first()->item_id === $w['juice']->id);
        $this->actingAs($manager)->get('/inventory?tab=movements&search=ORD-2026-0005')->assertOk()
            ->assertViewHas('movements', fn ($m) => $m->total() === 1 && $m->getCollection()->first()->item_id === $w['patty']->id);
        $this->actingAs($manager)->get('/inventory?tab=movements&search=%23'.$w['silangBatch']->id)->assertOk()
            ->assertViewHas('movements', fn ($m) => $m->total() === 1 && (int) $m->getCollection()->first()->batch_id === $w['silangBatch']->id);
    }

    public function test_order_reference_links_only_for_real_same_branch_orders(): void
    {
        $w = $this->world();
        // Reason naming a Silang order (links) and one naming a non-existent order (no link).
        $linked = $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'Order ORD-2026-0005 delivered', $w['users']['branch_manager']->id, now(), $w['silangBatch']->id);
        $unlinked = $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'Order ORD-9999-0000 delivered', $w['users']['branch_manager']->id, now()->subMinute());

        $response = $this->actingAs($w['users']['branch_manager'])->get('/inventory?tab=movements')->assertOk();
        $response->assertViewHas('movementOrderRefs', function ($refs) use ($linked, $unlinked, $w) {
            return isset($refs[$linked->id]) && $refs[$linked->id]['id'] === $w['silangOrder']->id
                && $refs[$linked->id]['number'] === 'ORD-2026-0005'
                && ! isset($refs[$unlinked->id]);
        });
        $response->assertSee(route('orders', ['order' => $w['silangOrder']->id]), false)
            ->assertSee(route('stock.batch', $w['silangBatch']->id), false);
    }

    public function test_pagination_shows_ten_per_page_and_preserves_tab_and_filters(): void
    {
        $w = $this->world();
        for ($i = 0; $i < 12; $i++) {
            $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'Meat batch '.$i, $w['users']['branch_manager']->id, now()->subMinutes($i));
        }

        $this->actingAs($w['users']['branch_manager'])
            ->get('/inventory?tab=movements&type=in')->assertOk()
            ->assertViewHas('movements', fn ($m) => $m->perPage() === 10 && $m->total() === 12 && $m->count() === 10)
            ->assertSee('of 12 records')
            // Page links carry the active tab and the filter.
            ->assertSee('tab=movements', false)->assertSee('type=in', false);

        $this->actingAs($w['users']['branch_manager'])
            ->get('/inventory?tab=movements&type=in&page=2')->assertOk()
            ->assertViewHas('movements', fn ($m) => $m->currentPage() === 2 && $m->count() === 2);
    }

    public function test_global_history_total_agrees_with_direct_branch_scope(): void
    {
        $w = $this->world();
        $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'A', $w['users']['branch_manager']->id, now());
        $this->tx($w['silang']->id, $w['juice']->id, 'out', -1, 'B', $w['users']['branch_manager']->id, now());
        $this->tx($w['dasma']->id, $w['patty']->id, 'in', 9, 'Other branch', $w['users']['owner']->id, now());

        $silangCount = StockTransaction::where('branch_id', $w['silang']->id)->count();
        $this->actingAs($w['users']['branch_manager'])->get('/inventory?tab=movements')->assertOk()
            ->assertViewHas('movements', fn ($m) => $m->total() === $silangCount);
    }

    public function test_empty_states_distinguish_no_records_from_no_matches(): void
    {
        $w = $this->world();
        $this->actingAs($w['users']['branch_manager'])->get('/inventory?tab=movements')->assertOk()
            ->assertSee('No inventory movements recorded yet.');

        $this->tx($w['silang']->id, $w['patty']->id, 'in', 1, 'Meat in', $w['users']['branch_manager']->id, now());
        $this->actingAs($w['users']['branch_manager'])->get('/inventory?tab=movements&type=out')->assertOk()
            ->assertSee('No movements match your filters.');
    }

    public function test_inventory_tab_stays_default_and_other_roles_do_not_get_movement_tab(): void
    {
        $w = $this->world();

        // Branch Manager: default tab is inventory, and the Movement History tab exists.
        $this->actingAs($w['users']['branch_manager'])->get('/inventory')->assertOk()
            ->assertViewHas('tab', 'inventory')->assertViewHas('showMovements', true)
            ->assertSee('Movement History')->assertSee('id="productTable"', false);

        // The Commissary Manager's /inventory is the all-branch Warehouse monitor,
        // not this branch view, so the branch Movement History tab is never added.
        $this->actingAs($w['users']['commissary_manager'])->get('/inventory?tab=movements')->assertOk()
            ->assertViewMissing('showMovements')->assertViewHas('monitorAllBranches');
    }
}
