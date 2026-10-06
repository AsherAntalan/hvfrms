<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class DashboardController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $isBranchScoped = $user->isBranchScoped();
        $branchId = $user->branch_id;
        $isOwner = $user->isOwner();

        // ── Summary counts ─────────────────────────────────
        $stats = [];

        $stats['items'] = Item::where('is_active', true)->count();
        $stats['branches'] = Branch::where('is_active', true)->count();

        // Order counts by status
        $orderCountsQuery = Order::select('status', DB::raw('COUNT(*) as cnt'))
            ->whereIn('status', ['pending', 'approved', 'delivering']);
        if ($isBranchScoped) {
            $orderCountsQuery->where('branch_id', $branchId);
        }
        $orderCounts = $orderCountsQuery->groupBy('status')->pluck('cnt', 'status');
        $stats['pending'] = $orderCounts->get('pending', 0);
        $stats['approved'] = $orderCounts->get('approved', 0);
        $stats['delivering'] = $orderCounts->get('delivering', 0);
        $stats['pending_orders'] = $stats['pending'] + $stats['approved'] + $stats['delivering'];

        // Delivered today
        $deliveredTodayQuery = Order::where('status', 'delivered')
            ->whereDate('updated_at', today());
        if ($isBranchScoped) {
            $deliveredTodayQuery->where('branch_id', $branchId);
        }
        $stats['delivered_today'] = $deliveredTodayQuery->count();

        // Alert counts by severity
        $alertCountsQuery = Alert::select('severity', DB::raw('COUNT(*) as cnt'))
            ->where('is_resolved', false);
        if ($isBranchScoped) {
            $alertCountsQuery->where('branch_id', $branchId);
        }
        $alertCounts = $alertCountsQuery->groupBy('severity')->pluck('cnt', 'severity');
        $stats['alerts_critical'] = $alertCounts->get('critical', 0);
        $stats['alerts_warning'] = $alertCounts->get('warning', 0);
        $stats['alerts_info'] = $alertCounts->get('info', 0);
        $stats['alerts'] = $stats['alerts_critical'] + $stats['alerts_warning'] + $stats['alerts_info'];

        // User counts (owner only)
        if ($isOwner) {
            $stats['users_active'] = User::where('status', 'active')->count();
            $stats['users_disabled'] = User::where('status', 'disabled')->count();
        }

        $branchName = $user->branch ? $user->branch->branch_name : 'N/A';

        // ── Low stock count ────────────────────────────────
        $branchFilter = $isBranchScoped
            ? 'AND b.id = ' . intval($branchId)
            : '';

        $lowStockCount = DB::selectOne("
            SELECT COUNT(*) AS cnt FROM (
                SELECT i.id, b.id AS bid,
                       COALESCE(SUM(sb.quantity_left), 0) AS total_qty,
                       i.reorder_level
                FROM items i
                CROSS JOIN branches b
                LEFT JOIN stock_batches sb ON sb.item_id = i.id AND sb.branch_id = b.id AND sb.quantity_left > 0
                WHERE i.is_active = 1 AND b.is_active = 1 {$branchFilter}
                GROUP BY i.id, b.id, i.reorder_level
            ) sub
            WHERE sub.total_qty > 0 AND sub.total_qty <= sub.reorder_level
        ");
        $stats['low_stock'] = $lowStockCount->cnt ?? 0;

        // ── Inactive branches (owner only) ─────────────────
        if ($isOwner) {
            $stats['inactive_branches'] = Branch::where('is_active', false)->count();
        }

        // ── Recent orders ──────────────────────────────────
        $recentOrdersQuery = Order::select('orders.*', 'branches.branch_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id');
        if ($isBranchScoped) {
            $recentOrdersQuery->where('orders.branch_id', $branchId);
        }
        $recentOrders = $recentOrdersQuery
            ->orderByDesc('orders.created_at')
            ->limit(8)
            ->get();

        // ── Active alerts ──────────────────────────────────
        $activeAlerts = [];
        if ($user->canManageInventory()) {
            $alertQuery = Alert::select('alerts.*', 'items.item_name', 'branches.branch_name')
                ->leftJoin('items', 'items.id', '=', 'alerts.item_id')
                ->leftJoin('branches', 'branches.id', '=', 'alerts.branch_id')
                ->where('alerts.is_resolved', false);

            if ($isBranchScoped) {
                $alertQuery->where('alerts.branch_id', $branchId);
            }

            $activeAlerts = $alertQuery
                ->orderByRaw("FIELD(alerts.severity, 'critical', 'warning', 'info')")
                ->orderByDesc('alerts.created_at')
                ->limit(8)
                ->get();
        }

        // ── Low stock items ────────────────────────────────
        $lowStock = [];
        if ($user->canManageInventory()) {
            $lowStock = DB::select("
                SELECT sub.* FROM (
                    SELECT i.item_name, i.unit, i.reorder_level,
                           b.branch_name,
                           COALESCE(SUM(sb.quantity_left), 0) AS total_qty
                    FROM items i
                    CROSS JOIN branches b
                    LEFT JOIN stock_batches sb ON sb.item_id = i.id AND sb.branch_id = b.id AND sb.quantity_left > 0
                    WHERE i.is_active = 1 AND b.is_active = 1 {$branchFilter}
                    GROUP BY i.id, b.id, i.item_name, i.unit, i.reorder_level, b.branch_name
                ) sub
                WHERE sub.total_qty > 0 AND sub.total_qty <= sub.reorder_level
                ORDER BY (sub.total_qty / sub.reorder_level) ASC
                LIMIT 10
            ");
        }

        return view('dashboard', compact(
            'user',
            'stats',
            'branchName',
            'isBranchScoped',
            'isOwner',
            'recentOrders',
            'activeAlerts',
            'lowStock',
        ));
    }
}
