<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProformaApprovalTest extends TestCase
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
        $other = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager', 'commissary_staff', 'branch_manager', 'branch_staff'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@proforma.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => str_starts_with($name, 'branch_') ? $branch->id : $commissary->id, 'status' => 'active']);
        }
        $outsider = User::create(['full_name' => 'Other Manager', 'email' => 'other@proforma.test', 'password_hash' => Hash::make('password'),
            'role_id' => $users['branch_manager']->role_id, 'branch_id' => $other->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Buns', 'unit' => 'piece', 'price' => 18, 'is_active' => true]);
        $order = Order::create(['order_number' => 'ORD-CONFIRM-1', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id, 'status' => 'pending', 'total_amount' => 36]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 2, 'unit_price' => 18]);

        return compact('commissary', 'branch', 'users', 'outsider', 'item', 'order', 'line');
    }

    private function approve(User $user, Order $order): void
    {
        $this->actingAs($user)->postJson('/orders/api', ['action' => 'review_order', 'id' => $order->id])->assertOk();
    }

    public function test_pending_order_proforma_is_blocked_for_every_viewer_and_has_no_print_links(): void
    {
        $world = $this->world();
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager', 'branch_manager'] as $role) {
            $this->actingAs($world['users'][$role])->getJson(route('orders.proforma', $world['order']))->assertForbidden()
                ->assertJsonFragment(['message' => 'Proforma printing is available only after order confirmation by the Owner, Admin, or Commissary Manager.']);
            $this->postJson('/orders/api', ['action' => 'view', 'id' => $world['order']->id])->assertOk()
                ->assertJsonPath('order.proforma_url', null)->assertJsonPath('order.proforma_awaiting_approval', true);
            $this->get('/orders')->assertOk()->assertDontSee('aria-label="Proforma invoice"', false);
        }
        $page = $this->actingAs($world['users']['branch_manager'])->get('/ordering')->assertOk()
            ->assertSee('Proforma available after approval')->assertDontSee('href="'.route('orders.proforma', $world['order']).'"', false);
        $this->capture('pending-ordering', $page->getContent());
        $this->capture('pending-orders', $this->get('/orders')->getContent());
        $this->capture('pending-details', $this->postJson('/orders/api', ['action' => 'view', 'id' => $world['order']->id])->getContent(), 'json');
        $this->assertNull($world['order']->fresh()->approved_by);
        $this->assertNull($world['order']->fresh()->approved_at);
    }

    public function test_owner_admin_super_admin_and_commissary_confirmation_unlocks_the_proforma(): void
    {
        $world = $this->world();
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager'] as $index => $role) {
            $order = Order::create(['order_number' => 'ORD-CONFIRM-ACTOR-'.$index, 'branch_id' => $world['branch']->id,
                'ordered_by' => $world['users']['branch_manager']->id, 'status' => 'pending', 'total_amount' => 36]);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $world['item']->id, 'quantity' => 2, 'unit_price' => 18]);
            $this->approve($world['users'][$role], $order);
            $order->refresh();
            $this->assertSame($world['users'][$role]->id, $order->approved_by);
            $this->assertNotNull($order->approved_at);
            $this->assertTrue($order->isProformaAvailable());
            $invoice = $this->actingAs($world['users']['branch_manager'])->get(route('orders.proforma', $order))->assertOk()
                ->assertSee('PROFORMA INVOICE')->assertSee('Confirmed by')->assertSee('Confirmed on')->assertSee($role)
                ->assertSee('36.00')->assertSee('window.print()', false)->assertSee('not a payment receipt');
            $this->assertStringContainsString('no-store', $invoice->headers->get('Cache-Control'));
            $details = $this->postJson('/orders/api', ['action' => 'view', 'id' => $order->id])->assertOk()
                ->assertJsonPath('order.proforma_url', route('orders.proforma', $order))->assertJsonPath('order.proforma_awaiting_approval', false);
            if ($role === 'commissary_manager') {
                $this->capture('approved-proforma', $invoice->getContent());
                $this->capture('approved-orders', $this->get('/orders')->getContent());
                $this->capture('approved-ordering', $this->get('/ordering')->getContent());
                $this->capture('approved-details', $details->getContent(), 'json');
            }
        }
        $this->assertDatabaseCount('stock_batches', 0);
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_status_alone_without_recorded_approval_does_not_unlock_printing(): void
    {
        $world = $this->world();
        foreach (['approved', 'preparing', 'delivering', 'delivered', 'settled'] as $status) {
            foreach ([[null, null], [$world['users']['owner']->id, null], [null, now()]] as [$by, $at]) {
                $world['order']->update(['status' => $status, 'approved_by' => $by, 'approved_at' => $at]);
                $this->assertFalse($world['order']->fresh()->isProformaAvailable());
                $this->actingAs($world['users']['owner'])->get(route('orders.proforma', $world['order']))->assertForbidden();
            }
        }
    }

    public function test_confirmed_orders_remain_printable_during_delivery_completion_and_archive(): void
    {
        $world = $this->world();
        $this->approve($world['users']['owner'], $world['order']);
        foreach (['approved', 'preparing', 'delivering', 'delivered', 'settled'] as $status) {
            $world['order']->update(['status' => $status]);
            $this->actingAs($world['users']['branch_manager'])->get(route('orders.proforma', $world['order']))->assertOk();
        }
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'archive_order', 'id' => $world['order']->id])->assertOk();
        $this->actingAs($world['users']['branch_manager'])->get(route('orders.proforma', $world['order']))->assertOk();
    }

    public function test_pending_declined_and_cancelled_orders_cannot_print_even_with_old_approval_fields(): void
    {
        $world = $this->world();
        foreach (['pending', 'declined', 'cancelled'] as $status) {
            $world['order']->update(['status' => $status, 'approved_by' => $world['users']['owner']->id, 'approved_at' => now()]);
            $this->actingAs($world['users']['owner'])->get(route('orders.proforma', $world['order']))->assertForbidden();
            $this->postJson('/orders/api', ['action' => 'view', 'id' => $world['order']->id])->assertOk()->assertJsonPath('order.proforma_url', null);
            $this->get('/orders')->assertOk()->assertDontSee('aria-label="Proforma invoice"', false);
            $this->actingAs($world['users']['branch_manager'])->get('/ordering')->assertOk()
                ->assertDontSee('href="'.route('orders.proforma', $world['order']).'"', false);
        }
    }

    public function test_approval_does_not_grant_access_to_other_branches_or_staff(): void
    {
        $world = $this->world();
        $this->approve($world['users']['owner'], $world['order']);
        foreach ([$world['outsider'], $world['users']['branch_staff'], $world['users']['commissary_staff']] as $user) {
            $this->actingAs($user)->get(route('orders.proforma', $world['order']))->assertForbidden();
        }
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager', 'branch_manager'] as $role) {
            $this->actingAs($world['users'][$role])->get(route('orders.proforma', $world['order']))->assertOk();
        }
    }

    public function test_branch_cannot_self_approve_or_submit_approval_fields_to_bypass_proforma_restriction(): void
    {
        $world = $this->world();
        $this->actingAs($world['users']['branch_manager'])->postJson('/orders/api', ['action' => 'review_order', 'id' => $world['order']->id])->assertForbidden();
        foreach (['orders', 'ordering'] as $index => $endpoint) {
            $image = UploadedFile::fake()->createWithContent('payment.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
            $lines = json_encode([['item_id' => $world['item']->id, 'quantity' => 2]]);
            $response = $this->postJson('/'.$endpoint.'/api', [
                'action' => $endpoint === 'orders' ? 'create' : 'place_order', 'cart' => $lines, 'line_items' => $lines,
                'destination_branch_id' => $world['branch']->id, 'payment_reference' => 'TESTCONFIRM'.$index, 'payment_screenshot' => $image,
                'status' => 'approved', 'approved_by' => $world['users']['owner']->id, 'approved_at' => now()->toDateTimeString(),
            ])->assertOk()->assertJsonPath('proforma_url', null);
            $order = Order::findOrFail($response->json('order_id'));
            $this->assertSame('pending', $order->status);
            $this->assertNull($order->approved_by);
            $this->assertNull($order->approved_at);
            $this->get(route('orders.proforma', $order))->assertForbidden();
        }
    }

    public function test_cancelling_an_approved_order_revokes_proforma_access(): void
    {
        $world = $this->world();
        $this->approve($world['users']['owner'], $world['order']);
        $this->get(route('orders.proforma', $world['order']))->assertOk();
        $this->postJson('/orders/api', ['action' => 'cancel', 'id' => $world['order']->id, 'reason' => 'Incorrect order'])->assertOk();
        $this->get(route('orders.proforma', $world['order']))->assertForbidden();
        $this->assertSame('cancelled', $world['order']->fresh()->status);
    }

    private function capture(string $name, string $content, string $extension = 'html'): void
    {
        if ($folder = getenv('PROFORMA_APPROVAL_BROWSER_ARTIFACTS')) {
            file_put_contents($folder.'/'.$name.'.'.$extension, $content);
        }
    }
}
