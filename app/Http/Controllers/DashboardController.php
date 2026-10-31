<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\User;
use App\Services\CommissarySalesService;
use App\Services\DemoSalesService;
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
        $isWA = $user->isWarehouseAdmin();
        $isWS = $user->isWarehouseStaff();
        $isWarehouse = $user->isWarehouseRole();
        $isBM = $user->isBranchManager();
        $isBS = $user->isBranchStaff();

        $mainBranch = Branch::where('branch_type', 'main')->where('is_active', true)->first();
        $mainBid = $mainBranch ? $mainBranch->id : 0;

        $stats = [];

        $stats['items'] = Item::where('is_active', true)->count();
        $stats['branches'] = Branch::where('is_active', true)->count();

        // Order counts by status
        $orderCountsQuery = Order::select('status', DB::raw('COUNT(*) as cnt'))
            ->whereIn('status', ['pending', 'approved', 'preparing', 'delivering']);
        if ($isBranchScoped && ! $isWarehouse) {
            $orderCountsQuery->where('branch_id', $branchId);
        }
        $orderCounts = $orderCountsQuery->groupBy('status')->pluck('cnt', 'status');
        $stats['pending'] = $orderCounts->get('pending', 0);
        $stats['approved'] = $orderCounts->get('approved', 0);
        $stats['preparing'] = $orderCounts->get('preparing', 0);
        $stats['delivering'] = $orderCounts->get('delivering', 0);
        $stats['pending_orders'] = $stats['pending'] + $stats['approved'] + $stats['preparing'] + $stats['delivering'];

        // Delivered today
        $deliveredTodayQuery = Order::where('status', 'delivered')
            ->whereDate('updated_at', today());
        if ($isBranchScoped && ! $isWarehouse) {
            $deliveredTodayQuery->where('branch_id', $branchId);
        }
        $stats['delivered_today'] = $deliveredTodayQuery->count();

        // Alert counts by severity
        $alertCountsQuery = Alert::visibleTo($user)->select('severity', DB::raw('COUNT(*) as cnt'))
            ->where('is_resolved', false);
        if ($isBranchScoped && ! $isWarehouse) {
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

        $salesAuthorized = $isOwner || $isWA;
        $salesAvailable = $salesAuthorized;
        $salesAmount = null;
        $salesMonthStart = now('Asia/Manila')->startOfMonth();
        $salesMonthEnd = now('Asia/Manila')->endOfMonth();
        $salesPeriodLabel = $salesMonthStart->format('F Y');
        $salesRangeLabel = $salesMonthStart->format('M j').'–'.$salesMonthEnd->format('j, Y');
        $demoPreview = $salesAuthorized && app(DemoSalesService::class)->enabled();
        if ($demoPreview) {
            $salesAmount = app(DemoSalesService::class)->summary($salesMonthStart->toDateString(), now('Asia/Manila')->toDateString())['amount'];
        } elseif ($salesAuthorized) {
            $salesAmount = app(CommissarySalesService::class)->summary($salesMonthStart->toDateString(), now('Asia/Manila')->toDateString())['amount'];
        }

        $branchName = $user->branch ? $user->branch->branch_name : 'N/A';

        // Low stock count (warehouse sees main branch, others see their scope)
        $branchFilter = '';
        if ($isWarehouse) {
            $branchFilter = 'AND b.id = '.intval($mainBid);
        } elseif ($isBranchScoped) {
            $branchFilter = 'AND b.id = '.intval($branchId);
        }

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

        // Inactive branches (owner only)
        if ($isOwner) {
            $stats['inactive_branches'] = Branch::where('is_active', false)->count();
        }

        // Expired stock count (warehouse roles)
        if ($isWarehouse || $isOwner) {
            $expiredFilter = $isWarehouse ? 'AND sb.branch_id = '.intval($mainBid) : '';
            $expiredCount = DB::selectOne("
                SELECT COUNT(*) AS cnt FROM stock_batches sb
                WHERE sb.quantity_left > 0
                  AND sb.expiry_date IS NOT NULL
                  AND sb.expiry_date < CURDATE()
                  {$expiredFilter}
            ");
            $stats['expired'] = $expiredCount->cnt ?? 0;
        }

        // Recent orders
        $recentOrdersQuery = Order::select('orders.*', 'branches.branch_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id');
        if ($isBranchScoped && ! $isWarehouse) {
            $recentOrdersQuery->where('orders.branch_id', $branchId);
        }
        $recentOrders = $recentOrdersQuery
            ->orderByDesc('orders.created_at')
            ->limit(8)
            ->get();

        // Active alerts
        $activeAlerts = [];
        if ($user->canManageInventory() || $isWarehouse) {
            $alertQuery = Alert::visibleTo($user)->select('alerts.*', 'items.item_name', 'branches.branch_name')
                ->leftJoin('items', 'items.id', '=', 'alerts.item_id')
                ->leftJoin('branches', 'branches.id', '=', 'alerts.branch_id')
                ->where('alerts.is_resolved', false);

            if ($isBranchScoped && ! $isWarehouse) {
                $alertQuery->where('alerts.branch_id', $branchId);
            }

            $activeAlerts = $alertQuery
                ->orderByRaw("CASE alerts.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
                ->orderByDesc('alerts.created_at')
                ->limit(8)
                ->get();
        }

        // Low stock items
        $lowStock = [];
        if ($user->canManageInventory() || $isWarehouse) {
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
            'isWA',
            'isWS',
            'isWarehouse',
            'isBM',
            'isBS',
            'recentOrders',
            'activeAlerts',
            'lowStock',
            'salesAuthorized',
            'salesAvailable',
            'salesAmount',
            'salesPeriodLabel',
            'salesRangeLabel',
            'demoPreview',
        ));
    }
}
