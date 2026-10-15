<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Archive / restore / permanent delete of orders from the Delivery Archived view.
 * Delete is owner-level only and removes the order with its dependent records.
 */
class OrderArchiveDeleteTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $n): Role
    {
        return Role::firstOrCreate(['role_name' => $n], ['description' => $n]);
    }

    private function user(string $role, Branch $b, string $e): User
    {
        return User::create(['full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('x'), 'role_id' => $this->role($role)->id, 'branch_id' => $b->id, 'status' => 'active']);
    }

    private function settledOrder(Branch $branch, int $by): Order
    {
        $order = Order::create(['order_number' => 'ORD-AD-1', 'branch_id' => $branch->id, 'status' => 'settled', 'ordered_by' => $by, 'total_amount' => 20]);
        $item = Item::create(['item_name' => 'Pho', 'unit' => 'pack', 'price' => 10, 'is_active' => 1]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 2, 'unit_price' => 10]);

        return $order;
    }

    public function test_archive_restore_then_owner_deletes(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $owner = $this->user('owner', $main, 'o@t.com');
        $bm = $this->user('branch_manager', $silang, 'bm@t.com');
        $order = $this->settledOrder($silang, $bm->id);

        // Archive → leaves active/history, enters archived.
        $this->actingAs($owner)->postJson('/orders/api', ['action' => 'archive_order', 'id' => $order->id])->assertOk();
        $this->assertNotNull($order->fresh()->archived_at);
        $this->actingAs($owner)->get('/delivery?tab=archived')->assertOk()
            ->assertViewHas('archived', fn ($rows) => $rows->contains('id', $order->id))
            ->assertViewHas('history', fn ($rows) => ! $rows->contains('id', $order->id));

        // Restore → back to history.
        $this->actingAs($owner)->postJson('/orders/api', ['action' => 'restore_order', 'id' => $order->id])->assertOk();
        $this->assertNull($order->fresh()->archived_at);

        // Delete requires archived first.
        $this->actingAs($owner)->postJson('/orders/api', ['action' => 'delete_order', 'id' => $order->id])->assertStatus(422);

        // Archive again, then owner deletes permanently (order + items gone).
        $this->actingAs($owner)->postJson('/orders/api', ['action' => 'archive_order', 'id' => $order->id])->assertOk();
        $this->actingAs($owner)->postJson('/orders/api', ['action' => 'delete_order', 'id' => $order->id])->assertOk();
        $this->assertDatabaseMissing('orders', ['id' => $order->id]);
        $this->assertSame(0, DB::table('order_items')->where('order_id', $order->id)->count());
    }

    public function test_commissary_manager_cannot_delete(): void
    {
        $commissary = $this->branch('Commissary', 'commissary');
        $silang = $this->branch('Silang');
        $manager = $this->user('commissary_manager', $commissary, 'cm@t.com');
        $bm = $this->user('branch_manager', $silang, 'bm@t.com');
        $order = $this->settledOrder($silang, $bm->id);

        // Manager can archive…
        $this->actingAs($manager)->postJson('/orders/api', ['action' => 'archive_order', 'id' => $order->id])->assertOk();
        // …but not permanently delete.
        $this->actingAs($manager)->postJson('/orders/api', ['action' => 'delete_order', 'id' => $order->id])->assertStatus(403);
        $this->assertDatabaseHas('orders', ['id' => $order->id]);
    }

    private function branch(string $n, string $t = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $n, 'branch_type' => $t, 'is_active' => 1]);
    }
}
