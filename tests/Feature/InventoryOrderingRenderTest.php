<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Role;
use App\Models\Setting;
use App\Models\StockBatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Regression for the `condition` reserved-word SQL error (MariaDB 1064) that
 * 500'd /inventory and /ordering. These drive the real routes and assert the
 * physical/usable/expired/damaged breakdown is computed correctly. NOTE: SQLite
 * does not flag the unquoted identifier, so MariaDB was additionally verified
 * by running the corrected queries on the live connection.
 */
class InventoryOrderingRenderTest extends TestCase
{
    use RefreshDatabase;

    private function role(string $n): Role
    {
        return Role::firstOrCreate(['role_name' => $n], ['description' => $n]);
    }

    private function branch(string $n, string $t = 'franchise'): Branch
    {
        return Branch::create(['branch_name' => $n, 'branch_type' => $t, 'is_active' => 1]);
    }

    private function user(Role $r, Branch $b, string $e): User
    {
        return User::create(['full_name' => 'U', 'email' => $e, 'password_hash' => Hash::make('secret123'), 'role_id' => $r->id, 'branch_id' => $b->id, 'status' => 'active']);
    }

    private function batch(int $itemId, int $branchId, int $qty, ?string $expiry, string $condition = 'good'): StockBatch
    {
        return StockBatch::create(['item_id' => $itemId, 'branch_id' => $branchId, 'quantity_in' => $qty, 'quantity_left' => $qty, 'expiry_date' => $expiry, 'condition' => $condition, 'received_at' => now()]);
    }

    public function test_inventory_renders_with_correct_breakdown(): void
    {
        $main = $this->branch('Tanza', 'main');
        $owner = $this->user($this->role('owner'), $main, 'owner@test.com');
        $cat = Category::create(['name' => 'Bread', 'goods_type' => 'dry']);
        $item = Item::create(['item_name' => 'Zephyr Buns', 'category_id' => $cat->id, 'unit' => 'pack', 'price' => 10, 'reorder_level' => 5, 'is_active' => 1]);

        // Physical 20 = healthy 7 + expired 11 + damaged 2; usable 7.
        $this->batch($item->id, $main->id, 7, now()->addMonths(3)->toDateString());
        $this->batch($item->id, $main->id, 11, now()->subDays(2)->toDateString());
        $this->batch($item->id, $main->id, 2, now()->addMonths(3)->toDateString(), 'damaged');

        $res = $this->actingAs($owner)->get('/inventory');
        $res->assertOk();                        // no 500 / SQL error
        $res->assertSee('Zephyr Buns');
        $res->assertSee('Physical Stock');
        $res->assertSee('Usable Stock');
        $res->assertSee('Catalog Status');
        $res->assertSee('11 expired');           // expired quantity surfaced
        $res->assertSee('2 damaged');            // damaged quantity surfaced
    }

    public function test_ordering_page_renders_for_franchise_manager(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $mgr = $this->user($this->role('branch_manager'), $silang, 'bm@test.com');
        $cat = Category::create(['name' => 'Bread', 'goods_type' => 'dry']);
        $item = Item::create(['item_name' => 'Zephyr Rolls', 'category_id' => $cat->id, 'unit' => 'pack', 'price' => 10, 'reorder_level' => 5, 'is_active' => 1]);

        // Eligible main stock = 7 (healthy); expired 50 must NOT inflate it.
        $this->batch($item->id, $main->id, 7, now()->addMonths(3)->toDateString());
        $this->batch($item->id, $main->id, 50, now()->subDays(1)->toDateString());

        $res = $this->actingAs($mgr)->get('/ordering');
        $res->assertOk();                        // no 500 / SQL error
        $res->assertSee('Zephyr Rolls');
    }

    public function test_commissary_buttons_follow_manual_availability_even_without_usable_stock(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $manager = $this->user($this->role('branch_manager'), $silang, 'ordering@test.com');
        $noStock = Item::create(['item_name' => 'Fresh Buns', 'unit' => 'piece', 'price' => 18, 'is_active' => 1]);
        $expiredOnly = Item::create(['item_name' => 'Bread Rolls', 'unit' => 'piece', 'price' => 12, 'is_active' => 1]);
        $disabled = Item::create(['item_name' => 'Paused Product', 'unit' => 'pack', 'price' => 10, 'is_active' => 0]);
        $this->batch($expiredOnly->id, $main->id, 34, now()->subDays(2)->toDateString());
        $this->batch($disabled->id, $main->id, 100, now()->addMonth()->toDateString());

        $response = $this->actingAs($manager)->get('/ordering');
        $response->assertOk()->assertDontSee('Out of stock')->assertDontSee('Available at main branch:');
        $previousErrors = libxml_use_internal_errors(true);
        $document = new \DOMDocument;
        $document->loadHTML($response->getContent());
        libxml_clear_errors();
        libxml_use_internal_errors($previousErrors);
        $xpath = new \DOMXPath($document);

        foreach ([$noStock, $expiredOnly] as $item) {
            $card = $xpath->query('//div[@data-id="'.$item->id.'" and @data-available]')->item(0);
            $this->assertNotNull($card);
            $button = $xpath->query('.//button[contains(@class, "btn-add-cart")]', $card)->item(0);
            $this->assertNotNull($button);
            $this->assertFalse($button->hasAttribute('disabled'));
            $this->assertStringContainsString('Made to order', $card->textContent);
        }

        $card = $xpath->query('//div[@data-id="'.$disabled->id.'" and @data-available]')->item(0);
        $this->assertNotNull($card);
        $this->assertSame('0', $card->getAttribute('data-available'));
        $this->assertSame(0, $xpath->query('.//button[contains(@class, "btn-add-cart")]', $card)->length);
        $this->assertSame(1, $xpath->query('.//button[@disabled]', $card)->length);
    }

    public function test_ordering_shows_forecast_suggested_quantity_clamped_to_limit(): void
    {
        $main = $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $manager = $this->user($this->role('branch_manager'), $silang, 'suggest@test.com');
        $item = Item::create(['item_name' => 'Spring Rolls', 'unit' => 'pack', 'price' => 10, 'reorder_level' => 5, 'is_active' => 1]);

        // Consumption history at THIS branch across the two most recent completed
        // weeks drives a positive forecast recommendation; no branch stock on hand.
        foreach ([8, 15] as $daysAgo) {
            DB::table('stock_transactions')->insert([
                'item_id' => $item->id, 'branch_id' => $silang->id, 'type' => 'out',
                'quantity' => -20, 'created_at' => now()->subDays($daysAgo),
            ]);
        }

        // Uncapped: a positive suggestion surfaces on the card.
        Setting::set('order_max_qty_per_line', '0');
        $res = $this->actingAs($manager)->get('/ordering')->assertOk()->assertSee('Suggested:');
        $suggested = $this->suggestedAttr($res->getContent(), $item->id);
        $this->assertGreaterThan(1, $suggested);

        // Capped: the suggestion is clamped down to the per-line limit.
        Setting::set('order_max_qty_per_line', '1');
        $capped = $this->suggestedAttr(
            $this->actingAs($manager)->get('/ordering')->assertOk()->getContent(),
            $item->id
        );
        $this->assertSame(1, $capped);
    }

    private function suggestedAttr(string $html, int $itemId): int
    {
        $prev = libxml_use_internal_errors(true);
        $doc = new \DOMDocument;
        $doc->loadHTML($html);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        $card = (new \DOMXPath($doc))->query('//div[@data-id="'.$itemId.'"]')->item(0);
        $this->assertNotNull($card);

        return (int) $card->getAttribute('data-suggested');
    }

    public function test_ordering_uses_the_configured_quantity_limit(): void
    {
        $this->branch('Tanza', 'main');
        $silang = $this->branch('Silang');
        $manager = $this->user($this->role('branch_manager'), $silang, 'limit@test.com');

        Setting::set('order_max_qty_per_line', '3');
        $this->actingAs($manager)->get('/ordering')->assertOk()
            ->assertViewHas('maxOrderQuantity', 3);

        Setting::set('order_max_qty_per_line', '0');
        $this->actingAs($manager)->get('/ordering')->assertOk()
            ->assertViewHas('maxOrderQuantity', 0);
    }
}
