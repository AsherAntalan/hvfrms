<?php

namespace Tests\Feature;

use App\Models\Branch;
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

class PieceLabelWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'commissary_manager', 'commissary_staff', 'branch_manager'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@labels.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => $name === 'branch_manager' ? $branch->id : $main->id, 'status' => 'active']);
        }
        $item = Item::create(['item_name' => 'Buns', 'unit' => 'piece', 'price' => 18, 'shelf_life_days' => 3, 'is_active' => true]);
        $order = Order::create(['order_number' => 'ORD-PIECE-1', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id, 'status' => 'pending', 'total_amount' => 72]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 4, 'unit_price' => 18]);

        return compact('main', 'branch', 'users', 'item', 'order', 'line');
    }

    private function prepare(array $world): void
    {
        $this->actingAs($world['users']['commissary_manager'])->postJson('/orders/api', [
            'action' => 'prepare_order', 'id' => $world['order']->id, 'expiry' => json_encode([$world['line']->id => now()->addDays(3)->toDateString()]),
        ])->assertOk()->assertJson(['label_count' => 4]);
    }

    private function printLabels(array $world): void
    {
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'print_order_labels', 'id' => $world['order']->id])->assertOk();
    }

    public function test_four_physical_label_copies_share_the_product_order_qr_and_one_stock_lot(): void
    {
        $world = $this->world();
        $this->prepare($world);
        $production = OrderProduction::firstOrFail();
        $codes = DB::table('order_production_units')->pluck('qr_code');
        $this->assertCount(4, $codes);
        $this->assertCount(4, $codes->unique());
        $this->assertEquals(4, StockBatch::where('branch_id', $world['main']->id)->sum('quantity_left'));
        $this->assertEquals(1, StockTransaction::where('type', 'in')->count());
        $response = $this->actingAs($world['users']['owner'])->get('/orders/'.$world['order']->id.'/labels')->assertOk();
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $labels = $xpath->query('//div[@data-unit-label]');
        $this->assertEquals(4, $labels->length);
        foreach ($labels as $label) {
            $this->assertSame($production->qr_code, $label->getAttribute('data-unit-label'));
            $this->assertSame('1', trim($xpath->query('.//span[@class="qty"]', $label)->item(0)->textContent));
        }
        $response->assertSee($production->qr_code);
        if ($artifacts = getenv('PIECE_LABEL_BROWSER_ARTIFACTS')) {
            if (! is_dir($artifacts)) {
                mkdir($artifacts, 0777, true);
            }
            file_put_contents($artifacts.'/labels.html', $response->getContent());
            file_put_contents($artifacts.'/orders.html', $this->actingAs($world['users']['owner'])->get('/orders')->getContent());
            file_put_contents($artifacts.'/delivery.html', $this->actingAs($world['users']['owner'])->get('/delivery')->getContent());
            file_put_contents($artifacts.'/stock.html', $this->actingAs($world['users']['commissary_staff'])->get('/stock')->getContent());
        }
        $this->prepare($world);
        $this->assertEquals(4, DB::table('order_production_units')->count());
        $this->assertEquals(1, StockBatch::count());
        $this->assertEquals(1, StockTransaction::count());
        $this->assertEquals($production->production_at, OrderProduction::first()->production_at);
    }

    public function test_print_then_staff_scans_each_piece_once_and_receipt_records_delivery(): void
    {
        $world = $this->world();
        $this->prepare($world);
        $codes = DB::table('order_production_units')->pluck('qr_code');
        $staff = $world['users']['commissary_staff'];
        $this->actingAs($staff)->get('/stock')->assertOk()->assertSee('Stock Out — For Delivery');
        $this->actingAs($staff)->postJson('/qr/api', ['action' => 'lookup', 'query' => $codes[0]])->assertOk()->assertJsonPath('production_unit.can_dispatch', true);
        $this->printLabels($world);
        $this->assertSame('preparing', $world['order']->fresh()->status);
        $this->actingAs($staff)->postJson('/qr/api', ['action' => 'lookup', 'query' => $codes[0]])->assertOk()
            ->assertJsonPath('production_unit.quantity', 1)->assertJsonPath('production_unit.destination', 'Silang')->assertJsonPath('production_unit.can_dispatch', true);
        $this->actingAs($staff)->postJson('/orders/api', ['action' => 'dispatch_scan', 'qr' => $codes[0], 'quantity' => 2])->assertStatus(422);
        foreach ($codes as $index => $code) {
            $this->actingAs($staff)->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $code, 'quantity' => 1])->assertOk()->assertJson(['full' => $index === 3]);
            $this->actingAs($staff)->postJson('/orders/api', ['action' => 'dispatch_scan', 'qr' => $code])->assertOk()->assertJson(['already' => true]);
            $this->assertEquals($index + 1, OrderProduction::first()->dispatched_qty);
        }
        $this->assertEquals(0, StockBatch::where('branch_id', $world['main']->id)->sum('quantity_left'));
        $this->assertEquals(4, StockTransaction::where('type', 'out')->count());
        $this->actingAs($world['users']['owner'])->get('/warehouse')->assertOk()->assertSee('Dispatched to Silang');
        $this->actingAs($world['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $world['order']->id])->assertOk()->assertJson(['settled' => true]);
        $this->assertEquals(4, StockBatch::where('branch_id', $world['branch']->id)->sum('quantity_left'));
        $this->actingAs($world['users']['owner'])->get('/inventory')->assertOk()->assertSee('Delivered to Silang');
        $this->actingAs($world['users']['branch_manager'])->postJson('/orders/api', ['action' => 'receive_scan', 'id' => $world['order']->id])->assertOk()->assertJson(['already' => true]);
        $this->assertEquals(4, StockBatch::where('branch_id', $world['branch']->id)->sum('quantity_left'));
    }

    public function test_invalid_expiry_and_foreign_lines_leave_no_partial_preparation(): void
    {
        $world = $this->world();
        $second = OrderItem::create(['order_id' => $world['order']->id, 'item_id' => $world['item']->id, 'quantity' => 2, 'unit_price' => 18]);
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'prepare_order', 'id' => $world['order']->id,
            'expiry' => json_encode([$world['line']->id => now()->addDay()->toDateString(), $second->id => 'bad-date'])])->assertStatus(422);
        $this->assertEquals(0, OrderProduction::count());
        $this->assertEquals(0, StockBatch::count());
        $this->assertEquals(0, DB::table('order_production_units')->count());
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'prepare_order', 'id' => $world['order']->id,
            'expiry' => json_encode([9999 => now()->addDay()->toDateString()])])->assertStatus(422);
        $this->assertSame('pending', $world['order']->fresh()->status);
    }

    public function test_roles_expiry_and_generic_stock_out_cannot_bypass_individual_labels(): void
    {
        $world = $this->world();
        foreach (['commissary_staff', 'branch_manager'] as $role) {
            $this->actingAs($world['users'][$role])->postJson('/orders/api', ['action' => 'prepare_order', 'id' => $world['order']->id, 'expiry' => '{}'])->assertForbidden();
        }
        $this->prepare($world);
        $this->printLabels($world);
        $production = OrderProduction::firstOrFail();
        $code = DB::table('order_production_units')->value('qr_code');
        $this->actingAs($world['users']['branch_manager'])->postJson('/orders/api', ['action' => 'dispatch_scan', 'qr' => $code])->assertForbidden();
        $this->actingAs($world['users']['commissary_staff'])->postJson('/orders/api', ['action' => 'dispatch_scan', 'qr' => $production->qr_code, 'quantity' => 5])->assertStatus(422);
        $generic = (new StockService)->stockOutBatch($production->stock_batch_id, $world['main']->id, 1, $world['users']['owner']->id, 'For Delivery');
        $this->assertFalse($generic['ok']);
        $this->assertEquals(0, (new StockService)->eligibleAvailable($world['item']->id, $world['main']->id));
        $production->update(['expiry_date' => now()->subDay()]);
        $this->actingAs($world['users']['commissary_staff'])->postJson('/orders/api', ['action' => 'dispatch_scan', 'qr' => $code])->assertStatus(422);
        $this->assertEquals(0, OrderProduction::first()->dispatched_qty);
        $this->assertEquals(4, StockBatch::first()->quantity_left);
    }

    public function test_print_retries_and_cancellation_keep_inventory_and_labels_stable(): void
    {
        $world = $this->world();
        $this->prepare($world);
        $this->printLabels($world);
        $timestamp = $world['order']->fresh()->labels_print_requested_at;
        $this->printLabels($world);
        $this->assertEquals($timestamp, $world['order']->fresh()->labels_print_requested_at);
        $this->assertEquals(1, StockTransaction::count());
        $this->assertEquals(4, DB::table('order_production_units')->count());
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'prepare_output', 'id' => $world['order']->id,
            'lines' => json_encode([['order_item_id' => $world['line']->id, 'produced_qty' => 1, 'expiry_date' => now()->addDay()->toDateString()]])])->assertStatus(422);
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'cancel', 'id' => $world['order']->id, 'reason' => 'Incorrect order'])->assertOk();
        $code = DB::table('order_production_units')->value('qr_code');
        $this->actingAs($world['users']['commissary_staff'])->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $code])->assertStatus(422);
        $this->assertEquals(4, StockBatch::first()->quantity_left);
    }

    public function test_tampered_qr_damaged_stock_and_stock_in_are_rejected(): void
    {
        $world = $this->world();
        $this->prepare($world);
        $this->printLabels($world);
        $code = DB::table('order_production_units')->value('qr_code');
        $staff = $world['users']['commissary_staff'];
        $this->actingAs($staff)->postJson('/qr/api', ['action' => 'lookup', 'query' => str_replace('HV-PROD-', 'HV-PROD-999', $code)])->assertStatus(422);
        $this->actingAs($staff)->postJson('/stock/api', ['action' => 'stock_in', 'qr' => $code, 'quantity' => 1])->assertStatus(422);
        StockBatch::first()->update(['condition' => 'damaged']);
        $this->actingAs($staff)->postJson('/stock/api', ['action' => 'stock_out', 'qr' => $code])->assertStatus(422);
        $this->assertEquals(0, OrderProduction::first()->dispatched_qty);
        $this->assertEquals(4, StockBatch::first()->quantity_left);
    }

    public function test_main_manager_can_cancel_before_dispatch_without_deleting_produced_stock(): void
    {
        $world = $this->world();
        $this->prepare($world);
        $this->actingAs($world['users']['commissary_manager'])->postJson('/orders/api', [
            'action' => 'cancel', 'id' => $world['order']->id, 'reason' => 'Incorrect quantity',
        ])->assertOk();
        $this->assertSame('cancelled', $world['order']->fresh()->status);
        $this->assertEquals(4, StockBatch::first()->quantity_left);
        $this->assertEquals(4, DB::table('order_production_units')->count());
    }
}
