<?php

namespace Tests\Feature;

use App\Models\Branch;
use App\Models\Item;
use App\Models\Setting;
use App\Services\ForecastService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ForecastMetadataTest extends TestCase
{
    use RefreshDatabase;

    private function item(string $name = 'Demo Sauce', int $reorder = 10): Item
    {
        return Item::create([
            'item_name' => $name,
            'unit' => 'bottle',
            'price' => 100,
            'reorder_level' => $reorder,
            'shelf_life_days' => 180,
            'is_active' => true,
        ]);
    }

    private function branch(string $name = 'Silang'): Branch
    {
        return Branch::create(['branch_name' => $name, 'branch_type' => 'franchise', 'is_active' => true]);
    }

    /** Record an out movement of $qty inside completed week index $w (0 = oldest). */
    private function movement(int $itemId, int $branchId, int $weekIndex, int $weeks, int $qty): void
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $date = (clone $weekStart)->subDays(($weeks - $weekIndex) * 7)->addDays(2);
        DB::table('stock_transactions')->insert([
            'item_id' => $itemId,
            'branch_id' => $branchId,
            'batch_id' => null,
            'type' => 'out',
            'quantity' => -$qty,
            'reason' => 'test usage',
            'performed_by' => null,
            'created_at' => $date->toDateTimeString(),
        ]);
    }

    public function test_metadata_follows_the_configured_window_for_4_6_and_8_weeks(): void
    {
        foreach ([4 => [2, 2], 6 => [3, 3], 8 => [4, 4]] as $weeks => $groups) {
            Setting::set('forecast_weeks', (string) $weeks);
            $meta = (new ForecastService)->metadata();

            $this->assertSame($weeks, $meta['weeks']);
            $this->assertSame(4, $meta['wma_weeks']); // min(4, weeks) for weeks >= 4
            $this->assertSame([1, 2, 3, 4], $meta['wma_weights']);
            $this->assertSame($groups[0], $meta['trend_first_group']);
            $this->assertSame($groups[1], $meta['trend_second_group']);

            // Historical window spans exactly $weeks * 7 days.
            $start = Carbon::parse($meta['historical_start']);
            $end = Carbon::parse($meta['historical_end']);
            $this->assertSame($weeks * 7, (int) $start->diffInDays($end) + 1);
        }
    }

    public function test_odd_window_reports_real_comparison_group_sizes(): void
    {
        Setting::set('forecast_weeks', '7');
        $meta = (new ForecastService)->metadata();

        $this->assertSame(7, $meta['weeks']);
        $this->assertSame(3, $meta['trend_first_group']);   // intdiv(7, 2)
        $this->assertSame(4, $meta['trend_second_group']);  // 7 - 3
        $this->assertSame(4, $meta['wma_weeks']);
    }

    public function test_setting_below_minimum_is_clamped_to_four_weeks(): void
    {
        Setting::set('forecast_weeks', '2');
        $this->assertSame(4, (new ForecastService)->weeks());
    }

    public function test_non_default_safety_factor_drives_target_and_suggested_order(): void
    {
        Setting::set('forecast_weeks', '6');
        Setting::set('safety_stock_factor', '2');
        $branch = $this->branch();
        $item = $this->item();

        // Steady 10/week across all 6 weeks → WMA 10, stable, forecast 10.
        for ($w = 0; $w < 6; $w++) {
            $this->movement($item->id, $branch->id, $w, 6, 10);
        }

        $row = collect((new ForecastService)->buildForecast($branch->id))->firstWhere('item.id', $item->id);

        $this->assertSame('stable', $row['trend']);
        $this->assertSame(10, $row['forecast_qty']);
        $this->assertSame(20, $row['target_stock']);      // ceil(10 * 2)
        $this->assertSame(0, $row['stock_on_hand']);      // no batches
        $this->assertSame(20, $row['recommend_qty']);     // max(0, 20 - 0)
        $this->assertFalse($row['wma_fallback']);
        $this->assertSame(6, $row['active_weeks']);
    }

    public function test_rising_falling_and_stable_trends_are_classified(): void
    {
        Setting::set('forecast_weeks', '6');
        $branch = $this->branch();

        $rising = $this->item('Rising');
        foreach ([2, 3, 4, 8, 9, 10] as $w => $q) {
            $this->movement($rising->id, $branch->id, $w, 6, $q);
        }
        $falling = $this->item('Falling');
        foreach ([10, 9, 8, 3, 2, 1] as $w => $q) {
            $this->movement($falling->id, $branch->id, $w, 6, $q);
        }
        $stable = $this->item('Stable');
        foreach ([5, 5, 5, 5, 5, 5] as $w => $q) {
            $this->movement($stable->id, $branch->id, $w, 6, $q);
        }

        $rows = collect((new ForecastService)->buildForecast($branch->id));
        $this->assertSame('rising', $rows->firstWhere('item.id', $rising->id)['trend']);
        $this->assertSame('falling', $rows->firstWhere('item.id', $falling->id)['trend']);
        $this->assertSame('stable', $rows->firstWhere('item.id', $stable->id)['trend']);
    }

    public function test_insufficient_data_is_not_a_zero_demand_forecast(): void
    {
        Setting::set('forecast_weeks', '6');
        $branch = $this->branch();
        $item = $this->item('Sparse');
        // Only one active week → insufficient (needs >= 2).
        $this->movement($item->id, $branch->id, 5, 6, 7);

        $row = collect((new ForecastService)->buildForecast($branch->id))->firstWhere('item.id', $item->id);

        $this->assertSame('insufficient', $row['trend']);
        $this->assertSame(1, $row['active_weeks']);
        $this->assertSame(0, $row['forecast_qty']);
    }

    public function test_active_week_average_and_wma_fallback_are_disclosed(): void
    {
        Setting::set('forecast_weeks', '6');
        $branch = $this->branch();
        $item = $this->item('OldOnly');
        // Movement only in the two OLDEST weeks (indexes 0,1); recent 4 weeks empty
        // → WMA over the recent 4 weeks is 0, so the active-week average is used.
        $this->movement($item->id, $branch->id, 0, 6, 12);
        $this->movement($item->id, $branch->id, 1, 6, 8);

        $row = collect((new ForecastService)->buildForecast($branch->id))->firstWhere('item.id', $item->id);

        $this->assertSame(2, $row['active_weeks']);
        $this->assertSame(10.0, (float) $row['avg_weekly']); // (12 + 8) / 2 active weeks
        $this->assertTrue($row['wma_fallback']);
        $this->assertSame(10.0, (float) $row['wma']);        // fell back to the active-week average
    }

    public function test_branch_scope_isolates_movements(): void
    {
        Setting::set('forecast_weeks', '6');
        $a = $this->branch('A');
        $b = $this->branch('B');
        $item = $this->item();
        for ($w = 0; $w < 6; $w++) {
            $this->movement($item->id, $a->id, $w, 6, 10);
        }

        $rowB = collect((new ForecastService)->buildForecast($b->id))->firstWhere('item.id', $item->id);
        $this->assertSame('insufficient', $rowB['trend']); // no movement in branch B

        $rowA = collect((new ForecastService)->buildForecast($a->id))->firstWhere('item.id', $item->id);
        $this->assertSame('stable', $rowA['trend']);
    }
}
