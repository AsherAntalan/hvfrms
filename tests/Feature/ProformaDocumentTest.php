<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderPaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProformaDocumentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    /** @return array<string, mixed> */
    private function world(): array
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true,
            'address' => '123 Session Rd, Baguio', 'contact_number' => '09170000001']);

        $users = [];
        foreach (['owner', 'branch_manager'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => ucfirst(str_replace('_', ' ', $name)), 'email' => $name.'@doc.test',
                'password_hash' => Hash::make('password'), 'role_id' => $role->id,
                'branch_id' => $name === 'branch_manager' ? $branch->id : $commissary->id, 'status' => 'active']);
        }

        // Multiple product categories and units on one order.
        $chicken = Item::create(['item_name' => 'Frozen Chicken', 'unit' => 'pack', 'price' => 200, 'is_active' => true]);
        $sauce = Item::create(['item_name' => 'Sweet Chili Sauce', 'unit' => 'liter', 'price' => 80, 'is_active' => true]);
        $juice = Item::create(['item_name' => 'Lychee Juice', 'unit' => 'liter', 'price' => 60, 'is_active' => true]);
        $bread = Item::create(['item_name' => 'Vietnam Bread', 'unit' => 'piece', 'price' => 18, 'is_active' => true]);

        $total = 5 * 200 + 2 * 80 + 3 * 60 + 4 * 18; // 1412

        $order = Order::create([
            'order_number' => 'ORD-2026-0041', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id,
            'status' => 'approved', 'total_amount' => $total,
            'approved_by' => $users['owner']->id, 'approved_at' => now(),
        ]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $chicken->id, 'quantity' => 5, 'unit_price' => 200]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $sauce->id, 'quantity' => 2, 'unit_price' => 80]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $juice->id, 'quantity' => 3, 'unit_price' => 60]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $bread->id, 'quantity' => 4, 'unit_price' => 18]);

        // Freeze the issued document, mirroring payment submission.
        $order->update(['proforma_snapshot' => app(OrderPaymentService::class)->snapshot($order)]);

        return compact('commissary', 'branch', 'users', 'chicken', 'order', 'total');
    }

    public function test_one_order_with_multiple_categories_renders_one_proforma_with_every_line(): void
    {
        $world = $this->world();

        $page = $this->actingAs($world['users']['owner'])->get(route('orders.proforma', $world['order']))->assertOk();

        // All four product lines, numbered 1..4, with their distinct units, on a single document.
        $page->assertSeeInOrder(['Frozen Chicken', 'Sweet Chili Sauce', 'Lychee Juice', 'Vietnam Bread']);
        foreach (['pack', 'liter', 'piece'] as $unit) {
            $page->assertSee($unit);
        }
        $this->assertSame(1, substr_count($page->getContent(), 'PROFORMA INVOICE'));
        $this->assertSame(4, substr_count($page->getContent(), 'class="center"'));

        // Subtotal and total only; peso-formatted; no invented charges.
        $page->assertSee('Subtotal')->assertSee('Total amount')->assertSee('1,412.00')->assertSee('&#8369;', false);
        $page->assertDontSee('Shipping')->assertDontSee('VAT')->assertDontSee('Tax');
    }

    public function test_proforma_keeps_historical_prices_after_catalog_price_change(): void
    {
        $world = $this->world();

        $world['chicken']->update(['price' => 999]); // catalog price moves after issuance

        $this->actingAs($world['users']['owner'])->get(route('orders.proforma', $world['order']))->assertOk()
            ->assertSee('1,412.00')          // original issued total preserved
            ->assertDontSee('999.00');       // current catalog price never leaks in
    }

    public function test_reference_and_issuance_date_are_stable_across_reprints(): void
    {
        $world = $this->world();
        $url = route('orders.proforma', $world['order']);

        $first = $this->actingAs($world['users']['owner'])->get($url)->assertOk()->assertSee('PF-ORD-2026-0041');
        $issued = $world['order']->fresh()->proforma_snapshot['date'];
        $first->assertSee($issued);

        $second = $this->actingAs($world['users']['owner'])->get($url)->assertOk();
        $second->assertSee('PF-ORD-2026-0041')->assertSee($issued);

        // No new reference or date is minted on reprint.
        $this->assertSame($issued, $world['order']->fresh()->proforma_snapshot['date']);
    }

    public function test_payment_instructions_and_mandatory_terms_render(): void
    {
        $world = $this->world();
        Storage::disk('local')->put('payment-settings/qr.png', 'fake-qr');
        Setting::set('gcash_receiving_qr_path', 'payment-settings/qr.png');
        Setting::set('gcash_account_name', 'HV Commissary');
        Setting::set('gcash_account_number', '09171234567');

        $this->actingAs($world['users']['owner'])->get(route('orders.proforma', $world['order']))->assertOk()
            ->assertSee('Payment instructions')
            ->assertSee('HV Commissary')->assertSee('09171234567')
            ->assertSee(route('payments.gcash-qr'), false)
            ->assertSee('For quotation / payment reference only.')
            ->assertSee('Not an official receipt.');
    }

    public function test_viewing_the_proforma_does_not_mutate_order_payment_or_inventory(): void
    {
        $world = $this->world();
        $before = $world['order']->fresh()->only(['status', 'total_amount', 'approved_by', 'payment_reference', 'fulfilled_amount']);

        $this->actingAs($world['users']['owner'])->get(route('orders.proforma', $world['order']))->assertOk();

        $this->assertSame($before, $world['order']->fresh()->only(['status', 'total_amount', 'approved_by', 'payment_reference', 'fulfilled_amount']));
        $this->assertDatabaseCount('stock_batches', 0);
        $this->assertDatabaseCount('stock_transactions', 0);
    }
}
