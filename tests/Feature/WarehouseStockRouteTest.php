<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * End-to-end tests that drive the REAL authenticated stock routes
 * (POST /stock/api) through the full middleware → controller → service → DB
 * stack, not the service in isolation. They prove the scanned batch is enforced
 * as the source of the movement and that expired/damaged goods are blocked.
 */
class WarehouseStockRouteTest extends TestCase
{
    use RefreshDatabase;

    // Standard RefreshDatabase runs migrate:fresh; the MySQL-only DDL in the
    // migrations is already guarded by DB::getDriverName()==='mysql', so it is
    // skipped cleanly on SQLite and the full schema + real routes are available.

    // ---------------- fixtures ----------------

    private function warehouseUserAtMain(): array
    {
        Role::firstOrCreate(['role_name' => 'commissary_manager'], ['description' => 'wh']);
        $role = Role::where('role_name', 'commissary_manager')->first();
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => 1]);
        $user = User::create([
            'full_name' => 'WH Admin', 'email' => 'wh@test.com',
            'password_hash' => Hash::make('secret123'),
            'role_id' => $role->id, 'branch_id' => $main->id, 'status' => 'active',
        ]);

        return [$user, $main];
    }

    private function item(string $name = 'Rice Noodles'): Item
    {
        return Item::create(['item_name' => $name, 'unit' => 'pack', 'reorder_level' => 5, 'is_active' => 1]);
    }

    private function batch(int $itemId, int $branchId, int $qty, ?string $expiry, string $condition = 'good'): StockBatch
    {
        return StockBatch::create([
            'item_id' => $itemId, 'branch_id' => $branchId,
            'quantity_in' => $qty, 'quantity_left' => $qty,
            'expiry_date' => $expiry, 'condition' => $condition, 'received_at' => now(),
        ]);
    }

    private function postStock(User $user, array $data)
    {
        return $this->actingAs($user)->postJson('/stock/api', $data);
    }

    // ---------------- tests ----------------

    /** Healthy batch with 3 packs: 1–3 allowed, 4 rejected, exact-batch deduction, 1 left after releasing 2. */
    public function test_scanned_batch_bounds_the_stock_out_quantity(): void
    {
        [$user, $main] = $this->warehouseUserAtMain();
        $item = $this->item();
        $scanned = $this->batch($item->id, $main->id, 3, now()->addMonths(1)->toDateString()); // earliest expiry (FEFO-correct)
        $other = $this->batch($item->id, $main->id, 10, now()->addMonths(3)->toDateString()); // newer, bigger — must stay untouched

        // Qty 4 from a 3-pack batch is rejected even though location stock is 13.
        $over = $this->postStock($user, [
            'action' => 'stock_out', 'item_id' => $item->id, 'batch_id' => $scanned->id,
            'quantity' => 4, 'reason' => 'Sold / Used',
        ]);
        $over->assertStatus(422);
        $over->assertJson(['ok' => false]);
        $this->assertEquals(3, $scanned->fresh()->quantity_left);
        $this->assertEquals(10, $other->fresh()->quantity_left);
        $this->assertEquals(0, StockTransaction::where('item_id', $item->id)->count());

        // Release 2 from the scanned batch → only that batch is touched.
        $ok = $this->postStock($user, [
            'action' => 'stock_out', 'item_id' => $item->id, 'batch_id' => $scanned->id,
            'quantity' => 2, 'reason' => 'Sold / Used',
        ]);
        $ok->assertOk();
        $ok->assertJson(['ok' => true]);
        $this->assertEquals(1, $scanned->fresh()->quantity_left, 'Only 1 should remain in the scanned batch');
        $this->assertEquals(10, $other->fresh()->quantity_left, 'Other batch must never be silently used');

        // Now only 1 remains available in that batch; releasing 2 fails.
        $tooMuch = $this->postStock($user, [
            'action' => 'stock_out', 'item_id' => $item->id, 'batch_id' => $scanned->id,
            'quantity' => 2, 'reason' => 'Sold / Used',
        ]);
        $tooMuch->assertStatus(422);
        $this->assertEquals(1, $scanned->fresh()->quantity_left);
    }

    /** Expired scanned batch: normal stock out blocked with reason, no movement. */
    public function test_expired_batch_normal_stock_out_is_blocked(): void
    {
        [$user, $main] = $this->warehouseUserAtMain();
        $item = $this->item('Fish Sauce');
        $expired = $this->batch($item->id, $main->id, 20, now()->subDays(3)->toDateString());

        $res = $this->postStock($user, [
            'action' => 'stock_out', 'item_id' => $item->id, 'batch_id' => $expired->id,
            'quantity' => 5, 'reason' => 'Sold / Used',
        ]);

        $res->assertStatus(422);
        $res->assertJsonFragment(['ok' => false]);
        $this->assertStringContainsString('expired', strtolower($res->json('error')));
        $this->assertEquals(20, $expired->fresh()->quantity_left);
        $this->assertEquals(0, StockTransaction::where('item_id', $item->id)->count());
    }

    /** Even a tampered (non-expired-looking) request can't issue an expired batch — server re-validates authoritative expiry. */
    public function test_expired_batch_disposal_is_allowed(): void
    {
        [$user, $main] = $this->warehouseUserAtMain();
        $item = $this->item('Soy Sauce');
        $expired = $this->batch($item->id, $main->id, 8, now()->subDay()->toDateString());

        // Disposal goes through the adjustment action (as the stock screen maps
        // expired/damaged/spoiled/lost reasons), which is allowed on expired stock.
        $res = $this->postStock($user, [
            'action' => 'adjustment', 'item_id' => $item->id, 'batch_id' => $expired->id,
            'quantity' => 8, 'reason' => 'Expired',
        ]);

        $res->assertOk();
        $this->assertEquals(0, $expired->fresh()->quantity_left);
        $this->assertEquals('expired', StockTransaction::where('item_id', $item->id)->value('type'));
    }

    /** Damaged scanned batch: normal stock out blocked, no movement. */
    public function test_damaged_batch_normal_stock_out_is_blocked(): void
    {
        [$user, $main] = $this->warehouseUserAtMain();
        $item = $this->item('Chili Paste');
        $damaged = $this->batch($item->id, $main->id, 15, now()->addMonths(2)->toDateString(), 'damaged');

        $res = $this->postStock($user, [
            'action' => 'stock_out', 'item_id' => $item->id, 'batch_id' => $damaged->id,
            'quantity' => 3, 'reason' => 'Sold / Used',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('damaged', strtolower($res->json('error')));
        $this->assertEquals(15, $damaged->fresh()->quantity_left);
        $this->assertEquals(0, StockTransaction::where('item_id', $item->id)->count());
    }

    /** Damaged receiving inspection: stock in is rejected, no batch/balance change. */
    public function test_damaged_stock_in_is_rejected(): void
    {
        [$user, $main] = $this->warehouseUserAtMain();
        $item = $this->item('Peanut Oil');

        $res = $this->postStock($user, [
            'action' => 'stock_in', 'item_id' => $item->id,
            'quantity' => 10, 'condition' => 'damaged',
            'expiry_date' => now()->addMonths(6)->toDateString(),
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('damaged', strtolower($res->json('error')));
        $this->assertEquals(0, StockBatch::where('item_id', $item->id)->count());
        $this->assertEquals(0, StockTransaction::where('item_id', $item->id)->count());
    }

    /** Product-only stock out (no batch_id) still works via FEFO. */
    public function test_product_only_stock_out_preserved(): void
    {
        [$user, $main] = $this->warehouseUserAtMain();
        $item = $this->item('Spring Roll Wrapper');
        $a = $this->batch($item->id, $main->id, 5, now()->addMonths(1)->toDateString());
        $b = $this->batch($item->id, $main->id, 5, now()->addMonths(4)->toDateString());

        $res = $this->postStock($user, [
            'action' => 'stock_out', 'item_id' => $item->id,   // no batch_id
            'quantity' => 6, 'reason' => 'Sold / Used',
        ]);

        $res->assertOk();
        $this->assertEquals(0, $a->fresh()->quantity_left, 'FEFO consumes earliest-expiring first');
        $this->assertEquals(4, $b->fresh()->quantity_left);
    }

    /** Through the real route, a warehouse user's tampered branch_id is ignored. */
    public function test_warehouse_movement_pinned_to_main_via_route(): void
    {
        [$user, $main] = $this->warehouseUserAtMain();
        $other = Branch::create(['branch_name' => 'Franchise B', 'branch_type' => 'franchise', 'is_active' => 1]);
        $item = $this->item('Hoisin');

        $res = $this->postStock($user, [
            'action' => 'stock_in', 'item_id' => $item->id,
            'branch_id' => $other->id,                 // tampered — must be ignored
            'quantity' => 9, 'expiry_date' => now()->addMonths(3)->toDateString(),
        ]);

        $res->assertOk();
        $this->assertEquals(1, StockBatch::where('item_id', $item->id)->where('branch_id', $main->id)->count());
        $this->assertEquals(0, StockBatch::where('item_id', $item->id)->where('branch_id', $other->id)->count());
    }

    /** A batch id belonging to another product is rejected. */
    public function test_batch_product_mismatch_is_rejected(): void
    {
        [$user, $main] = $this->warehouseUserAtMain();
        $itemA = $this->item('Item A');
        $itemB = $this->item('Item B');
        $batchB = $this->batch($itemB->id, $main->id, 10, now()->addMonths(2)->toDateString());

        $res = $this->postStock($user, [
            'action' => 'stock_out', 'item_id' => $itemA->id, 'batch_id' => $batchB->id,
            'quantity' => 1, 'reason' => 'Sold / Used',
        ]);

        $res->assertStatus(422);
        $this->assertStringContainsString('does not match', strtolower($res->json('error')));
        $this->assertEquals(10, $batchB->fresh()->quantity_left);
    }
}
