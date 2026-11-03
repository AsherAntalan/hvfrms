<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderProduction;
use App\Models\ReceivingIssue;
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

    public function test_production_records_show_ten_rows_per_page(): void
    {
        $scenario = $this->scenario();
        for ($number = 1; $number <= 11; $number++) {
            $orderId = DB::table('orders')->insertGetId([
                'order_number' => 'PAGED-RECORD-'.$number,
                'branch_id' => $scenario['silang']->id,
                'status' => 'preparing',
                'ordered_by' => $scenario['bm']->id,
                'total_amount' => 10,
            ]);
            $lineId = DB::table('order_items')->insertGetId([
                'order_id' => $orderId, 'item_id' => $scenario['item']->id,
                'quantity' => 1, 'unit_price' => 10,
            ]);
            DB::table('order_productions')->insert([
                'order_id' => $orderId, 'order_item_id' => $lineId,
                'item_id' => $scenario['item']->id,
                'destination_branch_id' => $scenario['silang']->id,
                'produced_qty' => 1, 'qr_code' => 'PAGED-QR-'.$number,
            ]);
        }

        $firstPage = $this->actingAs($scenario['owner'])->get('/inventory')->assertOk();
        $firstPage->assertSee('11 records')->assertSee('PAGED-RECORD-11')
            ->assertSee('PAGED-RECORD-2</a>', false)
            ->assertDontSee('PAGED-RECORD-1</a>', false)
            ->assertSee('production_page=2');
        $this->get('/inventory?production_page=2')->assertOk()
            ->assertSee('11 records')->assertSee('PAGED-RECORD-1</a>', false)
            ->assertDontSee('PAGED-RECORD-2</a>', false)
            ->assertSee('collapse show', false);
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

    /** @return array<string, mixed> */
    private function receivingReport(array $scenario, int $quantity, ?string $reason = null): array
    {
        return [
            'action' => 'report_receiving_issue', 'id' => $scenario['orderId'],
            'reason' => 'Receiving request failed after branch verified the goods.',
            'branch_confirmed' => true,
            'receipt' => [['order_item_id' => $scenario['oiId'], 'received_quantity' => $quantity, 'reason' => $reason]],
        ];
    }

    private function resolutionAdmin(array $scenario): User
    {
        $admin = $scenario['owner'];
        $admin->role_id = $this->role('admin')->id;
        $admin->save();

        return $admin->unsetRelation('role');
    }

    public function test_admin_reprocesses_branch_confirmed_receipt_once_without_general_receiving_rights(): void
    {
        $scenario = $this->scenario();
        $this->approve($scenario, $scenario['mbm'])->assertOk();
        $this->release($scenario);
        $admin = $this->resolutionAdmin($scenario);
        $report = $this->callApi($scenario['bm'], $this->receivingReport($scenario, 5))->assertOk();
        $this->assertEquals(0, StockBatch::where('branch_id', $scenario['silang']->id)->sum('quantity_left'));
        $this->callApi($admin, ['action' => 'view', 'id' => $scenario['orderId']])->assertOk()
            ->assertJsonPath('order.actions.can_receive', false)
            ->assertJsonPath('order.actions.can_resolve_receiving_issue', true);
        $payload = ['action' => 'resolve_receiving_issue', 'issue_id' => $report->json('issue_id'),
            'reason' => 'Reviewed branch confirmation and receiving timeout evidence.', 'review_confirmed' => true];
        $payload['receipt'] = [['order_item_id' => $scenario['oiId'], 'received_quantity' => 500]];
        $this->callApi($admin, $payload)->assertOk();
        $this->assertEquals(5, StockBatch::where('branch_id', $scenario['silang']->id)->sum('quantity_left'));
        $this->assertSame('settled', Order::findOrFail($scenario['orderId'])->status);
        $this->callApi($admin, $payload)->assertOk()->assertJsonPath('already', true);
        $this->assertEquals(5, StockBatch::where('branch_id', $scenario['silang']->id)->sum('quantity_left'));
        $issue = ReceivingIssue::findOrFail($report->json('issue_id'));
        $this->assertSame($admin->id, $issue->resolved_by);
        $this->assertSame($scenario['bm']->id, $issue->reported_by);
        $this->assertDatabaseHas('audit_log', ['action' => 'receiving_issue_resolved', 'user_id' => $admin->id, 'record_id' => $issue->id]);
        $this->assertSame(1, AuditLog::where('action', 'receiving_issue_resolved')->count());
    }

    public function test_receiving_assistance_requires_real_branch_authorization_confirmation_and_bounded_quantities(): void
    {
        $scenario = $this->scenario();
        $this->approve($scenario, $scenario['mbm'])->assertOk();
        $admin = $this->resolutionAdmin($scenario);
        $payload = $this->receivingReport($scenario, 5);
        $this->callApi($scenario['bm'], $payload)->assertUnprocessable();
        $this->release($scenario);
        $this->callApi($admin, $payload)->assertForbidden();
        $this->callApi($scenario['mbs'], $payload)->assertForbidden();
        $foreign = $this->user($this->role('branch_manager'), $scenario['main'], 'other-manager@hv.test');
        $this->callApi($foreign, $payload)->assertForbidden();
        $this->callApi($scenario['bm'], array_replace($payload, ['branch_confirmed' => false]))->assertUnprocessable();
        $this->callApi($scenario['bm'], $this->receivingReport($scenario, 6))->assertUnprocessable();
        $this->callApi($scenario['bm'], $this->receivingReport($scenario, 4))->assertUnprocessable();
        $this->callApi($scenario['bm'], array_replace($payload, ['receipt' => []]))->assertUnprocessable();
        $issue = $this->callApi($scenario['bm'], $payload)->assertOk()->json('issue_id');
        $resolution = ['action' => 'resolve_receiving_issue', 'issue_id' => $issue, 'reason' => 'Verified the evidence.', 'review_confirmed' => true];
        $this->callApi($scenario['bm'], $resolution)->assertForbidden();
        $this->callApi($admin, array_replace($resolution, ['review_confirmed' => false]))->assertUnprocessable();
        $this->callApi($admin, array_replace($resolution, ['reason' => '']))->assertUnprocessable();
        $scenario['bm']->update(['status' => 'disabled']);
        $this->callApi($admin, $resolution)->assertUnprocessable();
        $this->assertEquals(0, StockBatch::where('branch_id', $scenario['silang']->id)->sum('quantity_left'));
    }

    public function test_changed_receipts_need_a_fresh_branch_confirmation_before_admin_resolution(): void
    {
        $scenario = $this->scenario();
        $this->approve($scenario, $scenario['mbm'])->assertOk();
        $this->release($scenario);
        $admin = $this->resolutionAdmin($scenario);
        $issue = $this->callApi($scenario['bm'], $this->receivingReport($scenario, 5))->assertOk()->json('issue_id');
        $qr = OrderProduction::where('order_id', $scenario['orderId'])->value('qr_code');
        $this->actingAs($scenario['bm'])->postJson('/stock/api', ['action' => 'stock_in', 'qr' => $qr, 'quantity' => 1])->assertOk();
        $resolution = ['action' => 'resolve_receiving_issue', 'issue_id' => $issue, 'reason' => 'Verified branch evidence.', 'review_confirmed' => true];
        $this->callApi($admin, $resolution)->assertUnprocessable();
        $this->assertEquals(1, StockBatch::where('branch_id', $scenario['silang']->id)->sum('quantity_left'));
        $replacement = $this->callApi($scenario['bm'], $this->receivingReport($scenario, 4))->assertOk()->json('issue_id');
        $this->callApi($admin, $resolution)->assertUnprocessable();
        $this->callApi($admin, array_replace($resolution, ['issue_id' => $replacement]))->assertOk();
        $this->assertEquals(5, StockBatch::where('branch_id', $scenario['silang']->id)->sum('quantity_left'));
    }

    public function test_admin_resolution_preserves_partial_receipts_and_records_missing_goods_as_discrepancies(): void
    {
        $scenario = $this->scenario();
        $this->approve($scenario, $scenario['mbm'])->assertOk();
        $this->release($scenario);
        $admin = $this->resolutionAdmin($scenario);
        $qr = OrderProduction::where('order_id', $scenario['orderId'])->value('qr_code');
        $this->actingAs($scenario['bm'])->postJson('/stock/api', ['action' => 'stock_in', 'qr' => $qr, 'quantity' => 2])->assertOk();
        $issue = $this->callApi($scenario['bm'], $this->receivingReport($scenario, 2, 'Missing Quantity'))->assertOk()->json('issue_id');
        $this->callApi($admin, ['action' => 'resolve_receiving_issue', 'issue_id' => $issue,
            'reason' => 'Branch verified two accepted units and one missing unit.', 'review_confirmed' => true])->assertOk();
        $this->assertEquals(4, StockBatch::where('branch_id', $scenario['silang']->id)->sum('quantity_left'));
        $this->assertSame('delivered', Order::findOrFail($scenario['orderId'])->status);
        $this->assertDatabaseHas('delivery_discrepancies', ['order_id' => $scenario['orderId'], 'expected_quantity' => 3, 'received_quantity' => 2]);
    }

    public function test_admin_can_monitor_but_cannot_receive_through_order_or_qr_flows(): void
    {
        $scenario = $this->scenario();
        $this->approve($scenario, $scenario['mbm'])->assertOk();
        $this->release($scenario);
        $admin = $scenario['owner'];
        $admin->role_id = $this->role('admin')->id;
        $admin->save();
        $admin->unsetRelation('role');
        $production = OrderProduction::where('order_id', $scenario['orderId'])->firstOrFail();
        $before = DB::table('stock_transactions')->count();
        foreach (['receive_scan', 'branch_confirm', 'update_status'] as $action) {
            $this->callApi($admin, ['action' => $action, 'status' => 'delivered', 'id' => $scenario['orderId']])->assertForbidden();
        }
        $this->callApi($admin, ['action' => 'receive_scan', 'qr' => $production->qr_code])->assertForbidden();
        $this->actingAs($admin)->postJson('/stock/api', [
            'action' => 'stock_in', 'qr' => $production->qr_code, 'quantity' => 1,
        ])->assertForbidden();
        $this->callApi($admin, ['action' => 'view', 'id' => $scenario['orderId']])
            ->assertOk()->assertJsonPath('order.actions.can_receive', false);
        $this->actingAs($admin)->get('/orders')->assertOk()->assertDontSee('Receive / Confirm');
        $this->get('/delivery')->assertOk()->assertDontSee('Receive / Confirm');
        $this->assertSame($before, DB::table('stock_transactions')->count());
        $this->assertNull($production->fresh()->received_at);
        $this->confirm($scenario, $scenario['bm'])->assertOk();
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
