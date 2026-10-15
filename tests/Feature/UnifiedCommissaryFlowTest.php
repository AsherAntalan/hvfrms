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
use App\Services\StockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class UnifiedCommissaryFlowTest extends TestCase
{
    use RefreshDatabase;

    private function world(int $quantity = 10): array
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'commissary_manager', 'commissary_staff', 'branch_manager'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@flow.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => $name === 'branch_manager' ? $branch->id : $main->id, 'status' => 'active']);
        }
        $item = Item::create(['item_name' => 'Buns', 'unit' => 'piece', 'price' => 18, 'shelf_life_days' => 3, 'is_active' => true]);
        $order = Order::create(['order_number' => 'ORD-UNIFIED-1', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id, 'status' => 'pending', 'total_amount' => $quantity * 18]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => $quantity, 'unit_price' => 18]);

        return compact('main', 'branch', 'users', 'item', 'order', 'line');
    }

    private function prepare(array $world, int $quantity, string $source = 'produced', string $reason = 'Production shortage')
    {
        return $this->actingAs($world['users']['commissary_manager'])->postJson('/orders/api', [
            'action' => 'prepare_order', 'id' => $world['order']->id,
            'expiry' => json_encode([$world['line']->id => now()->addDays(3)->toDateString()]),
            'fulfillment' => json_encode([$world['line']->id => ['quantity' => $quantity, 'source' => $source, 'reason' => $reason]]),
        ]);
    }

    private function release(array $world): void
    {
        foreach (DB::table('order_production_units')->pluck('qr_code') as $code) {
            $this->actingAs($world['users']['commissary_staff'])->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $code])->assertOk();
        }
    }

    private function batch(array $world, int $quantity, string $expiry): StockBatch
    {
        return StockBatch::create(['item_id' => $world['item']->id, 'branch_id' => $world['main']->id, 'quantity_in' => $quantity, 'quantity_left' => $quantity,
            'expiry_date' => $expiry, 'condition' => 'good', 'received_at' => now()]);
    }

    public function test_review_does_not_create_stock_or_release_goods(): void
    {
        $w = $this->world();
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'review_order', 'id' => $w['order']->id])->assertForbidden();
        $this->actingAs($w['users']['owner'])->postJson('/orders/api', ['action' => 'review_order', 'id' => $w['order']->id])->assertOk();
        $this->assertSame('approved', $w['order']->fresh()->status);
        $this->assertSame(0, StockBatch::count());
        $this->assertSame(0, StockTransaction::count());
        $this->assertSame(0, OrderProduction::count());
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'branch_confirm', 'id' => $w['order']->id])->assertStatus(409);
        $this->assertSame('approved', $w['order']->fresh()->status);
    }

    public function test_ten_requested_seven_prepared_released_received_preserves_shortage_and_closes_receipt(): void
    {
        $w = $this->world();
        $this->prepare($w, 7)->assertOk()->assertJson(['label_count' => 7]);
        $this->assertSame(10, $w['line']->fresh()->quantity);
        $this->assertEquals(7, $w['line']->fresh()->fulfilled_quantity);
        $this->assertEquals(180, $w['order']->fresh()->total_amount);
        $this->assertEquals(126, $w['order']->fresh()->fulfilled_amount);
        $this->assertSame(7, DB::table('order_production_units')->count());
        $this->assertNull($w['order']->fresh()->labels_print_requested_at);
        $this->release($w);
        $this->assertEquals(0, StockBatch::where('branch_id', $w['main']->id)->sum('quantity_left'));
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $w['order']->id])->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals(7, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertEquals(7, $w['line']->fresh()->received_quantity);
        $this->assertEquals(0, DeliveryDiscrepancy::count());
        $this->assertSame('Production shortage', $w['line']->fresh()->fulfillment_reason);
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'branch_confirm', 'id' => $w['order']->id])->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(7, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->actingAs($w['users']['owner'])->get('/reports')->assertOk()->assertViewHas('orderGrand', fn ($totals) => $totals['value'] == 126);
    }

    public function test_delivering_without_print_timestamp_is_dispatchable_and_printing_never_rewinds_state(): void
    {
        $w = $this->world(1);
        $this->prepare($w, 1)->assertOk();
        $w['order']->update(['status' => 'delivering', 'branch_confirmed_at' => now()]);
        $code = DB::table('order_production_units')->value('qr_code');
        $this->actingAs($w['users']['commissary_staff'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $code])->assertOk()->assertJsonPath('production_unit.can_dispatch', true);
        $this->actingAs($w['users']['owner'])->postJson('/orders/api', ['action' => 'print_order_labels', 'id' => $w['order']->id])->assertOk();
        $this->assertSame('delivering', $w['order']->fresh()->status);
        $this->assertEquals(0, StockTransaction::where('type', 'out')->count());
        $this->release($w);
        $this->assertEquals(1, StockTransaction::where('type', 'out')->count());
    }

    public function test_picking_existing_lots_does_not_manufacture_stock_and_preserves_each_expiry(): void
    {
        $w = $this->world(5);
        $first = $this->batch($w, 2, now()->addDay()->toDateString());
        $second = $this->batch($w, 5, now()->addDays(2)->toDateString());
        $this->prepare($w, 5, 'picked', '')->assertOk();
        $this->assertSame(2, StockBatch::count());
        $this->assertSame(0, StockTransaction::count());
        $this->assertEquals(2, (new StockService)->eligibleAvailable($w['item']->id, $w['main']->id));
        $this->assertEquals(2, StockBatch::find($first->id)->quantity_left);
        $this->assertEquals(5, StockBatch::find($second->id)->quantity_left);
        $this->actingAs($w['users']['owner'])->get('/orders/'.$w['order']->id.'/labels')->assertOk()->assertSee(now()->addDay()->format('M j, Y'))->assertSee(now()->addDays(2)->format('M j, Y'));
        $this->release($w);
        $this->assertEquals(0, $first->fresh()->quantity_left);
        $this->assertEquals(2, $second->fresh()->quantity_left);
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $w['order']->id])->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals(2, StockBatch::where('branch_id', $w['branch']->id)->whereDate('expiry_date', $first->expiry_date)->sum('quantity_left'));
        $this->assertEquals(3, StockBatch::where('branch_id', $w['branch']->id)->whereDate('expiry_date', $second->expiry_date)->sum('quantity_left'));
    }

    public function test_reduced_quantity_requires_reason_and_insufficient_pick_rolls_back_everything(): void
    {
        $w = $this->world();
        $this->prepare($w, 7, 'produced', '')->assertStatus(422);
        $this->prepare($w, 11)->assertStatus(422);
        $this->prepare($w, 0)->assertStatus(422);
        $this->batch($w, 3, now()->addDays(3)->toDateString());
        $this->prepare($w, 7, 'picked')->assertStatus(422);
        $this->assertSame(0, OrderProduction::count());
        $this->assertSame(0, DB::table('order_production_units')->count());
        $this->assertSame('pending', $w['order']->fresh()->status);
        $this->assertNull($w['line']->fresh()->fulfilled_quantity);
    }

    public function test_unfulfilled_zero_line_does_not_block_valid_shipment_or_make_labels(): void
    {
        $w = $this->world(2);
        $second = OrderItem::create(['order_id' => $w['order']->id, 'item_id' => $w['item']->id, 'quantity' => 3, 'unit_price' => 18]);
        $this->actingAs($w['users']['owner'])->postJson('/orders/api', ['action' => 'prepare_order', 'id' => $w['order']->id,
            'expiry' => json_encode([$w['line']->id => now()->addDays(3)->toDateString()]),
            'fulfillment' => json_encode([$w['line']->id => ['quantity' => 2], $second->id => ['quantity' => 0, 'reason' => 'Not available']])])->assertOk()->assertJson(['label_count' => 2]);
        $this->release($w);
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $w['order']->id])->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals(0, $second->fresh()->received_quantity);
        $this->assertEquals(0, DeliveryDiscrepancy::count());
    }

    public function test_picked_labels_cap_group_release_and_protect_reserved_stock_from_generic_stock_out(): void
    {
        $w = $this->world(3);
        $batch = $this->batch($w, 3, now()->addDay()->toDateString());
        $this->prepare($w, 3, 'picked', '')->assertOk();
        $production = OrderProduction::first();
        $this->actingAs($w['users']['commissary_staff'])->postJson('/orders/api', ['action' => 'dispatch_scan', 'qr' => $production->qr_code, 'quantity' => 4])->assertStatus(422);
        $result = (new StockService)->stockOutBatch($batch->id, $w['main']->id, 1, $w['users']['owner']->id, 'Other');
        $this->assertFalse($result['ok']);
        $this->assertEquals(3, $batch->fresh()->quantity_left);
        $this->assertEquals(0, StockTransaction::where('type', 'out')->count());
        $this->actingAs($w['users']['owner'])->postJson('/orders/api', ['action' => 'cancel', 'id' => $w['order']->id, 'reason' => 'Wrong order'])->assertOk();
        $this->assertEquals(3, (new StockService)->eligibleAvailable($w['item']->id, $w['main']->id));
    }

    public function test_invalid_receipt_data_never_adds_destination_stock(): void
    {
        $w = $this->world(3);
        $this->prepare($w, 3)->assertOk();
        $this->release($w);
        foreach ([4, 1.5, -1, 'bad'] as $quantity) {
            $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $w['order']->id,
                'received_items' => json_encode([['order_item_id' => $w['line']->id, 'received_quantity' => $quantity]])])->assertStatus(422);
        }
        foreach (['bad-json', json_encode([['order_item_id' => 9999, 'received_quantity' => 1]]), json_encode([['order_item_id' => $w['line']->id, 'received_quantity' => 1], ['order_item_id' => $w['line']->id, 'received_quantity' => 2]])] as $entries) {
            $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $w['order']->id, 'received_items' => $entries])->assertStatus(422);
        }
        $this->assertSame('delivering', $w['order']->fresh()->status);
        $this->assertEquals(0, StockBatch::where('branch_id', $w['branch']->id)->count());
        $this->assertEquals(0, DeliveryDiscrepancy::count());
    }

    public function test_status_shortcuts_cannot_skip_preparation_release_or_exceptions(): void
    {
        $w = $this->world(3);
        $this->actingAs($w['users']['owner'])->postJson('/orders/api', ['action' => 'review_order', 'id' => $w['order']->id])->assertOk();
        foreach (['preparing', 'delivering', 'settled'] as $status) {
            $this->actingAs($w['users']['owner'])->postJson('/orders/api', ['action' => 'update_status', 'id' => $w['order']->id, 'status' => $status])->assertStatus(422);
        }
        $this->assertSame('approved', $w['order']->fresh()->status);
        $this->assertEquals(0, StockBatch::count());
        $this->assertEquals(0, StockTransaction::count());
    }

    public function test_short_receipt_keeps_selected_damage_reason_and_never_auto_settles(): void
    {
        $w = $this->world(3);
        $this->prepare($w, 3)->assertOk();
        $this->release($w);
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $w['order']->id,
            'received_items' => json_encode([['order_item_id' => $w['line']->id, 'received_quantity' => 2, 'reason' => 'Damaged in Transit']])])->assertOk()->assertJson(['settled' => false]);
        $this->assertDatabaseHas('delivery_discrepancies', ['order_id' => $w['order']->id, 'expected_quantity' => 3, 'received_quantity' => 2, 'reason' => 'Damaged in Transit']);
        $this->assertEquals(2, StockBatch::where('branch_id', $w['branch']->id)->sum('quantity_left'));
        $this->assertSame('delivered', $w['order']->fresh()->status);
    }
}
