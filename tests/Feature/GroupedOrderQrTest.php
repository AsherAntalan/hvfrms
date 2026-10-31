<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\DeliveryDiscrepancy;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class GroupedOrderQrTest extends TestCase
{
    use RefreshDatabase;

    public function test_received_shared_qr_consumes_only_destination_stock_and_retains_delivery_history(): void
    {
        $w = $this->world();
        $w['branch']->update(['branch_name' => 'Baguio']);
        $qr = $this->prepare($w);
        $this->release($w, $qr)->assertOk();
        $this->receive($w, $qr)->assertOk();
        $this->actingAs($w['users']['branch_manager'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])
            ->assertOk()->assertJsonPath('production_group.can_use', true)->assertJsonPath('production_group.usage_quantity', 10);
        $payload = ['action' => 'stock_out', 'qr' => $qr, 'movement' => 'usage', 'quantity' => 3, 'reason' => 'Consumed', 'idempotency_key' => 'use-1'];
        $this->postJson('/stock/api', $payload)->assertOk()->assertJson(['used_quantity' => 3, 'remaining_usable_quantity' => 7]);
        $this->postJson('/stock/api', $payload)->assertOk()->assertJson(['already' => true]);
        foreach ([8, 1.5, 0, -1, 'bad'] as $quantity) {
            $this->postJson('/stock/api', array_replace($payload, ['quantity' => $quantity, 'idempotency_key' => 'use-invalid']))->assertStatus(422);
        }
        $this->postJson('/stock/api', array_replace($payload, ['quantity' => 7, 'idempotency_key' => 'use-2']))
            ->assertOk()->assertJsonPath('remaining_usable_quantity', 0);
        $this->postJson('/stock/api', array_replace($payload, ['quantity' => 7, 'idempotency_key' => 'use-2']))->assertOk()->assertJson(['already' => true]);
        $this->postJson('/stock/api', array_replace($payload, ['quantity' => 1, 'idempotency_key' => 'use-3']))->assertStatus(422);
        $this->assertEquals(0, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(-10, StockTransaction::where('branch_id', $w['branch']->id)->where('type', 'out')->sum('quantity'));
        $this->assertEquals(10, $w['line']->fresh()->received_quantity);
        $this->assertEquals(10, OrderProduction::first()->dispatched_qty);
        $this->assertSame('settled', $w['order']->fresh()->status);
        $this->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()->assertJsonPath('production_group.can_use', false);
        $this->receive($w, $qr, null, 'receipt-retry-after-usage')->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(0, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        if ($folder = getenv('BRANCH_USAGE_BROWSER_ARTIFACTS')) {
            if (! is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            file_put_contents($folder.'/stock.html', $this->get('/stock')->getContent());
            file_put_contents($folder.'/qr.html', $this->get('/qr')->getContent());
        }
    }

    public function test_branch_staff_can_use_received_shared_qr_through_qr_api_but_cannot_dispatch_it(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->release($w, $qr)->assertOk();
        $this->receive($w, $qr)->assertOk();
        $this->actingAs($w['users']['branch_staff'])->postJson('/qr/api', ['action' => 'stock_out', 'qr' => $qr, 'quantity' => 2, 'reason' => 'Consumed'])
            ->assertOk()->assertJson(['used_quantity' => 2, 'remaining_usable_quantity' => 8]);
        $this->postJson('/qr/api', ['action' => 'stock_out', 'qr' => $qr, 'movement' => 'delivery'])->assertForbidden();
        $this->postJson('/qr/api', ['action' => 'stock_in', 'qr' => $qr])->assertOk()->assertJson(['already' => true]);
        $w['users']['branch_staff']->update(['branch_id' => $w['main']->id]);
        $this->actingAs($w['users']['branch_staff']->fresh())->postJson('/qr/api', ['action' => 'stock_out', 'qr' => $qr, 'quantity' => 2, 'reason' => 'Consumed'])->assertForbidden();
        $this->assertEquals(8, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
    }

    public function test_group_usage_excludes_unreceived_expired_damaged_and_unrelated_stock(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->actingAs($w['users']['branch_manager'])->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $qr, 'quantity' => 1, 'reason' => 'Consumed'])->assertStatus(422);
        $this->release($w, $qr)->assertOk();
        $this->receive($w, $qr, 3)->assertOk();
        $this->receive($w, $qr, 3, 'second-receipt')->assertOk();
        $this->receive($w, $qr, 4, 'third-receipt')->assertOk();
        $batches = StockBatch::where('branch_id', $w['branch']->id)->orderBy('id')->get();
        $batches[0]->update(['expiry_date' => now()->subDay()]);
        $batches[1]->update(['condition' => 'damaged']);
        $other = StockBatch::create(['item_id' => $w['item']->id, 'branch_id' => $w['branch']->id, 'quantity_in' => 100, 'quantity_left' => 100,
            'expiry_date' => now()->addDays(3), 'condition' => 'good', 'received_at' => now(), 'created_by' => $w['users']['owner']->id, 'qr_code' => 'UNRELATED']);
        $this->actingAs($w['users']['branch_manager'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()->assertJsonPath('production_group.usage_quantity', 4);
        $this->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $qr, 'quantity' => 5, 'reason' => 'Consumed'])->assertStatus(422);
        $this->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $qr, 'quantity' => 4, 'reason' => 'Consumed'])->assertOk();
        $this->assertEquals(3, $batches[0]->fresh()->quantity_left);
        $this->assertEquals(3, $batches[1]->fresh()->quantity_left);
        $this->assertEquals(0, $batches[2]->fresh()->quantity_left);
        $this->assertEquals(100, $other->fresh()->quantity_left);
    }

    public function test_partial_receipt_can_be_used_then_remaining_delivery_received_without_replenishing_consumed_stock(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->release($w, $qr)->assertOk();
        $this->receive($w, $qr, 7)->assertOk();
        $this->actingAs($w['users']['branch_manager'])->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $qr, 'quantity' => 3, 'reason' => 'Consumed'])->assertOk();
        $this->receive($w, $qr, 3, 'remaining-receipt')->assertOk()->assertJson(['settled' => true]);
        $this->actingAs($w['users']['branch_manager'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()->assertJsonPath('production_group.usage_quantity', 7);
        $this->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $qr, 'quantity' => 6, 'reason' => 'Consumed'])->assertOk()->assertJsonPath('remaining_usable_quantity', 1);
        $this->assertEquals(1, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(10, $w['line']->fresh()->received_quantity);
        $this->assertEquals(2, StockBatch::where('branch_id', $w['branch']->id)->count());
        $this->assertEquals(0, DeliveryDiscrepancy::count());
    }

    public function test_owner_must_select_destination_for_usage_and_main_branch_cannot_consume_franchise_goods(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->release($w, $qr)->assertOk();
        $this->receive($w, $qr)->assertOk();
        $data = ['action' => 'stock_out', 'qr' => $qr, 'movement' => 'usage', 'quantity' => 1, 'reason' => 'Consumed'];
        $this->actingAs($w['users']['commissary_manager'])->postJson('/stock/api', $data)->assertForbidden();
        $this->actingAs($w['users']['owner'])->postJson('/stock/api', $data + ['branch_id' => $w['main']->id])->assertForbidden();
        $this->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr, 'branch_id' => $w['main']->id])->assertOk()->assertJsonPath('production_group.can_use', false);
        $this->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr, 'branch_id' => $w['branch']->id])->assertOk()->assertJsonPath('production_group.can_use', true);
        $this->postJson('/stock/api', $data + ['branch_id' => $w['branch']->id])->assertOk();
        $this->postJson('/stock/api', array_replace($data, ['branch_id' => $w['branch']->id, 'reason' => 'For Delivery']))->assertStatus(422);
        $this->assertEquals(9, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
    }

    public function test_commissary_release_stays_in_transit_until_destination_staff_confirms_stock_in(): void
    {
        $w = $this->world(3);
        $qr = $this->prepare($w);
        $this->release($w, $qr)->assertOk();
        $this->assertSame('delivering', $w['order']->fresh()->status);
        $this->assertNull($w['order']->fresh()->delivered_at);
        $this->assertNull($w['order']->fresh()->settled_at);
        $this->assertEquals(0, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertSame(0, DB::table('order_production_units')->whereNotNull('received_at')->count());

        $this->actingAs($w['users']['branch_staff'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])
            ->assertOk()->assertJsonPath('production_group.can_receive', true)
            ->assertJsonPath('production_group.receivable_quantity', 3)
            ->assertJsonPath('production_group.received_quantity', 0)
            ->assertJsonPath('production_group.receive_message', 'In transit: 3 piece awaiting stock-in at Silang.');
        $data = ['action' => 'stock_in', 'qr' => $qr, 'quantity' => 2, 'idempotency_key' => 'staff-receipt-1'];
        $this->postJson('/qr/api', array_replace($data, ['quantity' => 4]))->assertStatus(422);
        $this->postJson('/qr/api', $data)->assertOk()->assertJson(['received_quantity' => 2, 'remaining_to_receive' => 1, 'status' => 'delivering', 'settled' => false]);
        $this->postJson('/qr/api', $data)->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(2, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertNull($w['order']->fresh()->delivered_at);
        $this->assertSame('delivering', $w['order']->fresh()->status);
        $this->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()
            ->assertJsonPath('production_group.can_receive', true)->assertJsonPath('production_group.receivable_quantity', 1);

        $this->postJson('/qr/api', array_replace($data, ['quantity' => 1, 'idempotency_key' => 'staff-receipt-2']))
            ->assertOk()->assertJson(['received_quantity' => 1, 'remaining_to_receive' => 0, 'status' => 'settled', 'settled' => true]);
        $order = $w['order']->fresh();
        $this->assertNotNull($order->delivered_at);
        $this->assertNotNull($order->settled_at);
        $this->assertEquals($w['users']['branch_staff']->id, $order->confirmed_by);
        $this->assertEquals(3, $w['line']->fresh()->received_quantity);
        $this->assertSame(3, DB::table('order_production_units')->where('received_by', $w['users']['branch_staff']->id)->where('receipt_accepted', true)->count());
        $this->postJson('/qr/api', array_replace($data, ['idempotency_key' => 'rescan-after-completion']))->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(3, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(3, StockTransaction::where('branch_id', $w['branch']->id)->where('type', 'in')->sum('quantity'));
        $this->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()
            ->assertJsonPath('production_group.can_receive', false)->assertJsonPath('production_group.can_use', true);
    }

    public function test_branch_staff_cannot_receive_before_release_or_receive_another_branch_delivery(): void
    {
        $w = $this->world(3);
        $qr = $this->prepare($w);
        $this->actingAs($w['users']['branch_staff'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()
            ->assertJsonPath('production_group.can_receive', false);
        $this->postJson('/qr/api', ['action' => 'stock_in', 'qr' => $qr, 'quantity' => 3])->assertStatus(422);
        $this->release($w, $qr)->assertOk();
        foreach (['commissary_manager', 'commissary_staff'] as $role) {
            $this->actingAs($w['users'][$role])->postJson('/qr/api', ['action' => 'stock_in', 'qr' => $qr])->assertForbidden();
        }
        $other = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $w['users']['branch_staff']->update(['branch_id' => $other->id]);
        $this->actingAs($w['users']['branch_staff']->fresh())->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr, 'branch_id' => $w['branch']->id])->assertForbidden();
        $this->postJson('/qr/api', ['action' => 'stock_in', 'qr' => $qr, 'quantity' => 3, 'branch_id' => $w['branch']->id])->assertForbidden();
        $this->assertEquals(0, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertSame(0, DB::table('order_production_units')->whereNotNull('received_at')->count());
        $this->assertSame('delivering', $w['order']->fresh()->status);
        $this->assertNull($w['order']->fresh()->delivered_at);
    }

    #[TestWith([3, 0, true])]
    #[TestWith([2, 1, false])]
    public function test_no_issue_receipts_accept_good_goods_without_recording_false_discrepancies(int $quantity, int $pending, bool $settled): void
    {
        $world = $this->world(3);
        $qr = $this->prepare($world);
        $this->release($world, $qr)->assertOk();
        $data = ['action' => 'stock_in', 'qr' => $qr, 'quantity' => $quantity, 'receipt_status' => 'good', 'reason' => '', 'finalize' => false, 'idempotency_key' => 'good-receipt'];
        $this->actingAs($world['users']['branch_staff'])->postJson('/qr/api', $data)->assertOk()
            ->assertJson(['received_quantity' => $quantity, 'remaining_to_receive' => $pending, 'settled' => $settled]);
        $this->assertSame(0, DeliveryDiscrepancy::count());
        $this->assertEquals($quantity, StockBatch::where('branch_id', $world['branch']->id)->where('condition', 'good')->sum('quantity_left'));
        $this->assertSame($pending, DB::table('order_production_units')->whereNull('received_at')->count());
        $this->postJson('/qr/api', $data)->assertOk()->assertJson(['already' => true]);
        $this->assertEquals($quantity, StockBatch::where('branch_id', $world['branch']->id)->sum('quantity_left'));
    }

    #[TestWith(['Missing Quantity', 2])]
    #[TestWith(['Damaged in Transit', 2])]
    #[TestWith(['Incorrect Item', 0])]
    public function test_reported_issues_only_add_accepted_good_units_and_record_the_selected_reason(string $reason, int $accepted): void
    {
        $world = $this->world(3);
        $qr = $this->prepare($world);
        $this->release($world, $qr)->assertOk();
        $this->actingAs($world['users']['branch_staff'])->postJson('/qr/api', ['action' => 'stock_in', 'qr' => $qr, 'quantity' => $accepted,
            'receipt_status' => 'issue', 'reason' => $reason, 'finalize' => true])->assertOk()
            ->assertJson(['received_quantity' => $accepted, 'remaining_to_receive' => 0, 'status' => 'delivered', 'settled' => false]);
        $this->assertDatabaseHas('delivery_discrepancies', ['order_id' => $world['order']->id, 'expected_quantity' => 3, 'received_quantity' => $accepted, 'reason' => $reason]);
        $this->assertEquals($accepted, StockBatch::where('branch_id', $world['branch']->id)->where('condition', 'good')->sum('quantity_left'));
        $this->assertSame(3 - $accepted, DB::table('order_production_units')->whereNotNull('received_at')->where('receipt_accepted', false)->count());
    }

    public function test_invalid_inspection_results_do_not_close_receipts_or_consume_retry_keys(): void
    {
        $world = $this->world(3);
        $qr = $this->prepare($world);
        $this->release($world, $qr)->assertOk();
        $base = ['action' => 'stock_in', 'qr' => $qr, 'quantity' => 2, 'receipt_status' => 'issue', 'reason' => 'Damaged in Transit', 'finalize' => true, 'idempotency_key' => 'inspection-retry'];
        $auditCount = DB::table('audit_log')->count();
        foreach ([['reason' => ''], ['finalize' => false], ['quantity' => 3], ['receipt_status' => 'good'], ['receipt_status' => 'unknown'], ['receipt_status' => ['good']]] as $invalid) {
            $this->actingAs($world['users']['branch_staff'])->postJson('/qr/api', array_replace($base, $invalid))->assertUnprocessable();
            $this->assertSame(0, DeliveryDiscrepancy::count());
            $this->assertSame(0, StockBatch::where('branch_id', $world['branch']->id)->count());
            $this->assertSame(0, DB::table('order_production_units')->whereNotNull('received_at')->count());
            $this->assertSame(0, DB::table('stock_idempotency_keys')->where('scope', 'group_receive')->count());
            $this->assertSame($auditCount, DB::table('audit_log')->count());
        }
        $this->postJson('/qr/api', array_replace($base, ['receipt_status' => 'good', 'reason' => '']))->assertUnprocessable();
        $this->postJson('/qr/api', $base)->assertOk()->assertJson(['received_quantity' => 2]);
        $this->postJson('/qr/api', $base)->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(2, StockBatch::where('branch_id', $world['branch']->id)->sum('quantity_left'));
    }

    private function world(int $quantity = 10): array
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'commissary_manager', 'commissary_staff', 'branch_manager', 'branch_staff'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@group.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => str_starts_with($name, 'branch_') ? $branch->id : $main->id, 'status' => 'active']);
        }
        $item = Item::create(['item_name' => 'Banh Mi', 'unit' => 'piece', 'price' => 18, 'shelf_life_days' => 3, 'is_active' => true]);
        $order = Order::create(['order_number' => 'ORD-GROUP-1', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id, 'status' => 'pending', 'total_amount' => $quantity * 18]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => 18]);

        return compact('main', 'branch', 'users', 'item', 'order', 'line');
    }

    private function prepare(array $w): string
    {
        $dates = $w['order']->orderItems->mapWithKeys(fn ($line) => [$line->id => now()->addDays(3)->toDateString()]);
        $this->actingAs($w['users']['owner'])->postJson('/orders/api', ['action' => 'prepare_order', 'id' => $w['order']->id, 'expiry' => json_encode($dates)])->assertOk();

        return OrderProduction::where('order_item_id', $w['line']->id)->value('qr_code');
    }

    private function release(array $w, string $qr, ?int $quantity = null, string $key = 'release-1')
    {
        $data = ['action' => 'stock_out', 'qr' => $qr, 'idempotency_key' => $key];
        if ($quantity !== null) {
            $data['quantity'] = $quantity;
        }

        return $this->actingAs($w['users']['commissary_staff'])->postJson('/stock/api', $data);
    }

    private function receive(array $w, string $qr, ?int $quantity = null, string $key = 'receipt-1')
    {
        $data = ['action' => 'stock_in', 'qr' => $qr, 'idempotency_key' => $key];
        if ($quantity !== null) {
            $data['quantity'] = $quantity;
        }

        return $this->actingAs($w['users']['branch_manager'])->postJson('/stock/api', $data);
    }

    public function test_ten_print_copies_share_one_code_and_one_scan_moves_all_ten_on_both_sides(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $response = $this->actingAs($w['users']['owner'])->get('/orders/'.$w['order']->id.'/labels')->assertOk();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $labels = (new \DOMXPath($document))->query('//div[@data-unit-label]');
        $this->assertSame(10, $labels->length);
        foreach ($labels as $label) {
            $this->assertSame($qr, $label->getAttribute('data-unit-label'));
        }
        $this->actingAs($w['users']['commissary_staff'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()->assertJsonPath('production_group.remaining_quantity', 10);
        $this->release($w, $qr)->assertOk()->assertJson(['dispatched_quantity' => 10, 'full' => true]);
        $this->assertEquals(0, StockBatch::where('branch_id', $w['main']->id)->sum('quantity_left'));
        $this->actingAs($w['users']['branch_manager'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()->assertJsonPath('production_group.receivable_quantity', 10)->assertJsonPath('production_group.can_receive', true);
        $this->receive($w, $qr)->assertOk()->assertJson(['received_quantity' => 10, 'settled' => true]);
        $this->assertEquals(10, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(10, $w['line']->fresh()->received_quantity);
        $this->assertSame('settled', $w['order']->fresh()->status);
        $this->assertEquals(10, DB::table('order_production_units')->whereNotNull('received_at')->where('receipt_accepted', true)->count());
        $this->receive($w, $qr)->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(10, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(0, DeliveryDiscrepancy::count());
        if ($folder = getenv('GROUP_QR_BROWSER_ARTIFACTS')) {
            if (! is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            file_put_contents($folder.'/labels.html', $response->getContent());
            file_put_contents($folder.'/stock.html', $this->actingAs($w['users']['commissary_staff'])->get('/stock')->getContent());
            file_put_contents($folder.'/delivery.html', $this->actingAs($w['users']['owner'])->get('/delivery')->getContent());
            file_put_contents($folder.'/qr.html', $this->actingAs($w['users']['branch_manager'])->get('/qr')->getContent());
        }
    }

    public function test_partial_release_caps_receipt_to_seven_and_retries_do_not_release_remaining_three(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->release($w, $qr, 11)->assertStatus(422);
        $this->release($w, $qr, 7)->assertOk()->assertJson(['remaining_quantity' => 3]);
        $this->release($w, $qr, 7)->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(3, StockBatch::where('branch_id', $w['main']->id)->sum('quantity_left'));
        $this->receive($w, $qr, 8)->assertStatus(422);
        $this->receive($w, $qr, 7)->assertOk()->assertJson(['settled' => false]);
        $this->assertEquals(7, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->receive($w, $qr, 7)->assertOk()->assertJson(['already' => true]);
        $this->release($w, $qr, 4, 'release-2')->assertStatus(422);
        $this->release($w, $qr, 3, 'release-2')->assertOk();
        $this->receive($w, $qr, null, 'receipt-2')->assertOk()->assertJson(['received_quantity' => 3, 'settled' => true]);
        $this->assertEquals(10, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(0, DeliveryDiscrepancy::count());
    }

    public function test_receiving_only_seven_of_ten_records_three_missing_and_never_stock_ins_them_on_rescan(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->release($w, $qr)->assertOk();
        $this->actingAs($w['users']['branch_manager'])->postJson('/stock/api', ['action' => 'stock_in', 'qr' => $qr, 'quantity' => 7, 'finalize' => true])->assertOk()->assertJson(['settled' => false]);
        $this->assertDatabaseHas('delivery_discrepancies', ['expected_quantity' => 10, 'received_quantity' => 7, 'reason' => 'Missing Quantity']);
        $this->assertSame('delivered', $w['order']->fresh()->status);
        $this->receive($w, $qr, 3, 'retry-new')->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(7, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
    }

    public function test_each_product_is_received_separately_and_whole_order_confirmation_only_adds_remaining_products(): void
    {
        $w = $this->world();
        $other = Item::create(['item_name' => 'Sauce', 'unit' => 'pack', 'price' => 10, 'shelf_life_days' => 3, 'is_active' => true]);
        $second = OrderItem::create(['order_id' => $w['order']->id, 'item_id' => $other->id, 'quantity' => 2, 'unit_price' => 10]);
        $qr = $this->prepare($w);
        $otherQr = OrderProduction::where('order_item_id', $second->id)->value('qr_code');
        $this->release($w, $qr)->assertOk();
        $this->receive($w, $qr)->assertOk()->assertJson(['settled' => false]);
        $this->release($w, $otherQr, null, 'other-release')->assertOk();
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $w['order']->id])->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals(10, StockBatch::where('branch_id', $w['branch']->id)->where('item_id', $w['item']->id)->sum('quantity_left'));
        $this->assertEquals(2, StockBatch::where('branch_id', $w['branch']->id)->where('item_id', $other->id)->sum('quantity_left'));
    }

    public function test_wrong_branch_roles_invalid_quantities_and_expired_groups_are_blocked(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->actingAs($w['users']['branch_manager'])->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $qr, 'movement' => 'delivery'])->assertForbidden();
        $this->actingAs($w['users']['commissary_staff'])->postJson('/stock/api', ['action' => 'stock_in', 'qr' => $qr])->assertForbidden();
        $other = Branch::create(['branch_name' => 'Dasma', 'branch_type' => 'franchise', 'is_active' => true]);
        $w['users']['branch_manager']->update(['branch_id' => $other->id]);
        $this->receive($w, $qr)->assertForbidden();
        $w['users']['branch_manager']->update(['branch_id' => $w['branch']->id]);
        foreach ([1.5, 0, -1, 'bad'] as $quantity) {
            $this->actingAs($w['users']['commissary_staff'])->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $qr, 'quantity' => $quantity])->assertStatus(422);
        }
        StockBatch::first()->update(['expiry_date' => now()->subDay()]);
        $this->release($w, $qr)->assertStatus(422);
        $this->assertEquals(0, StockTransaction::where('type', 'out')->count());
        $this->assertEquals(0, OrderProduction::first()->dispatched_qty);
    }

    public function test_expired_in_transit_is_acknowledged_as_exception_without_usable_stock_in(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->release($w, $qr)->assertOk();
        StockBatch::first()->update(['expiry_date' => now()->subDay()]);
        $this->receive($w, $qr)->assertOk()->assertJson(['received_quantity' => 0, 'settled' => false]);
        $this->assertEquals(0, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(1, DeliveryDiscrepancy::count());
    }

    public function test_partial_receipt_leaves_three_pending_and_stock_management_retry_is_safe(): void
    {
        $w = $this->world();
        $qr = $this->prepare($w);
        $this->release($w, $qr)->assertOk();
        $this->receive($w, $qr, 7)->assertOk()->assertJson(['received_quantity' => 7, 'remaining_to_receive' => 3, 'settled' => false]);
        $this->receive($w, $qr, 7)->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(7, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(0, DeliveryDiscrepancy::count());
        $this->actingAs($w['users']['branch_manager'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $qr])->assertOk()->assertJsonPath('production_group.receivable_quantity', 3);
        $this->receive($w, $qr, 4, 'new-receipt')->assertStatus(422);
        $this->receive($w, $qr, 3, 'new-receipt')->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals(10, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(0, DeliveryDiscrepancy::count());
    }

    public function test_group_release_is_atomic_across_source_lots_and_receipt_keeps_their_expiries(): void
    {
        $w = $this->world(5);
        $first = StockBatch::create(['item_id' => $w['item']->id, 'branch_id' => $w['main']->id, 'quantity_in' => 2, 'quantity_left' => 2, 'expiry_date' => now()->addDay(), 'condition' => 'good']);
        $second = StockBatch::create(['item_id' => $w['item']->id, 'branch_id' => $w['main']->id, 'quantity_in' => 5, 'quantity_left' => 5, 'expiry_date' => now()->addDays(2), 'condition' => 'good']);
        $this->actingAs($w['users']['owner'])->postJson('/orders/api', ['action' => 'prepare_order', 'id' => $w['order']->id,
            'fulfillment' => json_encode([$w['line']->id => ['quantity' => 5, 'source' => 'picked']])])->assertOk();
        $qr = OrderProduction::first()->qr_code;
        $second->update(['condition' => 'damaged']);
        $this->release($w, $qr)->assertStatus(422);
        $this->assertEquals(2, $first->fresh()->quantity_left);
        $this->assertEquals(5, $second->fresh()->quantity_left);
        $this->assertEquals(0, DB::table('order_production_units')->whereNotNull('dispatched_at')->count());
        $this->assertSame('preparing', $w['order']->fresh()->status);
        $second->update(['condition' => 'good']);
        $this->release($w, $qr)->assertOk();
        $this->receive($w, $qr)->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals(0, $first->fresh()->quantity_left);
        $this->assertEquals(2, $second->fresh()->quantity_left);
        $this->assertEquals(2, StockBatch::where('branch_id', $w['branch']->id)->whereDate('expiry_date', $first->expiry_date)->sum('quantity_left'));
        $this->assertEquals(3, StockBatch::where('branch_id', $w['branch']->id)->whereDate('expiry_date', $second->expiry_date)->sum('quantity_left'));
    }
}
