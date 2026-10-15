<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class OrderArchiveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function world(): array
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $otherBranch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager', 'commissary_staff', 'branch_manager', 'branch_staff'] as $roleName) {
            $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);
            $users[$roleName] = User::create(['full_name' => $roleName, 'email' => $roleName.'@archive.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => str_starts_with($roleName, 'branch_') ? $branch->id : $commissary->id, 'status' => 'active']);
        }
        $item = Item::create(['item_name' => 'Archive Buns', 'unit' => 'piece', 'price' => 20, 'is_active' => true]);
        $history = Order::create(['order_number' => 'ORD-HISTORY', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id,
            'status' => 'settled', 'approved_by' => $users['commissary_manager']->id, 'approved_at' => now(), 'total_amount' => 40, 'fulfilled_amount' => 40, 'payment_reference' => '1234567890123',
            'payment_screenshot_path' => 'order-payments/proof.png', 'payment_submitted_at' => now(), 'remarks' => 'Keep original order notes']);
        $active = Order::create(['order_number' => 'ORD-ACTIVE', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id, 'status' => 'pending', 'total_amount' => 40]);
        $foreign = Order::create(['order_number' => 'ORD-FOREIGN', 'branch_id' => $otherBranch->id, 'ordered_by' => $users['owner']->id, 'status' => 'delivered', 'total_amount' => 40]);
        foreach ([$history, $active, $foreign] as $order) {
            OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 2, 'unit_price' => 20]);
        }
        $history->update(['proforma_snapshot' => (new OrderPaymentService)->snapshot($history)]);
        Storage::disk('local')->put($history->payment_screenshot_path, base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
        $batch = StockBatch::create(['item_id' => $item->id, 'branch_id' => $branch->id, 'quantity_in' => 2, 'quantity_left' => 1, 'condition' => 'good', 'expiry_date' => now()->addDays(3), 'received_at' => now()]);
        OrderProduction::create(['order_id' => $history->id, 'order_item_id' => $history->orderItems()->first()->id, 'item_id' => $item->id,
            'destination_branch_id' => $branch->id, 'produced_qty' => 2, 'dispatched_qty' => 2, 'stock_batch_id' => $batch->id,
            'production_at' => now(), 'expiry_date' => now()->addDays(3), 'prepared_by' => $users['commissary_manager']->id,
            'qr_code' => 'HV-ARCHIVE-PRODUCTION']);

        return compact('commissary', 'branch', 'otherBranch', 'users', 'item', 'history', 'active', 'foreign', 'batch');
    }

    private function changeArchive(User $user, Order $order, bool $restore = false)
    {
        return $this->actingAs($user)->postJson('/orders/api', ['action' => $restore ? 'restore_order' : 'archive_order', 'id' => $order->id]);
    }

    public function test_terminal_orders_can_be_archived_and_restored_without_changing_records_or_inventory(): void
    {
        $world = $this->world();
        $lineBefore = DB::table('order_items')->get()->toJson();
        $batchesBefore = DB::table('stock_batches')->get()->toJson();
        $productionBefore = DB::table('order_productions')->get()->toJson();
        $proofBefore = Storage::disk('local')->get($world['history']->payment_screenshot_path);
        foreach (Order::HISTORY_STATUSES as $status) {
            $world['history']->update(['status' => $status]);
            $this->changeArchive($world['users']['owner'], $world['history'])->assertOk()->assertJson(['ok' => true, 'already' => false]);
            $order = $world['history']->fresh();
            $this->assertSame($status, $order->status);
            $this->assertNotNull($order->archived_at);
            $this->assertSame($world['users']['owner']->id, $order->archived_by);
            $this->assertSame('40.00', $order->total_amount);
            $this->assertSame('1234567890123', $order->payment_reference);
            $this->assertSame('Keep original order notes', $order->remarks);
            $this->changeArchive($world['users']['owner'], $order, true)->assertOk()->assertJson(['ok' => true, 'already' => false]);
            $this->assertNull($order->fresh()->archived_at);
            $this->assertNull($order->fresh()->archived_by);
        }
        $this->assertSame($lineBefore, DB::table('order_items')->get()->toJson());
        $this->assertSame($batchesBefore, DB::table('stock_batches')->get()->toJson());
        $this->assertSame($productionBefore, DB::table('order_productions')->get()->toJson());
        $this->assertSame($proofBefore, Storage::disk('local')->get($world['history']->payment_screenshot_path));
        $this->assertDatabaseCount('stock_transactions', 0);
        $this->assertDatabaseCount('orders', 3);
    }

    public function test_pending_approved_preparing_and_in_transit_orders_cannot_be_archived(): void
    {
        $world = $this->world();
        foreach (['pending', 'approved', 'preparing', 'delivering'] as $status) {
            $world['active']->update(['status' => $status]);
            $this->changeArchive($world['users']['owner'], $world['active'])->assertUnprocessable()->assertJson(['ok' => false]);
            $this->assertNull($world['active']->fresh()->archived_at);
        }
        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_owner_admin_super_admin_commissary_manager_and_own_branch_manager_can_manage_archives(): void
    {
        $world = $this->world();
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager', 'branch_manager'] as $role) {
            $this->changeArchive($world['users'][$role], $world['history'])->assertOk();
            $this->assertSame($world['users'][$role]->id, $world['history']->fresh()->archived_by);
            $this->changeArchive($world['users'][$role], $world['history'], true)->assertOk();
        }
        foreach (['commissary_staff', 'branch_staff'] as $role) {
            $this->changeArchive($world['users'][$role], $world['history'])->assertForbidden();
            $this->changeArchive($world['users'][$role], $world['history'], true)->assertForbidden();
        }
        $this->assertNull($world['history']->fresh()->archived_at);
    }

    public function test_branch_scope_cannot_be_bypassed_for_archiving_restoring_or_archive_list(): void
    {
        $world = $this->world();
        $manager = $world['users']['branch_manager'];
        $this->actingAs($manager)->postJson('/orders/api?branch='.$world['otherBranch']->id, ['action' => 'archive_order', 'id' => $world['foreign']->id])->assertForbidden();
        $this->changeArchive($world['users']['owner'], $world['foreign'])->assertOk();
        $this->changeArchive($manager, $world['foreign'], true)->assertForbidden();
        $this->get('/orders?archived=1&branch='.$world['otherBranch']->id)->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->isEmpty())->assertViewHas('archivedOrderCount', 0)->assertDontSee('ORD-FOREIGN');
        $this->assertNotNull($world['foreign']->fresh()->archived_at);
    }

    public function test_archived_orders_leave_normal_lists_and_counts_and_are_listed_separately_with_restore(): void
    {
        $world = $this->world();
        $this->changeArchive($world['users']['owner'], $world['history'])->assertOk()->assertJsonPath('redirect_url', route('orders', ['queue' => 'history']));
        $normal = $this->get('/orders?queue=history')->assertOk()->assertViewHas('defaultOrderQueue', 'history')->assertViewHas('archivedOrderCount', 1)
            ->assertViewHas('orderQueueCounts', ['active' => 1, 'history' => 1, 'all' => 2]);
        $normal->assertDontSee('ORD-HISTORY')->assertSee('ORD-ACTIVE')->assertSee('ORD-FOREIGN')->assertSee('Archived Orders');
        $archived = $this->get('/orders?archived=1')->assertOk()->assertViewHas('showArchivedOrders', true)
            ->assertViewHas('orders', fn ($orders) => $orders->count() === 1 && $orders->first()->id === $world['history']->id);
        $archived->assertSee('ORD-HISTORY')->assertDontSee('ORD-ACTIVE')->assertDontSee('ORD-FOREIGN')
            ->assertSee('data-order-archive="restore"', false)->assertSee('Back to Order History')->assertDontSee('onclick="openOrderModal()"', false)
            ->assertDontSee('> Print Labels</a>', false);
        $this->capture('owner-archive', $archived->getContent());
        $this->capture('owner-history', $normal->getContent());
        $this->changeArchive($world['users']['branch_manager'], $world['history'], true)->assertOk();
        $this->get('/orders')->assertOk()->assertViewHas('archivedOrderCount', 0)->assertSee('ORD-HISTORY');
    }

    public function test_retrying_archive_or_restore_is_idempotent_and_audited_once_per_change(): void
    {
        $world = $this->world();
        $this->changeArchive($world['users']['owner'], $world['history'])->assertOk();
        $archivedAt = $world['history']->fresh()->archived_at->toISOString();
        $this->changeArchive($world['users']['admin'], $world['history'])->assertOk()->assertJson(['already' => true]);
        $this->assertSame($world['users']['owner']->id, $world['history']->fresh()->archived_by);
        $this->assertSame($archivedAt, $world['history']->fresh()->archived_at->toISOString());
        $this->assertSame(1, AuditLog::where('action', 'order archived')->count());
        $this->changeArchive($world['users']['admin'], $world['history'], true)->assertOk();
        $this->changeArchive($world['users']['owner'], $world['history'], true)->assertOk()->assertJson(['already' => true]);
        $this->assertSame(1, AuditLog::where('action', 'order restored from archive')->count());
        $this->assertSame($world['users']['admin']->id, AuditLog::where('action', 'order restored from archive')->first()->user_id);
    }

    public function test_archived_order_details_proforma_payment_proof_and_reporting_remain_available(): void
    {
        $world = $this->world();
        $before = $this->actingAs($world['users']['owner'])->get('/reports')->assertOk()->viewData('orderGrand');
        $this->changeArchive($world['users']['owner'], $world['history'])->assertOk();
        $this->postJson('/orders/api', ['action' => 'view', 'id' => $world['history']->id])->assertOk()
            ->assertJsonPath('order.status', 'settled')->assertJsonPath('order.payment_reference', '1234567890123');
        $this->get(route('orders.proforma', $world['history']))->assertOk()->assertSee('PROFORMA INVOICE')->assertSee('40.00');
        $this->get(route('orders.payment-screenshot', $world['history']))->assertOk()->assertHeader('Content-Type', 'image/png');
        $this->assertSame($before, $this->get('/reports')->assertOk()->viewData('orderGrand'));
        $this->get('/delivery')->assertOk()->assertSee('ORD-HISTORY');
    }

    public function test_commissary_staff_can_read_archives_without_management_actions_and_branch_staff_cannot_access_orders(): void
    {
        $world = $this->world();
        $this->changeArchive($world['users']['owner'], $world['history'])->assertOk();
        $staff = $this->actingAs($world['users']['commissary_staff'])->get('/orders?archived=1')->assertOk()->assertSee('ORD-HISTORY')
            ->assertDontSee('data-order-archive="restore"', false)->assertDontSee('onclick="openOrderModal()"', false);
        $this->capture('staff-archive', $staff->getContent());
        $this->actingAs($world['users']['branch_staff'])->get('/orders?archived=1')->assertForbidden();
    }

    public function test_archive_audit_failure_rolls_back_the_archive_change(): void
    {
        $world = $this->world();
        AuditLog::creating(function () {
            throw new RuntimeException('Simulated audit write failure');
        });
        try {
            $this->changeArchive($world['users']['owner'], $world['history'])->assertStatus(500);
            $this->assertNull($world['history']->fresh()->archived_at);
            $this->assertNull($world['history']->fresh()->archived_by);
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_empty_archive_and_invalid_order_ids_are_handled_without_side_effects(): void
    {
        $world = $this->world();
        $empty = $this->actingAs($world['users']['branch_manager'])->get('/orders?archived=1')->assertOk()->assertSee('No archived orders yet.');
        $this->capture('branch-empty', $empty->getContent());
        foreach (['archive_order', 'restore_order'] as $action) {
            foreach ([null, 'wrong', 0, -1] as $id) {
                $this->postJson('/orders/api', ['action' => $action, 'id' => $id])->assertUnprocessable()->assertJsonValidationErrors('id');
            }
            $this->postJson('/orders/api', ['action' => $action, 'id' => 999999])->assertNotFound();
        }
        $this->assertDatabaseCount('audit_log', 0);
        $this->assertDatabaseCount('orders', 3);
    }

    private function capture(string $name, string $html): void
    {
        if ($folder = getenv('ORDER_ARCHIVE_BROWSER_ARTIFACTS')) {
            file_put_contents($folder.'/'.$name.'.html', $html);
        }
    }
}
