<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Authorized review works independently of finished stock and never deducts it. */
class DeliveryApproveTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $n): Role
    {
        return Role::firstOrCreate(['role_name' => $n], ['description' => $n]);
    }

    private function branch(string $n, string $t = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $n, 'branch_type' => $t, 'is_active' => 1]);
    }

    private function user(Role $r, Branch $b, string $e): User
    {
        return User::create([
            'full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('secret123'),
            'role_id' => $r->id, 'branch_id' => $b->id, 'status' => 'active',
        ]);
    }

    private function item(): Item
    {
        return Item::create(['item_name' => 'Rice Noodles', 'unit' => 'pack', 'reorder_level' => 5, 'is_active' => 1]);
    }

    /** @return array{0:User,1:Branch,2:Branch,3:Item,4:Order} */
    private function scenario(int $orderQty = 5, int $mainStock = 100): array
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $owner = $this->user($this->role('owner'), $main, 'owner@test.com');
        $bm = $this->user($this->role('branch_manager'), $silang, 'bm@test.com');
        $item = $this->item();
        if ($mainStock > 0) {
            StockBatch::create([
                'item_id' => $item->id, 'branch_id' => $main->id, 'quantity_in' => $mainStock,
                'quantity_left' => $mainStock, 'expiry_date' => now()->addMonths(6)->toDateString(),
                'condition' => 'good', 'received_at' => now(),
            ]);
        }
        $orderId = DB::table('orders')->insertGetId([
            'order_number' => 'ORD-TEST-0008', 'branch_id' => $silang->id, 'status' => 'pending',
            'ordered_by' => $bm->id, 'total_amount' => 180, 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('order_items')->insert([
            'order_id' => $orderId, 'item_id' => $item->id, 'quantity' => $orderQty, 'unit_price' => 36,
        ]);

        return [$owner, $main, $silang, $item, Order::find($orderId), $bm];
    }

    // 0. The dead-button regression: the page now emits the handler.
    public function test_delivery_page_emits_approve_handler(): void
    {
        [$owner] = $this->scenario();
        $res = $this->actingAs($owner)->get('/delivery');
        $res->assertOk();
        $res->assertSee('Approve');
        // The onclick wiring and the handler definition must both be present
        // (they were previously swallowed by @section and never rendered).
        $res->assertSee('openApproveModal(', false);
        $res->assertSee('async function openApproveModal', false);
        $res->assertSee('async function submitApprove', false);
    }

    // 1. Authorized approval → reviewed status + audit, no stock movement, once.
    public function test_owner_can_approve_pending_order(): void
    {
        [$owner, $main, , $item, $order] = $this->scenario(5, 100);

        $res = $this->actingAs($owner)->postJson('/orders/api', [
            'action' => 'update_status', 'id' => $order->id, 'status' => 'approved',
        ]);
        $res->assertOk();
        $res->assertJson(['ok' => true]);

        $this->assertEquals('approved', $order->fresh()->status);
        $this->assertEquals(100, StockBatch::where('branch_id', $main->id)->where('item_id', $item->id)->value('quantity_left'));
        $this->assertEquals(0, StockTransaction::where('reason', 'Order ORD-TEST-0008 approved')->where('type', 'out')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'commissary request reviewed', 'record_id' => $order->id]);
    }

    // 2. Unauthorized role rejected server-side, no state change.
    public function test_branch_manager_cannot_approve(): void
    {
        [, $main, , $item, $order, $bm] = $this->scenario(5, 100);

        $res = $this->actingAs($bm)->postJson('/orders/api', [
            'action' => 'update_status', 'id' => $order->id, 'status' => 'approved',
        ]);
        $res->assertStatus(403);

        $this->assertEquals('pending', $order->fresh()->status);
        $this->assertEquals(100, StockBatch::where('branch_id', $main->id)->value('quantity_left'));
        $this->assertEquals(0, StockTransaction::count());
    }

    // 3 & 4. Repeated approval is safe — the order transitions once and stock
    //        is deducted only once.
    public function test_repeated_approval_does_not_double_deduct(): void
    {
        [$owner, $main, , , $order] = $this->scenario(5, 100);

        $first = $this->actingAs($owner)->postJson('/orders/api', ['action' => 'update_status', 'id' => $order->id, 'status' => 'approved']);
        $first->assertOk();

        $second = $this->actingAs($owner)->postJson('/orders/api', ['action' => 'update_status', 'id' => $order->id, 'status' => 'approved']);
        $second->assertOk()->assertJson(['already' => true]);

        $this->assertEquals(100, StockBatch::where('branch_id', $main->id)->value('quantity_left'), 'Review must never deduct finished stock');
        $this->assertEquals(0, StockTransaction::where('reason', 'Order ORD-TEST-0008 approved')->count());
    }

    // 5. Review is allowed before finished products exist.
    public function test_review_is_independent_of_finished_stock(): void
    {
        [$owner, $main, , , $order] = $this->scenario(999, 100);

        $res = $this->actingAs($owner)->postJson('/orders/api', [
            'action' => 'update_status', 'id' => $order->id, 'status' => 'approved',
        ]);
        $res->assertOk();

        $this->assertEquals('approved', $order->fresh()->status);
        $this->assertEquals(100, StockBatch::where('branch_id', $main->id)->value('quantity_left'));
        $this->assertEquals(0, StockTransaction::count());
    }
}
