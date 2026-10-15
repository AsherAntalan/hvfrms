<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommissaryOrdersOrganizationTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $baguio = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $silang = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['commissary_manager', 'commissary_staff', 'branch_manager', 'owner'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name === 'branch_manager' ? 'Baguio Manager' : $name, 'email' => $name.'@orders-ui.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => $name === 'branch_manager' ? $baguio->id : $commissary->id, 'status' => 'active']);
        }
        $item = Item::create(['item_name' => 'Chicken', 'unit' => 'pack', 'price' => 100, 'is_active' => true]);
        $definitions = [
            ['pending', 0, 0, $baguio], ['pending', 0, 0, $silang], ['approved', 0, 0, $baguio], ['preparing', 0, 0, $silang],
            ['preparing', 4, 0, $baguio], ['delivering', 4, 2, $silang], ['delivering', 4, 4, $baguio],
            ['delivered', 4, 4, $silang], ['settled', 4, 4, $baguio], ['cancelled', 4, 0, $silang], ['declined', 0, 0, $baguio],
        ];
        foreach ($definitions as $index => [$status, $prepared, $released, $branch]) {
            $order = Order::create(['order_number' => 'ORD-UI-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT), 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id,
                'status' => $status, 'total_amount' => 400, 'created_at' => now()->subMinutes(20 - $index)]);
            $order->created_at = now()->subMinutes(20 - $index);
            $order->save();
            $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 4, 'unit_price' => 100]);
            if ($prepared) {
                $batch = StockBatch::create(['item_id' => $item->id, 'branch_id' => $commissary->id, 'quantity_in' => $prepared, 'quantity_left' => $prepared - $released,
                    'expiry_date' => now()->addDays(3), 'condition' => 'good', 'received_at' => now(), 'created_by' => $users['commissary_manager']->id]);
                OrderProduction::create(['order_id' => $order->id, 'order_item_id' => $line->id, 'item_id' => $item->id, 'destination_branch_id' => $branch->id,
                    'produced_qty' => $prepared, 'dispatched_qty' => $released, 'stock_batch_id' => $batch->id, 'production_at' => now(), 'expiry_date' => now()->addDays(3),
                    'prepared_by' => $users['commissary_manager']->id, 'qr_code' => OrderProduction::qrFor($order->id, $line->id)]);
            }
        }

        return compact('users', 'baguio', 'silang');
    }

    public function test_manager_work_queues_distinguish_unprepared_partial_release_transit_and_history(): void
    {
        $w = $this->world();
        $before = Order::pluck('status', 'id')->all();
        $response = $this->actingAs($w['users']['commissary_manager'])->get('/orders')->assertOk();
        $response->assertViewHas('organizeCommissaryOrders', true)->assertViewHas('defaultOrderQueue', 'review')
            ->assertViewHas('orderQueueCounts', ['review' => 2, 'prepare' => 2, 'release' => 2, 'transit' => 1, 'history' => 4, 'all' => 11]);
        $response->assertViewHas('orders', function ($orders) {
            return $orders->firstWhere('order_number', 'ORD-UI-005')->work_queue === 'release'
                && $orders->firstWhere('order_number', 'ORD-UI-006')->work_queue === 'transit'
                && $orders->firstWhere('order_number', 'ORD-UI-009')->work_queue === 'history';
        });
        $response->assertSee('Approve Request')->assertSee('Prepare / Pick')->assertSee('Release / Scan Out')->assertSee('Print Labels')
            ->assertSee('id="orderSort"', false)->assertSee('data-search="ord-ui-000 baguio baguio manager"', false);
        $this->assertSame($before, Order::pluck('status', 'id')->all());
        $this->capture('manager', $response->getContent());
    }

    public function test_staff_starts_with_release_queue_without_manager_actions_or_order_creation(): void
    {
        $w = $this->world();
        $response = $this->actingAs($w['users']['commissary_staff'])->get('/orders')->assertOk()->assertViewHas('defaultOrderQueue', 'release');
        $response->assertSee('Release / Scan Out')->assertDontSee('onclick="openOrderModal()"', false)->assertDontSee('onclick="reviewCommissaryOrder(this)"', false)
            ->assertDontSee('onclick="openPiecePrepare(this)"', false)->assertDontSee('>Print Labels</a>', false);
        $this->capture('staff', $response->getContent());
    }

    public function test_branch_manager_orders_remain_scoped_and_owner_list_keeps_existing_mode(): void
    {
        $w = $this->world();
        $this->actingAs($w['users']['branch_manager'])->get('/orders')->assertOk()->assertViewHas('organizeCommissaryOrders', false)
            ->assertViewHas('orders', fn ($orders) => $orders->every(fn ($order) => $order->branch_id === $w['baguio']->id))
            ->assertDontSee('data-order-queue=', false)->assertSee('onclick="openOrderModal()"', false);
        // Owner/Admin get a simple Active / History / All grouping (commissary
        // staff keep the granular operational queues).
        $this->actingAs($w['users']['owner'])->get('/orders')->assertOk()->assertViewHas('organizeCommissaryOrders', true)
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 11)
            ->assertViewHas('orderQueues', fn ($q) => array_keys($q) === ['active', 'history', 'all'])
            ->assertSee('data-order-queue=', false);
    }

    public function test_default_queue_advances_to_remaining_work_and_history(): void
    {
        $w = $this->world();
        Order::where('status', 'pending')->update(['status' => 'declined']);
        $this->actingAs($w['users']['commissary_manager'])->get('/orders')->assertOk()->assertViewHas('defaultOrderQueue', 'prepare');
        Order::query()->update(['status' => 'settled']);
        $this->get('/orders')->assertOk()->assertViewHas('defaultOrderQueue', 'history');
        $this->actingAs($w['users']['branch_manager'])->get('/orders')->assertOk()->assertDontSee('data-order-queue=', false);
    }

    public function test_empty_commissary_orders_page_keeps_filters_and_zero_counts(): void
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $role = Role::firstOrCreate(['role_name' => 'commissary_manager'], ['description' => 'Manager']);
        $manager = User::create(['full_name' => 'Manager', 'email' => 'empty@orders-ui.test', 'password_hash' => Hash::make('password'), 'role_id' => $role->id,
            'branch_id' => $commissary->id, 'status' => 'active']);
        $response = $this->actingAs($manager)->get('/orders')->assertOk()->assertViewHas('defaultOrderQueue', 'all')
            ->assertViewHas('orderQueueCounts', ['review' => 0, 'prepare' => 0, 'release' => 0, 'transit' => 0, 'history' => 0, 'all' => 0]);
        $response->assertSee('No orders yet.')->assertSee('id="filterBranch"', false)->assertSee('id="orderSort"', false);
        $this->capture('empty', $response->getContent());
    }

    private function capture(string $name, string $html): void
    {
        if ($folder = getenv('COMMISSARY_ORDERS_BROWSER_ARTIFACTS')) {
            if (! is_dir($folder)) {
                mkdir($folder, 0777, true);
            }
            file_put_contents($folder.'/'.$name.'.html', $html);
        }
    }
}
