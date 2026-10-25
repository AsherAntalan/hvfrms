<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class ProductsPageTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $tanza = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager', 'commissary_staff', 'branch_manager', 'branch_staff'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@products.test', 'password_hash' => Hash::make('password'), 'role_id' => $role->id,
                'branch_id' => str_starts_with($name, 'branch_') ? $tanza->id : $commissary->id, 'status' => 'active']);
        }
        $category = Category::create(['name' => 'Bread', 'goods_type' => 'dry']);
        $item = Item::create(['item_name' => 'Buns', 'unit' => 'piece', 'category_id' => $category->id, 'price' => 18, 'is_active' => true, 'qr_code' => 'PRODUCT-BUNS']);
        $otherCategory = Category::create(['name' => 'Frozen Products', 'goods_type' => 'wet']);
        Item::create(['item_name' => 'Chicken', 'unit' => 'pack', 'category_id' => $otherCategory->id, 'price' => 100, 'is_active' => false, 'qr_code' => 'PRODUCT-CHICKEN']);
        foreach ([[$commissary, 7, 'good', now()->addDay()], [$tanza, 4, 'good', now()->addDay()], [$tanza, 2, 'good', now()->subDay()], [$tanza, 1, 'damaged', now()->addDay()]] as [$branch, $quantity, $condition, $expiry]) {
            StockBatch::create(['item_id' => $item->id, 'branch_id' => $branch->id, 'quantity_in' => $quantity, 'quantity_left' => $quantity, 'expiry_date' => $expiry,
                'condition' => $condition, 'received_at' => now(), 'created_by' => $users['owner']->id]);
        }

        return compact('users', 'item', 'commissary', 'tanza');
    }

    public function test_products_has_its_own_page_and_active_sidebar_entry_for_catalog_roles(): void
    {
        $w = $this->world();
        foreach (['owner', 'admin', 'super_admin', 'commissary_manager'] as $role) {
            $response = $this->actingAs($w['users'][$role])->get('/products')->assertOk()->assertViewIs('products.index');
            $response->assertViewHas('allItems', function ($items) {
                $buns = $items->firstWhere('item_name', 'Buns');

                return $items->count() === 2 && $buns->physical_stock == 14 && $buns->usable_stock == 11 && $buns->expired_stock == 2 && $buns->damaged_stock == 1;
            });
            $response->assertSee('id="searchProducts"', false)->assertSee('id="filterCategory"', false)->assertSee('id="filterGoods"', false)
                ->assertSee('Commissary Products')->assertSee('Manage Commissary product details, prices, reorder levels, shelf life, and catalog availability.')
                ->assertDontSee('Physical Stock')->assertDontSee('Usable Stock')
                ->assertDontSee('printSingleQR(')->assertDontSee('Print QR label')->assertDontSee('<th class="text-center">QR</th>', false)->assertSee('toggleProduct(')->assertDontSee('id="tabActive"', false)->assertDontSee('id="tabProducts"', false);
            $document = new \DOMDocument;
            libxml_use_internal_errors(true);
            $document->loadHTML($response->getContent());
            libxml_clear_errors();
            libxml_use_internal_errors(false);
            $xpath = new \DOMXPath($document);
            $this->assertSame(1, $xpath->query('//nav//a[contains(@href,"/products") and contains(@class,"active")]')->length);
            $this->assertSame(1, $xpath->query('//nav//a[contains(@href,"/products")]/ancestor::div[contains(@class,"hv-nav-submenu") and contains(@class,"open")]')->length);
            if ($role === 'commissary_manager') {
                $response->assertDontSee('id="productModal"', false)->assertDontSee('onclick="openProductModal()', false);
            } else {
                $response->assertSee('id="productModal"', false)->assertSee('onclick="openProductModal()', false);
            }
            $inventory = $this->get('/inventory')->assertOk()->assertDontSee('data-bs-target="#tabProducts"', false)
                ->assertDontSee('id="searchProducts"', false)->assertDontSee('id="productModal"', false)->assertDontSee('onclick="openProductModal()', false)
                ->assertSee('data-bs-target="#tabActive"', false)->assertSee('data-bs-target="#tabDisposal"', false);
            if (($folder = getenv('PRODUCTS_BROWSER_ARTIFACTS')) && in_array($role, ['owner', 'commissary_manager'], true)) {
                if (! is_dir($folder)) {
                    mkdir($folder, 0777, true);
                }
                file_put_contents($folder.'/'.$role.'.html', $response->getContent());
                file_put_contents($folder.'/'.$role.'-inventory.html', $inventory->getContent());
            }
        }
    }

    public function test_branch_accounts_and_commissary_staff_cannot_access_global_products(): void
    {
        $w = $this->world();
        foreach (['branch_manager', 'branch_staff', 'commissary_staff'] as $role) {
            $this->actingAs($w['users'][$role])->get('/products')->assertForbidden();
            $path = $role === 'branch_manager' ? '/inventory' : '/qr';
            $this->get($path)->assertOk()->assertDontSee('href="'.route('products').'"', false);
        }
    }

    public function test_product_actions_keep_existing_permissions_and_do_not_change_stock(): void
    {
        $w = $this->world();
        $this->actingAs($w['users']['commissary_manager'])->postJson('/warehouse/api', ['action' => 'save_product', 'id' => $w['item']->id])->assertForbidden();
        $this->postJson('/warehouse/api', ['action' => 'toggle_product', 'id' => $w['item']->id, 'is_active' => 0])->assertOk()->assertJson(['ok' => true]);
        $this->get('/products')->assertOk()->assertViewHas('allItems', fn ($items) => ! $items->firstWhere('item_name', 'Buns')->is_active);
        $this->actingAs($w['users']['owner'])->postJson('/warehouse/api', ['action' => 'toggle_product', 'id' => $w['item']->id, 'is_active' => 1])->assertOk();
        $this->assertEquals(14, StockBatch::sum('quantity_left'));
    }
}
