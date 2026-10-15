<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\OrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Real-route preparation, release and receipt, including historical allocation compatibility. */
class DeliveryWorkflowTest extends TestCase
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
        return User::create(['full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('secret123'), 'role_id' => $r->id, 'branch_id' => $b->id, 'status' => 'active']);
    }

    private function item(): Item
    {
        return Item::create(['item_name' => 'Noodles', 'unit' => 'pack', 'price' => 10, 'reorder_level' => 5, 'is_active' => 1]);
    }

    /** Build world + a pending order for Silang. */
    private function scenario(int $orderQty = 5, int $mainStock = 100, ?string $expiry = null): array
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $owner = $this->user($this->role('owner'), $main, 'owner@test.com');
        $wa = $this->user($this->role('commissary_manager'), $main, 'wa@test.com');
        $bm = $this->user($this->role('branch_manager'), $silang, 'bm@test.com');
        $item = $this->item();
        if ($mainStock > 0) {
            StockBatch::create(['item_id' => $item->id, 'branch_id' => $main->id, 'quantity_in' => $mainStock, 'quantity_left' => $mainStock, 'expiry_date' => $expiry ?? now()->addMonths(6)->toDateString(), 'condition' => 'good', 'received_at' => now()]);
        }
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'ORD-W-1', 'branch_id' => $silang->id, 'status' => 'pending', 'ordered_by' => $bm->id, 'total_amount' => $orderQty * 10, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'item_id' => $item->id, 'quantity' => $orderQty, 'unit_price' => 10]);

        return compact('main', 'silang', 'owner', 'wa', 'bm', 'item', 'orderId');
    }

    private function callApi(User $u, array $d)
    {
        return $this->actingAs($u)->postJson('/orders/api', $d);
    }

    private function prepareAndRelease(array $s): void
    {
        $line = DB::table('order_items')->where('order_id', $s['orderId'])->first();
        $this->callApi($s['owner'], ['action' => 'prepare_order', 'id' => $s['orderId'],
            'fulfillment' => json_encode([$line->id => ['quantity' => $line->quantity, 'source' => 'picked']])])->assertOk();
        foreach (DB::table('order_production_units')->pluck('qr_code') as $code) {
            $this->callApi($s['wa'], ['action' => 'dispatch_scan', 'qr' => $code])->assertOk();
        }
    }

    public function test_prepared_release_decreases_source_once_and_exposes_receipt_qr(): void
    {
        $s = $this->scenario(5, 100);
        $this->prepareAndRelease($s);
        $res = $this->callApi($s['wa'], ['action' => 'confirm_delivery', 'id' => $s['orderId']]);
        $res->assertOk()->assertJson(['ok' => true]);

        $this->assertEquals('delivering', Order::find($s['orderId'])->status);
        $this->assertEquals(95, StockBatch::where('branch_id', $s['main']->id)->value('quantity_left'));
        $this->assertEquals(5, StockTransaction::where('type', 'out')->count());
        $this->assertDatabaseHas('audit_log', ['action' => 'individual piece scanned out', 'table_name' => 'order_production_units']);

        // Idempotent: repeat confirm does not allocate again.
        $again = $this->callApi($s['wa'], ['action' => 'confirm_delivery', 'id' => $s['orderId']]);
        $again->assertOk();
        $this->assertEquals(95, StockBatch::where('branch_id', $s['main']->id)->value('quantity_left'));

        // The shipment exposes a receiving QR.
        $view = $this->callApi($s['wa'], ['action' => 'view', 'id' => $s['orderId']]);
        $view->assertJsonPath('order.shipment_qr', OrderWorkflowService::shipmentQr(Order::find($s['orderId'])));
    }

    public function test_confirm_blocked_by_insufficient_stock(): void
    {
        $s = $this->scenario(10, 3); // request 10, only 3 eligible
        $res = $this->callApi($s['owner'], ['action' => 'confirm_delivery', 'id' => $s['orderId']]);
        $res->assertStatus(422);

        $this->assertEquals('pending', Order::find($s['orderId'])->status);
        $this->assertEquals(3, StockBatch::where('branch_id', $s['main']->id)->value('quantity_left'));
        $this->assertEquals(0, StockTransaction::count());
    }

    public function test_unauthorized_cannot_confirm(): void
    {
        $s = $this->scenario(5, 100);
        $this->callApi($s['bm'], ['action' => 'confirm_delivery', 'id' => $s['orderId']])->assertStatus(403);
        $this->assertEquals('pending', Order::find($s['orderId'])->status);
        $this->assertEquals(0, StockTransaction::count());
    }

    public function test_wrong_destination_scan_is_rejected(): void
    {
        $s = $this->scenario(5, 100);
        $this->prepareAndRelease($s);

        $other = $this->branch('Dasma');
        $otherBm = $this->user($this->role('branch_manager'), $other, 'bm2@test.com');

        $res = $this->callApi($otherBm, ['action' => 'receive_scan', 'id' => $s['orderId']]);
        $res->assertStatus(403);
        $this->assertEquals('delivering', Order::find($s['orderId'])->status);
        $this->assertEquals(0, StockBatch::where('branch_id', $other->id)->count());
    }

    public function test_full_receipt_at_correct_branch_settles_once(): void
    {
        $s = $this->scenario(5, 100);
        $this->prepareAndRelease($s);

        // Receive via the shipment QR at the destination branch.
        $qr = OrderWorkflowService::shipmentQr(Order::find($s['orderId']));
        $res = $this->callApi($s['bm'], ['action' => 'receive_scan', 'qr' => $qr]);
        $res->assertOk()->assertJson(['ok' => true, 'settled' => true]);

        $order = Order::find($s['orderId']);
        $this->assertEquals('settled', $order->status);
        $this->assertNotNull($order->delivered_at);
        $this->assertNotNull($order->settled_at);
        $this->assertDatabaseHas('audit_log', ['action' => 'order settled', 'record_id' => $s['orderId']]);

        // Destination gained exactly 5 once; warehouse not deducted again.
        $this->assertEquals(5, StockBatch::where('branch_id', $s['silang']->id)->sum('quantity_left'));
        $this->assertEquals(95, StockBatch::where('branch_id', $s['main']->id)->value('quantity_left'));
        $inCount = StockTransaction::where('branch_id', $s['silang']->id)->where('type', 'in')->count();

        // Idempotent: repeat scan is a safe no-op (no duplicate branch stock).
        $this->callApi($s['bm'], ['action' => 'receive_scan', 'qr' => $qr])->assertJson(['already' => true]);
        $this->assertEquals(5, StockBatch::where('branch_id', $s['silang']->id)->sum('quantity_left'));
        $this->assertEquals($inCount, StockTransaction::where('branch_id', $s['silang']->id)->where('type', 'in')->count());
    }

    public function test_partial_receipt_is_exception_not_settled(): void
    {
        $s = $this->scenario(5, 100);
        $this->prepareAndRelease($s);

        $oiId = DB::table('order_items')->where('order_id', $s['orderId'])->value('id');
        $res = $this->callApi($s['bm'], ['action' => 'receive_scan', 'id' => $s['orderId'],
            'received_items' => json_encode([['order_item_id' => $oiId, 'received_quantity' => 3]])]);
        $res->assertOk()->assertJson(['ok' => true, 'settled' => false]);

        $order = Order::find($s['orderId']);
        $this->assertEquals('delivered', $order->status);   // NOT settled
        $this->assertNull($order->settled_at);
        $this->assertEquals(3, StockBatch::where('branch_id', $s['silang']->id)->sum('quantity_left'));
        $this->assertDatabaseHas('delivery_discrepancies', ['order_id' => $s['orderId'], 'received_quantity' => 3]);
    }

    public function test_expired_in_transit_is_not_auto_settled(): void
    {
        Carbon::setTestNow('2026-01-01 09:00:00');
        $s = $this->scenario(5, 100, '2026-01-02'); // batch expires tomorrow (eligible now)
        $this->prepareAndRelease($s);

        // Time passes — the allocated lot expires in transit.
        Carbon::setTestNow('2026-01-05 09:00:00');
        $res = $this->callApi($s['bm'], ['action' => 'receive_scan', 'id' => $s['orderId']]);
        $res->assertOk()->assertJson(['settled' => false]);

        $order = Order::find($s['orderId']);
        $this->assertEquals('delivered', $order->status);
        $this->assertEquals(0, StockBatch::where('branch_id', $s['silang']->id)->sum('quantity_left')); // expired not stocked in
        $this->assertDatabaseHas('delivery_discrepancies', ['order_id' => $s['orderId'], 'reason' => 'Other', 'received_quantity' => 0]);
        Carbon::setTestNow();
    }

    public function test_declined_cannot_be_dispatched_or_received(): void
    {
        $s = $this->scenario(5, 100);
        $this->callApi($s['owner'], ['action' => 'decline', 'id' => $s['orderId'], 'reason' => 'Incorrect order'])->assertOk();

        $this->callApi($s['owner'], ['action' => 'confirm_delivery', 'id' => $s['orderId']])->assertStatus(409);
        $this->callApi($s['bm'], ['action' => 'receive_scan', 'id' => $s['orderId']])->assertStatus(409);
        $this->assertEquals('declined', Order::find($s['orderId'])->status);
        $this->assertEquals(0, StockTransaction::count());
    }

    public function test_legacy_approved_order_confirms_without_second_deduction(): void
    {
        $s = $this->scenario(5, 100);
        $order = Order::find($s['orderId']);
        DB::transaction(function () use ($s, $order) {
            $allocation = (new OrderWorkflowService)->allocate($order, $s['main']->id, $s['owner']->id, 'Silang');
            $this->assertTrue($allocation['ok']);
            $order->update(['status' => 'approved']);
        });
        $this->callApi($s['owner'], ['action' => 'confirm_delivery', 'id' => $s['orderId']])->assertOk();
        $this->assertEquals('delivering', $order->fresh()->status);
        $this->assertEquals(95, StockBatch::where('branch_id', $s['main']->id)->sum('quantity_left'));
        $this->assertEquals(1, StockTransaction::where('type', 'out')->count());
        $this->callApi($s['bm'], ['action' => 'receive_scan', 'id' => $s['orderId']])->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals(5, StockBatch::where('branch_id', $s['silang']->id)->sum('quantity_left'));
    }
}
