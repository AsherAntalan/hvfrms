<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderProduction;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use App\Services\OrderWorkflowService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/** Review -> prepare/pick -> serial scan-out -> destination receipt. */
class CommissaryWorkflowTest extends TestCase
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
        return User::create(['full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('x'), 'role_id' => $r->id, 'branch_id' => $b->id, 'status' => 'active']);
    }

    /** world + a pending order for Silang (one line, qty 5). */
    private function scenario(int $qty = 5): array
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $owner = $this->user($this->role('owner'), $main, 'o@t.com');
        $mbm = $this->user($this->role('commissary_manager'), $main, 'mbm@t.com');
        $mbs = $this->user($this->role('commissary_staff'), $main, 'mbs@t.com');
        $bm = $this->user($this->role('branch_manager'), $silang, 'bm@t.com');
        $item = Item::create(['item_name' => 'Pho Broth', 'unit' => 'pack', 'price' => 10, 'reorder_level' => 5, 'is_active' => 1]);
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'ORD-C-1', 'branch_id' => $silang->id, 'status' => 'pending', 'ordered_by' => $bm->id, 'total_amount' => $qty * 10, 'created_at' => now(), 'updated_at' => now()]);
        $oiId = DB::table('order_items')->insertGetId(['order_id' => $orderId, 'item_id' => $item->id, 'quantity' => $qty, 'unit_price' => 10]);

        return compact('main', 'silang', 'owner', 'mbm', 'mbs', 'bm', 'item', 'orderId', 'oiId');
    }

    private function callApi(User $u, array $d)
    {
        return $this->actingAs($u)->postJson('/orders/api', $d);
    }

    private function approve(array $s, User $u, ?string $expiry = null)
    {
        return $this->callApi($u, ['action' => 'approve_prepare', 'id' => $s['orderId'],
            'expiry' => json_encode([$s['oiId'] => $expiry ?? now()->addMonths(2)->toDateString()])]);
    }

    private function confirm(array $s, User $u)
    {
        return $this->callApi($u, ['action' => 'branch_confirm', 'id' => $s['orderId']]);
    }

    public function test_approval_role_gated_produces_stock_and_qr_no_deduction(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['bm'])->assertStatus(403);   // requester cannot approve
        $this->approve($s, $s['mbs'])->assertStatus(403);  // staff cannot approve
        $this->approve($s, $s['mbm'])->assertOk();

        $order = Order::find($s['orderId']);
        $this->assertEquals('preparing', $order->status);
        $p = OrderProduction::where('order_item_id', $s['oiId'])->first();
        $this->assertNotNull($p);
        $this->assertEquals(5, $p->produced_qty);          // produced = ordered
        $this->assertEquals(0, $p->dispatched_qty);
        $this->assertEquals(OrderProduction::qrFor($s['orderId'], $s['oiId']), $p->qr_code);
        // Approval creates NO finished-stock deduction.
        $this->assertEquals(1, StockTransaction::where('type', 'in')->count());
        $this->assertEquals(0, StockTransaction::where('type', 'out')->count());
        $this->assertEquals(5, StockBatch::where('branch_id', $s['main']->id)->sum('quantity_left'));
    }

    public function test_invalid_expiry_rejected_with_no_writes(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['mbm'], now()->subDay()->toDateString())->assertStatus(422); // past
        $this->assertEquals(0, OrderProduction::count());
        $this->assertEquals('pending', Order::find($s['orderId'])->status);

        $this->callApi($s['mbm'], ['action' => 'approve_prepare', 'id' => $s['orderId'],
            'expiry' => json_encode([$s['oiId'] => 'not-a-date'])])->assertStatus(422);
        $this->assertEquals(0, OrderProduction::count());
    }

    public function test_approval_idempotent(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['mbm'])->assertOk();
        $first = OrderProduction::where('order_item_id', $s['oiId'])->value('production_at');
        $r = $this->approve($s, $s['mbm']);           // re-approve
        $r->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(1, OrderProduction::count());
        $this->assertEquals((string) $first, (string) OrderProduction::where('order_item_id', $s['oiId'])->value('production_at'));
    }

    private function codes(array $s)
    {
        return DB::table('order_production_units')->join('order_productions as p', 'p.id', '=', 'production_id')->where('p.order_id', $s['orderId'])->pluck('order_production_units.qr_code');
    }

    private function release(array $s): void
    {
        foreach ($this->codes($s) as $code) {
            $this->callApi($s['mbs'], ['action' => 'dispatch_scan', 'qr' => $code])->assertOk();
        }
    }

    public function test_branch_confirmation_requires_physical_release_and_correct_destination(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['mbm'])->assertOk();
        $this->confirm($s, $s['bm'])->assertStatus(409);
        $other = $this->branch('Dasma');
        $otherBm = $this->user($this->role('branch_manager'), $other, 'obm@t.com');
        $this->confirm($s, $otherBm)->assertForbidden();
        $this->release($s);
        $this->confirm($s, $s['bm'])->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals('settled', Order::find($s['orderId'])->status);
    }

    public function test_dispatch_permissions_and_remaining_quantity(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['mbm'])->assertOk();
        $qr = $this->codes($s)->first();

        $this->callApi($s['bm'], ['action' => 'dispatch_scan', 'qr' => $qr, 'quantity' => 1])->assertStatus(403); // branch mgr
        $this->callApi($s['mbs'], ['action' => 'dispatch_scan', 'qr' => $qr, 'quantity' => 6])->assertStatus(422); // over
        $this->callApi($s['mbs'], ['action' => 'dispatch_scan', 'qr' => $qr, 'quantity' => 2])->assertStatus(422);
        foreach ($this->codes($s)->take(2) as $code) {
            $this->callApi($s['mbs'], ['action' => 'dispatch_scan', 'qr' => $code])->assertOk();
        }
        $this->assertEquals(2, OrderProduction::where('order_item_id', $s['oiId'])->value('dispatched_qty'));
        $this->assertFalse((new OrderWorkflowService)->isFullyDispatched(Order::find($s['orderId'])));
        foreach ($this->codes($s)->skip(2) as $code) {
            $this->callApi($s['mbs'], ['action' => 'dispatch_scan', 'qr' => $code])->assertOk();
        }
        $this->assertTrue((new OrderWorkflowService)->isFullyDispatched(Order::find($s['orderId'])));
    }

    public function test_receipt_blocked_until_dispatched_then_settles_once(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['mbm'])->assertOk();

        // No dispatch yet → receipt blocked, no writes.
        $this->callApi($s['bm'], ['action' => 'receive_scan', 'id' => $s['orderId']])->assertStatus(409);
        $this->assertEquals(0, StockBatch::where('branch_id', $s['silang']->id)->count());

        $qr = $this->codes($s)->first();
        $this->release($s);
        $this->callApi($s['bm'], ['action' => 'receive_scan', 'id' => $s['orderId']])->assertOk()->assertJson(['settled' => true]);

        $order = Order::find($s['orderId']);
        $this->assertEquals('settled', $order->status);
        $this->assertEquals(5, StockBatch::where('branch_id', $s['silang']->id)->sum('quantity_left'));
        $inCount = StockTransaction::where('branch_id', $s['silang']->id)->where('type', 'in')->count();

        $this->callApi($s['bm'], ['action' => 'receive_scan', 'id' => $s['orderId']])->assertJson(['already' => true]);
        $this->assertEquals(5, StockBatch::where('branch_id', $s['silang']->id)->sum('quantity_left'));
        $this->assertEquals($inCount, StockTransaction::where('branch_id', $s['silang']->id)->where('type', 'in')->count());
    }

    public function test_requester_cancel_with_reason(): void
    {
        $s = $this->scenario();
        $other = $this->branch('Dasma');
        $otherBm = $this->user($this->role('branch_manager'), $other, 'obm@t.com');
        $this->callApi($otherBm, ['action' => 'cancel', 'id' => $s['orderId'], 'reason' => 'x'])->assertStatus(403);
        $this->callApi($s['bm'], ['action' => 'cancel', 'id' => $s['orderId'], 'reason' => '  '])->assertStatus(422);
        $this->callApi($s['bm'], ['action' => 'cancel', 'id' => $s['orderId'], 'reason' => 'Wrong product'])->assertOk();
        $o = Order::find($s['orderId']);
        $this->assertEquals('cancelled', $o->status);
        $this->assertEquals('Wrong product', $o->cancel_reason);
    }

    public function test_cancel_blocked_after_dispatch(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['mbm'])->assertOk();
        $qr = $this->codes($s)->first();
        $this->callApi($s['mbs'], ['action' => 'dispatch_scan', 'qr' => $qr, 'quantity' => 1])->assertOk();

        $this->callApi($s['bm'], ['action' => 'cancel', 'id' => $s['orderId'], 'reason' => 'too late'])->assertStatus(422);
        $this->assertEquals('delivering', Order::find($s['orderId'])->status);
    }

    public function test_manual_deliver_role_and_reason_gated_then_dispatches_without_scan(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['mbm'])->assertOk();

        // Branch manager and Main Branch staff cannot use the privileged fallback.
        $this->callApi($s['bm'], ['action' => 'manual_deliver', 'id' => $s['orderId'], 'reason' => 'x'])->assertStatus(403);
        $this->callApi($s['mbs'], ['action' => 'manual_deliver', 'id' => $s['orderId'], 'reason' => 'x'])->assertStatus(403);
        // Reason is required.
        $this->callApi($s['mbm'], ['action' => 'manual_deliver', 'id' => $s['orderId'], 'reason' => '  '])->assertStatus(422);

        // Owner/Main Branch Manager deliver manually: dispatch recorded, not receipt.
        $this->callApi($s['mbm'], ['action' => 'manual_deliver', 'id' => $s['orderId'], 'reason' => 'scanner camera failure'])->assertOk();
        $order = Order::find($s['orderId']);
        $this->assertEquals('delivering', $order->status);              // still awaiting receipt
        $this->assertNull($order->delivered_at);                        // never marked received
        $p = OrderProduction::where('order_item_id', $s['oiId'])->first();
        $this->assertEquals($p->produced_qty, $p->dispatched_qty);      // fully dispatched
        $this->assertTrue((new OrderWorkflowService)->isFullyDispatched($order));

        // Idempotent.
        $this->callApi($s['mbm'], ['action' => 'manual_deliver', 'id' => $s['orderId'], 'reason' => 'again'])
            ->assertOk()->assertJson(['already' => true]);

        // Destination can now receive and settle.
        $this->callApi($s['bm'], ['action' => 'receive_scan', 'id' => $s['orderId']])->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals('settled', Order::find($s['orderId'])->status);
    }

    public function test_manual_release_blocked_before_preparation(): void
    {
        $s = $this->scenario();
        $this->callApi($s['mbm'], ['action' => 'manual_deliver', 'id' => $s['orderId'], 'reason' => 'early'])->assertStatus(422);
        $this->assertEquals('pending', Order::find($s['orderId'])->status);
        $this->assertEquals(0, StockTransaction::count());
    }

    public function test_labels_endpoint_role_gated(): void
    {
        $s = $this->scenario();
        $this->approve($s, $s['mbm'])->assertOk();

        $this->actingAs($s['mbm'])->get('/orders/'.$s['orderId'].'/labels')
            ->assertOk()->assertSee(OrderProduction::qrFor($s['orderId'], $s['oiId']))->assertSee('Pho Broth');
        $this->actingAs($s['mbs'])->get('/orders/'.$s['orderId'].'/labels')->assertStatus(403);
        $this->actingAs($s['bm'])->get('/orders/'.$s['orderId'].'/labels')->assertStatus(403);
    }
}
