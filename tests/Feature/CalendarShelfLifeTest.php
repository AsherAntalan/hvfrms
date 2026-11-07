<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\Role;
use App\Models\User;
use App\Services\OrderLabelService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalendarShelfLifeTest extends TestCase
{
    use RefreshDatabase;

    public function test_calendar_expiry_handles_category_defaults_month_end_and_leap_year(): void
    {
        $category = Category::create(['name' => 'Bread', 'goods_type' => 'dry']);
        $item = Item::create(['item_name' => 'Bread', 'category_id' => $category->id, 'unit' => 'piece', 'price' => 10]);
        $this->assertSame('2027-02-28', $item->expiryFromProduction('2026-08-31'));
        $item->update(['shelf_life_months' => 12]);
        $this->assertSame('2025-02-28', $item->expiryFromProduction('2024-02-29'));
        $item->update(['shelf_life_months' => null, 'shelf_life_days' => 14]);
        $this->assertSame('2026-12-19', $item->expiryFromProduction('2026-12-05'));
        $category->update(['name' => 'Juices/Others']);
        $item->unsetRelation('category')->update(['shelf_life_days' => null]);
        $this->assertNull($item->expiryFromProduction('2026-12-05'));
    }

    public function test_products_page_renders_calendar_shelf_life_without_server_error(): void
    {
        [$user, $branch] = $this->actor();
        Item::create(['item_name' => 'Juice', 'unit' => 'liter', 'price' => 10, 'shelf_life_months' => 12, 'is_active' => true]);
        $this->actingAs($user)->get('/products')->assertOk()->assertSee('12 months')
            ->assertSee('Shelf Life (calendar months)');
    }

    public function test_production_auto_calculates_calendar_expiry_and_keeps_it_when_reopened(): void
    {
        $this->travelTo(now()->setDate(2026, 8, 31));
        [$user, $branch] = $this->actor();
        $destination = Branch::create(['branch_name' => 'Baguio', 'branch_type' => 'franchise', 'is_active' => true]);
        $item = Item::create(['item_name' => 'Sauce', 'unit' => 'pack', 'price' => 10, 'shelf_life_months' => 6, 'is_active' => true]);
        $order = Order::create(['order_number' => 'CALENDAR-1', 'branch_id' => $destination->id, 'ordered_by' => $user->id, 'status' => 'pending', 'total_amount' => 10]);
        $line = OrderItem::create(['order_id' => $order->id, 'item_id' => $item->id, 'quantity' => 1, 'unit_price' => 10]);
        $this->actingAs($user)->postJson('/orders/api', ['action' => 'prepare_order', 'id' => $order->id, 'expiry' => '{}'])->assertOk();
        $production = OrderProduction::where('order_item_id', $line->id)->firstOrFail();
        $this->assertSame('2027-02-28', $production->expiry_date->toDateString());
        $this->travel(3)->days();
        app(OrderLabelService::class)->prepare($order->fresh(), [], $user);
        $this->assertSame('2027-02-28', $production->fresh()->expiry_date->toDateString());
        $this->actingAs($user)->postJson('/orders/api', ['action' => 'preparation_options', 'id' => $order->id])
            ->assertOk()->assertJsonPath('lines.0.suggested_expiry', '2027-02-28');
    }

    public function test_stock_in_requires_production_date_for_automatic_expiry_and_respects_printed_expiry(): void
    {
        $this->travelTo(now()->setDate(2026, 11, 4));
        [$user, $branch] = $this->actor();
        $item = Item::create(['item_name' => 'Juice', 'unit' => 'liter', 'price' => 10, 'shelf_life_months' => 12, 'is_active' => true]);
        $payload = ['action' => 'stock_in', 'item_id' => $item->id, 'branch_id' => $branch->id, 'quantity' => 2];
        $this->actingAs($user)->postJson('/stock/api', $payload)->assertUnprocessable();
        $this->postJson('/stock/api', $payload + ['production_date' => '2026-12-01'])->assertUnprocessable();
        $this->postJson('/stock/api', $payload + ['production_date' => '2026-10-31'])->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('stock_batches', ['item_id' => $item->id, 'expiry_date' => '2027-10-31 00:00:00']);
        $this->postJson('/stock/api', $payload + ['expiry_date' => '2027-01-15'])->assertOk()->assertJson(['ok' => true]);
        $this->assertDatabaseHas('stock_batches', ['item_id' => $item->id, 'expiry_date' => '2027-01-15 00:00:00']);
        $this->postJson('/stock/api', $payload + ['expiry_date' => '2027-02-30'])->assertUnprocessable();
    }

    /** @return array{User, Branch} */
    private function actor(): array
    {
        $branch = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $role = Role::firstOrCreate(['role_name' => 'admin']);
        $user = User::create(['full_name' => 'Admin', 'username' => 'calendaradmin', 'role_id' => $role->id,
            'branch_id' => $branch->id, 'password_hash' => bcrypt('password'), 'status' => 'active']);

        return [$user, $branch];
    }
}
