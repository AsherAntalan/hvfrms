<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\TestCase;

class OrderPaymentProofTest extends TestCase
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
        foreach (['owner', 'admin', 'commissary_manager', 'commissary_staff', 'branch_manager', 'branch_staff'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@payments.test',
                'password_hash' => Hash::make('password'), 'role_id' => $role->id, 'status' => 'active',
                'branch_id' => str_starts_with($name, 'branch_') ? $branch->id : $commissary->id]);
        }
        $outsider = User::create(['full_name' => 'Other manager', 'email' => 'other@payments.test',
            'password_hash' => Hash::make('password'), 'role_id' => $users['branch_manager']->role_id,
            'branch_id' => $other->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Chicken', 'unit' => 'pack', 'price' => 123.45, 'pack_description' => '600 grams', 'is_active' => true]);

        return compact('commissary', 'branch', 'other', 'users', 'outsider', 'item');
    }

    private function screenshot(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('payment.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg=='));
    }

    private function payload(array $world, string $endpoint, array $payment = []): array
    {
        $lines = json_encode([['item_id' => $world['item']->id, 'quantity' => 3, 'unit_price' => 0.01]]);

        return $payment + ($endpoint === 'ordering' ? ['action' => 'place_order', 'cart' => $lines] : [
            'action' => 'create', 'destination_branch_id' => $world['branch']->id, 'line_items' => $lines,
        ]);
    }

    private function createOrder(array $world, string $endpoint = 'ordering'): Order
    {
        $payment = ['payment_reference' => '1234567890123', 'payment_screenshot' => $this->screenshot()];
        $this->actingAs($world['users']['branch_manager'])->postJson('/'.$endpoint.'/api', $this->payload($world, $endpoint, $payment))
            ->assertOk()->assertJson(['ok' => true])->assertJsonStructure(['proforma_url']);

        return Order::latest('id')->firstOrFail();
    }

    public function test_both_order_endpoints_require_a_reference_and_actual_uploaded_screenshot(): void
    {
        $world = $this->world();
        foreach (['ordering', 'orders'] as $endpoint) {
            foreach ([[], ['payment_reference' => '1234567890123'], ['payment_screenshot' => $this->screenshot()]] as $payment) {
                $res = $this->actingAs($world['users']['branch_manager'])->postJson('/'.$endpoint.'/api', $this->payload($world, $endpoint, $payment));
                $res->assertUnprocessable();
                $res->assertJsonValidationErrors(isset($payment['payment_reference']) ? 'payment_screenshot' : 'payment_reference');
            }
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertDatabaseCount('order_items', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_fake_images_svg_oversize_files_and_invalid_references_are_rejected(): void
    {
        $world = $this->world();
        $files = [
            UploadedFile::fake()->createWithContent('receipt.png', '<?php echo "not an image";')->mimeType('text/plain'),
            UploadedFile::fake()->createWithContent('receipt.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'),
            $this->screenshot()->size(5121),
        ];
        foreach (['ordering', 'orders'] as $endpoint) {
            foreach ($files as $file) {
                $this->actingAs($world['users']['branch_manager'])->postJson('/'.$endpoint.'/api', $this->payload($world, $endpoint, [
                    'payment_reference' => '1234567890123', 'payment_screenshot' => $file,
                ]))->assertUnprocessable()->assertJsonValidationErrors('payment_screenshot');
            }
            foreach (['123', '../anything', str_repeat('1', 41), ['1234567890123']] as $reference) {
                $this->postJson('/'.$endpoint.'/api', $this->payload($world, $endpoint, [
                    'payment_reference' => $reference, 'payment_screenshot' => $this->screenshot(),
                ]))->assertUnprocessable()->assertJsonValidationErrors('payment_reference');
            }
        }
        $this->assertDatabaseCount('orders', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_valid_payment_creates_pending_order_with_server_prices_private_proof_and_invoice_snapshot(): void
    {
        $world = $this->world();
        foreach (['ordering', 'orders'] as $index => $endpoint) {
            $ref = (string) (1234567890123 + $index);
            $res = $this->actingAs($world['users']['branch_manager'])->postJson('/'.$endpoint.'/api', $this->payload($world, $endpoint, [
                'payment_reference' => $ref, 'payment_screenshot' => $this->screenshot(),
            ]))->assertOk()->assertJson(['ok' => true]);
            $order = Order::findOrFail($res->json('order_id'));
            $this->assertSame('pending', $order->status);
            $this->assertSame('370.35', $order->total_amount);
            $this->assertSame($ref, $order->payment_reference);
            $this->assertNotNull($order->payment_submitted_at);
            Storage::disk('local')->assertExists($order->payment_screenshot_path);
            $this->assertStringStartsWith('order-payments/', $order->payment_screenshot_path);
            $this->assertSame('Chicken', $order->proforma_snapshot['lines'][0]['name']);
            $this->assertSame(3, $order->proforma_snapshot['lines'][0]['quantity']);
            $res->assertJsonPath('proforma_url', null);
            $this->get(route('orders.proforma', $order))->assertForbidden();
            if ($index === 0 && ($folder = getenv('PAYMENT_BROWSER_ARTIFACTS'))) {
                file_put_contents($folder.'/ordering.html', $this->get('/ordering')->getContent());
                file_put_contents($folder.'/orders-branch.html', $this->get('/orders')->getContent());
                file_put_contents($folder.'/orders-owner.html', $this->actingAs($world['users']['owner'])->get('/orders')->getContent());
                file_put_contents($folder.'/settings.html', $this->get('/settings')->getContent());
            }

        }
        $this->assertSame(0, StockBatch::count());
        $this->assertSame(0, StockTransaction::count());
    }

    public function test_reference_cannot_be_reused_across_endpoints_or_branches_even_with_spacing(): void
    {
        $world = $this->world();
        $this->createOrder($world);
        $this->actingAs($world['outsider'])->postJson('/ordering/api', $this->payload($world, 'ordering', [
            'payment_reference' => '1234-5678 90123', 'payment_screenshot' => $this->screenshot(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('payment_reference');
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', $this->payload($world, 'orders', [
            'payment_reference' => '1234567890123', 'payment_screenshot' => $this->screenshot(),
        ]))->assertUnprocessable()->assertJsonValidationErrors('payment_reference');
        $this->assertDatabaseCount('orders', 1);
        $this->assertCount(1, Storage::disk('local')->allFiles('order-payments'));
    }

    public function test_invoice_retains_original_names_units_prices_and_branch_after_catalog_changes(): void
    {
        $world = $this->world();
        $order = $this->createOrder($world);
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'review_order', 'id' => $order->id])->assertOk();
        $world['item']->update(['item_name' => 'New name', 'unit' => 'kilogram', 'price' => 999]);
        $world['branch']->update(['branch_name' => 'Renamed branch']);
        Setting::set('app_name', 'Changed app');
        $order->update(['fulfilled_amount' => 246.90, 'status' => 'settled']);
        $this->get(route('orders.proforma', $order))->assertOk()->assertSee('Chicken')->assertSee('Baguio')
            ->assertSee('pack')->assertSee('123.45')->assertSee('370.35')->assertSee('246.90')->assertSee('window.print()', false)
            ->assertSee('not a payment receipt')->assertSee('for manual review')->assertDontSee('Renamed branch')->assertDontSee('New name')->assertDontSee('Changed app');
    }

    public function test_proof_and_invoice_are_private_to_branch_manager_owner_admin_and_commissary_manager(): void
    {
        $world = $this->world();
        $order = $this->createOrder($world);
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'review_order', 'id' => $order->id])->assertOk();
        foreach (['branch_manager', 'owner', 'admin', 'commissary_manager'] as $role) {
            $this->actingAs($world['users'][$role])->get(route('orders.proforma', $order))->assertOk();
            $this->get(route('orders.payment-screenshot', $order))->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff');
        }
        foreach ([$world['outsider'], $world['users']['commissary_staff'], $world['users']['branch_staff']] as $user) {
            $this->actingAs($user)->get(route('orders.proforma', $order))->assertForbidden();
            $this->get(route('orders.payment-screenshot', $order))->assertForbidden();
        }
        $res = $this->actingAs($world['users']['commissary_staff'])->postJson('/orders/api', ['action' => 'view', 'id' => $order->id])->assertOk();
        $res->assertJsonPath('order.payment_reference', null)->assertJsonPath('order.payment_screenshot_url', null);
        $this->actingAs($world['users']['owner'])->postJson('/orders/api', ['action' => 'view', 'id' => $order->id])
            ->assertOk()->assertJsonPath('order.payment_reference', '1234567890123');
        auth()->logout();
        $this->get(route('orders.payment-screenshot', $order))->assertRedirect(route('login'));
    }

    public function test_failed_order_audit_rolls_back_order_lines_and_removes_stored_proof(): void
    {
        $world = $this->world();
        AuditLog::creating(function () {
            throw new RuntimeException('Simulated write failure');
        });
        try {
            foreach (['ordering', 'orders'] as $endpoint) {
                $this->actingAs($world['users']['branch_manager'])->postJson('/'.$endpoint.'/api', $this->payload($world, $endpoint, [
                    'payment_reference' => '1234567890123', 'payment_screenshot' => $this->screenshot(),
                ]))->assertStatus(500)->assertJson(['ok' => false]);
                $this->assertDatabaseCount('orders', 0);
                $this->assertDatabaseCount('order_items', 0);
                $this->assertSame([], Storage::disk('local')->allFiles('order-payments'));
            }
        } finally {
            AuditLog::flushEventListeners();
        }
    }

    public function test_only_owner_or_admin_can_configure_confirmed_receiving_qr(): void
    {
        $world = $this->world();
        $data = ['gcash_receiving_qr' => $this->screenshot(), 'gcash_account_name' => 'Test Recipient', 'confirm_receiving_qr' => '1'];
        $this->actingAs($world['users']['commissary_manager'])->post('/settings/gcash-qr', $data)->assertForbidden();
        $this->actingAs($world['users']['owner'])->postJson('/settings/gcash-qr', array_diff_key($data, ['confirm_receiving_qr' => true]))
            ->assertUnprocessable()->assertJsonValidationErrors('confirm_receiving_qr');
        $this->assertSame('', Setting::get('gcash_receiving_qr_path'));
        $this->get('/payments/gcash-qr')->assertNotFound();
        $this->post('/settings/gcash-qr', $data)->assertRedirect(route('settings'));
        $old = Setting::get('gcash_receiving_qr_path');
        Storage::disk('local')->assertExists($old);
        $this->actingAs($world['users']['branch_manager'])->get('/ordering')->assertOk()->assertSee('Test Recipient')->assertSee('payments/gcash-qr');
        $this->get('/payments/gcash-qr')->assertOk()->assertHeader('Content-Type', 'image/png');
        $data['gcash_receiving_qr'] = $this->screenshot();
        $this->actingAs($world['users']['admin'])->post('/settings/gcash-qr', $data)->assertRedirect(route('settings'));
        Storage::disk('local')->assertMissing($old);
        $this->assertNotSame($old, Setting::get('gcash_receiving_qr_path'));
    }

    public function test_existing_orders_remain_readable_without_fabricated_payment_proof(): void
    {
        $world = $this->world();
        $order = Order::create(['order_number' => 'ORD-LEGACY', 'branch_id' => $world['branch']->id,
            'ordered_by' => $world['users']['branch_manager']->id, 'status' => 'pending', 'total_amount' => 0]);
        $this->actingAs($world['users']['owner'])->get(route('orders.proforma', $order))->assertForbidden();
        $this->postJson('/orders/api', ['action' => 'review_order', 'id' => $order->id])->assertOk();
        $this->get(route('orders.proforma', $order))->assertOk()->assertSee('No proof recorded');
        $this->get(route('orders.payment-screenshot', $order))->assertNotFound();
        $this->get('/orders')->assertOk()->assertSee('newOrderPaymentScreenshot')->assertSee('newOrderPaymentReference');
        $this->get('/settings')->assertOk()->assertSee('gcashReceivingQr')->assertSee('confirmReceivingQr');
    }
}
