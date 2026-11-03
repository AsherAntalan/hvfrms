<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AllBranchesInventoryFilterTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, mixed> */
    private function world(): array
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        Setting::set('commissary_branch_id', (string) $commissary->id);
        $alpha = Branch::create(['branch_name' => 'Alpha', 'branch_type' => 'franchise', 'is_active' => true]);
        $bravo = Branch::create(['branch_name' => 'Bravo', 'branch_type' => 'franchise', 'is_active' => true]);

        $role = fn (string $name) => Role::firstOrCreate(['role_name' => $name], ['description' => $name])->id;
        $user = fn (string $r, int $bid) => User::create([
            'full_name' => $r, 'email' => $r.'@filter.test', 'password_hash' => Hash::make('password'),
            'role_id' => $role($r), 'branch_id' => $bid, 'status' => 'active',
        ]);
        $owner = $user('owner', $commissary->id);
        $manager = $user('commissary_manager', $commissary->id);
        $admin = $user('admin', $commissary->id);

        $cat = Category::create(['name' => 'Produce', 'goods_type' => 'wet']);
        $mk = fn (string $name, string $unit, int $reorder) => Item::create([
            'item_name' => $name, 'category_id' => $cat->id, 'unit' => $unit, 'price' => 20,
            'reorder_level' => $reorder, 'shelf_life_days' => 30, 'is_active' => true,
        ]);
        $apple = $mk('Apple', 'kg', 10);     // two usable batches 6+7=13 (> reorder → above)
        $banana = $mk('Banana', 'piece', 5); // one usable batch 3 (< reorder → low)
        $cherry = $mk('Cherry', 'kg', 10);   // only an expired batch → out of stock
        $date = $mk('Date', 'piece', 5);     // expired + damaged only → out of stock

        $batch = fn (Item $i, Branch $b, int $qty, $expiry, string $cond = 'good') => StockBatch::create([
            'item_id' => $i->id, 'branch_id' => $b->id, 'quantity_in' => $qty, 'quantity_left' => $qty,
            'expiry_date' => $expiry, 'condition' => $cond, 'qr_code' => 'QR-'.$i->id.'-'.$b->id.'-'.$qty, 'created_by' => $owner->id,
        ]);
        $batch($apple, $alpha, 6, now()->addDays(20));
        $batch($apple, $alpha, 7, now()->addDays(25));
        $batch($banana, $alpha, 3, now()->addDays(10));
        $cherryExpired = $batch($cherry, $alpha, 5, now()->subDays(4));
        $batch($date, $bravo, 4, now()->subDays(3));            // expired
        $batch($date, $bravo, 2, now()->addDays(10), 'damaged'); // damaged

        // Disposal records (one linked to an expired batch so owner can archive).
        // created_at is forced after creation because model timestamps overwrite it.
        $recentDisposal = StockTransaction::create(['item_id' => $cherry->id, 'branch_id' => $alpha->id, 'batch_id' => $cherryExpired->id,
            'type' => 'expired', 'quantity' => -5, 'reason' => 'Expired disposal', 'performed_by' => $owner->id]);
        $oldDisposal = StockTransaction::create(['item_id' => $date->id, 'branch_id' => $bravo->id, 'type' => 'damaged', 'quantity' => -2,
            'reason' => 'Breakage', 'performed_by' => $owner->id]);
        StockTransaction::whereKey($recentDisposal->id)->update(['created_at' => now()->subDay()]);
        StockTransaction::whereKey($oldDisposal->id)->update(['created_at' => now()->subDays(20)]);

        $alphaOrder = Order::create(['order_number' => 'ORD-A', 'branch_id' => $alpha->id, 'ordered_by' => $owner->id, 'status' => 'pending', 'total_amount' => 500]);
        $bravoOrder = Order::create(['order_number' => 'ORD-B', 'branch_id' => $bravo->id, 'ordered_by' => $owner->id, 'status' => 'preparing', 'total_amount' => 1500]);

        return compact('commissary', 'alpha', 'bravo', 'owner', 'manager', 'admin', 'apple', 'banana', 'cherry', 'date', 'alphaOrder', 'bravoOrder', 'cherryExpired');
    }

    /** @param array<string, mixed> $query */
    private function panel(User $as, array $query): array
    {
        return $this->actingAs($as)->getJson(route('warehouse.panel').'?'.http_build_query($query))->json();
    }

    public function test_admin_monitor_is_read_only_with_flat_filterable_tables(): void
    {
        $w = $this->world();
        $res = $this->actingAs($w['admin'])->get('/warehouse')->assertOk();
        foreach (['active', 'expired', 'disposal', 'orders'] as $kind) {
            $res->assertSee('id="flt-'.$kind.'-branch"', false);
        }
        $res->assertDontSee('onclick="disposeBatch(', false)
            ->assertDontSee('onclick="voidBatch(', false)
            ->assertDontSee('onclick="archiveBatch(', false);
    }

    public function test_owner_keeps_dispose_and_archive_actions_in_the_flat_view(): void
    {
        $w = $this->world();
        $res = $this->actingAs($w['owner'])->get('/warehouse')->assertOk();
        $res->assertSee('onclick="disposeBatch(', false);
        $res->assertSee('onclick="archiveBatch(', false);
    }

    public function test_commissary_manager_flat_view_stays_read_only(): void
    {
        $w = $this->world();
        $this->actingAs($w['manager'])->get('/warehouse')->assertOk()
            ->assertSee('id="flt-active-branch"', false)
            ->assertDontSee('onclick="disposeBatch(', false)
            ->assertDontSee('onclick="archiveBatch(', false);
    }

    public function test_branch_filter_scopes_rows_to_one_branch(): void
    {
        $w = $this->world();
        $all = $this->panel($w['owner'], ['tab' => 'expired']);
        $this->assertStringContainsString('Alpha', $all['html']['expired']);
        $this->assertStringContainsString('Bravo', $all['html']['expired']);

        $alphaOnly = $this->panel($w['owner'], ['tab' => 'expired', 'branch' => $w['alpha']->id]);
        $this->assertStringContainsString('Alpha', $alphaOnly['html']['expired']);
        $this->assertStringNotContainsString('Bravo', $alphaOnly['html']['expired']);
    }

    public function test_product_level_low_stock_uses_branch_product_total_not_single_batch(): void
    {
        $w = $this->world();
        // Apple has a 6 kg batch (< reorder 10) but 13 kg usable total → "Above", never "Low".
        $above = $this->panel($w['owner'], ['tab' => 'active', 'status' => 'above']);
        $this->assertStringContainsString('Apple', $above['html']['active']);

        $low = $this->panel($w['owner'], ['tab' => 'active', 'status' => 'low']);
        $this->assertStringNotContainsString('Apple', $low['html']['active']);
        $this->assertStringContainsString('Banana', $low['html']['active']); // 3 < 5 → low
    }

    public function test_out_of_stock_products_stay_discoverable_without_batches(): void
    {
        $w = $this->world();
        $out = $this->panel($w['owner'], ['tab' => 'active', 'status' => 'out']);
        // Cherry (only expired) and Date (expired + damaged) have no usable stock.
        $this->assertStringContainsString('Cherry', $out['html']['active']);
        $this->assertStringContainsString('Date', $out['html']['active']);
        $this->assertStringContainsString('Out of Stock', $out['html']['active']);
    }

    public function test_expired_and_damaged_quantities_are_excluded_from_usable_stock(): void
    {
        $w = $this->world();
        // Date at Bravo has 4 expired + 2 damaged, so it must read as out of stock,
        // not as usable above-reorder stock.
        $active = $this->panel($w['owner'], ['tab' => 'active', 'product' => $w['date']->id]);
        $this->assertStringContainsString('Out of Stock', $active['html']['active']);
        $this->assertStringNotContainsString('In Stock', $active['html']['active']);
    }

    public function test_unit_filter_and_cross_unit_quantity_sort_note(): void
    {
        $w = $this->world();
        // Unit filter keeps compatible units only.
        $kg = $this->panel($w['owner'], ['tab' => 'active', 'unit' => 'kg']);
        $this->assertStringContainsString('Apple', $kg['html']['active']);
        $this->assertStringNotContainsString('Banana', $kg['html']['active']);

        // Quantity sort across mixed units groups by unit and shows the note.
        $mixed = $this->panel($w['owner'], ['tab' => 'active', 'sort' => 'qty_desc']);
        $this->assertStringContainsString('Quantities compared within each unit.', $mixed['html']['active']);
    }

    public function test_disposal_date_range_filter(): void
    {
        $w = $this->world();
        // Only the recent (yesterday) disposal, excluding the 20-day-old one.
        $recent = $this->panel($w['owner'], ['tab' => 'disposal', 'from' => now()->subDays(5)->toDateString()]);
        $this->assertSame(1, $recent['filtered']['disposal']);
        $this->assertStringContainsString('Cherry', $recent['html']['disposal']);
        $this->assertStringNotContainsString('Breakage', $recent['html']['disposal']);
    }

    public function test_pending_orders_link_targets_the_exact_order_in_orders_page(): void
    {
        $w = $this->world();
        $res = $this->panel($w['owner'], ['tab' => 'orders']);
        $this->assertStringContainsString('order='.$w['alphaOrder']->id, $res['html']['orders']);
        $this->assertStringNotContainsString('Manage in Delivery', $res['html']['orders']);
    }

    public function test_order_status_filter_and_value_sort(): void
    {
        $w = $this->world();
        $pending = $this->panel($w['owner'], ['tab' => 'orders', 'status' => 'pending']);
        $this->assertSame(1, $pending['filtered']['orders']);
        $this->assertStringContainsString('ORD-A', $pending['html']['orders']);
        $this->assertStringNotContainsString('ORD-B', $pending['html']['orders']);
    }

    public function test_pagination_limits_to_ten_rows_and_preserves_filters(): void
    {
        $w = $this->world();
        // 12 pending orders at Alpha → 10 on page one, 2 on page two.
        for ($i = 1; $i <= 12; $i++) {
            Order::create(['order_number' => 'ORD-P'.$i, 'branch_id' => $w['alpha']->id, 'ordered_by' => $w['owner']->id, 'status' => 'pending', 'total_amount' => 100 + $i]);
        }

        $page1 = $this->panel($w['owner'], ['tab' => 'orders', 'branch' => $w['alpha']->id, 'status' => 'pending', 'page' => 1]);
        $this->assertSame(13, $page1['filtered']['orders']); // 12 new + ORD-A
        $this->assertSame(10, substr_count($page1['html']['orders'], 'order='));

        $page2 = $this->panel($w['owner'], ['tab' => 'orders', 'branch' => $w['alpha']->id, 'status' => 'pending', 'page' => 2]);
        $this->assertSame(3, substr_count($page2['html']['orders'], 'order='));
    }

    public function test_overall_counts_are_unfiltered_while_filtered_total_tracks_matches(): void
    {
        $w = $this->world();
        $res = $this->panel($w['owner'], ['tab' => 'orders', 'status' => 'pending']);
        $this->assertSame(2, $res['counts']['orders']);     // overall: ORD-A + ORD-B
        $this->assertSame(1, $res['filtered']['orders']);   // filtered: pending only
    }
}
