<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\Setting;
use App\Models\StockBatch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class AlertService
{
    /** @return Collection<int, Alert> */
    public function generate(): Collection
    {
        $newAlerts = collect();
        $today = Carbon::today('Asia/Manila');
        $warningDays = max(1, (int) Setting::get('expiry_warning_days', '7'));
        $lowStock = DB::table('stock_batches as sb')->join('items as i', 'i.id', '=', 'sb.item_id')
            ->join('branches as b', 'b.id', '=', 'sb.branch_id')
            ->where('i.is_active', true)->where('b.is_active', true)->where('b.branch_type', '<>', 'commissary')
            ->where('sb.quantity_left', '>', 0)
            ->select('i.id as item_id', 'i.item_name', 'i.unit', 'i.reorder_level', 'b.id as branch_id', 'b.branch_name')
            ->selectRaw('SUM(sb.quantity_left) AS total_qty')
            ->groupBy('i.id', 'i.item_name', 'i.unit', 'i.reorder_level', 'b.id', 'b.branch_name')
            ->havingRaw('SUM(sb.quantity_left) <= i.reorder_level')->get();
        foreach ($lowStock as $stock) {
            $this->publish(['alert_type' => 'low_stock', 'item_id' => $stock->item_id, 'branch_id' => $stock->branch_id], [
                'severity' => $stock->total_qty <= $stock->reorder_level / 2 ? 'critical' : 'warning',
                'message' => "{$stock->item_name} is low at {$stock->branch_name}: {$stock->total_qty} {$stock->unit}(s) remaining (reorder level: {$stock->reorder_level}).",
            ], $newAlerts);
        }

        $batches = StockBatch::with(['item', 'branch'])->where('quantity_left', '>', 0)
            ->whereNotNull('expiry_date')->whereDate('expiry_date', '<=', $today->copy()->addDays($warningDays)->toDateString())
            ->whereHas('branch', fn (Builder $branches) => $branches->where('is_active', true))
            ->where(function (Builder $batches): void {
                $batches->whereHas('branch', fn (Builder $branches) => $branches->where('branch_type', '<>', 'commissary'))
                    ->orWhere(fn (Builder $prepared) => $prepared->awaitingDispatch());
            })->get();
        foreach ($batches as $batch) {
            $days = (int) $today->diffInDays($batch->expiry_date->startOfDay(), false);
            $expired = $days < 0;
            $message = $expired
                ? "{$batch->item->item_name} (Batch #{$batch->id}) at {$batch->branch->branch_name} has EXPIRED (".abs($days)." day(s) ago) - {$batch->quantity_left} {$batch->item->unit}(s) still remaining."
                : "{$batch->item->item_name} (Batch #{$batch->id}) at {$batch->branch->branch_name} expires in {$days} day(s) - {$batch->quantity_left} {$batch->item->unit}(s) remaining.";
            $this->publish(['alert_type' => $expired ? 'expired' : 'expiring_soon', 'batch_id' => $batch->id], [
                'severity' => $days <= 2 ? 'critical' : 'warning', 'message' => $message,
                'item_id' => $batch->item_id, 'branch_id' => $batch->branch_id,
            ], $newAlerts);
        }

        $this->generateConsumptionAlerts($newAlerts);
        $idleDays = max(1, (int) Setting::get('idle_branch_days', '14'));
        $branches = DB::table('branches as b')->leftJoin('stock_transactions as st', 'st.branch_id', '=', 'b.id')
            ->where('b.is_active', true)->whereNotIn('b.branch_type', ['main', 'commissary'])
            ->select('b.id', 'b.branch_name', 'b.created_at')->selectRaw('MAX(st.created_at) AS last_movement')
            ->groupBy('b.id', 'b.branch_name', 'b.created_at')->get();
        foreach ($branches as $branch) {
            $lastActivity = $branch->last_movement ?? $branch->created_at;
            if ($lastActivity === null) {
                continue;
            }
            $days = (int) Carbon::parse($lastActivity, 'Asia/Manila')->startOfDay()->diffInDays($today, false);
            if ($days >= $idleDays) {
                $this->publish(['alert_type' => 'operational', 'branch_id' => $branch->id, 'order_id' => null], [
                    'severity' => 'info', 'message' => "{$branch->branch_name} has had no stock activity for {$days} day(s).",
                ], $newAlerts);
            }
        }

        return $newAlerts;
    }

    /** @param Collection<int, Alert> $newAlerts */
    private function generateConsumptionAlerts(Collection $newAlerts): void
    {
        $movements = DB::table('stock_transactions as st')->join('items as i', 'i.id', '=', 'st.item_id')
            ->join('branches as b', 'b.id', '=', 'st.branch_id')->where('b.branch_type', '<>', 'commissary')
            ->whereIn('st.type', ['out', 'adjustment', 'damaged', 'expired'])
            ->where('st.created_at', '>=', Carbon::today('Asia/Manila')->subWeeks(8))
            ->orderBy('st.created_at')->select('st.item_id', 'st.branch_id', 'st.quantity', 'st.created_at', 'i.item_name', 'b.branch_name')->get();
        $sigma = max(1.0, (float) Setting::get('unusual_sales_sigma', '2'));
        foreach ($movements->groupBy(fn (object $movement): string => $movement->item_id.'-'.$movement->branch_id) as $group) {
            $values = $group->groupBy(fn (object $movement): string => Carbon::parse($movement->created_at)->startOfWeek()->toDateString())
                ->sortKeys()->map(fn (Collection $week): int => abs((int) $week->sum('quantity')))->values()->all();
            if (count($values) < 3) {
                continue;
            }
            $mean = array_sum($values) / count($values);
            $standardDeviation = sqrt(array_sum(array_map(fn (int $value): float => ($value - $mean) ** 2, $values)) / count($values));
            $latest = end($values);
            if ($standardDeviation < 1 || $latest <= $mean + $sigma * $standardDeviation) {
                continue;
            }
            $movement = $group->first();
            $this->publish(['alert_type' => 'unusual_sales', 'item_id' => $movement->item_id, 'branch_id' => $movement->branch_id], [
                'severity' => 'warning',
                'message' => "{$movement->item_name} at {$movement->branch_name} had unusually high consumption this week: {$latest} units (avg: ".round($mean, 1).').',
            ], $newAlerts);
        }
    }

    /**
     * @param  array<string, int|string|null>  $identity
     * @param  array<string, int|string>  $attributes
     * @param  Collection<int, Alert>  $newAlerts
     */
    private function publish(array $identity, array $attributes, Collection $newAlerts): void
    {
        $existing = Alert::where($identity)->where(function (Builder $alerts): void {
            $alerts->where('is_resolved', false)->orWhere('resolved_at', '>=', now()->subHours(24));
        })->first();
        if ($existing) {
            if (! $existing->is_resolved) {
                $existing->fill($attributes)->save();
            }

            return;
        }
        $newAlerts->push(Alert::create(array_merge($identity, $attributes)));
    }
}
