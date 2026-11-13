<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\ReceivingIssue;
use App\Services\AccountRequestService;
use App\Services\AlertService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

        // Filters. Cast to string because the global ConvertEmptyStringsToNull
        // middleware turns an empty "branch="/"type=" query value into null, and a
        // null would wrongly pass a `!== ''` guard and filter on branch_id = 0.
        $filterType = (string) $request->input('type', '');
        $filterResolved = (string) $request->input('resolved', '0');
        $filterBranch = (string) $request->input('branch', '');
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
        $refundRequests = collect();
        if ($isNotificationCenter && in_array($category, ['all', 'orders'], true) && $filterResolved !== '1' && in_array($filterType, ['', 'operational'], true)) {
            // In-progress orders, plus orders received (delivered/settled) in the
            // last 7 days so a fresh scan-in shows as a "delivered" notification.
            $orders = Order::with('branch')->where(function ($q) {
                $q->whereIn('status', ['pending', 'approved', 'preparing', 'delivering'])
                    ->orWhere(fn ($t) => $t->whereIn('status', ['delivered', 'settled'])->where('updated_at', '>=', now()->subDays(7)));
            });
            if ($user->isBranchScoped() && ! $user->isCommissaryManager()) {
                $orders->where('branch_id', $user->branch_id);
            } elseif ($filterBranch !== '') {
                $orders->where('branch_id', (int) $filterBranch);
            }
            $operationalOrderCount = (clone $orders)->count();
            $operationalOrders = $orders->latest('updated_at')->limit(25)->get();

            // Pending branch refund requests.
            $rq = ReceivingIssue::with('order.branch')->where('type', 'refund')->where('status', 'pending');
            if ($user->isBranchScoped() && ! $user->isCommissaryRole()) {
                $rq->whereHas('order', fn ($o) => $o->where('branch_id', $user->branch_id));
            } elseif ($filterBranch !== '') {
                $rq->whereHas('order', fn ($o) => $o->where('branch_id', (int) $filterBranch));
            }
            $refundRequests = $rq->latest('id')->limit(20)->get();
        }

        $sevBadges = [
            'critical' => 'hv-badge-danger',
            'warning' => 'hv-badge-warning',
            'info' => 'hv-badge-info',
        ];

        // Password recovery is now self-service OTP — there are no approval items.
        $recoveryRequests = collect();

        $accountNotices = [];
        if ($isNotificationCenter && in_array($category, ['all', 'accounts'], true) && $filterResolved !== '1') {
            $accountNotices = app(AccountRequestService::class)->notificationItemsFor($user);
            if ($filterBranch !== '') {
                $accountNotices = array_values(array_filter($accountNotices, fn ($notice) => (int) $notice['branch_id'] === (int) $filterBranch));
            }
        }

        return response()->view('alerts.index', compact(
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
            'refundRequests',
            'recoveryRequests',
            'isNotificationCenter',
            'category',
            'accountNotices'
        ))->header('Cache-Control', 'no-store, private');
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
        // Password recovery is now self-service OTP — no approval notifications.
        $recoveryItems = [];
        $recoveryTotal = 0;
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
                'ts' => $a->created_at->timestamp,
                'link' => $declinedOrder ? route('orders', array_filter(['order' => $declinedOrder->id, 'queue' => 'history', 'archived' => $declinedOrder->archived_at ? 1 : null])) : ($user->isCommissaryStaff() ? route('delivery') : route('alerts')),
            ];
        }

        // In-progress orders, plus recently received (delivered/settled) ones so a
        // branch scan-in surfaces as a "delivered" bell notification.
        $orderQuery = Order::select('orders.*', 'branches.branch_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->where(function ($q) {
                $q->whereIn('orders.status', ['pending', 'approved', 'preparing', 'delivering'])
                    ->orWhere(fn ($t) => $t->whereIn('orders.status', ['delivered', 'settled'])->where('orders.updated_at', '>=', now()->subDays(7)));
            });

        if ($scopeOrders) {
            $orderQuery->where('orders.branch_id', $user->branch_id);
        }

        // Orders placed for review (pending) are the priority, so pull them first
        // and keep room for the other in-flight stages too.
        $orders = $orderQuery
            ->orderByRaw("CASE WHEN orders.status = 'pending' THEN 0 ELSE 1 END")
            ->orderByDesc('orders.updated_at')
            ->limit(8)->get();

        foreach ($orders as $o) {
            $received = in_array($o->status, ['delivered', 'settled'], true);
            $msg = match ($o->status) {
                'pending' => "Order {$o->order_number} waiting for approval",
                'approved' => "Order {$o->order_number} approved, awaiting preparation",
                'preparing' => "Order {$o->order_number} is being prepared",
                'delivering' => "Order {$o->order_number} is being delivered",
                'delivered', 'settled' => "Order {$o->order_number} delivered — received at branch",
                default => "Order {$o->order_number} — {$o->status}",
            };
            $items[] = [
                'id' => 'order-'.$o->id.'-'.$o->status,
                'type' => 'delivery',
                'title' => $o->status === 'pending' ? 'New Order' : ($received ? 'Delivered' : 'Order Update'),
                'severity' => $o->status === 'pending' ? 'warning' : 'info',
                // Pending orders placed for review bubble to the top of the bell.
                'priority' => $o->status === 'pending' ? 1 : 0,
                'message' => $msg,
                'context' => $o->branch_name,
                'time' => $o->updated_at->diffForHumans(),
                'ts' => ($o->status === 'pending' ? $o->created_at : $o->updated_at)->timestamp,
                'link' => $user->isCommissaryStaff() ? route('delivery') : route('orders', ['order' => $o->id, 'queue' => 'active']),
            ];
        }

        // Pending branch refund requests (commissary/owner/admin see all; a
        // branch manager sees their own branch).
        $refundQuery = ReceivingIssue::with('order:id,order_number,branch_id', 'order.branch:id,branch_name')
            ->where('type', 'refund')->where('status', 'pending');
        if ($scopeOrders) {
            $refundQuery->whereHas('order', fn ($o) => $o->where('branch_id', $user->branch_id));
        }
        $refundTotal = (clone $refundQuery)->count();
        foreach ($refundQuery->latest('id')->limit(5)->get() as $rf) {
            $items[] = [
                'id' => 'refund-'.$rf->id,
                'type' => 'refund',
                'title' => 'Refund Request',
                'severity' => 'warning',
                'priority' => 1,
                'message' => 'Refund requested for Order '.($rf->order?->order_number ?? '').($rf->reason ? ': '.Str::limit($rf->reason, 60) : ''),
                'context' => $rf->order?->branch?->branch_name ?? '',
                'time' => $rf->created_at->diffForHumans(),
                'ts' => $rf->created_at->timestamp,
                'link' => route('orders', ['order' => $rf->order_id, 'queue' => 'all']),
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
            'total' => $alertTotal + $orderTotal + $recoveryTotal + $refundTotal + count($accountItems),
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
        }

        if ($request->ajax() || $request->expectsJson()) {
            return response()->json(['ok' => $updated > 0]);
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

        return redirect()->back();
    }
}
