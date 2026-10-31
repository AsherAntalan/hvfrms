<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\Role;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductAvailabilityTest extends TestCase
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

    private function branch(string $name, string $type = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $name, 'branch_type' => $type, 'is_active' => true]);
    }

    private function user(string $roleName, Branch $branch): User
    {
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);

        return User::create([
            'full_name' => $roleName,
            'email' => $roleName.'-'.$branch->id.'@availability.test',
            'password_hash' => Hash::make('secret123'),
            'role_id' => $role->id,
            'branch_id' => $branch->id,
            'status' => 'active',
        ]);
    }

    private function item(): Item
    {
        return Item::create([
            'item_name' => 'Buns',
            'unit' => 'piece',
            'price' => 18,
            'reorder_level' => 20,
            'is_active' => true,
        ]);
    }

    /** @return array<string, array{string, string, string}> */
    public static function authorizedRoles(): array
    {
        $cases = [];

        foreach (['owner', 'super_admin', 'commissary_manager'] as $role) {
            foreach (['inventory' => 'toggle', 'warehouse' => 'toggle_product'] as $module => $action) {
                $cases[$role.'-'.$module] = [$role, '/'.$module.'/api', $action];
            }
        }

        return $cases;
    }

    #[DataProvider('authorizedRoles')]
    public function test_authorized_roles_toggle_availability_without_stock_movements(string $role, string $endpoint, string $action): void
    {
        $main = $this->branch('Tanza', 'main');
        $actor = $this->user($role, $main);
        $item = $this->item();

        $this->actingAs($actor)->postJson($endpoint, [
            'action' => $action, 'id' => $item->id, 'is_active' => false,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertFalse($item->fresh()->is_active);
        $this->assertDatabaseHas('audit_log', [
            'user_id' => $actor->id, 'action' => 'deactivated product', 'record_id' => $item->id,
        ]);

        $this->actingAs($actor)->postJson($endpoint, [
            'action' => $action, 'id' => $item->id, 'is_active' => 0,
        ])->assertOk();
        $this->assertEquals(1, AuditLog::count(), 'An unchanged state must not create a second audit entry.');

        $this->actingAs($actor)->postJson($endpoint, [
            'action' => $action, 'id' => $item->id, 'is_active' => true,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertTrue($item->fresh()->is_active);
        $this->assertEquals(2, AuditLog::count());
        $this->assertEquals(0, StockTransaction::count());
    }

    /** @return array<string, array{string, bool, string, string}> */
    public static function unauthorizedRoles(): array
    {
        $cases = [];

        foreach (['branch_manager-main' => ['branch_manager', true], 'branch_manager-franchise' => ['branch_manager', false], 'commissary_staff' => ['commissary_staff', true], 'branch_staff' => ['branch_staff', false]] as $label => [$role, $atMain]) {
            foreach (['inventory' => 'toggle', 'warehouse' => 'toggle_product'] as $module => $action) {
                $cases[$label.'-'.$module] = [$role, $atMain, '/'.$module.'/api', $action];
            }
        }

        return $cases;
    }

    #[DataProvider('unauthorizedRoles')]
    public function test_other_roles_cannot_change_availability_even_at_tanza(string $role, bool $atMain, string $endpoint, string $action): void
    {
        $main = $this->branch('Tanza', 'main');
        $franchise = $this->branch('Silang');
        $actor = $this->user($role, $atMain ? $main : $franchise);
        $item = $this->item();

        $this->actingAs($actor)->postJson($endpoint, [
            'action' => $action, 'id' => $item->id, 'is_active' => 0,
        ])->assertForbidden();

        $this->assertTrue($item->fresh()->is_active);
        $this->assertEquals(0, AuditLog::count());
    }

    /** @return array<string, array{string, string}> */
    public static function toggleEndpoints(): array
    {
        return [
            'inventory' => ['/inventory/api', 'toggle'],
            'warehouse' => ['/warehouse/api', 'toggle_product'],
        ];
    }

    #[DataProvider('toggleEndpoints')]
    public function test_invalid_toggle_requests_and_missing_products_do_not_write(string $endpoint, string $action): void
    {
        $main = $this->branch('Tanza', 'main');
        $actor = $this->user('owner', $main);
        $item = $this->item();

        foreach ([['is_active' => 0], ['id' => 0, 'is_active' => 0], ['id' => 'bad', 'is_active' => 0], ['id' => $item->id], ['id' => $item->id, 'is_active' => 2], ['id' => $item->id, 'is_active' => 'false']] as $invalid) {
            $this->actingAs($actor)->postJson($endpoint, ['action' => $action] + $invalid)->assertStatus(422);
        }

        $this->actingAs($actor)->postJson($endpoint, [
            'action' => $action, 'id' => 999999, 'is_active' => 0,
        ])->assertNotFound();

        $this->assertTrue($item->fresh()->is_active);
        $this->assertEquals(0, AuditLog::count());
    }

    public function test_commissary_manager_can_reach_availability_and_catalog_controls(): void
    {
        $main = $this->branch('Tanza', 'main');
        $manager = $this->user('commissary_manager', $main);
        $this->item();

        $this->actingAs($manager)->get('/products')->assertOk()
            ->assertSee('Commissary Products')
            ->assertSee('Mark Buns unavailable for orders')
            ->assertSee('async function toggleProduct', false)
            ->assertSee('Add Product')
            ->assertSee('onclick="openProductModal', false);

        $this->actingAs($manager)->get('/inventory')->assertOk()
            ->assertDontSee('Mark Buns unavailable for orders')
            ->assertDontSee('Add Product')
            ->assertDontSee('onclick="openModal', false);

        $franchiseManager = $this->user('branch_manager', $this->branch('Silang'));
        $this->actingAs($franchiseManager)->get('/inventory')->assertOk()
            ->assertDontSee('title="Mark unavailable"', false)
            ->assertDontSee('Add Product')
            ->assertDontSee('onclick="openModal', false);
    }

    public function test_branch_accounts_cannot_create_or_edit_catalog_products_through_inventory(): void
    {
        $main = $this->branch('Tanza', 'main');
        $item = $this->item();
        $item->update(['is_active' => false]);

        foreach (['commissary_staff', 'branch_manager', 'branch_staff'] as $role) {
            $actor = $this->user($role, $main);
            $this->actingAs($actor)->postJson('/inventory/api', [
                'action' => 'save', 'item_name' => 'New Buns', 'is_active' => true,
            ])->assertForbidden();
            $this->actingAs($actor)->postJson('/inventory/api', [
                'action' => 'save', 'id' => $item->id, 'item_name' => 'Changed Buns', 'is_active' => true,
            ])->assertForbidden();
        }

        $this->assertEquals(1, Item::count());
        $this->assertEquals('Buns', $item->fresh()->item_name);
        $this->assertFalse($item->fresh()->is_active);
        $this->assertEquals(0, AuditLog::count());
    }

    public function test_stale_carts_fail_when_unavailable_and_zero_stock_orders_succeed_after_reenable(): void
    {
        $main = $this->branch('Tanza', 'main');
        $franchise = $this->branch('Silang');
        $manager = $this->user('commissary_manager', $main);
        $owner = $this->user('owner', $main);
        $requester = $this->user('branch_manager', $franchise);
        $item = $this->item();
        $lines = json_encode([['item_id' => $item->id, 'quantity' => 10]]);

        $this->actingAs($manager)->postJson('/inventory/api', [
            'action' => 'toggle', 'id' => $item->id, 'is_active' => 0,
        ])->assertOk();

        $this->actingAs($requester)->postJson('/ordering/api', [
            ...$this->paymentProof(), 'action' => 'place_order', 'cart' => $lines,
        ])->assertStatus(422);
        $this->actingAs($owner)->postJson('/orders/api?branch='.$franchise->id, [
            ...$this->paymentProof(), 'action' => 'create', 'branch_id' => $franchise->id, 'line_items' => $lines,
        ])->assertStatus(422);
        $this->assertEquals(0, Order::count());

        $this->actingAs($manager)->postJson('/warehouse/api', [
            'action' => 'toggle_product', 'id' => $item->id, 'is_active' => 1,
        ])->assertOk();

        $this->actingAs($requester)->postJson('/ordering/api', [
            ...$this->paymentProof(), 'action' => 'place_order', 'cart' => $lines,
        ])->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($owner)->postJson('/orders/api?branch='.$franchise->id, [
            ...$this->paymentProof(), 'action' => 'create', 'branch_id' => $franchise->id, 'line_items' => $lines,
        ])->assertOk()->assertJson(['ok' => true]);

        $this->assertEquals(2, Order::count());
        $this->assertEquals(0, StockTransaction::count());
    }
}
