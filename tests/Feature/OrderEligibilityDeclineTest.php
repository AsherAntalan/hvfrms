<?php

namespace Tests\Feature;

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
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Eligible-stock order validation (both creation endpoints), and the
 * approver "Decline with required reason" workflow. Real routes.
 */
class OrderEligibilityDeclineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    private function paymentProof(): array
    {
        return [
            'payment_reference' => 'TEST'.strtoupper(bin2hex(random_bytes(8))),
            'payment_screenshot' => UploadedFile::fake()->createWithContent('payment.png', base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+aWQAAAABJRU5ErkJggg==')),
        ];
    }

    private function role(string $n): Role
    {
        return Role::firstOrCreate(['role_name' => $n], ['description' => $n]);
    }

    private function branch(string $n, string $t = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $n, 'branch_type' => $t, 'is_active' => 1]);
    }

    private function user(Role $r, Branch $b, string $e): User
    {
        return User::create(['full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('secret123'), 'role_id' => $r->id, 'branch_id' => $b->id, 'status' => 'active']);
    }

    private function item(string $n = 'Buns', float $price = 18): Item
    {
        return Item::create(['item_name' => $n, 'unit' => 'pack', 'price' => $price, 'reorder_level' => 5, 'is_active' => 1]);
    }

    private function batch(int $itemId, int $branchId, int $qty, ?string $expiry, string $condition = 'good'): StockBatch
    {
        return StockBatch::create(['item_id' => $itemId, 'branch_id' => $branchId, 'quantity_in' => $qty, 'quantity_left' => $qty, 'expiry_date' => $expiry, 'condition' => $condition, 'received_at' => now()]);
    }

    /** @return array{owner:User,bm:User,main:Branch,silang:Branch} */
    private function world(): array
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');

        return [
            'owner' => $this->user($this->role('owner'), $main, 'owner@test.com'),
            'bm' => $this->user($this->role('branch_manager'), $silang, 'bm@test.com'),
            'main' => $main, 'silang' => $silang,
        ];
    }

    private function placeViaOrdering(User $bm, array $cart)
    {
        return $this->actingAs($bm)->postJson('/ordering/api', [...$this->paymentProof(), 'action' => 'place_order', 'cart' => json_encode($cart)]);
    }

    private function createViaOrders(User $owner, int $branchId, array $lines)
    {
        // BranchScope resolves an owner's target branch from the query string.
        return $this->actingAs($owner)->postJson('/orders/api?branch='.$branchId,
            [...$this->paymentProof(), 'action' => 'create', 'branch_id' => $branchId, 'line_items' => json_encode($lines)]);
    }

    // 1. 34 physical, all expired → usable 0; both endpoints reject qty 10.
    // Tanza is a commissary: products are MADE on order, so creation is NOT
    // gated on finished stock. Zero (or only expired) stock is still orderable.
    public function test_commissary_order_allowed_with_zero_finished_stock(): void
    {
        $w = $this->world();
        $buns = $this->item('Buns'); // no batches at all

        $this->placeViaOrdering($w['bm'], [['item_id' => $buns->id, 'quantity' => 10]])->assertOk();
        $this->createViaOrders($w['owner'], $w['silang']->id, [['item_id' => $buns->id, 'quantity' => 10]])->assertOk();
        $this->assertEquals(2, Order::count());
    }

    // 2. Healthy 3 → accept 3, reject 4 on both endpoints.
    // The only numeric policy is the owner-configurable per-line quantity limit.
    public function test_per_line_limit_enforced_both_endpoints(): void
    {
        $w = $this->world();
        Setting::set('order_max_qty_per_line', '3');
        $item = $this->item('Rice'); // no stock needed

        $this->placeViaOrdering($w['bm'], [['item_id' => $item->id, 'quantity' => 4]])->assertStatus(422);
        $this->assertEquals(0, Order::count());

        $this->placeViaOrdering($w['bm'], [['item_id' => $item->id, 'quantity' => 3]])->assertOk();
        $this->assertEquals(1, Order::count());

        $this->createViaOrders($w['owner'], $w['silang']->id, [['item_id' => $item->id, 'quantity' => 4]])->assertStatus(422);
        $this->assertEquals(1, Order::count());

        // Duplicate lines aggregate before the limit (2+2 > 3 rejected).
        $this->createViaOrders($w['owner'], $w['silang']->id, [
            ['item_id' => $item->id, 'quantity' => 2],
            ['item_id' => $item->id, 'quantity' => 2],
        ])->assertStatus(422);
        $this->assertEquals(1, Order::count());
    }

    // 3. Mixed batches: only eligible quantity counts.
    public function test_limit_unlimited_by_default(): void
    {
        $w = $this->world();
        $item = $this->item('Oil'); // default setting '0' = unlimited
        $this->placeViaOrdering($w['bm'], [['item_id' => $item->id, 'quantity' => 9999]])->assertOk();
        $this->assertEquals(1, Order::count());
    }

    // 4. Duplicate lines are aggregated; invalid quantity types rejected.
    public function test_duplicate_lines_and_invalid_quantity(): void
    {
        $w = $this->world();
        Setting::set('order_max_qty_per_line', '3');
        $item = $this->item('Oil');

        // Two lines of 2 each (=4) must not bypass the per-line max of 3.
        $dup = $this->createViaOrders($w['owner'], $w['silang']->id, [
            ['item_id' => $item->id, 'quantity' => 2],
            ['item_id' => $item->id, 'quantity' => 2],
        ]);
        $dup->assertStatus(422);
        $this->assertEquals(0, Order::count());

        // Non-integer quantity rejected, not truncated.
        $this->createViaOrders($w['owner'], $w['silang']->id, [['item_id' => $item->id, 'quantity' => 2.5]])->assertStatus(422);
        $this->assertEquals(0, Order::count());
    }

    // 7. Authorized decline persists metadata, no stock movement, shows in history.
    public function test_authorized_decline_persists_and_has_no_stock_movement(): void
    {
        $w = $this->world();
        $item = $this->item('Noodles');
        $this->batch($item->id, $w['main']->id, 100, now()->addMonths(3)->toDateString());
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'ORD-T-1', 'branch_id' => $w['silang']->id, 'status' => 'pending', 'ordered_by' => $w['bm']->id, 'total_amount' => 180, 'remarks' => 'please rush', 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'item_id' => $item->id, 'quantity' => 10, 'unit_price' => 18]);

        $res = $this->actingAs($w['owner'])->postJson('/orders/api', ['action' => 'decline', 'id' => $orderId, 'reason' => 'Incorrect order']);
        $res->assertOk()->assertJson(['ok' => true]);

        $order = Order::find($orderId);
        $this->assertEquals('declined', $order->status);
        $this->assertEquals('Incorrect order', $order->decline_reason);
        $this->assertEquals($w['owner']->id, $order->declined_by);
        $this->assertNotNull($order->declined_at);
        $this->assertEquals('please rush', $order->remarks); // original remarks preserved
        $this->assertEquals(0, StockTransaction::count());    // no stock movement
        $this->assertEquals(100, StockBatch::where('branch_id', $w['main']->id)->value('quantity_left'));
        $this->assertDatabaseHas('audit_log', ['action' => 'order declined', 'record_id' => $orderId]);

        // Appears in delivery history (now a tab on the unified Delivery page).
        $hist = $this->actingAs($w['owner'])->get('/delivery?tab=history');
        $hist->assertOk();
        $hist->assertSee('Declined');
        $hist->assertViewHas('history', fn ($history) => $history->contains('decline_reason', 'Incorrect order'));
    }

    // 8. Empty reason, unauthorized role, and non-pending state all fail with no writes.
    public function test_decline_validation_and_authorization(): void
    {
        $w = $this->world();
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'ORD-T-2', 'branch_id' => $w['silang']->id, 'status' => 'pending', 'ordered_by' => $w['bm']->id, 'total_amount' => 10, 'created_at' => now(), 'updated_at' => now()]);

        // Empty / whitespace reason.
        $this->actingAs($w['owner'])->postJson('/orders/api', ['action' => 'decline', 'id' => $orderId, 'reason' => '   '])->assertStatus(422);
        // Unauthorized role (branch manager).
        $this->actingAs($w['bm'])->postJson('/orders/api', ['action' => 'decline', 'id' => $orderId, 'reason' => 'nope'])->assertStatus(403);

        $this->assertEquals('pending', Order::find($orderId)->status); // unchanged
    }

    // 9. Approve and decline are mutually exclusive terminal transitions.
    public function test_approve_then_decline_is_blocked(): void
    {
        $w = $this->world();
        $item = $this->item('Paste');
        $this->batch($item->id, $w['main']->id, 100, now()->addMonths(3)->toDateString());
        $orderId = DB::table('orders')->insertGetId(['order_number' => 'ORD-T-3', 'branch_id' => $w['silang']->id, 'status' => 'pending', 'ordered_by' => $w['bm']->id, 'total_amount' => 18, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $orderId, 'item_id' => $item->id, 'quantity' => 1, 'unit_price' => 18]);

        $this->actingAs($w['owner'])->postJson('/orders/api', ['action' => 'update_status', 'id' => $orderId, 'status' => 'approved'])->assertOk();
        // Now approved → decline must fail (409) and not alter the order.
        $this->actingAs($w['owner'])->postJson('/orders/api', ['action' => 'decline', 'id' => $orderId, 'reason' => 'too late'])->assertStatus(409);
        $this->assertEquals('approved', Order::find($orderId)->status);

        // A declined order cannot then be approved.
        $other = DB::table('orders')->insertGetId(['order_number' => 'ORD-T-4', 'branch_id' => $w['silang']->id, 'status' => 'pending', 'ordered_by' => $w['bm']->id, 'total_amount' => 18, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('order_items')->insert(['order_id' => $other, 'item_id' => $item->id, 'quantity' => 1, 'unit_price' => 18]);
        $this->actingAs($w['owner'])->postJson('/orders/api', ['action' => 'decline', 'id' => $other, 'reason' => 'Incorrect order'])->assertOk();
        $this->actingAs($w['owner'])->postJson('/orders/api', ['action' => 'update_status', 'id' => $other, 'status' => 'approved'])->assertStatus(422);
        $this->assertEquals('declined', Order::find($other)->status);
    }
}
