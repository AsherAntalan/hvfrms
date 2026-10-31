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
        $response->assertSee('Review Order')->assertSee('Prepare / Pick')->assertSee('Release / Scan Out')->assertSee('Print Labels')
            ->assertSee('id="orderSort"', false)->assertSee('data-search="ord-ui-000 baguio baguio manager"', false);
        $this->assertSame($before, Order::pluck('status', 'id')->all());
        $this->capture('manager', $response->getContent());
    }

    public function test_pending_rows_expose_review_order_instead_of_direct_decision_buttons(): void
    {
        $w = $this->world();
        $before = Order::pluck('status', 'id')->all();
        $response = $this->actingAs($w['users']['commissary_manager'])->get('/orders')->assertOk();

        // The pending-order row offers a single Review Order control that opens
        // the details interface; the old direct Approve/Decline row controls are gone.
        $response->assertSee('Review Order')
            ->assertDontSee('onclick="reviewCommissaryOrder(this)"', false);

        // Decision controls live inside the review interface.
        $response->assertSee('approveReviewOrder(', false)
            ->assertSee('declineReviewOrder(', false)
            ->assertSee('Approve Request')
            ->assertSee('Decline Request');

        // Merely loading the list (and thus the review entry point) changes nothing.
        $this->assertSame($before, Order::pluck('status', 'id')->all());
    }

    public function test_staff_starts_with_release_queue_without_manager_actions_or_order_creation(): void
    {
        $w = $this->world();
        $response = $this->actingAs($w['users']['commissary_staff'])->get('/orders')->assertOk()->assertViewHas('defaultOrderQueue', 'release');
        $response->assertSee('Release / Scan Out')->assertDontSee('onclick="openOrderModal()"', false)->assertDontSee('onclick="reviewCommissaryOrder(this)"', false)
            ->assertDontSee('onclick="openPiecePrepare(this)"', false)->assertDontSee('>Print Labels</a>', false);
        $this->capture('staff', $response->getContent());
    }

    public function test_preparation_and_release_rows_offer_one_action_with_supporting_actions_inside(): void
    {
        $world = $this->world();
        $before = Order::pluck('status', 'id')->all();
        $response = $this->actingAs($world['users']['commissary_manager'])->get('/orders')->assertOk();
        $document = new \DOMDocument;
        @$document->loadHTML($response->getContent());
        $xpath = new \DOMXPath($document);

        foreach (['ord-ui-002' => 'Prepare / Pick', 'ord-ui-004' => 'Release', 'ord-ui-005' => 'Release'] as $number => $label) {
            $cell = $xpath->query('//tr[@data-number="'.$number.'"]/td[last()]')->item(0);
            $this->assertNotNull($cell);
            $controls = $xpath->query('.//button | .//a', $cell);
            $this->assertSame(1, $controls->length);
            $this->assertSame($label, trim($controls->item(0)->textContent));
        }

        $modal = $xpath->query('//div[@id="piecePrepareModal"]')->item(0);
        $this->assertNotNull($modal);
        foreach (['View Details', 'Print Proforma', 'Print Labels', 'Cancel Order', 'Release / Scan Out'] as $label) {
            $this->assertStringContainsString($label, $modal->textContent);
        }

        foreach (['commissary_manager', 'commissary_staff'] as $role) {
            $delivery = $this->actingAs($world['users'][$role])->get('/delivery')->assertOk();
            @$document->loadHTML($delivery->getContent());
            $xpath = new \DOMXPath($document);
            $cell = $xpath->query('//tr[td[normalize-space(.)="ORD-UI-004"]]/td[last()]')->item(0);
            $this->assertNotNull($cell);
            $controls = $xpath->query('.//button | .//a', $cell);
            $this->assertSame(1, $controls->length);
            $this->assertSame('Release', trim($controls->item(0)->textContent));
        }

        $this->assertSame($before, Order::pluck('status', 'id')->all());
    }

    public function test_workspace_actions_follow_preparation_dispatch_and_archive_state(): void
    {
        $world = $this->world();
        $manager = $world['users']['commissary_manager'];
        $orders = Order::get()->keyBy('order_number');
        $this->actingAs($manager);

        foreach ([
            'ORD-UI-000' => [false, false, true],
            'ORD-UI-002' => [true, false, true],
            'ORD-UI-004' => [false, true, true],
            'ORD-UI-005' => [false, true, false],
            'ORD-UI-006' => [false, false, false],
            'ORD-UI-008' => [false, false, false],
            'ORD-UI-009' => [false, false, false],
        ] as $number => [$prepare, $release, $cancel]) {
            $this->postJson('/orders/api', ['action' => 'view', 'id' => $orders[$number]->id])->assertOk()
                ->assertJsonPath('order.actions.can_prepare', $prepare)
                ->assertJsonPath('order.actions.can_release', $release)
                ->assertJsonPath('order.actions.can_cancel', $cancel);
        }

        $ready = $orders['ORD-UI-004'];
        $ready->update(['approved_by' => $manager->id, 'approved_at' => now()]);
        $this->postJson('/orders/api', ['action' => 'view', 'id' => $ready->id])->assertOk()
            ->assertJsonPath('order.proforma_url', route('orders.proforma', $ready))
            ->assertJsonPath('order.actions.labels_url', route('orders.labels', $ready));
        $ready->update(['archived_at' => now()]);
        $this->postJson('/orders/api', ['action' => 'view', 'id' => $ready->id])->assertOk()
            ->assertJsonPath('order.actions.can_prepare', false)->assertJsonPath('order.actions.can_release', false)
            ->assertJsonPath('order.actions.can_cancel', false);
    }

    public function test_in_transit_rows_only_offer_details_and_receiving_remains_role_scoped(): void
    {
        $world = $this->world();
        $shipment = Order::where('order_number', 'ORD-UI-006')->firstOrFail();
        $partial = Order::where('order_number', 'ORD-UI-005')->firstOrFail();

        foreach ($world['users'] as $role => $user) {
            foreach (['/orders', '/delivery'] as $path) {
                $response = $this->actingAs($user)->get($path)->assertOk();
                $document = new \DOMDocument;
                @$document->loadHTML($response->getContent());
                $xpath = new \DOMXPath($document);
                $rowQuery = $path === '/orders' ? '//tr[@data-number="ord-ui-006"]' : '//tr[td[normalize-space(.)="ORD-UI-006"]]';
                $cell = $xpath->query($rowQuery.'/td[last()]')->item(0);
                $this->assertNotNull($cell);
                $controls = $xpath->query('.//button | .//a', $cell);
                $this->assertSame(1, $controls->length);
                $this->assertSame('View Details', trim($controls->item(0)->textContent));
            }

            $this->postJson('/orders/api', ['action' => 'view', 'id' => $shipment->id])->assertOk()
                ->assertJsonPath('order.actions.can_receive', in_array($role, ['owner', 'branch_manager'], true));
        }

        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'view', 'id' => $partial->id])->assertOk()
            ->assertJsonPath('order.actions.can_receive', false);
        $this->assertSame('delivering', $shipment->fresh()->status);
    }

    public function test_workspace_actions_respect_staff_document_permissions_and_branch_scope(): void
    {
        $world = $this->world();
        $ready = Order::where('order_number', 'ORD-UI-004')->firstOrFail();
        $other = Order::where('order_number', 'ORD-UI-005')->firstOrFail();
        $this->actingAs($world['users']['commissary_staff'])->postJson('/orders/api', ['action' => 'view', 'id' => $ready->id])->assertOk()
            ->assertJsonPath('order.actions.can_prepare', false)->assertJsonPath('order.actions.can_release', true)
            ->assertJsonPath('order.actions.can_cancel', false)->assertJsonPath('order.actions.labels_url', null)
            ->assertJsonPath('order.proforma_url', null);
        $this->actingAs($world['users']['branch_manager'])->postJson('/orders/api', ['action' => 'view', 'id' => $ready->id])->assertOk()
            ->assertJsonPath('order.actions.can_prepare', false)->assertJsonPath('order.actions.can_release', false)
            ->assertJsonPath('order.actions.can_cancel', true)->assertJsonPath('order.actions.labels_url', null)
            ->assertJsonPath('order.actions.scan_out_url', null);
        $this->postJson('/orders/api', ['action' => 'view', 'id' => $other->id])->assertNotFound();
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

    public function test_orders_and_delivery_share_tabs_and_one_sidebar_entry_for_every_permitted_role(): void
    {
        $world = $this->world();

        foreach ($world['users'] as $role => $user) {
            foreach (['/orders', '/delivery', '/delivery?tab=history', '/delivery?tab=archived'] as $path) {
                $response = $this->actingAs($user)->get($path)->assertOk()
                    ->assertSee('aria-label="Orders views"', false)
                    ->assertSee('data-orders-view="all"', false)
                    ->assertSee('data-orders-view="delivery"', false);

                $html = $response->getContent();
                preg_match('/<aside\b[^>]*id="sidebar"[^>]*>(.*?)<\/aside>/s', $html, $sidebar);
                $this->assertCount(2, $sidebar);
                // Commissary Staff is scanner-only (no Orders sidebar entry); every
                // other permitted role keeps exactly one.
                $expectedOrdersEntries = $role === 'commissary_staff' ? 0 : 1;
                $this->assertSame($expectedOrdersEntries, substr_count($sidebar[1], 'data-sidebar-orders'));
                $this->assertStringNotContainsString('Delivery Status', $sidebar[1]);
                $this->assertStringNotContainsString('All Orders', $sidebar[1]);
                $this->assertSame($role === 'branch_manager', str_contains($html, 'data-orders-view="supplies"'));

                $selectedView = str_starts_with($path, '/delivery') ? 'delivery' : 'all';
                $this->assertMatchesRegularExpression('/data-orders-view="'.$selectedView.'"\s+class="[^"]*active"\s+aria-current="page"/', $html);
            }
        }
    }

    public function test_delivery_status_contains_ready_partial_and_transit_shipments_with_scoped_counts(): void
    {
        $world = $this->world();
        $before = Order::pluck('status', 'id')->all();

        foreach (['owner', 'commissary_manager', 'commissary_staff'] as $role) {
            $this->actingAs($world['users'][$role])->get('/delivery')->assertOk()
                ->assertViewHas('active', fn ($orders) => $orders->count() === 3
                    && $orders->pluck('order_number')->sort()->values()->all() === ['ORD-UI-004', 'ORD-UI-005', 'ORD-UI-006'])
                ->assertViewHas('branches', fn ($branches) => $branches->sum('ready') === 2 && $branches->sum('count') === 3)
                ->assertSee('ready')->assertDontSee('awaiting approval');
        }

        $this->actingAs($world['users']['branch_manager'])->get('/delivery')->assertOk()
            ->assertViewHas('active', fn ($orders) => $orders->count() === 2 && $orders->every(fn ($order) => $order->branch_id === $world['baguio']->id))
            ->assertViewHas('history', fn ($orders) => $orders->every(fn ($order) => $order->branch_id === $world['baguio']->id))
            ->assertViewHas('archived', fn ($orders) => $orders->every(fn ($order) => $order->branch_id === $world['baguio']->id));

        $this->assertSame($before, Order::pluck('status', 'id')->all());
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
