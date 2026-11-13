<?php

namespace Tests\Feature;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class OrderDeliveryDateTest extends TestCase
{
    use RefreshDatabase;

    private function world(): array
    {
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $users = [];
        foreach (['commissary_manager', 'branch_manager', 'branch_staff'] as $roleName) {
            $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);
            $users[$roleName] = User::create(['full_name' => $roleName, 'email' => $roleName.'@dd.test', 'password_hash' => Hash::make('password'),
                'role_id' => $role->id, 'branch_id' => str_starts_with($roleName, 'branch_') ? $branch->id : $commissary->id, 'status' => 'active']);
        }
        $item = Item::create(['item_name' => 'Buns', 'unit' => 'piece', 'price' => 20, 'is_active' => true]);
        $order = Order::create(['order_number' => 'ORD-DD-1', 'branch_id' => $branch->id, 'ordered_by' => $users['branch_manager']->id, 'status' => 'approved', 'total_amount' => 40]);
        OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 2, 'unit_price' => 20]);

        return compact('commissary', 'branch', 'users', 'order');
    }

    public function test_commissary_manager_sets_delivery_date_and_notifies_branch(): void
    {
        $w = $this->world();
        $date = now()->addDays(3)->toDateString();

        $this->actingAs($w['users']['commissary_manager'])
            ->postJson('/orders/api', ['action' => 'set_delivery_date', 'id' => $w['order']->id, 'delivery_date' => $date, 'delivery_note' => 'Delayed by typhoon'])
            ->assertOk()->assertJson(['ok' => true]);

        $this->assertSame($date, $w['order']->fresh()->expected_delivery_date->toDateString());
        $this->assertSame('Delayed by typhoon', $w['order']->fresh()->delivery_date_note);

        $alert = Alert::where('order_id', $w['order']->id)->where('alert_type', 'operational')->first();
        $this->assertNotNull($alert);
        $this->assertSame($w['branch']->id, $alert->branch_id);
        $this->assertStringContainsString('scheduled for delivery', $alert->message);
    }

    public function test_resetting_delivery_date_supersedes_the_previous_notification(): void
    {
        $w = $this->world();
        $this->actingAs($w['users']['commissary_manager'])->postJson('/orders/api', ['action' => 'set_delivery_date', 'id' => $w['order']->id, 'delivery_date' => now()->addDays(2)->toDateString()])->assertOk();
        $this->actingAs($w['users']['commissary_manager'])->postJson('/orders/api', ['action' => 'set_delivery_date', 'id' => $w['order']->id, 'delivery_date' => now()->addDays(5)->toDateString()])->assertOk();

        $this->assertSame(1, Alert::where('order_id', $w['order']->id)->where('is_resolved', false)->count());
    }

    public function test_branch_roles_cannot_set_delivery_date(): void
    {
        $w = $this->world();
        $this->actingAs($w['users']['branch_manager'])->postJson('/orders/api', ['action' => 'set_delivery_date', 'id' => $w['order']->id, 'delivery_date' => now()->addDays(3)->toDateString()])->assertForbidden();
        $this->actingAs($w['users']['branch_staff'])->postJson('/orders/api', ['action' => 'set_delivery_date', 'id' => $w['order']->id, 'delivery_date' => now()->addDays(3)->toDateString()])->assertForbidden();
    }

    public function test_past_delivery_date_is_rejected(): void
    {
        $w = $this->world();
        $this->actingAs($w['users']['commissary_manager'])
            ->postJson('/orders/api', ['action' => 'set_delivery_date', 'id' => $w['order']->id, 'delivery_date' => now()->subDay()->toDateString()])
            ->assertStatus(422);
    }
}
