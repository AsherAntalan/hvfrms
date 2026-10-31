<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\ExpiredBatchArchive;
use App\Models\Item;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CommissaryInventoryReadOnlyTest extends TestCase
{
    use RefreshDatabase;

    /** @return array{commissary:Branch,franchise:Branch,manager:User,owner:User,bm:User,item:Item,batch:StockBatch,expired:StockBatch} */
    private function world(): array
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $franchise = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        Setting::set('commissary_branch_id', (string) $commissary->id);

        $role = fn (string $name) => Role::firstOrCreate(['role_name' => $name], ['description' => $name])->id;
        $user = fn (string $r, int $bid, string $email) => User::create([
            'full_name' => $r, 'email' => $email, 'password_hash' => Hash::make('password'),
            'role_id' => $role($r), 'branch_id' => $bid, 'status' => 'active',
        ]);

        $manager = $user('commissary_manager', $commissary->id, 'mgr@t.test');
        $owner = $user('owner', $commissary->id, 'owner@t.test');
        $bm = $user('branch_manager', $franchise->id, 'bm@t.test');

        $category = Category::create(['name' => 'Sauces', 'goods_type' => 'wet']);
        $item = Item::create(['item_name' => 'Demo Sauce', 'category_id' => $category->id, 'unit' => 'bottle', 'price' => 100, 'reorder_level' => 10, 'shelf_life_days' => 180, 'is_active' => true]);

        $batch = StockBatch::create(['item_id' => $item->id, 'branch_id' => $commissary->id, 'quantity_in' => 20, 'quantity_left' => 20, 'expiry_date' => now()->addDays(60)->toDateString(), 'condition' => 'good', 'created_by' => $owner->id]);
        $expired = StockBatch::create(['item_id' => $item->id, 'branch_id' => $commissary->id, 'quantity_in' => 5, 'quantity_left' => 5, 'expiry_date' => now()->subDays(5)->toDateString(), 'condition' => 'good', 'created_by' => $owner->id]);

        return compact('commissary', 'franchise', 'manager', 'owner', 'bm', 'item', 'batch', 'expired');
    }

    public function test_handler_helper_excludes_commissary_manager_but_keeps_monitoring(): void
    {
        $w = $this->world();
        $this->assertTrue($w['manager']->canManageInventory());   // monitoring preserved
        $this->assertFalse($w['manager']->canHandleInventory());  // handling prohibited
        $this->assertTrue($w['owner']->canHandleInventory());
        $this->assertTrue($w['bm']->canHandleInventory());
    }

    public function test_commissary_manager_inventory_mutations_return_403_without_side_effects(): void
    {
        $w = $this->world();
        $before = (int) $w['expired']->quantity_left;

        foreach (['dispose_batch', 'adjust_batch', 'void_batch', 'edit_batch', 'delete_batch'] as $action) {
            $this->actingAs($w['manager'])
                ->postJson(route('warehouse.api'), ['action' => $action, 'batch_id' => $w['expired']->id, 'quantity' => 1, 'reason' => 'Expired'])
                ->assertStatus(403);
        }

        // QR/stock API disposal path is also refused.
        $this->actingAs($w['manager'])
            ->postJson(route('stock.api'), ['action' => 'adjustment', 'batch_id' => $w['expired']->id, 'quantity' => 1, 'reason' => 'Expired goods disposal'])
            ->assertStatus(403);

        $this->assertSame($before, (int) $w['expired']->fresh()->quantity_left);
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_commissary_manager_inventory_archive_actions_return_403(): void
    {
        $w = $this->world();

        $this->actingAs($w['manager'])
            ->postJson(route('reports.archive'), ['batch_id' => $w['expired']->id])
            ->assertStatus(403);
        $this->assertDatabaseCount('expired_batch_archives', 0);

        $archive = ExpiredBatchArchive::create(['batch_id' => $w['expired']->id, 'branch_id' => $w['commissary']->id, 'archived_by' => $w['owner']->id, 'archived_at' => now()]);
        $this->actingAs($w['manager'])
            ->postJson(route('reports.archiveRemove'), ['id' => $archive->id])
            ->assertStatus(403);
        $this->assertNull($archive->fresh()->removed_at);
    }

    public function test_commissary_manager_inventory_screen_is_read_only(): void
    {
        $w = $this->world();
        $response = $this->actingAs($w['manager'])->get('/inventory')->assertOk();

        $response->assertSee('Inventory monitoring only. Disposal and inventory archive actions require an authorized inventory handler.');
        // Prohibited action controls are absent for this role (buttons, not JS no-ops).
        $response->assertDontSee('onclick="disposeBatch(', false);
        $response->assertDontSee('onclick="archiveBatch(', false);
        // Monitoring tabs remain available.
        $response->assertSee('data-bs-target="#tabActive"', false);
        $response->assertSee('data-bs-target="#tabExpired"', false);
        $response->assertSee('data-bs-target="#tabDisposal"', false);
    }

    public function test_authorized_handlers_retain_their_actions(): void
    {
        $w = $this->world();

        // Owner may still dispose via the warehouse API.
        $this->actingAs($w['owner'])
            ->postJson(route('warehouse.api'), ['action' => 'dispose_batch', 'batch_id' => $w['expired']->id, 'quantity' => 2, 'reason' => 'Expired goods disposal'])
            ->assertStatus(200)->assertJson(['ok' => true]);
        // disposeBatch disposes the whole expired batch (quantity param ignored).
        $this->assertSame(0, (int) $w['expired']->fresh()->quantity_left);

        // Owner may still archive an expired batch.
        $expired2 = StockBatch::create(['item_id' => $w['item']->id, 'branch_id' => $w['commissary']->id, 'quantity_in' => 3, 'quantity_left' => 3, 'expiry_date' => now()->subDays(8)->toDateString(), 'condition' => 'good', 'created_by' => $w['owner']->id]);
        $this->actingAs($w['owner'])
            ->postJson(route('reports.archive'), ['batch_id' => $expired2->id])
            ->assertStatus(200)->assertJson(['ok' => true]);
        $this->assertDatabaseCount('expired_batch_archives', 1);

        // Branch Manager may still dispose their own branch stock via the stock API.
        $branchBatch = StockBatch::create(['item_id' => $w['item']->id, 'branch_id' => $w['franchise']->id, 'quantity_in' => 4, 'quantity_left' => 4, 'expiry_date' => now()->subDays(3)->toDateString(), 'condition' => 'good', 'created_by' => $w['bm']->id]);
        $this->actingAs($w['bm'])
            ->postJson(route('stock.api'), ['action' => 'adjustment', 'item_id' => $w['item']->id, 'branch_id' => $w['franchise']->id, 'batch_id' => $branchBatch->id, 'quantity' => 1, 'reason' => 'Expired goods disposal'])
            ->assertStatus(200)->assertJson(['ok' => true]);
    }

    public function test_commissary_manager_catalog_permission_is_preserved(): void
    {
        $w = $this->world();
        $category = Category::first();

        $this->actingAs($w['manager'])
            ->postJson(route('warehouse.api'), ['action' => 'save_product', 'item_name' => 'New Demo Product', 'category_id' => $category->id, 'unit' => 'bottle', 'price' => 50, 'reorder_level' => 5, 'shelf_life_days' => 100])
            ->assertStatus(200)->assertJson(['ok' => true]);
    }
}
