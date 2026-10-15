<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class BranchInventoryOverviewTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $silang = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $dasma = Branch::create(['branch_name' => 'Dasma', 'branch_type' => 'franchise', 'is_active' => true]);
        $empty = Branch::create(['branch_name' => 'No Stock Branch', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['owner', 'commissary_manager', 'commissary_staff', 'branch_manager'] as $name) {
            $role = Role::firstOrCreate(['role_name' => $name], ['description' => $name]);
            $users[$name] = User::create(['full_name' => $name, 'email' => $name.'@inventory.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => $name === 'branch_manager' ? $silang->id : $main->id, 'status' => 'active']);
        }
        $item = Item::create(['item_name' => 'Buns', 'unit' => 'piece', 'price' => 18, 'reorder_level' => 5, 'is_active' => true]);
        foreach ([[$main, 6, now()->addDay(), 'good'], [$silang, 3, now()->addDays(2), 'good'], [$dasma, 12, now()->addDays(3), 'good'],
            [$silang, 4, now()->subDay(), 'good'], [$dasma, 8, now()->subDays(2), 'good'], [$silang, 2, now()->addDay(), 'damaged']] as [$branch,$quantity,$expiry,$condition]) {
            StockBatch::create(['item_id' => $item->id, 'branch_id' => $branch->id, 'quantity_in' => $quantity, 'quantity_left' => $quantity,
                'expiry_date' => $expiry, 'condition' => $condition, 'qr_code' => 'BATCH-'.$branch->id.'-'.$quantity, 'received_at' => now()]);
        }
        $orders = [];
        foreach ([[$silang, 'pending'], [$silang, 'preparing'], [$dasma, 'delivering']] as $index => [$branch,$status]) {
            $order = Order::create(['order_number' => 'ORD-INVENTORY-'.$index, 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id, 'status' => $status, 'total_amount' => 180]);
            OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 10, 'unit_price' => 18]);
            $orders[] = $order;
        }
        foreach ([$silang, $dasma] as $branch) {
            StockTransaction::create(['item_id' => $item->id, 'branch_id' => $branch->id, 'type' => 'expired', 'quantity' => -2, 'reason' => 'Expired disposal', 'performed_by' => $users['owner']->id]);
        }

        return compact('main', 'silang', 'dasma', 'empty', 'users', 'item', 'orders');
    }

    public function test_owner_and_main_manager_see_each_branch_in_all_four_monitoring_tabs(): void
    {
        $w = $this->world();
        foreach (['owner', 'commissary_manager'] as $role) {
            foreach (['/inventory', '/warehouse'] as $path) {
                $response = $this->actingAs($w['users'][$role])->get($path)->assertOk()->assertViewHas('monitorAllBranches', true);
                $response->assertViewHas('branchSummaries', function ($summaries) use ($w) {
                    $silang = $summaries->first(fn ($row) => $row['branch']->id === $w['silang']->id);
                    $empty = $summaries->first(fn ($row) => $row['branch']->id === $w['empty']->id);

                    return $summaries->count() === 4 && $silang['active']->sum('quantity_left') === 3 && $silang['expired']->sum('quantity_left') === 4
                        && $silang['orders']->count() === 2 && $silang['disposal']->count() === 1 && $silang['low_stock'] === 1 && $empty['active']->isEmpty();
                });
                $response->assertViewHas('totalStock', 21)->assertViewHas('lowStockCount', 1);
                foreach (['active', 'expired', 'orders', 'disposal'] as $kind) {
                    $response->assertSee('id="inventory-'.$kind.'-branch-'.$w['silang']->id.'"', false)->assertSee('id="inventory-'.$kind.'-branch-'.$w['dasma']->id.'"', false);
                }
                if ($folder = getenv('BRANCH_INVENTORY_BROWSER_ARTIFACTS')) {
                    if (! is_dir($folder)) {
                        mkdir($folder, 0777, true);
                    } file_put_contents($folder.'/'.$role.'.html', $response->getContent());
                }
                $response->assertSee('Manage in Delivery')->assertSee('ORD-INVENTORY-1')->assertDontSee('Receive Stock')
                    ->assertDontSee('id="createModal"', false)->assertDontSee('fulfillOrder(', false);
            }
        }
    }

    public function test_products_stay_global_while_branch_details_do_not_mix_stock_or_actions(): void
    {
        $w = $this->world();
        $response = $this->actingAs($w['users']['owner'])->get('/inventory')->assertOk();
        $response->assertViewHas('allItems', fn ($items) => $items->first()->physical_stock == 35 && $items->first()->usable_stock == 21 && $items->first()->expired_stock == 12 && $items->first()->damaged_stock == 2);
        $document = new \DOMDocument;
        $previous = libxml_use_internal_errors(true);
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previous);
        $xpath = new \DOMXPath($document);
        $this->assertSame(0, $xpath->query('//div[@id="tabProducts"]')->length);
        $this->assertSame(0, $xpath->query('//tr[contains(@class,"product-row")]')->length);
        $this->get('/products')->assertOk()->assertViewHas('allItems', fn ($items) => $items->count() === 1 && $items->first()->usable_stock == 21);
        $detail = $xpath->query('//div[@id="inventory-active-branch-'.$w['silang']->id.'"]')->item(0);
        $this->assertSame(1, $xpath->query('.//tr[contains(@class,"row-active")]', $detail)->length);
        $this->assertSame(0, $xpath->query('.//button[contains(@onclick,"openAdjustModal") or contains(@onclick,"voidBatch")]', $detail)->length);
        $expired = $xpath->query('//div[@id="inventory-expired-branch-'.$w['silang']->id.'"]')->item(0);
        $this->assertSame(0, $xpath->query('.//button[contains(@onclick,"disposeBatch")]', $expired)->length);
        $this->actingAs($w['users']['commissary_manager'])->postJson('/warehouse/api', ['action' => 'adjust_batch', 'batch_id' => StockBatch::where('branch_id', $w['silang']->id)->first()->id, 'quantity_left' => 20])->assertStatus(422);
    }

    public function test_franchise_manager_keeps_their_own_inventory_and_main_staff_does_not_gain_global_branch_monitoring(): void
    {
        $w = $this->world();
        $this->actingAs($w['users']['branch_manager'])->get('/inventory?branch_id='.$w['dasma']->id)->assertOk()->assertViewHas('scopeLabel', 'Silang')
            ->assertViewHas('items', fn ($items) => $items->first()->physical_stock == 9 && $items->first()->usable_stock == 3)->assertDontSee('class="inventory-branch-row"', false);
        $this->actingAs($w['users']['branch_manager'])->get('/warehouse')->assertForbidden();
        $this->actingAs($w['users']['commissary_staff'])->get('/warehouse')->assertOk()->assertViewHas('monitorAllBranches', false)
            ->assertViewHas('activeBatches', fn ($batches) => $batches->count() === 1 && $batches->first()->branch_id === $w['main']->id)->assertDontSee('class="inventory-branch-row"', false)->assertDontSee('Receive Stock');
    }

    public function test_inventory_legacy_approval_does_not_deduct_stock_or_fake_delivery(): void
    {
        $w = $this->world();
        $order = $w['orders'][0];
        $this->actingAs($w['users']['commissary_manager'])->postJson('/warehouse/api', ['action' => 'fulfill_order', 'order_id' => $order->id, 'status' => 'approved'])->assertOk();
        $this->assertSame('approved', $order->fresh()->status);
        $this->assertEquals(6, StockBatch::where('branch_id', $w['main']->id)->sum('quantity_left'));
        $this->assertEquals(0, StockTransaction::where('type', 'out')->count());
        $this->actingAs($w['users']['commissary_manager'])->postJson('/warehouse/api', ['action' => 'fulfill_order', 'order_id' => $order->id, 'status' => 'delivering'])->assertStatus(422);
        $this->assertSame('approved', $order->fresh()->status);
    }
}
