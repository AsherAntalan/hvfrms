<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Services\AccountRequestService;
use App\Services\AlertService;
use App\Services\RecoveryApprovalService;
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
        app(AlertService::class)->generate();

        // Filters
        $filterType = $request->input('type', '');
        $filterResolved = $request->input('resolved', '0');
        $filterBranch = $request->input('branch', '');
        $isNotificationCenter = $user->canAuthorizeResets() || $user->isManagerRole();
        $category = $isNotificationCenter ? $request->query('category', 'all') : 'all';
        if (! in_array($category, ['all', 'recovery', 'inventory', 'orders', 'accounts'], true)) {
            $category = 'all';
        }

        // Per-branch alert counts (for the branch filter chips)
        $branchCounts = collect();
        if (! $user->isBranchScoped()) {
            $branchCounts = Alert::relevant()->select('branch_id', DB::raw('COUNT(*) as cnt'))
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
        $query = Alert::visibleTo($user)->with(['item', 'branch', 'resolvedByUser']);

        // Branch scope
        if ($user->isBranchScoped()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($filterBranch !== '') {
            $query->where('branch_id', (int) $filterBranch);
        }

        if ($filterType !== '') {
            $query->where('alert_type', $filterType);
        }
        if ($category === 'inventory') {
            $query->whereNull('order_id');
        } elseif ($category === 'orders') {
            $query->whereNotNull('order_id');
        } elseif (in_array($category, ['recovery', 'accounts'], true)) {
            $query->whereRaw('1 = 0');
        }

        if ($filterResolved === '0') {
            $query->where('is_resolved', false);
        } elseif ($filterResolved === '1') {
            $query->where('is_resolved', true);
        }

        $alerts = $query
            ->orderBy('is_resolved')
            ->orderByRaw("CASE severity WHEN 'critical' THEN 0 WHEN 'warning' THEN 1 ELSE 2 END")
            ->orderBy('created_at', 'desc')
            ->get();

        $countOpen = $alerts->where('is_resolved', false)->count();
        $countCritical = $alerts->where('is_resolved', false)->where('severity', 'critical')->count();

        $typeBadges = [
            'low_stock' => ['hv-badge-warning', 'ph-warning', 'Low Stock'],
            'expiring_soon' => ['hv-badge-warning', 'ph-clock-counter-clockwise',        'Expiring Soon'],
            'expired' => ['hv-badge-danger',  'ph-x-circle',            'Expired'],
            'unusual_sales' => ['hv-badge-info',    'ph-pulse',             'Unusual Stock Consumption'],
            'operational' => ['hv-badge-info',    'ph-gear',                 'Operational'],
        ];

        if ($user->isCommissaryManager()) {
            unset($typeBadges['low_stock'], $typeBadges['unusual_sales']);
        }
        $operationalOrders = collect();
        $operationalOrderCount = 0;
        if ($isNotificationCenter && in_array($category, ['all', 'orders'], true) && $filterResolved !== '1' && in_array($filterType, ['', 'operational'], true)) {
            $orders = Order::with('branch')->whereIn('status', ['pending', 'approved', 'preparing', 'delivering']);
            if ($user->isBranchScoped() && ! $user->isCommissaryManager()) {
                $orders->where('branch_id', $user->branch_id);
            } elseif ($filterBranch !== '') {
                $orders->where('branch_id', (int) $filterBranch);
            }
            $operationalOrderCount = (clone $orders)->count();
            $operationalOrders = $orders->latest('updated_at')->limit(20)->get();
        }

        $sevBadges = [
            'critical' => 'hv-badge-danger',
            'warning' => 'hv-badge-warning',
            'info' => 'hv-badge-info',
        ];

        $recoveryRequests = collect();
        if ($isNotificationCenter && in_array($category, ['all', 'recovery'], true) && $filterResolved !== '1') {
            $recoveryRequests = app(RecoveryApprovalService::class)->actionableFor($user);
            if ($filterBranch !== '' && ! $user->isBranchScoped()) {
                $recoveryRequests = $recoveryRequests->filter(fn ($reset) => (int) $reset->user->branch_id === (int) $filterBranch);
            }
        }

        $accountNotices = [];
        if ($isNotificationCenter && in_array($category, ['all', 'accounts'], true) && $filterResolved !== '1') {
            $accountNotices = app(AccountRequestService::class)->notificationItemsFor($user);
            if ($filterBranch !== '') {
                $accountNotices = array_values(array_filter($accountNotices, fn ($notice) => (int) $notice['branch_id'] === (int) $filterBranch));
            }
        }

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
            'sevBadges',
            'operationalOrders',
            'operationalOrderCount',
            'recoveryRequests',
            'isNotificationCenter',
            'category',
            'accountNotices'
        ));
    }

    /**
     * Return the count of unresolved alerts as JSON.
     * Used by the alert badge polling AJAX call.
     */
    public function count(): JsonResponse
    {
        $user = Auth::user();
        $query = Alert::visibleTo($user)->where('is_resolved', false);

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

        // Pending recovery approvals the viewer is authorized to act on. Derived
        // live, so an approval/rejection/supersede/delete by anyone, or a branch
        // or role change, updates every approver's bell on the next poll. Payloads
        // never carry codes, tokens, emails, contact numbers, or approval links.
        $recoveryItems = app(RecoveryApprovalService::class)->notificationItemsFor($user);
        $recoveryTotal = count($recoveryItems);
        $accountItems = app(AccountRequestService::class)->notificationItemsFor($user);

        // Commissary roles (like Owner/Admin) oversee every branch's orders, so
        // their order notifications are NOT scoped to their own location — a new
        // order for any branch reaches the Commissary bell.
        $scopeOrders = $user->isBranchScoped() && ! $user->isCommissaryRole();

        // Keep order decisions visible alongside the inventory alerts.
        $alertQuery = Alert::visibleTo($user)->with('order:id,order_number,decline_reason,status,archived_at')->select('alerts.*', 'items.item_name', 'branches.branch_name')
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
                'category' => $a->order_id ? 'orders' : 'inventory',
                'severity' => $a->severity,
                'message' => $declinedOrder ? 'Order '.$declinedOrder->order_number.' was declined. Reason: '.$declinedOrder->decline_reason : $a->message,
                'context' => $a->branch_name ?? '',
                'time' => $a->created_at->diffForHumans(),
                'link' => $declinedOrder ? route('orders', array_filter(['order' => $declinedOrder->id, 'queue' => 'history', 'archived' => $declinedOrder->archived_at ? 1 : null])) : ($user->isCommissaryStaff() ? route('delivery') : route('alerts')),
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
                'link' => $user->isCommissaryManager() || $user->isOwner() ? route('orders', ['order' => $o->id, 'queue' => 'active']) : route('delivery'),
            ];
        }

        // Total count
        $alertCount = Alert::visibleTo($user)->where('is_resolved', false);
        if ($user->isBranchScoped()) {
            $alertCount->where('branch_id', $user->branch_id);
        }

        $orderCount = Order::whereIn('status', ['pending', 'approved', 'preparing', 'delivering']);
        if ($scopeOrders) {
            $orderCount->where('branch_id', $user->branch_id);
        }

        $alertTotal = $alertCount->count();
        $orderTotal = $orderCount->count();

        // Surface recovery approvals first — they are the most time-bound.
        $items = array_merge($accountItems, $recoveryItems, $items);

        $pendingDeliveryCount = Order::whereIn('status', ['pending', 'approved', 'preparing', 'delivering']);
        if ($scopeOrders) {
            $pendingDeliveryCount->where('branch_id', $user->branch_id);
        }

        return response()->json([
            'ok' => true,
            'total' => $alertTotal + $orderTotal + $recoveryTotal + count($accountItems),
            'items' => $items,
            'sidebar' => [
                'orders' => $pendingDeliveryCount->count(),
                'alerts' => $alertTotal,
                'recovery' => $recoveryTotal,
            ],
        ])->header('Cache-Control', 'no-store, private');
    }

    /**
     * Resolve a single alert.
     */
    protected function resolveAlert(Request $request)
    {
        $resolveId = (int) $request->input('resolve_id');
        $user = Auth::user();

        $query = Alert::visibleTo($user)->where('id', $resolveId)->where('is_resolved', false);

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
                'action' => 'alert reviewed',
                'table_name' => 'alerts',
                'record_id' => $resolveId,
            ]);

            return redirect()->back()->with('success', 'Alert marked as reviewed.');
        }

        return redirect()->back();
    }

    /**
     * Resolve all open alerts.
     */
    protected function resolveAll(Request $request)
    {
        $user = Auth::user();

        $query = Alert::visibleTo($user)->where('is_resolved', false);

        if ($user->isBranchScoped()) {
            $query->where('branch_id', $user->branch_id);
        } elseif ($request->filled('branch')) {
            $query->where('branch_id', (int) $request->input('branch'));
        }
        if ($request->input('category') === 'inventory') {
            $query->whereNull('order_id');
        } elseif ($request->input('category') === 'orders') {
            $query->whereNotNull('order_id');
        } elseif (in_array($request->input('category'), ['recovery', 'accounts'], true)) {
            $query->whereRaw('1 = 0');
        }
        if ($request->filled('type')) {
            $query->where('alert_type', $request->input('type'));
        }

        $count = $query->update([
            'is_resolved' => true,
            'resolved_by' => $user->id,
            'resolved_at' => now(),
        ]);

        if ($count > 0) {
            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'reviewed all alerts',
                'table_name' => 'alerts',
                'record_id' => 0,
                'details' => "{$count} alert(s) marked as reviewed.",
            ]);
        }

        return redirect()->back()->with('success', "{$count} alert(s) marked as reviewed.");
    }
}
