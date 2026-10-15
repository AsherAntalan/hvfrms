<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
use Tests\TestCase;

class DeclinedOrderNotificationTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $other = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager', 'commissary_staff', 'branch_manager', 'branch_staff'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@decline.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => str_starts_with($name, 'branch_') ? $branch->id : $commissary->id, 'status' => 'active']);
        }
        $outsider = User::create(['full_name' => 'Silang Manager', 'email' => 'other@decline.test', 'password_hash' => Hash::make('password'),
            'role_id' => $users['branch_manager']->role_id, 'branch_id' => $other->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Chicken', 'unit' => 'pack', 'price' => 20, 'is_active' => true]);
        $order = Order::create(['order_number' => 'ORD-NOTICE-1', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id,
            'status' => 'pending', 'total_amount' => 60, 'remarks' => 'Retain my request']);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 3, 'unit_price' => 20]);
        StockBatch::create(['item_id' => $item->id, 'branch_id' => $commissary->id, 'quantity_in' => 10, 'quantity_left' => 10,
            'condition' => 'good', 'expiry_date' => now()->addDays(5), 'received_at' => now()]);

        return compact('commissary', 'branch', 'other', 'users', 'outsider', 'order');
    }

    private function decline(User $user, Order $order, string $reason = 'Incorrect requested quantity')
    {
        return $this->actingAs($user)->postJson('/orders/api', ['action' => 'decline', 'id' => $order->id, 'reason' => $reason]);
    }

    public function test_commissary_decline_notifies_destination_branch_even_when_total_count_does_not_increase(): void
    {
        $world = $this->world();
        $reason = 'Incorrect quantity <img src=x onerror=alert(1)>; please order 2 packs.';
        $before = $this->actingAs($world['users']['branch_manager'])->getJson('/notifications')->assertOk()->assertJsonPath('total', 1);
        $before->assertJsonPath('items.0.type', 'delivery');
        $this->decline($world['users']['commissary_manager'], $world['order'], $reason)->assertOk();
        $alert = Alert::where('order_id', $world['order']->id)->firstOrFail();
        $this->assertSame($world['branch']->id, $alert->branch_id);
        $this->assertSame('operational', $alert->alert_type);
        $this->assertFalse($alert->is_resolved);
        $after = $this->actingAs($world['users']['branch_manager'])->getJson('/notifications')->assertOk()
            ->assertJsonPath('total', 1)->assertJsonPath('sidebar.orders', 0)->assertJsonPath('sidebar.alerts', 1)
            ->assertJsonPath('items.0.id', 'alert-'.$alert->id)->assertJsonPath('items.0.type', 'order_declined')
            ->assertJsonPath('items.0.context', 'Baguio')->assertJsonPath('items.0.message', 'Order ORD-NOTICE-1 was declined. Reason: '.$reason);
        $this->assertNotSame($before->json('items.0.id'), $after->json('items.0.id'));
        $this->getJson('/alerts/count')->assertOk()->assertJsonPath('count', 1);
        $link = $after->json('items.0.link');
        $page = $this->get($link)->assertOk()->assertViewHas('openOrderId', $world['order']->id);
        $details = $this->postJson('/orders/api', ['action' => 'view', 'id' => $world['order']->id])->assertOk()->assertJsonPath('order.decline_reason', $reason);
        if ($folder = getenv('DECLINE_NOTIFICATION_BROWSER_ARTIFACTS')) {
            file_put_contents($folder.'/before.json', $before->getContent());
            file_put_contents($folder.'/after.json', $after->getContent());
            file_put_contents($folder.'/details.json', $details->getContent());
            file_put_contents($folder.'/branch.html', $page->getContent());
        }
    }

    public function test_notifications_are_branch_scoped_and_follow_destination_not_encoding_user(): void
    {
        $world = $this->world();
        $world['order']->update(['ordered_by' => $world['users']['commissary_manager']->id]);
        $this->decline($world['users']['owner'], $world['order'])->assertOk();
        foreach ([$world['outsider'], $world['users']['commissary_manager'], $world['users']['commissary_staff']] as $user) {
            $this->actingAs($user)->getJson('/notifications?branch='.$world['branch']->id)->assertOk()
                ->assertJsonPath('total', 0)->assertJsonCount(0, 'items');
        }
        $this->actingAs($world['users']['branch_manager'])->getJson('/notifications')->assertOk()->assertJsonPath('items.0.type', 'order_declined');
        $this->actingAs($world['users']['owner'])->getJson('/notifications')->assertOk()->assertJsonPath('items.0.type', 'order_declined');
        $this->actingAs($world['outsider'])->get('/orders?order='.$world['order']->id)->assertOk()->assertViewHas('openOrderId', 0);
        $this->actingAs($world['users']['branch_staff'])->getJson('/notifications')->assertForbidden();
    }

    public function test_owner_admin_super_admin_and_commissary_manager_declines_each_create_one_notice(): void
    {
        $world = $this->world();
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager'] as $index => $role) {
            $order = Order::create(['order_number' => 'ORD-ACTOR-'.$index, 'branch_id' => $world['branch']->id,
                'ordered_by' => $world['users']['branch_manager']->id, 'status' => 'pending', 'total_amount' => 60]);
            $this->decline($world['users'][$role], $order)->assertOk();
            $this->assertSame(1, Alert::where('order_id', $order->id)->count());
            $this->assertSame($world['users'][$role]->id, $order->fresh()->declined_by);
        }
        $this->assertDatabaseCount('alerts', 4);
    }

    public function test_invalid_unauthorized_and_nonpending_declines_do_not_create_notifications(): void
    {
        $world = $this->world();
        foreach ([$world['users']['branch_manager'], $world['users']['commissary_staff']] as $user) {
            $this->decline($user, $world['order'])->assertForbidden();
        }
        foreach (['', '   ', str_repeat('x', 501)] as $reason) {
            $this->decline($world['users']['commissary_manager'], $world['order'], $reason)->assertUnprocessable();
        }
        foreach (['approved', 'preparing', 'delivering', 'delivered', 'settled', 'cancelled'] as $status) {
            $world['order']->update(['status' => $status]);
            $this->decline($world['users']['commissary_manager'], $world['order'])->assertConflict();
        }
        $this->assertDatabaseCount('alerts', 0);
        $this->assertDatabaseCount('audit_log', 0);
    }

    public function test_retrying_decline_creates_no_duplicate_notice_and_polling_preserves_notice(): void
    {
        $world = $this->world();
        $this->decline($world['users']['commissary_manager'], $world['order'])->assertOk();
        $this->decline($world['users']['owner'], $world['order'], 'Different reason')->assertConflict();
        $this->assertDatabaseCount('alerts', 1);
        $this->assertSame('Incorrect requested quantity', $world['order']->fresh()->decline_reason);
        $notice = $this->actingAs($world['users']['branch_manager'])->getJson('/notifications')->assertOk()->json('items.0');
        $this->getJson('/notifications')->assertOk()->assertJsonPath('items.0.id', $notice['id'])->assertJsonPath('items.0.message', $notice['message']);
        $this->assertFalse(Alert::firstOrFail()->is_resolved);
    }

    public function test_decline_notice_is_visible_even_with_many_critical_inventory_alerts(): void
    {
        $world = $this->world();
        for ($index = 0; $index < 8; $index++) {
            Alert::create(['alert_type' => 'low_stock', 'branch_id' => $world['branch']->id, 'severity' => 'critical', 'message' => 'Low stock '.$index]);
        }
        $this->decline($world['users']['commissary_manager'], $world['order'])->assertOk();
        $this->actingAs($world['users']['branch_manager'])->getJson('/notifications')->assertOk()
            ->assertJsonPath('items.0.type', 'order_declined')->assertJsonPath('total', 9)->assertJsonCount(6, 'items');
    }

    public function test_full_long_decline_reason_is_available_without_exceeding_alert_message_column(): void
    {
        $world = $this->world();
        $reason = str_repeat('x', 495).' END!';
        $this->assertSame(500, mb_strlen($reason));
        $this->decline($world['users']['commissary_manager'], $world['order'], $reason)->assertOk();
        $this->assertLessThanOrEqual(500, mb_strlen(Alert::firstOrFail()->message));
        $this->actingAs($world['users']['branch_manager'])->getJson('/notifications')->assertOk()
            ->assertJsonPath('items.0.message', 'Order ORD-NOTICE-1 was declined. Reason: '.$reason);
    }

    public function test_branch_can_resolve_own_notice_but_another_branch_cannot(): void
    {
        $world = $this->world();
        $this->decline($world['users']['commissary_manager'], $world['order'])->assertOk();
        $alert = Alert::firstOrFail();
        $this->actingAs($world['outsider'])->post('/alerts', ['post_action' => 'resolve', 'resolve_id' => $alert->id])->assertRedirect();
        $this->assertFalse($alert->fresh()->is_resolved);
        $this->actingAs($world['users']['branch_manager'])->post('/alerts', ['post_action' => 'resolve', 'resolve_id' => $alert->id])->assertRedirect();
        $this->getJson('/notifications')->assertOk()->assertJsonPath('total', 0)->assertJsonCount(0, 'items');
        $this->assertTrue($alert->fresh()->is_resolved);
    }

    public function test_archived_declined_order_notice_opens_the_archived_order_details(): void
    {
        $world = $this->world();
        $this->decline($world['users']['commissary_manager'], $world['order'])->assertOk();
        $this->postJson('/orders/api', ['action' => 'archive_order', 'id' => $world['order']->id])->assertOk();
        $response = $this->actingAs($world['users']['branch_manager'])->getJson('/notifications')->assertOk();
        $this->assertStringContainsString('archived=1', $response->json('items.0.link'));
        $this->get($response->json('items.0.link'))->assertOk()->assertViewHas('showArchivedOrders', true)->assertViewHas('openOrderId', $world['order']->id);
        $this->assertDatabaseCount('alerts', 1);
    }

    public function test_failed_notification_or_audit_creation_rolls_back_decline_and_notification_without_stock_changes(): void
    {
        $world = $this->world();
        $stockBefore = DB::table('stock_batches')->get()->toJson();
        foreach ([Alert::class, AuditLog::class] as $model) {
            $model::creating(function () {
                throw new RuntimeException('Simulated notification transaction failure');
            });
            try {
                $this->decline($world['users']['commissary_manager'], $world['order'])->assertStatus(500);
                $this->assertSame('pending', $world['order']->fresh()->status);
                $this->assertNull($world['order']->fresh()->decline_reason);
                $this->assertDatabaseCount('alerts', 0);
                $this->assertDatabaseCount('audit_log', 0);
            } finally {
                $model::flushEventListeners();
            }
        }
        $this->assertSame($stockBefore, DB::table('stock_batches')->get()->toJson());
        $this->assertDatabaseCount('stock_transactions', 0);
        $this->assertSame('60.00', $world['order']->fresh()->total_amount);
        $this->assertSame('Retain my request', $world['order']->fresh()->remarks);
    }
}
