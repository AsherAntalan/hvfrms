<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

/**
 * FEFO on branch consumption: a "Sold / Used" stock-out must come from the
 * earliest-expiring usable batch; scanning a newer batch is blocked and the
 * correct batch is suggested. Disposal reasons target the scanned batch.
 */
class FefoStockOutTest extends TestCase
{
    use RefreshDatabase;

    private Item $item;

    private Branch $branch;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => 1]);
        $role = Role::firstOrCreate(['role_name' => 'branch_manager'], ['description' => 'bm']);
        $this->manager = User::create(['full_name' => 'BM', 'email' => 'bm@t.com', 'password_hash' => Hash::make('x'), 'role_id' => $role->id, 'branch_id' => $this->branch->id, 'status' => 'active']);
        $this->item = Item::create(['item_name' => 'Banh Mi Bread', 'unit' => 'piece', 'price' => 10, 'is_active' => 1]);
    }

    private function batch(string $expiry, int $qty, string $condition = 'good'): StockBatch
    {
        return StockBatch::create([
            'item_id' => $this->item->id, 'branch_id' => $this->branch->id,
            'quantity_in' => $qty, 'quantity_left' => $qty,
            'expiry_date' => $expiry, 'condition' => $condition, 'received_at' => now(),
        ]);
    }

    private function stockOut(int $batchId, string $reason): TestResponse
    {
        return $this->actingAs($this->manager)->postJson('/stock/api', [
            'action' => 'stock_out', 'item_id' => $this->item->id, 'batch_id' => $batchId,
            'quantity' => 1, 'reason' => $reason,
        ]);
    }

    public function test_sold_used_blocks_newer_batch_and_suggests_earliest(): void
    {
        $soon = $this->batch(now()->addDays(1)->toDateString(), 5);   // expires first
        $later = $this->batch(now()->addDays(9)->toDateString(), 4);  // newer stock

        $res = $this->stockOut($later->id, 'Sold / Used')->assertStatus(422);
        $this->assertStringContainsString('FEFO', $res->json('error'));
        $this->assertSame($soon->id, $res->json('suggest_batch_id'));
        $this->assertEquals(4, $later->fresh()->quantity_left);   // untouched
    }

    public function test_sold_used_allows_the_earliest_batch(): void
    {
        $soon = $this->batch(now()->addDays(1)->toDateString(), 5);
        $this->batch(now()->addDays(9)->toDateString(), 4);

        $this->stockOut($soon->id, 'Sold / Used')->assertOk();
        $this->assertEquals(4, $soon->fresh()->quantity_left);
    }

    public function test_non_sold_reason_bypasses_fefo_on_scanned_batch(): void
    {
        $this->batch(now()->addDays(1)->toDateString(), 5);          // earlier usable (FEFO batch)
        $later = $this->batch(now()->addDays(9)->toDateString(), 4); // newer usable

        // A non-"Sold / Used" reason targets the scanned batch; FEFO is not enforced.
        $this->stockOut($later->id, 'Lost / Missing')->assertOk();
        $this->assertEquals(3, $later->fresh()->quantity_left);
    }
}
