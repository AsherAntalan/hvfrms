<?php

namespace App\Services;

use App\Models\ForecastRecommendation;
use App\Models\Item;
use App\Models\Setting;
use App\Models\StockBatch;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class ForecastService
{
    protected int $weeks;

    protected float $safetyFactor;

    protected array $weights = [1, 2, 3, 4];

    public function __construct()
    {
        $this->weeks = max(4, (int) Setting::get('forecast_weeks', '8'));
        $this->safetyFactor = max(1.0, (float) Setting::get('safety_stock_factor', '1.5'));
    }

    /** Effective historical window in completed weeks (>= 4). */
    public function weeks(): int
    {
        return $this->weeks;
    }

    /** Effective safety-stock multiplier (>= 1.0). */
    public function safetyFactor(): float
    {
        return $this->safetyFactor;
    }

    /**
     * Effective configuration and calculation metadata for the UI, so the page
     * can describe exactly what the service computes without re-deriving any
     * formula or re-reading settings per product row.
     *
     * @return array{
     *   weeks:int, safety_factor:float,
     *   wma_weeks:int, wma_weights:array<int,int>,
     *   trend_first_group:int, trend_second_group:int,
     *   historical_start:string, historical_end:string,
     *   target_start:string, target_end:string
     * }
     */
    public function metadata(): array
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $historicalStart = (clone $weekStart)->subDays($this->weeks * 7);
        $historicalEnd = (clone $weekStart)->subDay(); // last completed day before this week
        $targetStart = (clone $weekStart);
        $targetEnd = (clone $weekStart)->addDays(6);

        $wmaWeeks = min(count($this->weights), $this->weeks);
        $wmaWeights = array_slice($this->weights, count($this->weights) - $wmaWeeks);

        return [
            'weeks' => $this->weeks,
            'safety_factor' => $this->safetyFactor,
            'wma_weeks' => $wmaWeeks,
            'wma_weights' => array_values($wmaWeights),
            'trend_first_group' => intdiv($this->weeks, 2),
            'trend_second_group' => $this->weeks - intdiv($this->weeks, 2),
            'historical_start' => $historicalStart->format('Y-m-d'),
            'historical_end' => $historicalEnd->format('Y-m-d'),
            'target_start' => $targetStart->format('Y-m-d'),
            'target_end' => $targetEnd->format('Y-m-d'),
        ];
    }

    /**
     * Get weekly consumption totals grouped by item.
     * Queries stock_transactions for out/expired/damaged/adjustment types.
     *
     * Returns array of weekly totals per item:
     *   [item_id => [0 => ['start'=>..., 'end'=>..., 'total'=>...], ...]]
     */
    public function weeklySales(?int $branchId = null): array
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $periodStart = (clone $weekStart)->subDays($this->weeks * 7)->format('Y-m-d');

        $query = DB::table('stock_transactions')
            ->select('item_id', DB::raw('DATE(created_at) AS tx_date'), DB::raw('ABS(SUM(quantity)) AS daily_out'))
            ->whereIn('type', ['out', 'expired', 'damaged', 'adjustment'])
            ->where(DB::raw('DATE(created_at)'), '>=', $periodStart)
            ->where(DB::raw('DATE(created_at)'), '<', $weekStart->format('Y-m-d'));

        if ($branchId && $branchId > 0) {
            $query->where('branch_id', $branchId);
        }

        $dailyData = $query->groupBy('item_id', DB::raw('DATE(created_at)'))
            ->orderBy('item_id')
            ->orderBy('tx_date')
            ->get();

        // Build week ranges
        $weekRanges = [];
        for ($w = 0; $w < $this->weeks; $w++) {
            $ws = (clone $weekStart)->subDays(($this->weeks - $w) * 7);
            $we = (clone $ws)->addDays(6);
            $weekRanges[$w] = [
                'start' => $ws->format('Y-m-d'),
                'end' => $we->format('Y-m-d'),
            ];
        }

        // Group daily data into weekly totals per item
        $result = [];
        foreach ($dailyData as $dd) {
            $itemId = $dd->item_id;
            if (! isset($result[$itemId])) {
                // Initialize weekly totals
                foreach ($weekRanges as $w => $range) {
                    $result[$itemId][$w] = [
                        'start' => $range['start'],
                        'end' => $range['end'],
                        'total' => 0,
                    ];
                }
            }

            $txDate = $dd->tx_date;
            foreach ($weekRanges as $w => $range) {
                if ($txDate >= $range['start'] && $txDate <= $range['end']) {
                    $result[$itemId][$w]['total'] += (int) $dd->daily_out;
                    break;
                }
            }
        }

        return $result;
    }

    /**
     * Weighted Moving Average calculation.
     * Applies weights [1,2,3,4] to the most recent 4 weeks of the configured window.
     */
    public function weightedAverage(array $weeklyTotals): float
    {
        $values = array_column($weeklyTotals, 'total');

        if (count($values) < $this->weeks) {
            return 0.0;
        }

        $wmaSum = 0;
        $wtSum = 0;
        $wmaStart = max(0, $this->weeks - count($this->weights));
        for ($i = $wmaStart; $i < $this->weeks; $i++) {
            $wi = $i - $wmaStart;
            $wmaSum += $values[$i] * $this->weights[$wi];
            $wtSum += $this->weights[$wi];
        }

        return $wtSum > 0 ? round($wmaSum / $wtSum, 2) : 0.0;
    }

    /**
     * Classify demand pattern by comparing first half vs second half.
     * Returns: rising, falling, stable, flat, insufficient
     */
    public function demandPattern(array $weeklyTotals): string
    {
        $values = array_column($weeklyTotals, 'total');
        $totalAll = array_sum($values);
        $activeWeeks = count(array_filter($values, fn ($v) => $v > 0));

        if ($activeWeeks < 2) {
            return 'insufficient';
        }

        if ($totalAll == 0) {
            return 'flat';
        }

        $half = intdiv(count($values), 2);
        $firstHalf = array_sum(array_slice($values, 0, $half));
        $secondHalf = array_sum(array_slice($values, $half));

        if ($firstHalf == 0 && $secondHalf > 0) {
            return 'rising';
        }
        if ($secondHalf == 0 && $firstHalf > 0) {
            return 'falling';
        }

        $ratio = $firstHalf > 0 ? $secondHalf / $firstHalf : 1;

        if ($ratio > 1.15) {
            return 'rising';
        }
        if ($ratio < 0.85) {
            return 'falling';
        }

        return 'stable';
    }

    /**
     * Build the complete forecast for all active items.
     *
     * Returns array of forecast rows:
     *   [['item' => [...], 'weekly' => [...], 'avg_weekly' => ..., 'wma' => ..., ...]]
     */
    public function buildForecast(?int $branchId = null): array
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY);
        $nextWeek = (clone $weekStart)->addDays(7)->format('Y-m-d');

        $items = Item::with('category')
            ->where('is_active', true)
            ->orderBy('category_id')
            ->orderBy('item_name')
            ->get();

        $weeklySales = $this->weeklySales($branchId);

        // Build week ranges for items with no data
        $weekRanges = [];
        for ($w = 0; $w < $this->weeks; $w++) {
            $ws = (clone $weekStart)->subDays(($this->weeks - $w) * 7);
            $we = (clone $ws)->addDays(6);
            $weekRanges[$w] = [
                'start' => $ws->format('Y-m-d'),
                'end' => $we->format('Y-m-d'),
                'total' => 0,
            ];
        }

        $forecasts = [];

        foreach ($items as $item) {
            $weekly = $weeklySales[$item->id] ?? $weekRanges;

            $values = array_column($weekly, 'total');
            $activeWeeks = count(array_filter($values, fn ($v) => $v > 0));

            if ($activeWeeks < 2) {
                $forecasts[] = [
                    'item' => $item,
                    'weekly' => $weekly,
                    'avg_weekly' => 0,
                    'wma' => 0,
                    'trend' => 'insufficient',
                    'forecast_qty' => 0,
                    'stock_on_hand' => 0,
                    'target_stock' => 0,
                    'recommend_qty' => 0,
                    'active_weeks' => $activeWeeks,
                    'wma_fallback' => false,
                ];

                continue;
            }

            // Simple average (divided by weeks with positive movement, not all weeks)
            $totalAll = array_sum($values);
            $avgWeekly = round($totalAll / max($activeWeeks, 1), 2);

            // WMA — most recent weeks only. When it is zero (e.g. all recent
            // weeks are empty) the active-week average supplies the base.
            $rawWma = $this->weightedAverage($weekly);
            $wmaFallback = ($rawWma == 0);
            $wma = $wmaFallback ? $avgWeekly : $rawWma;

            // Trend
            $trend = $this->demandPattern($weekly);

            // Forecast with trend adjustment
            $forecastQty = (int) ceil($wma);
            if ($trend === 'rising') {
                $forecastQty = (int) ceil($wma * 1.1);
            }
            if ($trend === 'falling') {
                $forecastQty = (int) ceil($wma * 0.9);
            }

            // Stock on hand (recorded remaining quantity; NOT filtered for
            // expired/damaged batches — see UI note).
            $sohQuery = StockBatch::where('item_id', $item->id)
                ->where('quantity_left', '>', 0);
            if ($branchId && $branchId > 0) {
                $sohQuery->where('branch_id', $branchId);
            }
            $stockOnHand = (int) $sohQuery->sum('quantity_left');

            // Target = ceil(forecast × safety factor); suggested = max(0, target − stock).
            $safeTarget = (int) ceil($forecastQty * $this->safetyFactor);
            $recommendQty = max(0, $safeTarget - $stockOnHand);

            $forecasts[] = [
                'item' => $item,
                'weekly' => $weekly,
                'avg_weekly' => $avgWeekly,
                'wma' => $wma,
                'trend' => $trend,
                'forecast_qty' => $forecastQty,
                'stock_on_hand' => $stockOnHand,
                'target_stock' => $safeTarget,
                'recommend_qty' => $recommendQty,
                'active_weeks' => $activeWeeks,
                'wma_fallback' => $wmaFallback,
            ];
        }

        return $forecasts;
    }

    /**
     * Persist forecast recommendations for the coming week.
     */
    public function saveForecast(array $forecasts): int
    {
        $weekStart = Carbon::now()->startOfWeek(Carbon::MONDAY)->addDays(7)->format('Y-m-d');

        // Remove old forecast for this week
        ForecastRecommendation::where('week_start', $weekStart)->delete();

        $count = 0;
        foreach ($forecasts as $fc) {
            if ($fc['recommend_qty'] <= 0) {
                continue;
            }

            ForecastRecommendation::create([
                'item_id' => $fc['item']->id,
                'week_start' => $weekStart,
                'avg_weekly' => $fc['avg_weekly'],
                'forecast_qty' => $fc['forecast_qty'],
                'recommend_qty' => $fc['recommend_qty'],
                'pattern' => $fc['trend'],
            ]);
            $count++;
        }

        return $count;
    }
}
