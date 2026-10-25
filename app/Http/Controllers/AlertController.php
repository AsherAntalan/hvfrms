<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AlertController extends Controller
{
    /**
     * Show the alerts page.
     * Auto-generates alerts on load and handles resolve actions.
     */
    public function index(Request $request)
    {
        $user = Auth::user();

        // Handle POST actions
        if ($request->isMethod('post')) {
            $postAction = $request->input('post_action');

            if ($postAction === 'resolve') {
                return $this->resolveAlert($request);
            }

            if ($postAction === 'resolve_all') {
                return $this->resolveAll($request);
            }
        }

        // Auto-generate alerts
        $this->generateAlerts($user);

        // Filters
        $filterType = $request->input('type', '');
        $filterResolved = $request->input('resolved', '0');
        $filterBranch = $request->input('branch', '');

        // Per-branch alert counts (for the branch filter chips)
        $branchCounts = collect();
        if (! $user->isBranchScoped()) {
            $branchCounts = Alert::select('branch_id', DB::raw('COUNT(*) as cnt'))
                ->where('is_resolved', false)
                ->groupBy('branch_id')
                ->pluck('cnt', 'branch_id');
        }

        // Branches for the filter chips (aggregate users only; a scoped user is
        // locked to their own branch server-side and sees no chips).
        $branches = collect();
        if (! $user->isBranchScoped()) {
            $branches = Branch::where('is_active', true)->orderBy('branch_name')->get();
        }
        $mainBranch = Branch::where('branch_type', 'main')->where('is_active', true)->orderBy('id')->first();
        $allowAllBranches = ! $user->isBranchScoped();

        // Build query
        $query = Alert::with(['item', 'branch', 'resolvedByUser']);

        // Branch scope
        if ($user->isBranchScoped()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($filterBranch !== '') {
            $query->where('branch_id', (int) $filterBranch);
        }

        if ($filterType !== '') {
            $query->where('alert_type', $filterType);
        }

        if ($filterResolved === '0') {
            $query->where('is_resolved', false);
        } elseif ($filterResolved === '1') {
            $query->where('is_resolved', true);
        }

        $alerts = $query
            ->orderBy('is_resolved')
            ->orderByRaw("FIELD(severity, 'critical', 'warning', 'info')")
            ->orderBy('created_at', 'desc')
            ->get();

        $countOpen = $alerts->where('is_resolved', false)->count();
        $countCritical = $alerts->where('is_resolved', false)->where('severity', 'critical')->count();

        $typeBadges = [
            'low_stock' => ['hv-badge-warning', 'ph-warning', 'Low Stock'],
            'expiring_soon' => ['hv-badge-warning', 'ph-clock-counter-clockwise',        'Expiring Soon'],
            'expired' => ['hv-badge-danger',  'ph-x-circle',            'Expired'],
            'unusual_sales' => ['hv-badge-info',    'ph-pulse',             'Unusual Sales'],
            'operational' => ['hv-badge-info',    'ph-gear',                 'Operational'],
        ];

        $sevBadges = [
            'critical' => 'hv-badge-danger',
            'warning' => 'hv-badge-warning',
            'info' => 'hv-badge-info',
        ];

        return view('alerts.index', compact(
            'alerts',
            'countOpen',
            'countCritical',
            'filterType',
            'filterResolved',
            'filterBranch',
            'branches',
            'branchCounts',
            'mainBranch',
            'allowAllBranches',
            'typeBadges',
            'sevBadges'
        ));
    }

    /**
     * Return the count of unresolved alerts as JSON.
     * Used by the alert badge polling AJAX call.
     */
    public function count(): JsonResponse
    {
        $user = Auth::user();
        $query = Alert::where('is_resolved', false);

        if ($user->isBranchScoped()) {
            $query->where('branch_id', $user->branch_id);
        }

        return response()->json([
            'ok' => true,
            'count' => $query->count(),
        ]);
    }

    /**
     * Return notifications for the topbar bell.
     * Combines unresolved alerts + active delivery orders.
     */
    public function notifications(): JsonResponse
    {
        $user = Auth::user();
        $items = [];
        // Commissary roles (like Owner/Admin) oversee every branch's orders, so
        // their order notifications are NOT scoped to their own location — a new
        // order for any branch reaches the Commissary bell.
        $scopeOrders = $user->isBranchScoped() && ! $user->isCommissaryRole();

        // Keep order decisions visible alongside the inventory alerts.
        $alertQuery = Alert::with('order:id,order_number,decline_reason,status,archived_at')->select('alerts.*', 'items.item_name', 'branches.branch_name')
            ->leftJoin('items', 'items.id', '=', 'alerts.item_id')
            ->leftJoin('branches', 'branches.id', '=', 'alerts.branch_id')
            ->where('alerts.is_resolved', false);

        if ($user->isBranchScoped()) {
            $alertQuery->where('alerts.branch_id', $user->branch_id);
        }

        $orderAlerts = (clone $alertQuery)->whereNotNull('alerts.order_id')
            ->orderByDesc('alerts.created_at')->orderByDesc('alerts.id')->limit(5)->get();
        $inventoryAlerts = (clone $alertQuery)->whereNull('alerts.order_id')
            ->orderByRaw("CASE alerts.severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderByDesc('alerts.created_at')->limit(5)->get();
        $alerts = $orderAlerts->concat($inventoryAlerts);

        foreach ($alerts as $a) {
            $declinedOrder = $a->order?->status === 'declined' ? $a->order : null;
            $items[] = [
                'id' => 'alert-'.$a->id,
                'type' => $declinedOrder ? 'order_declined' : 'alert',
                'severity' => $a->severity,
                'message' => $declinedOrder ? 'Order '.$declinedOrder->order_number.' was declined. Reason: '.$declinedOrder->decline_reason : $a->message,
                'context' => $a->branch_name ?? '',
                'time' => $a->created_at->diffForHumans(),
                'link' => $declinedOrder ? route('orders', array_filter(['order' => $declinedOrder->id, 'queue' => 'history', 'archived' => $declinedOrder->archived_at ? 1 : null])) : route('alerts'),
            ];
        }

        // Pending/delivering orders (up to 5)
        $orderQuery = Order::select('orders.*', 'branches.branch_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->whereIn('orders.status', ['pending', 'approved', 'preparing', 'delivering']);

        if ($scopeOrders) {
            $orderQuery->where('orders.branch_id', $user->branch_id);
        }

        $orders = $orderQuery->orderByDesc('orders.updated_at')->limit(5)->get();

        foreach ($orders as $o) {
            $msg = match ($o->status) {
                'pending' => "Order {$o->order_number} awaiting approval",
                'approved' => "Order {$o->order_number} approved, awaiting preparation",
                'preparing' => "Order {$o->order_number} is being prepared",
                'delivering' => "Order {$o->order_number} is being delivered",
                default => "Order {$o->order_number} — {$o->status}",
            };
            $items[] = [
                'id' => 'order-'.$o->id.'-'.$o->status,
                'type' => 'delivery',
                'severity' => $o->status === 'pending' ? 'warning' : 'info',
                'message' => $msg,
                'context' => $o->branch_name,
                'time' => $o->updated_at->diffForHumans(),
                'link' => route('delivery'),
            ];
        }

        // Total count
        $alertCount = Alert::where('is_resolved', false);
        if ($user->isBranchScoped()) {
            $alertCount->where('branch_id', $user->branch_id);
        }

        $orderCount = Order::whereIn('status', ['pending', 'delivering']);
        if ($scopeOrders) {
            $orderCount->where('branch_id', $user->branch_id);
        }

        $alertTotal = $alertCount->count();
        $orderTotal = $orderCount->count();

        $pendingDeliveryCount = Order::whereIn('status', ['pending', 'approved', 'delivering']);
        if ($scopeOrders) {
            $pendingDeliveryCount->where('branch_id', $user->branch_id);
        }

        return response()->json([
            'ok' => true,
            'total' => $alertTotal + $orderTotal,
            'items' => $items,
            'sidebar' => [
                'orders' => $pendingDeliveryCount->count(),
                'alerts' => $alertTotal,
            ],
        ]);
    }

    /**
     * Resolve a single alert.
     */
    protected function resolveAlert(Request $request)
    {
        $resolveId = (int) $request->input('resolve_id');
        $user = Auth::user();

        $query = Alert::where('id', $resolveId)->where('is_resolved', false);

        if ($user->isBranchScoped()) {
            $query->where('branch_id', $user->branch_id);
        }

        $updated = $query->update([
            'is_resolved' => true,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        if ($updated > 0) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'alert resolved',
                'table_name' => 'alerts',
                'record_id' => $resolveId,
            ]);

            return redirect()->back()->with('success', 'Alert marked as resolved.');
        }

        return redirect()->back();
    }

    /**
     * Resolve all open alerts.
     */
    protected function resolveAll(Request $request)
    {
        $user = Auth::user();

        $query = Alert::where('is_resolved', false);

        if ($user->isBranchScoped()) {
            $query->where('branch_id', $user->branch_id);
        }

        $count = $query->update([
            'is_resolved' => true,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        if ($count > 0) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'resolved all alerts',
                'table_name' => 'alerts',
                'record_id' => 0,
                'details' => "{$count} alert(s) resolved.",
            ]);
        }

        return redirect()->back()->with('success', "{$count} alert(s) resolved.");
    }

    /**
     * Auto-generate alerts based on current stock levels and expiry dates.
     */
    protected function generateAlerts($user): void
    {
        $expiryWarningDays = max(1, (int) Setting::get('expiry_warning_days', '7'));
        $unusualSalesSigma = max(1.0, (float) Setting::get('unusual_sales_sigma', '2'));
        $idleBranchDays = max(1, (int) Setting::get('idle_branch_days', '14'));
        $suppressWindow = now()->subHours(24);

        // 1. LOW STOCK ALERTS
        $lowStock = DB::select('
            SELECT sub.* FROM (
                SELECT i.id AS item_id, i.item_name, i.unit, i.reorder_level,
                       b.id AS branch_id, b.branch_name,
                       COALESCE(SUM(sb.quantity_left), 0) AS total_qty
                FROM items i
                CROSS JOIN branches b
                LEFT JOIN stock_batches sb ON sb.item_id = i.id AND sb.branch_id = b.id AND sb.quantity_left > 0
                WHERE i.is_active = 1 AND b.is_active = 1
                GROUP BY i.id, b.id, i.item_name, i.unit, i.reorder_level, b.branch_name
            ) sub
            WHERE sub.total_qty > 0 AND sub.total_qty <= sub.reorder_level
            ORDER BY sub.total_qty ASC
        ');

        foreach ($lowStock as $ls) {
            $exists = Alert::where('alert_type', 'low_stock')
                ->where('item_id', $ls->item_id)
                ->where('branch_id', $ls->branch_id)
                ->where(fn ($q) => $q->where('is_resolved', false)
                    ->orWhere('resolved_at', '>=', $suppressWindow))
                ->exists();

            if (! $exists) {
                $sev = $ls->total_qty <= ($ls->reorder_level / 2) ? 'critical' : 'warning';
                Alert::create([
                    'alert_type' => 'low_stock',
                    'severity' => $sev,
                    'message' => "{$ls->item_name} is low at {$ls->branch_name}: {$ls->total_qty} {$ls->unit}(s) remaining (reorder level: {$ls->reorder_level}).",
                    'item_id' => $ls->item_id,
                    'branch_id' => $ls->branch_id,
                ]);
            }
        }

        // 2. EXPIRING SOON ALERTS (within 7 days)
        $expiring = DB::select('
            SELECT sb.id AS batch_id, sb.item_id, sb.branch_id, sb.quantity_left, sb.expiry_date,
                   i.item_name, i.unit, b.branch_name,
                   DATEDIFF(sb.expiry_date, CURDATE()) AS days_left
            FROM stock_batches sb
            JOIN items i ON i.id = sb.item_id
            JOIN branches b ON b.id = sb.branch_id
            WHERE sb.quantity_left > 0
              AND sb.expiry_date IS NOT NULL
              AND sb.expiry_date > CURDATE()
              AND DATEDIFF(sb.expiry_date, CURDATE()) <= ?
        ', [$expiryWarningDays]);

        foreach ($expiring as $ex) {
            $exists = Alert::where('alert_type', 'expiring_soon')
                ->where('batch_id', $ex->batch_id)
                ->where(fn ($q) => $q->where('is_resolved', false)
                    ->orWhere('resolved_at', '>=', $suppressWindow))
                ->exists();

            if (! $exists) {
                $sev = $ex->days_left <= 2 ? 'critical' : 'warning';
                Alert::create([
                    'alert_type' => 'expiring_soon',
                    'severity' => $sev,
                    'message' => "{$ex->item_name} (Batch #{$ex->batch_id}) at {$ex->branch_name} expires in {$ex->days_left} day(s) - {$ex->quantity_left} {$ex->unit}(s) remaining.",
                    'item_id' => $ex->item_id,
                    'branch_id' => $ex->branch_id,
                    'batch_id' => $ex->batch_id,
                ]);
            }
        }

        // 3. EXPIRED ALERTS
        $expired = DB::select('
            SELECT sb.id AS batch_id, sb.item_id, sb.branch_id, sb.quantity_left, sb.expiry_date,
                   i.item_name, i.unit, b.branch_name,
                   ABS(DATEDIFF(sb.expiry_date, CURDATE())) AS days_overdue
            FROM stock_batches sb
            JOIN items i ON i.id = sb.item_id
            JOIN branches b ON b.id = sb.branch_id
            WHERE sb.quantity_left > 0
              AND sb.expiry_date IS NOT NULL
              AND sb.expiry_date < CURDATE()
        ');

        foreach ($expired as $eb) {
            $exists = Alert::where('alert_type', 'expired')
                ->where('batch_id', $eb->batch_id)
                ->where(fn ($q) => $q->where('is_resolved', false)
                    ->orWhere('resolved_at', '>=', $suppressWindow))
                ->exists();

            if (! $exists) {
                Alert::create([
                    'alert_type' => 'expired',
                    'severity' => 'critical',
                    'message' => "{$eb->item_name} (Batch #{$eb->batch_id}) at {$eb->branch_name} has EXPIRED ({$eb->days_overdue} day(s) ago) - {$eb->quantity_left} {$eb->unit}(s) still in stock.",
                    'item_id' => $eb->item_id,
                    'branch_id' => $eb->branch_id,
                    'batch_id' => $eb->batch_id,
                ]);
            }
        }

        // 4. UNUSUAL SALES ALERTS (consumption spikes beyond N sigma)
        $weeklyUsage = DB::select("
            SELECT st.item_id, st.branch_id, i.item_name, b.branch_name,
                   YEARWEEK(st.created_at, 1) AS yw,
                   ABS(SUM(st.quantity)) AS weekly_total
            FROM stock_transactions st
            JOIN items i ON i.id = st.item_id
            JOIN branches b ON b.id = st.branch_id
            WHERE st.type IN ('out', 'adjustment', 'damaged', 'expired')
              AND st.created_at >= DATE_SUB(CURDATE(), INTERVAL 8 WEEK)
            GROUP BY st.item_id, st.branch_id, i.item_name, b.branch_name, YEARWEEK(st.created_at, 1)
        ");

        $grouped = [];
        foreach ($weeklyUsage as $row) {
            $key = $row->item_id.'-'.$row->branch_id;
            $grouped[$key]['item_id'] = $row->item_id;
            $grouped[$key]['branch_id'] = $row->branch_id;
            $grouped[$key]['item_name'] = $row->item_name;
            $grouped[$key]['branch_name'] = $row->branch_name;
            $grouped[$key]['weeks'][] = (float) $row->weekly_total;
        }

        foreach ($grouped as $g) {
            $vals = $g['weeks'];
            if (count($vals) < 3) {
                continue;
            }

            $mean = array_sum($vals) / count($vals);
            $variance = array_sum(array_map(fn ($v) => ($v - $mean) ** 2, $vals)) / count($vals);
            $stdDev = sqrt($variance);
            if ($stdDev < 1) {
                continue;
            }

            $latest = end($vals);
            if ($latest > $mean + ($unusualSalesSigma * $stdDev)) {
                $exists = Alert::where('alert_type', 'unusual_sales')
                    ->where('item_id', $g['item_id'])
                    ->where('branch_id', $g['branch_id'])
                    ->where(fn ($q) => $q->where('is_resolved', false)
                        ->orWhere('resolved_at', '>=', $suppressWindow))
                    ->exists();

                if (! $exists) {
                    Alert::create([
                        'alert_type' => 'unusual_sales',
                        'severity' => 'warning',
                        'message' => "{$g['item_name']} at {$g['branch_name']} had unusually high consumption this week: ".(int) $latest.' units (avg: '.round($mean, 1).').',
                        'item_id' => $g['item_id'],
                        'branch_id' => $g['branch_id'],
                    ]);
                }
            }
        }

        // 5. OPERATIONAL ALERTS (idle branches with no stock transactions)
        $idleBranches = DB::select("
            SELECT b.id AS branch_id, b.branch_name,
                   DATEDIFF(CURDATE(), COALESCE(MAX(st.created_at), b.created_at)) AS days_idle
            FROM branches b
            LEFT JOIN stock_transactions st ON st.branch_id = b.id
            WHERE b.is_active = 1 AND b.branch_type != 'main'
            GROUP BY b.id, b.branch_name, b.created_at
            HAVING days_idle >= ?
        ", [$idleBranchDays]);

        foreach ($idleBranches as $ib) {
            $exists = Alert::where('alert_type', 'operational')->whereNull('order_id')
                ->where('branch_id', $ib->branch_id)
                ->where(fn ($q) => $q->where('is_resolved', false)
                    ->orWhere('resolved_at', '>=', $suppressWindow))
                ->exists();

            if (! $exists) {
                Alert::create([
                    'alert_type' => 'operational',
                    'severity' => 'info',
                    'message' => "{$ib->branch_name} has had no stock activity for {$ib->days_idle} day(s).",
                    'branch_id' => $ib->branch_id,
                ]);
            }
        }
    }
}
