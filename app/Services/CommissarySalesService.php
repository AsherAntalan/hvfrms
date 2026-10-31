<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class CommissarySalesService
{
    /**
     * Released order-line values, never payment verification or requested totals.
     * Unit labels are the primary event source. Older outputs without labels use
     * their last recorded release date; pre-production completed lines use their
     * recorded completion date. These sources are mutually exclusive per line.
     *
     * @return array{amount: float, orders: int, legacy_rows: int, rows: array<int, object>}
     */
    public function summary(string $from, string $to, int $branch = 0): array
    {
        $unitReleases = $this->lines($branch)
            ->join('order_productions as p', function (JoinClause $join): void {
                $join->on('p.order_item_id', '=', 'oi.id')->on('p.order_id', '=', 'o.id');
            })
            ->join('order_production_units as u', 'u.production_id', '=', 'p.id')
            ->whereNotNull('u.dispatched_at')
            ->selectRaw("1 AS released_quantity, u.dispatched_at AS recognized_at, 'unit_release' AS record_basis");

        $legacyReleases = $this->lines($branch)
            ->join('order_productions as p', function (JoinClause $join): void {
                $join->on('p.order_item_id', '=', 'oi.id')->on('p.order_id', '=', 'o.id');
            })
            ->where('p.dispatched_qty', '>', 0)
            ->whereNotExists(function (Builder $units): void {
                $units->selectRaw('1')->from('order_production_units as u')->whereColumn('u.production_id', 'p.id');
            })
            ->selectRaw("p.dispatched_qty AS released_quantity, COALESCE(p.updated_at, p.production_at) AS recognized_at, 'legacy_release' AS record_basis");

        $legacyCompletions = $this->lines($branch)
            ->whereIn('o.status', ['delivered', 'settled'])
            ->whereNotExists(function (Builder $productions): void {
                $productions->selectRaw('1')->from('order_productions as p')->whereColumn('p.order_item_id', 'oi.id');
            })
            ->selectRaw("COALESCE(oi.fulfilled_quantity, oi.received_quantity, oi.quantity) AS released_quantity, COALESCE(o.settled_at, o.confirmed_at, o.delivered_at) AS recognized_at, 'legacy_completion' AS record_basis");

        $events = $unitReleases->unionAll($legacyReleases)->unionAll($legacyCompletions);
        $rows = DB::query()->fromSub($events, 'released')
            ->where('released_quantity', '>', 0)
            ->whereDate('recognized_at', '>=', $from)->whereDate('recognized_at', '<=', $to)
            ->select('order_id', 'order_number', 'order_status', 'archived_at', 'branch_id', 'branch_name', 'line_id', 'item_name', 'unit', 'unit_price', 'record_basis')
            ->selectRaw('DATE(recognized_at) AS released_on, SUM(released_quantity) AS released_quantity, SUM(released_quantity * unit_price) AS amount')
            ->groupBy('order_id', 'order_number', 'order_status', 'archived_at', 'branch_id', 'branch_name', 'line_id', 'item_name', 'unit', 'unit_price', 'record_basis')
            ->groupByRaw('DATE(recognized_at)')
            ->orderByDesc('released_on')->orderBy('order_number')->orderBy('line_id')->get();

        return [
            'amount' => round((float) $rows->sum('amount'), 2),
            'orders' => $rows->unique('order_id')->count(),
            'legacy_rows' => $rows->where('record_basis', '<>', 'unit_release')->count(),
            'rows' => $rows->all(),
        ];
    }

    private function lines(int $branch): Builder
    {
        return DB::table('order_items as oi')
            ->join('orders as o', 'o.id', '=', 'oi.order_id')
            ->join('branches as b', 'b.id', '=', 'o.branch_id')
            ->join('items as i', 'i.id', '=', 'oi.item_id')
            ->where('b.branch_type', '<>', 'commissary')
            ->whereIn('o.status', ['delivering', 'delivered', 'settled'])
            ->when($branch > 0, fn (Builder $query): Builder => $query->where('o.branch_id', $branch))
            ->select('o.id as order_id', 'o.order_number', 'o.status as order_status', 'o.archived_at', 'b.id as branch_id', 'b.branch_name', 'oi.id as line_id', 'i.item_name', 'i.unit', 'oi.unit_price');
    }
}
