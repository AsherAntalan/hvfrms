<?php

namespace App\Services;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

class DemoSalesService
{
    public function enabled(): bool
    {
        if (! config('demo.preview', false) || ! app()->environment(['local', 'testing']) || config('database.default') !== 'demo') {
            return false;
        }
        $database = DB::connection()->getDatabaseName();

        return str_contains(strtolower($database), 'demo')
            && $database === config('database.connections.demo.database')
            && strcasecmp($database, (string) config('database.connections.mysql.database')) !== 0;
    }

    /** @return array{amount: float, orders: int, rows: array<int, object>} */
    public function summary(string $from, string $to, int $branch = 0): array
    {
        abort_unless($this->enabled(), 403);
        $rows = DB::table('orders as o')->join('branches as b', 'b.id', '=', 'o.branch_id')
            ->where('o.remarks', 'like', 'DEMO-HVFRMS%')->where('o.status', 'settled')
            ->where('b.is_active', true)->where('b.branch_type', '<>', 'commissary')
            ->whereDate('o.settled_at', '>=', $from)->whereDate('o.settled_at', '<=', $to)
            ->when($branch > 0, fn (Builder $query) => $query->where('o.branch_id', $branch))
            ->select('b.id as branch_id', 'b.branch_name')->selectRaw('COUNT(*) AS closed_orders, SUM(COALESCE(o.fulfilled_amount, o.total_amount)) AS amount')
            ->groupBy('b.id', 'b.branch_name')->orderBy('b.branch_name')->get();

        return ['amount' => (float) $rows->sum('amount'), 'orders' => (int) $rows->sum('closed_orders'), 'rows' => $rows->all()];
    }
}
