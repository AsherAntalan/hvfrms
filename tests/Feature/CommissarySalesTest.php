<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\Role;
use App\Models\StockBatch;
use App\Models\User;
use App\Services\CommissarySalesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use PHPUnit\Framework\Attributes\TestWith;
use Tests\TestCase;

class CommissarySalesTest extends TestCase
{
    use RefreshDatabase;

    private function world(string $roleName = 'commissary_manager'): array
    {
        $this->travelTo(now('Asia/Manila')->setDate(2026, 10, 26)->startOfDay());
        $commissary = Branch::create(['branch_name' => 'Commissary', 'branch_type' => 'commissary', 'is_active' => true]);
        $branch = Branch::create(['branch_name' => 'Silang', 'branch_type' => 'franchise', 'is_active' => true]);
        $other = Branch::create(['branch_name' => 'Tanza', 'branch_type' => 'main', 'is_active' => true]);
        $role = Role::firstOrCreate(['role_name' => $roleName], ['description' => $roleName]);
        $user = User::create(['full_name' => 'Sales Reviewer', 'email' => 'sales@hvfrms.test', 'password_hash' => Hash::make('password'), 'role_id' => $role->id,
            'branch_id' => $roleName === 'branch_manager' ? $branch->id : $commissary->id, 'status' => 'active']);
        $item = Item::create(['item_name' => 'Chicken', 'unit' => 'pack', 'price' => 999, 'is_active' => true]);

        return compact('commissary', 'branch', 'other', 'user', 'item');
    }

    private function line(array $world, string $status = 'delivering', ?Branch $branch = null, float $price = 12.5): OrderItem
    {
        $order = Order::create(['order_number' => 'SALES-'.(Order::count() + 1), 'branch_id' => ($branch ?? $world['branch'])->id,
            'ordered_by' => $world['user']->id, 'status' => $status, 'total_amount' => 9999]);

        return OrderItem::create(['order_id' => $order->id, 'item_id' => $world['item']->id, 'quantity' => 10, 'unit_price' => $price]);
    }

    private function release(array $world, OrderItem $line, array $dates): OrderProduction
    {
        $batch = StockBatch::create(['item_id' => $line->item_id, 'branch_id' => $world['commissary']->id, 'quantity_in' => 10, 'quantity_left' => 10 - count(array_filter($dates)),
            'condition' => 'good', 'expiry_date' => '2026-12-31', 'received_at' => '2026-09-01', 'created_by' => $world['user']->id]);
        $production = OrderProduction::create(['order_id' => $line->order_id, 'order_item_id' => $line->id, 'item_id' => $line->item_id,
            'destination_branch_id' => $line->order->branch_id, 'stock_batch_id' => $batch->id, 'produced_qty' => 10, 'dispatched_qty' => count(array_filter($dates)),
            'production_at' => '2026-09-01', 'expiry_date' => '2026-12-31', 'prepared_by' => $world['user']->id, 'qr_code' => OrderProduction::qrFor($line->order_id, $line->id)]);
        foreach (array_pad($dates, 10, null) as $index => $date) {
            DB::table('order_production_units')->insert(['production_id' => $production->id, 'unit_number' => $index + 1, 'stock_batch_id' => $batch->id,
                'qr_code' => $production->qr_code.'-U'.($index + 1), 'dispatched_at' => $date, 'created_at' => '2026-09-01']);
        }

        return $production;
    }

    public function test_partial_releases_use_event_dates_and_saved_line_prices_without_unreleased_quantities(): void
    {
        $world = $this->world();
        $line = $this->line($world);
        $this->release($world, $line, ['2026-09-30 23:59:59', '2026-10-01 00:00:00', '2026-10-25 23:59:59', '2026-10-26 00:00:00']);
        $summary = app(CommissarySalesService::class)->summary('2026-10-01', '2026-10-25');

        $this->assertSame(25.0, $summary['amount']);
        $this->assertSame(1, $summary['orders']);
        $this->assertSame(0, $summary['legacy_rows']);
        $this->assertSame(2, (int) collect($summary['rows'])->sum('released_quantity'));
        $this->assertSame('delivering', $line->order->fresh()->status);
    }

    public function test_settling_and_archiving_do_not_count_release_again_or_move_it_to_another_month(): void
    {
        $world = $this->world();
        $line = $this->line($world, price: 18.25);
        $this->release($world, $line, ['2026-10-10 10:00:00', '2026-10-10 10:00:00']);
        $line->order->update(['status' => 'settled', 'settled_at' => '2026-11-05 10:00:00', 'archived_at' => '2026-11-10 10:00:00']);
        $this->assertSame(36.5, app(CommissarySalesService::class)->summary('2026-10-01', '2026-10-31')['amount']);
        $this->assertSame(0.0, app(CommissarySalesService::class)->summary('2026-11-01', '2026-11-30')['amount']);
    }

    public function test_legacy_released_and_completed_lines_use_recorded_quantities_and_do_not_overlap(): void
    {
        $world = $this->world();
        $legacyRelease = $this->line($world, price: 20);
        $production = OrderProduction::create(['order_id' => $legacyRelease->order_id, 'order_item_id' => $legacyRelease->id, 'item_id' => $legacyRelease->item_id,
            'destination_branch_id' => $world['branch']->id, 'produced_qty' => 10, 'dispatched_qty' => 2, 'production_at' => '2026-10-10', 'expiry_date' => '2026-12-31',
            'prepared_by' => $world['user']->id, 'qr_code' => OrderProduction::qrFor($legacyRelease->order_id, $legacyRelease->id)]);
        $production->update(['updated_at' => '2026-10-18 10:00:00']);
        $closed = $this->line($world, 'settled', price: 7);
        $closed->update(['fulfilled_quantity' => 4]);
        $closed->order->update(['settled_at' => '2026-10-15 10:00:00']);
        $zero = $this->line($world, 'settled');
        $zero->update(['fulfilled_quantity' => 0]);
        $zero->order->update(['settled_at' => '2026-10-15 10:00:00']);

        $summary = app(CommissarySalesService::class)->summary('2026-10-01', '2026-10-31');
        $this->assertSame(68.0, $summary['amount']);
        $this->assertSame(2, $summary['orders']);
        $this->assertSame(2, $summary['legacy_rows']);
        $this->assertSame(['legacy_completion', 'legacy_release'], collect($summary['rows'])->pluck('record_basis')->sort()->values()->all());
    }

    public function test_unreleased_states_and_commissary_destination_are_excluded_while_historical_branches_remain(): void
    {
        $world = $this->world();
        foreach (['pending', 'approved', 'preparing', 'cancelled', 'declined'] as $status) {
            $this->release($world, $this->line($world, $status), ['2026-10-10 10:00:00']);
        }
        $this->release($world, $this->line($world, branch: $world['commissary']), ['2026-10-10 10:00:00']);
        $this->release($world, $this->line($world, branch: $world['other']), ['2026-10-10 10:00:00']);
        $world['other']->update(['is_active' => false]);
        $summary = app(CommissarySalesService::class)->summary('2026-10-01', '2026-10-31');

        $this->assertSame(12.5, $summary['amount']);
        $this->assertSame(1, $summary['orders']);
        $this->assertSame(0.0, app(CommissarySalesService::class)->summary('2026-10-01', '2026-10-31', $world['branch']->id)['amount']);
    }

    #[TestWith(['owner'])]
    #[TestWith(['admin'])]
    #[TestWith(['commissary_manager'])]
    public function test_actual_sales_report_and_csv_share_date_branch_scope_and_amounts(string $roleName): void
    {
        $world = $this->world($roleName);
        $line = $this->line($world);
        $this->release($world, $line, ['2026-10-10 10:00:00', '2026-10-10 11:00:00']);
        $this->release($world, $this->line($world, branch: $world['other']), ['2026-10-10 12:00:00']);
        $query = '?from=2026-10-01&to=2026-10-25&branch='.$world['branch']->id;

        $response = $this->actingAs($world['user'])->get('/reports'.$query.'&tab=sales')->assertOk()
            ->assertViewHas('canSelectBranches', true)->assertViewHas('salesScopeText', 'Silang')
            ->assertViewHas('salesSummary', fn (array $summary): bool => $summary['amount'] === 25.0 && $summary['orders'] === 1)
            ->assertSee('Released items')->assertSee('25.00')->assertDontSee('>Unavailable<', false)
            ->assertSee(route('orders', ['order' => $line->order_id, 'queue' => 'all']));
        $csv = $this->get('/reports/export'.$query.'&type=sales')->assertOk()->assertDownload();
        $this->assertStringContainsString('25.00', $csv->streamedContent());
        $this->assertStringContainsString('unit_release', $csv->streamedContent());
        $this->assertStringNotContainsString('Tanza', $csv->streamedContent());
        if ($roleName === 'commissary_manager') {
            $this->get('/reports'.$query.'&tab=stock')->assertOk()->assertViewHas('branchFilter', $world['commissary']->id);
        }
    }

    public function test_sales_csv_keeps_product_and_branch_text_from_becoming_spreadsheet_formulas(): void
    {
        $world = $this->world();
        $world['item']->update(['item_name' => '=1+1']);
        $world['branch']->update(['branch_name' => '@SUM(1,2)']);
        $this->release($world, $this->line($world), ['2026-10-10 10:00:00']);
        $response = $this->actingAs($world['user'])->get('/reports/export?type=sales&from=2026-10-01&to=2026-10-25')->assertOk();
        $lines = explode("\n", trim($response->streamedContent()));
        $data = str_getcsv($lines[1]);
        $this->assertSame("'@SUM(1,2)", $data[2]);
        $this->assertSame("'=1+1", $data[3]);
        $this->assertSame('12.50', $data[7]);
    }

    #[TestWith(['branch_manager'])]
    #[TestWith(['commissary_staff'])]
    public function test_unpermitted_roles_cannot_read_or_export_commissary_sales(string $roleName): void
    {
        $world = $this->world($roleName);
        $this->actingAs($world['user'])->get('/reports?tab=sales')->assertForbidden();
        $this->get('/reports/export?type=sales')->assertForbidden();
    }
}
