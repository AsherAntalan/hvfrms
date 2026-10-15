<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Admin (Owner-equivalent), the separate Commissary source location, and the
 * Commissary Manager's ability to encode an order for a chosen branch without
 * the branch.scope middleware hijacking the destination.
 */
class CommissaryRolesLocationTest extends TestCase
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

    private function user(string $role, Branch $b, string $e): User
    {
        return User::create(['full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('secret123'), 'role_id' => $this->role($role)->id, 'branch_id' => $b->id, 'status' => 'active']);
    }

    public function test_admin_has_owner_level_access(): void
    {
        $main = $this->branch('Tanza', 'main');
        $admin = $this->user('admin', $main, 'admin@t.com');

        $this->assertTrue($admin->isOwner());          // owner-level authority
        $this->assertTrue($admin->isAdmin());          // distinct identity
        $this->assertFalse($admin->isBranchScoped());  // all-branch
        // Owner-only route is reachable.
        $this->actingAs($admin)->get('/users')->assertOk();
        $this->actingAs($admin)->get('/branches')->assertOk();
    }

    public function test_commissary_resolver_prefers_configured_then_type_then_main_fallback(): void
    {
        $main = $this->branch('Tanza', 'main');
        $this->assertEquals($main->id, Branch::commissary()->id);     // fallback to main

        $comm = $this->branch('Commissary', 'commissary');
        $this->assertEquals($comm->id, Branch::commissary()->id);     // single commissary type wins

        $comm2 = $this->branch('Commissary 2', 'commissary');
        $this->assertNull(Branch::commissary());                      // ambiguous → refuse to guess

        Setting::set('commissary_branch_id', (string) $comm2->id);
        $this->assertEquals($comm2->id, Branch::commissary()->id);    // explicit config resolves it
    }

    public function test_commissary_manager_encodes_for_a_chosen_branch(): void
    {
        $comm = $this->branch('Commissary', 'commissary');
        $silang = $this->branch('Silang');
        $manager = $this->user('commissary_manager', $comm, 'cm@t.com');
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);

        $res = $this->actingAs($manager)->postJson('/orders/api', [
            ...$this->paymentProof(), 'action' => 'create',
            'destination_branch_id' => $silang->id,
            'line_items' => json_encode([['item_id' => $item->id, 'quantity' => 3]]),
        ]);
        $res->assertOk()->assertJson(['ok' => true]);

        $order = Order::latest('id')->first();
        $this->assertEquals($silang->id, $order->branch_id);   // destination preserved, not the commissary
        $this->assertEquals($manager->id, $order->ordered_by); // encoder recorded as creator
    }

    public function test_commissary_cannot_be_the_destination(): void
    {
        $comm = $this->branch('Commissary', 'commissary');
        $manager = $this->user('commissary_manager', $comm, 'cm@t.com');
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);

        $this->actingAs($manager)->postJson('/orders/api', [
            ...$this->paymentProof(), 'action' => 'create',
            'destination_branch_id' => $comm->id,
            'line_items' => json_encode([['item_id' => $item->id, 'quantity' => 1]]),
        ])->assertStatus(422);
    }

    public function test_branch_manager_cannot_encode_for_another_branch(): void
    {
        $comm = $this->branch('Commissary', 'commissary');
        $silang = $this->branch('Silang');
        $dasma = $this->branch('Dasma');
        $bm = $this->user('branch_manager', $silang, 'bm@t.com');
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);

        $this->actingAs($bm)->postJson('/orders/api', [
            ...$this->paymentProof(), 'action' => 'create',
            'destination_branch_id' => $dasma->id,
            'line_items' => json_encode([['item_id' => $item->id, 'quantity' => 1]]),
        ])->assertStatus(403);
    }

    public function test_tanza_main_store_can_request_from_commissary(): void
    {
        $comm = $this->branch('Commissary', 'commissary');
        $tanza = $this->branch('Tanza', 'main');
        $tbm = $this->user('branch_manager', $tanza, 'tbm@t.com');
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);

        $res = $this->actingAs($tbm)->postJson('/orders/api', [
            ...$this->paymentProof(), 'action' => 'create',
            'destination_branch_id' => $tanza->id,
            'line_items' => json_encode([['item_id' => $item->id, 'quantity' => 2]]),
        ]);
        $res->assertOk()->assertJson(['ok' => true]);
        $this->assertEquals($tanza->id, Order::latest('id')->first()->branch_id);
    }

    public function test_commissary_role_permissions_allow_operations_without_owner_administration(): void
    {
        $comm = $this->branch('Commissary', 'commissary');
        $manager = $this->user('commissary_manager', $comm, 'manager@comm.test');
        $staff = $this->user('commissary_staff', $comm, 'staff@comm.test');
        $admin = $this->user('admin', $comm, 'admin@comm.test');

        $this->assertTrue($manager->canManageInventory());
        $this->assertTrue($manager->canAccessWarehouse());
        $this->assertFalse($staff->canManageInventory());
        $this->assertTrue($staff->canAccessWarehouse());
        $this->assertTrue($admin->canManageInventory());
        $this->assertTrue($admin->canAccessWarehouse());

        $this->actingAs($manager)->get('/inventory')->assertOk();
        $this->get('/reports')->assertOk();
        $this->get('/users')->assertForbidden();
        $this->actingAs($staff)->get('/stock')->assertOk();
        $this->get('/warehouse')->assertOk();
        $this->get('/users')->assertForbidden();
        $this->get('/inventory')->assertForbidden();
    }

    public function test_owner_orders_page_groups_active_and_history(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $owner = $this->user('owner', $main, 'owner-ord@t.com');
        $bm = $this->user('branch_manager', $silang, 'bm-ord@t.com');

        DB::table('orders')->insert([
            ['order_number' => 'ORD-A', 'branch_id' => $silang->id, 'status' => 'pending', 'ordered_by' => $bm->id, 'total_amount' => 10, 'created_at' => now(), 'updated_at' => now()],
            ['order_number' => 'ORD-H', 'branch_id' => $silang->id, 'status' => 'settled', 'ordered_by' => $bm->id, 'total_amount' => 10, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $res = $this->actingAs($owner)->get('/orders')->assertOk();
        $res->assertViewHas('orderQueues', fn ($q) => array_keys($q) === ['active', 'history', 'all']);
        $res->assertViewHas('orderQueueCounts', fn ($c) => $c['active'] === 1 && $c['history'] === 1 && $c['all'] === 2);
        $res->assertViewHas('defaultOrderQueue', 'active');   // opens on Active
    }

    public function test_commissary_is_excluded_from_inventory_monitor(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $comm = $this->branch('Commissary', 'commissary');
        $owner = $this->user('owner', $main, 'owner-inv@t.com');

        $this->actingAs($owner)->get('/warehouse')->assertOk()
            ->assertViewHas('branchSummaries', function ($summaries) use ($comm, $main, $silang) {
                $ids = $summaries->map(fn ($s) => (int) $s['branch']->id)->all();

                return ! in_array($comm->id, $ids, true)   // commissary hidden
                    && in_array($main->id, $ids, true)      // stock-holding branches stay
                    && in_array($silang->id, $ids, true);
            });
    }
}
