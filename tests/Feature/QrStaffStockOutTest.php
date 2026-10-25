<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QrStaffStockOutTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $other = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $role = Role::firstOrCreate(['role_name' => 'branch_staff'], ['description' => 'Branch Staff']);
        $staff = User::create(['full_name' => 'Silang Staff', 'email' => 'staff.silang@scanner.test',
            'password_hash' => bcrypt('password'), 'role_id' => $role->id, 'branch_id' => $branch->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Pita Bread', 'unit' => 'piece', 'price' => 12, 'is_active' => true, 'qr_code' => 'HV-ITEM-PITA']);
        $batch = StockBatch::create(['item_id' => $item->id, 'branch_id' => $branch->id, 'quantity_in' => 3, 'quantity_left' => 3,
            'expiry_date' => now()->addDay(), 'condition' => 'good', 'received_at' => now(), 'qr_code' => 'HV-RECEIVED-42-17-B150']);

        return compact('branch', 'other', 'staff', 'item', 'batch');
    }

    public function test_staff_can_scan_and_use_only_the_selected_batch_without_duplicate_deductions(): void
    {
        $w = $this->world();
        $other = StockBatch::create(['item_id' => $w['item']->id, 'branch_id' => $w['branch']->id, 'quantity_in' => 10, 'quantity_left' => 10,
            'expiry_date' => now()->addDays(10), 'condition' => 'good', 'received_at' => now()]);
        $this->actingAs($w['staff'])->get('/qr')->assertOk();
        $this->postJson('/qr/api', ['action' => 'lookup', 'query' => $w['batch']->qr_code])->assertOk()
            ->assertJsonPath('matched_batch.id', $w['batch']->id)->assertJsonPath('matched_batch.quantity_left', 3);
        $data = ['action' => 'stock_out', 'item_id' => $w['item']->id, 'batch_id' => $w['batch']->id,
            'quantity' => 1, 'reason' => 'Sold / Used', 'idempotency_key' => 'staff-usage-1'];
        $this->postJson('/qr/api', array_replace($data, ['quantity' => 4]))->assertStatus(422);
        $this->postJson('/qr/api', $data)->assertOk()->assertJson(['ok' => true]);
        $this->postJson('/qr/api', $data)->assertOk()->assertJson(['duplicate' => true]);
        $this->assertEquals(2, $w['batch']->fresh()->quantity_left);
        $this->assertEquals(10, $other->fresh()->quantity_left);
        $this->assertDatabaseHas('stock_transactions', ['batch_id' => $w['batch']->id, 'branch_id' => $w['branch']->id,
            'type' => 'out', 'quantity' => -1, 'performed_by' => $w['staff']->id]);
        $this->postJson('/qr/api', array_replace($data, ['quantity' => 2, 'idempotency_key' => 'staff-usage-2']))->assertOk();
        $this->postJson('/qr/api', array_replace($data, ['quantity' => 1, 'idempotency_key' => 'staff-usage-3']))->assertStatus(422);
        $this->assertEquals(0, $w['batch']->fresh()->quantity_left);
        $this->assertEquals(10, $other->fresh()->quantity_left);
        $this->assertEquals(-3, StockTransaction::sum('quantity'));
    }

    public function test_staff_product_scan_uses_eligible_stock_from_their_own_branch(): void
    {
        $w = $this->world();
        $foreign = StockBatch::create(['item_id' => $w['item']->id, 'branch_id' => $w['other']->id, 'quantity_in' => 10,
            'quantity_left' => 10, 'condition' => 'good', 'received_at' => now()]);
        $this->actingAs($w['staff'])->postJson('/qr/api', ['action' => 'stock_out', 'item_id' => $w['item']->id,
            'branch_id' => $w['other']->id, 'quantity' => 2, 'reason' => 'Sold / Used'])->assertOk();
        $this->assertEquals(1, $w['batch']->fresh()->quantity_left);
        $this->assertEquals(10, $foreign->fresh()->quantity_left);
    }

    public function test_staff_cannot_lookup_or_stock_out_another_branch_batch(): void
    {
        $w = $this->world();
        $foreign = StockBatch::create(['item_id' => $w['item']->id, 'branch_id' => $w['other']->id, 'quantity_in' => 10,
            'quantity_left' => 10, 'condition' => 'good', 'received_at' => now(), 'qr_code' => 'FOREIGN-BATCH']);
        $this->actingAs($w['staff'])->postJson('/qr/api', ['action' => 'lookup', 'query' => $foreign->qr_code])->assertForbidden();
        $this->postJson('/qr/api', ['action' => 'stock_out', 'item_id' => $w['item']->id, 'batch_id' => $foreign->id,
            'branch_id' => $w['other']->id, 'quantity' => 1, 'reason' => 'Sold / Used'])->assertStatus(422);
        $this->assertEquals(10, $foreign->fresh()->quantity_left);
        $this->assertEquals(3, $w['batch']->fresh()->quantity_left);
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_staff_normal_stock_out_rejects_expired_and_damaged_batches(): void
    {
        $w = $this->world();
        $this->actingAs($w['staff']);
        foreach ([['expiry_date' => now()->subDay(), 'condition' => 'good'], ['expiry_date' => now()->addDay(), 'condition' => 'damaged']] as $attributes) {
            $w['batch']->update($attributes);
            $this->postJson('/qr/api', ['action' => 'stock_out', 'item_id' => $w['item']->id, 'batch_id' => $w['batch']->id,
                'quantity' => 1, 'reason' => 'Sold / Used'])->assertStatus(422);
        }
        $this->assertEquals(3, $w['batch']->fresh()->quantity_left);
        $this->assertDatabaseCount('stock_transactions', 0);
    }

    public function test_staff_stock_out_rejects_invalid_quantities_and_mismatched_products(): void
    {
        $w = $this->world();
        $this->actingAs($w['staff']);
        $data = ['action' => 'stock_out', 'item_id' => $w['item']->id, 'batch_id' => $w['batch']->id, 'reason' => 'Sold / Used'];
        foreach ([0, -1, 1.5, 'bad'] as $quantity) {
            $this->postJson('/qr/api', $data + ['quantity' => $quantity])->assertStatus(422);
        }
        $this->postJson('/qr/api', array_replace($data, ['quantity' => 1, 'reason' => '']))->assertStatus(422);
        $other = Item::create(['item_name' => 'Other item', 'unit' => 'piece', 'is_active' => true]);
        $this->postJson('/qr/api', array_replace($data, ['item_id' => $other->id, 'quantity' => 1]))->assertStatus(422);
        $this->assertEquals(3, $w['batch']->fresh()->quantity_left);
        $this->assertDatabaseCount('stock_transactions', 0);
    }
}
