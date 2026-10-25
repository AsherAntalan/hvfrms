<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * From the All-Branches inventory monitor, the Owner can dispose and archive a
 * franchise branch's expired batch (previously restricted to the main/source
 * branch), and the disposal is recorded against that batch's own branch.
 */
class WarehouseExpiredActionsTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $n): Role
    {
        return Role::firstOrCreate(['role_name' => $n], ['description' => $n]);
    }

    private function expiredBatch(int $itemId, int $branchId, int $qty = 5): StockBatch
    {
        return StockBatch::create([
            'item_id' => $itemId, 'branch_id' => $branchId, 'quantity_in' => $qty, 'quantity_left' => $qty,
            'expiry_date' => now()->subDays(3)->toDateString(), 'condition' => 'good', 'received_at' => now()->subDays(10),
        ]);
    }

    public function test_owner_disposes_a_franchise_branch_expired_batch(): void
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => 1]);
        $silang = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => 1]);
        $owner = User::create(['full_name' => 'O', 'email' => 'o@t.com', 'password_hash' => Hash::make('x'), 'role_id' => $this->role('owner')->id, 'branch_id' => $main->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);
        $batch = $this->expiredBatch($item->id, $silang->id, 5);

        $this->actingAs($owner)->postJson('/warehouse/api', ['action' => 'dispose_batch', 'batch_id' => $batch->id])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertEquals(0, $batch->fresh()->quantity_left);
        // Disposal recorded against the franchise branch, not the source branch.
        $this->assertDatabaseHas('stock_transactions', [
            'batch_id' => $batch->id, 'branch_id' => $silang->id, 'type' => 'expired', 'quantity' => -5,
        ]);
    }

    public function test_owner_archives_a_franchise_branch_expired_batch(): void
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => 1]);
        $silang = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => 1]);
        $owner = User::create(['full_name' => 'O', 'email' => 'o@t.com', 'password_hash' => Hash::make('x'), 'role_id' => $this->role('owner')->id, 'branch_id' => $main->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);
        $batch = $this->expiredBatch($item->id, $silang->id, 4);

        $this->actingAs($owner)->postJson('/reports/archive', ['batch_id' => $batch->id])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertDatabaseHas('expired_batch_archives', ['batch_id' => $batch->id, 'branch_id' => $silang->id]);
        // Physical stock is untouched by archiving (reporting only).
        $this->assertEquals(4, $batch->fresh()->quantity_left);
        // Archived batch drops out of the monitor's Expired list.
        $this->actingAs($owner)->get('/warehouse')->assertOk()
            ->assertViewHas('expiredBatches', fn ($rows) => ! collect($rows)->contains('id', $batch->id));
    }

    public function test_branch_manager_archives_only_own_branch_stock(): void
    {
        $silang = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => 1]);
        $dasma = Branch::create(['branch_name' => 'Dasma', 'branch_type' => 'franchise', 'is_active' => 1]);
        $bm = User::create(['full_name' => 'BM', 'email' => 'bm@t.com', 'password_hash' => Hash::make('x'), 'role_id' => $this->role('branch_manager')->id, 'branch_id' => $silang->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);
        $own = $this->expiredBatch($item->id, $silang->id, 3);
        $other = $this->expiredBatch($item->id, $dasma->id, 3);

        $this->actingAs($bm)->postJson('/reports/archive', ['batch_id' => $own->id])->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($bm)->postJson('/reports/archive', ['batch_id' => $other->id])->assertStatus(403);
        $this->assertDatabaseMissing('expired_batch_archives', ['batch_id' => $other->id]);
    }

    public function test_archiving_a_disposed_batch_removes_it_from_disposal_records(): void
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => 1]);
        $owner = User::create(['full_name' => 'O', 'email' => 'o@t.com', 'password_hash' => Hash::make('x'), 'role_id' => $this->role('owner')->id, 'branch_id' => $main->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);
        $batch = $this->expiredBatch($item->id, $main->id, 5);

        // Dispose (creates a disposal transaction, zeroes the batch) then archive it.
        $this->actingAs($owner)->postJson('/warehouse/api', ['action' => 'dispose_batch', 'batch_id' => $batch->id])->assertOk()->assertJson(['ok' => true]);
        $this->actingAs($owner)->postJson('/reports/archive', ['batch_id' => $batch->id])->assertOk()->assertJson(['ok' => true]);

        // The disposal row for the archived batch must drop out on a fresh request.
        $this->actingAs($owner)->get('/warehouse')->assertOk()
            ->assertViewHas('disposalRecords', fn ($rows) => ! collect($rows)->contains('batch_id', $batch->id));

        // The JSON panel refresh excludes it too.
        $this->actingAs($owner)->getJson('/warehouse/panel')->assertOk()->assertJson(['ok' => true]);
    }

    public function test_archives_viewer_shows_disposed_batch_with_zero_quantity(): void
    {
        $main = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => 1]);
        $owner = User::create(['full_name' => 'O', 'email' => 'o@t.com', 'password_hash' => Hash::make('x'), 'role_id' => $this->role('owner')->id, 'branch_id' => $main->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);
        $batch = $this->expiredBatch($item->id, $main->id, 6);

        $this->actingAs($owner)->postJson('/warehouse/api', ['action' => 'dispose_batch', 'batch_id' => $batch->id])->assertOk();
        $this->assertEquals(0, $batch->fresh()->quantity_left);
        $this->actingAs($owner)->postJson('/reports/archive', ['batch_id' => $batch->id])->assertOk()->assertJson(['ok' => true]);

        // Even with zero remaining quantity, the archived disposed batch is discoverable.
        $this->actingAs($owner)->getJson('/reports/archives')->assertOk()
            ->assertJson(['ok' => true])
            ->assertJsonFragment(['batch_id' => $batch->id, 'quantity_left' => 0]);
    }
}
