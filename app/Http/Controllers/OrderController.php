<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\DeliveryDiscrepancy;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\Setting;
use App\Models\User;
use App\Services\OrderGroupLabelService;
use App\Services\OrderLabelService;
use App\Services\OrderPaymentService;
use App\Services\OrderWorkflowService;
use App\Services\StockService;
use Carbon\Carbon;
use Illuminate\Contracts\View\View;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderController extends Controller
{
    public function index(Request $request): View
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $canManage = $user->isOwner();
        $isBranchScoped = $user->isBranchScoped();
        $isWarehouse = $user->isWarehouseRole();
        $isWA = $user->isWarehouseAdmin();
        $isWS = $user->isWarehouseStaff();
        $isBM = $user->isBranchManager();
        $showArchivedOrders = $request->boolean('archived');
        $canArchiveOrders = $user->isOwner() || $user->isCommissaryManager() || $isBM;

        // Fetch orders with branch and user names
        $ordersQuery = Order::select('orders.*', 'branches.branch_name',
            'u_ord.full_name as ordered_by_name',
            'u_app.full_name as approved_by_name', 'u_arc.full_name as archived_by_name')
            ->selectRaw('(SELECT COUNT(*) FROM order_productions p WHERE p.order_id = orders.id AND (p.stock_batch_id IS NOT NULL OR EXISTS (SELECT 1 FROM order_production_units u WHERE u.production_id = p.id))) AS piece_outputs')
            ->selectRaw('(SELECT COALESCE(SUM(p.produced_qty),0) FROM order_productions p WHERE p.order_id = orders.id) AS prepared_units')
            ->selectRaw('(SELECT COALESCE(SUM(p.dispatched_qty),0) FROM order_productions p WHERE p.order_id = orders.id) AS released_units')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('users as u_ord', 'u_ord.id', '=', 'orders.ordered_by')
            ->leftJoin('users as u_app', 'u_app.id', '=', 'orders.approved_by')
            ->leftJoin('users as u_arc', 'u_arc.id', '=', 'orders.archived_by');

        if ($isBranchScoped && ! $isWarehouse) {
            $ordersQuery->where('orders.branch_id', $user->branch_id);
        }

        $archivedOrderCount = (clone $ordersQuery)->whereNotNull('orders.archived_at')->count();
        if ($showArchivedOrders) {
            $ordersQuery->whereNotNull('orders.archived_at');
        } else {
            $ordersQuery->whereNull('orders.archived_at');
        }
        $orders = $ordersQuery->orderByDesc($showArchivedOrders ? 'orders.archived_at' : 'orders.created_at')->get();
        // Organized work-queue tabs. Commissary roles get the granular operational
        // queues; Owner/Admin get a simple Active / History / All grouping where a
        // delivered-and-received (or cancelled/declined) order moves to History.
        $simpleQueues = $user->isOwner() && ! $user->isCommissaryRole();
        $organizeCommissaryOrders = $user->isCommissaryRole() || $simpleQueues;
        $orderQueues = $simpleQueues
            ? ['active' => 'Active', 'history' => 'History', 'all' => 'All Orders']
            : ['review' => 'Needs Review', 'prepare' => 'To Prepare', 'release' => 'For Release', 'transit' => 'In Transit', 'history' => 'History', 'all' => 'All Orders'];
        $orderQueueCounts = array_fill_keys(array_keys($orderQueues), 0);
        $defaultOrderQueue = 'all';
        if ($organizeCommissaryOrders) {
            foreach ($orders as $order) {
                $order->setAttribute('work_queue', $simpleQueues ? $this->simpleOrderQueue($order) : $this->commissaryOrderQueue($order));
                if ($order->work_queue !== 'all') {
                    $orderQueueCounts[$order->work_queue]++;
                }
            }
            $orderQueueCounts['all'] = $orders->count();
            $priorities = $user->isCommissaryStaff() ? ['release', 'transit', 'review', 'prepare', 'history', 'all'] : array_keys($orderQueues);
            foreach ($priorities as $queue) {
                if ($orderQueueCounts[$queue] > 0) {
                    $defaultOrderQueue = $queue;
                    break;
                }
            }
        }

        if ($showArchivedOrders) {
            $orderQueues = ['all' => 'Archived Orders'];
            $orderQueueCounts = ['all' => $orders->count()];
            $defaultOrderQueue = 'all';
            foreach ($orders as $order) {
                $order->setAttribute('work_queue', 'all');
            }
        } elseif ($organizeCommissaryOrders && is_string($request->query('queue')) && array_key_exists($request->query('queue'), $orderQueues)) {
            $defaultOrderQueue = $request->query('queue');
        }

        // Active items for the order form
        $items = Item::select('items.id', 'items.item_name', 'items.unit', 'items.price',
            'items.pack_description', 'categories.name as category_name')
            ->leftJoin('categories', 'categories.id', '=', 'items.category_id')
            ->where('items.is_active', true)
            ->orderBy('categories.name')
            ->orderBy('items.item_name')
            ->get();

        // Branches for dropdown
        $branches = Branch::where('is_active', true)->orderBy('branch_name')->get();

        $productsJson = $items->map(function ($i) {
            return [
                'id' => $i->id,
                'name' => $i->item_name,
                'unit' => $i->unit,
                'price' => (float) $i->price,
                'category' => $i->category_name ?? '',
                'pack' => $i->pack_description ?? '',
            ];
        });

        $openOrderId = (int) $request->query('order', 0);
        if (! $orders->contains('id', $openOrderId)) {
            $openOrderId = 0;
        }

        return view('orders.index', compact('user', 'canManage', 'isBranchScoped', 'isWarehouse', 'isWA', 'isWS', 'isBM', 'orders', 'items', 'branches', 'productsJson', 'organizeCommissaryOrders', 'orderQueues', 'orderQueueCounts', 'defaultOrderQueue', 'showArchivedOrders', 'canArchiveOrders', 'archivedOrderCount', 'openOrderId'));
    }

    /** Display grouping only; actual workflow statuses remain unchanged. */
    /**
     * Owner/Admin view: a delivered-and-received, settled, cancelled, or declined
     * order is History; everything still in progress is Active.
     */
    private function simpleOrderQueue(Order $order): string
    {
        return in_array($order->status, Order::HISTORY_STATUSES, true) ? 'history' : 'active';
    }

    private function commissaryOrderQueue(Order $order): string
    {
        if (in_array($order->status, Order::HISTORY_STATUSES, true)) {
            return 'history';
        }
        if ($order->status === 'pending') {
            return 'review';
        }
        if ($order->piece_outputs && (int) $order->prepared_units > (int) $order->released_units) {
            return 'release';
        }
        if ($order->status === 'delivering' || ($order->piece_outputs && (int) $order->prepared_units > 0)) {
            return 'transit';
        }

        return in_array($order->status, ['approved', 'preparing'], true) ? 'prepare' : 'all';
    }

    /**
     * Printable order-bound production QR labels. Owner / Main Branch Manager
     * only. Data comes from saved OrderProduction records, never the client.
     */
    public function labels(Order $order)
    {
        $user = Auth::user();
        if (! $user->isOwner() && ! $user->isMainBranchManager()) {
            abort(403);
        }
        $order->load('branch');
        $prods = OrderProduction::where('order_productions.order_id', $order->id)
            ->join('items', 'items.id', '=', 'order_productions.item_id')
            ->select('order_productions.*', 'items.item_name', 'items.unit')
            ->orderBy('order_productions.id')
            ->get();
        if ($prods->isEmpty()) {
            abort(404, 'No produced labels for this order yet.');
        }

        return view('orders.labels', [
            'order' => $order,
            'branchName' => $order->branch->branch_name ?? '',
            'prods' => $prods,
            'unitLabels' => DB::table('order_production_units as u')
                ->join('order_productions as p', 'p.id', '=', 'u.production_id')
                ->join('items as i', 'i.id', '=', 'p.item_id')
                ->leftJoin('stock_batches as sb', function ($join) {
                    $join->on('sb.id', '=', DB::raw('COALESCE(u.stock_batch_id, p.stock_batch_id)'));
                })
                ->where('p.order_id', $order->id)
                ->select('u.*', 'p.qr_code as group_qr_code', 'p.source_type', 'p.production_at', DB::raw("CASE WHEN p.source_type = 'picked' THEN sb.expiry_date ELSE p.expiry_date END as expiry_date"), 'p.produced_qty', 'i.item_name', 'i.unit')
                ->orderBy('p.id')->orderBy('u.unit_number')->get(),
        ]);
    }

    private function setOrderArchive(Request $request, User $user, bool $restore): JsonResponse
    {
        abort_unless($user->isOwner() || $user->isCommissaryManager() || $user->isBranchManager(), 403);
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($data, $request, $user, $restore): JsonResponse {
            $order = Order::whereKey($data['id'])->lockForUpdate()->firstOrFail();
            abort_if($user->isBranchManager() && (int) $order->branch_id !== (int) $user->branch_id, 403);
            if (! $restore && ! in_array($order->status, Order::HISTORY_STATUSES, true)) {
                return response()->json(['ok' => false, 'error' => 'Only orders in History can be archived. Complete or close this order first.'], 422);
            }

            $already = $restore ? $order->archived_at === null : $order->archived_at !== null;
            if (! $already) {
                $order->update([
                    'archived_at' => $restore ? null : now(),
                    'archived_by' => $restore ? null : $user->id,
                ]);
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => $restore ? 'order restored from archive' : 'order archived',
                    'table_name' => 'orders',
                    'record_id' => $order->id,
                    'details' => 'Order '.$order->order_number.($restore ? ' restored to Order History.' : ' moved to Archived Orders.').' Status and all order, payment, and inventory records retained.',
                    'ip_address' => $request->ip(),
                ]);
            }

            return response()->json([
                'ok' => true,
                'already' => $already,
                'message' => $restore ? 'Order restored to History.' : 'Order moved to Archived Orders.',
                'redirect_url' => $restore ? route('orders', ['archived' => 1]) : route('orders', ['queue' => 'history']),
            ]);
        });
    }

    /**
     * Permanently delete an ARCHIVED order and all its dependent records.
     * Owner-level only (Owner / Admin / super_admin). Irreversible.
     */
    private function deleteArchivedOrder(Request $request, User $user): JsonResponse
    {
        abort_unless($user->isOwner(), 403);
        $data = $request->validate(['id' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($data, $request, $user): JsonResponse {
            $order = Order::whereKey($data['id'])->lockForUpdate()->firstOrFail();
            if ($order->archived_at === null) {
                return response()->json(['ok' => false, 'error' => 'Only archived orders can be permanently deleted. Archive it first.'], 422);
            }
            $number = $order->order_number;

            $productionIds = DB::table('order_productions')->where('order_id', $order->id)->pluck('id')->all();
            if (! empty($productionIds)) {
                DB::table('order_production_units')->whereIn('production_id', $productionIds)->delete();
            }
            DB::table('order_productions')->where('order_id', $order->id)->delete();
            DB::table('delivery_discrepancies')->where('order_id', $order->id)->delete();
            DB::table('alerts')->where('order_id', $order->id)->delete();
            DB::table('order_items')->where('order_id', $order->id)->delete();
            DB::table('orders')->where('id', $order->id)->delete();

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'order permanently deleted',
                'table_name' => 'orders',
                'record_id' => $data['id'],
                'details' => "Archived order {$number} permanently deleted with its items, productions, and discrepancy records.",
                'ip_address' => $request->ip(),
            ]);

            return response()->json(['ok' => true, 'message' => "Order {$number} permanently deleted.", 'redirect_url' => route('delivery', ['tab' => 'archived'])]);
        });
    }

    public function api(Request $request)
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $action = $request->input('action', '');
        if (in_array($action, ['archive_order', 'restore_order'], true)) {
            return $this->setOrderArchive($request, $user, $action === 'restore_order');
        }
        if ($action === 'delete_order') {
            return $this->deleteArchivedOrder($request, $user);
        }
        if ($action === 'update_status' && $request->input('status') === 'delivered') {
            $action = 'receive_scan';
        }
        if ($action === 'update_status' && $request->input('status') === 'cancelled') {
            $action = 'cancel';
        }
        if ($action === 'branch_confirm') {
            $action = 'receive_scan';
        }
        if ($action === 'preparation_options') {
            abort_unless($user->isOwner() || $user->isMainBranchManager(), 403);
            $order = Order::findOrFail((int) $request->input('id'));
            $main = Branch::commissary();
            abort_unless($main, 422, 'No active Commissary location is configured.');

            return response()->json(['ok' => true, 'lines' => $order->orderItems->map(fn ($line) => [
                'order_item_id' => $line->id, 'available' => (new StockService)->eligibleAvailable($line->item_id, $main->id),
            ])]);
        }
        if ($action === 'review_order' || ($action === 'update_status' && $request->input('status') === 'approved')) {
            return response()->json((new OrderLabelService)->review(Order::findOrFail((int) $request->input('id')), $user));
        }

        if (in_array($action, ['prepare_order', 'approve_prepare', 'print_order_labels'], true)) {
            abort_unless($user->isOwner() || $user->isMainBranchManager(), 403);
            $order = Order::findOrFail((int) $request->input('id'));
            $labels = new OrderLabelService;
            if ($action === 'print_order_labels') {
                return response()->json($labels->startPreparing($order, $user));
            }
            $expiry = json_decode($request->input('expiry', '{}'), true);
            if (! is_array($expiry)) {
                return response()->json(['ok' => false, 'error' => 'Invalid expiry entries.'], 422);
            }

            $fulfillment = json_decode($request->input('fulfillment', '{}'), true);
            if (! is_array($fulfillment)) {
                return response()->json(['ok' => false, 'error' => 'Invalid fulfillment plan.'], 422);
            }

            return response()->json($labels->prepare($order, $expiry, $user, $fulfillment));
        }

        // ── CREATE ORDER ────────────────────────────────────
        if ($action === 'create') {
            // Read the destination from a dedicated field so the branch.scope
            // middleware (which rewrites branch_id to the actor's own location)
            // cannot silently redirect an encoded order's destination.
            $branchId = (int) ($request->input('destination_branch_id') ?? $request->input('branch_id', 0));
            $remarks = trim($request->input('remarks', ''));
            $lineItems = json_decode($request->input('line_items', '[]'), true);

            if ($branchId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Please select a branch.'], 422);
            }

            // Branch access check. Owner/Admin and the Commissary Manager may
            // encode an order for any active receiving branch; a Branch Manager/
            // Staff can only create for their own branch. The encoding user is
            // still recorded as the creator (ordered_by); branch_id is the
            // separate receiving destination.
            $canEncodeAnyBranch = $user->isOwner() || $user->isCommissaryManager();
            if (! $canEncodeAnyBranch && $user->branch_id !== $branchId) {
                return response()->json(['ok' => false, 'error' => 'You do not have access to this branch.'], 403);
            }

            $branch = Branch::where('id', $branchId)->where('is_active', true)->first();
            if (! $branch) {
                return response()->json(['ok' => false, 'error' => 'Branch not found or is inactive.'], 422);
            }

            if (! is_array($lineItems) || count($lineItems) === 0) {
                return response()->json(['ok' => false, 'error' => 'Please add at least one item.'], 422);
            }

            // Aggregate duplicate product lines before availability checks so
            // two lines of 2 cannot bypass a maximum of 3. Reject malformed
            // quantities instead of silently truncating them.
            $byItem = [];
            foreach ($lineItems as $li) {
                $itemId = (int) ($li['item_id'] ?? 0);
                $rawQty = $li['quantity'] ?? null;

                if ($itemId <= 0) {
                    return response()->json(['ok' => false, 'error' => 'Invalid product in line items.'], 422);
                }
                if (! is_numeric($rawQty) || (int) $rawQty != $rawQty || (int) $rawQty <= 0) {
                    return response()->json(['ok' => false, 'error' => 'Invalid quantity in line items.'], 422);
                }
                $byItem[$itemId] = ($byItem[$itemId] ?? 0) + (int) $rawQty;
            }

            // Tanza is the commissary: finished products are MADE on order, so
            // creation is NOT gated on existing finished stock. The only numeric
            // policy is the owner-configurable per-line quantity limit.
            $maxPerLine = (int) Setting::get('order_max_qty_per_line', '0');

            // The Commissary is the source, never a destination for its own order.
            $mainBranch = Branch::commissary();
            if ($mainBranch && (int) $branchId === (int) $mainBranch->id) {
                return response()->json(['ok' => false, 'error' => 'The Commissary is the source location and cannot be an order destination.'], 422);
            }

            $validatedItems = [];
            foreach ($byItem as $itemId => $quantity) {
                $item = Item::where('id', $itemId)->where('is_active', true)->first();
                if (! $item) {
                    return response()->json(['ok' => false, 'error' => "Product ID {$itemId} not found or is inactive."], 422);
                }

                if ($maxPerLine > 0 && $quantity > $maxPerLine) {
                    return response()->json([
                        'ok' => false,
                        'error' => "{$item->item_name}: {$quantity} exceeds the per-product limit of {$maxPerLine}.",
                    ], 422);
                }

                $validatedItems[] = [
                    'item_id' => $itemId,
                    'item_name' => $item->item_name,
                    'unit' => $item->unit,
                    'quantity' => $quantity,
                    'unit_price' => (float) $item->price,
                ];
            }

            $totalAmount = 0;
            foreach ($validatedItems as $vi) {
                $totalAmount += $vi['unit_price'] * $vi['quantity'];
            }

            $payments = new OrderPaymentService;
            $payment = $payments->validateSubmission($request);
            $paymentPath = null;

            DB::beginTransaction();
            try {
                // Generate order number: ORD-YYYY-NNNN (inside transaction with retry)
                $year = date('Y');
                $maxRetries = 5;
                for ($attempt = 1; $attempt <= $maxRetries; $attempt++) {
                    $count = Order::where('order_number', 'like', "ORD-{$year}-%")->count();
                    $orderNumber = sprintf('ORD-%s-%04d', $year, $count + 1);

                    if (! Order::where('order_number', $orderNumber)->exists()) {
                        break;
                    }

                    if ($attempt === $maxRetries) {
                        // Fallback: use max numeric suffix + 1
                        $last = Order::where('order_number', 'like', "ORD-{$year}-%")
                            ->orderByDesc('order_number')
                            ->value('order_number');
                        $lastNum = $last ? (int) substr($last, -4) : 0;
                        $orderNumber = sprintf('ORD-%s-%04d', $year, $lastNum + 1);
                    }
                }

                $order = Order::create([
                    'order_number' => $orderNumber,
                    'branch_id' => $branchId,
                    'status' => 'pending',
                    'ordered_by' => $user->id,
                    'remarks' => $remarks ?: null,
                    'total_amount' => $totalAmount,
                ]);

                foreach ($validatedItems as $vi) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'item_id' => $vi['item_id'],
                        'quantity' => $vi['quantity'],
                        'unit_price' => $vi['unit_price'],
                    ]);
                }

                $paymentPath = $payments->attach($order, $payment);

                $summary = implode(', ', array_map(fn ($vi) => "{$vi['quantity']}x {$vi['item_name']}", $validatedItems));
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'order created',
                    'table_name' => 'orders',
                    'record_id' => $order->id,
                    'details' => "Order {$orderNumber}, Branch ID: {$branchId}, Total: PHP ".number_format($totalAmount, 2).", Items: {$summary}",
                    'ip_address' => $request->ip(),
                ]);

                DB::commit();

                return response()->json([
                    'ok' => true,
                    'message' => "Order {$orderNumber} placed successfully! Payment proof submitted for review.",
                    'order_id' => $order->id,
                    'order_number' => $orderNumber,
                    'proforma_url' => null,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();

                if ($paymentPath) {
                    Storage::disk('local')->delete($paymentPath);
                }
                report($e);

                return response()->json(['ok' => false, 'error' => 'Failed to create order. Please try again.'], 500);
            }
        }

        // ── UPDATE STATUS ───────────────────────────────────
        if ($action === 'update_status') {
            return response()->json(['ok' => false, 'error' => 'Use Review, Prepare/Pick, scan-out, or receipt confirmation. Order stages follow recorded goods movements.'], 422);
        }

        // ── PREPARE PRODUCED OUTPUT (Main Branch Manager / Owner) ──
        if ($action === 'prepare_output') {
            if (! $user->isOwner() && ! $user->isMainBranchManager()) {
                return response()->json(['ok' => false, 'error' => 'Only the Main Branch Manager or Owner can prepare produced output.'], 403);
            }
            $orderId = (int) $request->input('id', 0);
            $order = Order::find($orderId);
            if (! $order) {
                return response()->json(['ok' => false, 'error' => 'Order not found.'], 422);
            }
            $lines = json_decode($request->input('lines', '[]'), true);
            if (! is_array($lines) || empty($lines)) {
                return response()->json(['ok' => false, 'error' => 'Provide produced output for the order lines.'], 422);
            }
            $map = [];
            foreach ($lines as $l) {
                $oid = (int) ($l['order_item_id'] ?? 0);
                if ($oid > 0) {
                    $map[$oid] = [
                        'produced_qty' => $l['produced_qty'] ?? 0,
                        'expiry_date' => $l['expiry_date'] ?? null,
                        'condition' => $l['condition'] ?? 'good',
                    ];
                }
            }
            $expiry = [];
            $fulfillment = [];
            foreach ($map as $id => $line) {
                if ($line['condition'] !== 'good') {
                    return response()->json(['ok' => false, 'error' => 'Damaged goods cannot be prepared for delivery.'], 422);
                }
                $expiry[$id] = $line['expiry_date'];
                $fulfillment[$id] = ['quantity' => $line['produced_qty'], 'source' => 'produced', 'reason' => $request->input('reason', '')];
            }
            $res = (new OrderLabelService)->prepare($order, $expiry, $user, $fulfillment);

            return response()->json($res, $res['ok'] ? 200 : 422);
        }

        // ── MAIN BRANCH SCAN-OUT / DISPATCH (Staff / Manager / Owner) ──
        if ($action === 'dispatch_scan') {
            if (! $user->isOwner() && ! $user->isMainBranchRole()) {
                return response()->json(['ok' => false, 'error' => 'You are not authorized to dispatch Main Branch goods.'], 403);
            }
            $qr = trim((string) $request->input('qr', ''));
            if (str_contains($qr, '-U')) {
                return response()->json((new OrderLabelService)->dispatch($qr, $user, $request->input('quantity', 1)));
            }

            return response()->json((new OrderGroupLabelService)->dispatch($qr, $user, $request->input('quantity'), $request->input('idempotency_key')));
        }

        // ── MANUAL DELIVER (privileged scan-out fallback; Owner / Main Branch Mgr) ──
        if ($action === 'manual_deliver') {
            if (! $user->isOwner() && ! $user->isMainBranchManager()) {
                return response()->json(['ok' => false, 'error' => 'Only the Owner or Main Branch Manager can manually deliver without a scan.'], 403);
            }
            $orderId = (int) $request->input('id', 0);
            $reason = trim((string) $request->input('reason', ''));
            if ($orderId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Invalid order.'], 422);
            }
            if ($reason === '') {
                return response()->json(['ok' => false, 'error' => 'A reason for bypassing Scan-Out is required.'], 422);
            }
            $order = Order::select('orders.*', 'branches.branch_name')
                ->join('branches', 'branches.id', '=', 'orders.branch_id')
                ->where('orders.id', $orderId)->first();
            if (! $order) {
                return response()->json(['ok' => false, 'error' => 'Order not found.'], 422);
            }
            $res = (new OrderWorkflowService)->manualDispatch($order, $user->id, $reason);

            return response()->json($res, $res['ok'] ? 200 : 422);
        }

        // ── REQUESTER CANCELLATION (before any dispatch) ──
        if ($action === 'cancel') {
            $orderId = (int) $request->input('id', 0);
            $reason = trim((string) $request->input('reason', ''));
            if ($orderId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Invalid order.'], 422);
            }
            if ($reason === '') {
                return response()->json(['ok' => false, 'error' => 'A cancellation reason is required.'], 422);
            }
            if (mb_strlen($reason) > 500) {
                return response()->json(['ok' => false, 'error' => 'Cancellation reason is too long (max 500).'], 422);
            }

            $order = Order::find($orderId);
            if (! $order) {
                return response()->json(['ok' => false, 'error' => 'Order not found.'], 422);
            }
            // The requesting branch manager (own order) or the Owner may cancel.
            if (! $user->isOwner() && ! $user->isMainBranchManager()) {
                if (! $user->isBranchManager() || (int) $order->branch_id !== (int) $user->branch_id) {
                    return response()->json(['ok' => false, 'error' => 'You are not authorized to cancel this order.'], 403);
                }
            }

            $mainBranch = Branch::commissary();
            $mainBid = $mainBranch ? $mainBranch->id : 0;

            DB::beginTransaction();
            try {
                $locked = Order::where('id', $orderId)->lockForUpdate()->first();
                $cur = $locked->status ?? 'unknown';
                if (! in_array($cur, ['pending', 'approved', 'preparing', 'delivering'], true)) {
                    DB::rollBack();

                    return response()->json(['ok' => false, 'error' => "Order {$order->order_number} is '{$cur}' and can no longer be cancelled."], 409);
                }
                // No cancellation once any physical dispatch has happened.
                $dispatched = (int) OrderProduction::where('order_id', $orderId)->sum('dispatched_qty');
                if ($dispatched > 0) {
                    DB::rollBack();

                    return response()->json(['ok' => false, 'error' => "Order {$order->order_number} is already partially dispatched. Use the return/exception process."], 422);
                }

                // Legacy stock-backed orders: reverse their allocation once.
                $workflow = new OrderWorkflowService;
                if ($workflow->isAllocated($order)) {
                    (new StockService)->restoreOrderStock($orderId, $order->order_number, $mainBid, $user->id);
                }

                Order::where('id', $orderId)->update([
                    'status' => 'cancelled',
                    'cancelled_by' => $user->id,
                    'cancelled_at' => now(),
                    'cancel_reason' => $reason,
                ]);
                DB::commit();

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'order cancelled (requester)',
                    'table_name' => 'orders',
                    'record_id' => $orderId,
                    'details' => "Order {$order->order_number} cancelled before dispatch. Reason: {$reason}",
                    'ip_address' => $request->ip(),
                ]);

                return response()->json(['ok' => true, 'message' => "Order {$order->order_number} cancelled."]);
            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json(['ok' => false, 'error' => 'Failed to cancel order. Please try again.'], 500);
            }
        }

        // ── APPROVE + AUTO-PRODUCE (expiry-only input; Owner / Main Branch Mgr) ──
        if ($action === 'confirm_delivery') {
            $orderId = (int) $request->input('id', 0);
            if ($orderId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Invalid order.'], 422);
            }
            // Dispatch authority only (owner/super-admin or warehouse admin) —
            // a branch requester cannot dispatch warehouse stock.
            if (! $user->isOwner() && ! $user->isWarehouseAdmin()) {
                return response()->json(['ok' => false, 'error' => 'You are not authorized to confirm orders for delivery.'], 403);
            }

            $order = Order::select('orders.*', 'branches.branch_name')
                ->join('branches', 'branches.id', '=', 'orders.branch_id')
                ->where('orders.id', $orderId)->first();
            if (! $order) {
                return response()->json(['ok' => false, 'error' => 'Order not found.'], 422);
            }

            if (in_array($order->status, ['cancelled', 'declined', 'delivered', 'settled'], true)) {
                return response()->json(['ok' => false, 'error' => 'This order can no longer be released.'], 409);
            }
            if ((new OrderLabelService)->hasUnits($order->id)) {
                return response()->json(['ok' => true, 'message' => 'Prepared for release. Scan each unit out for delivery; destination confirmation happens after receipt.']);
            }
            if (! (new OrderWorkflowService)->isAllocated($order)) {
                return response()->json(['ok' => false, 'error' => 'Review and prepare/pick this request before release.'], 422);
            }

            $mainBranch = Branch::commissary();
            $mainBid = $mainBranch ? $mainBranch->id : 0;

            DB::beginTransaction();
            try {
                $locked = Order::where('id', $orderId)->lockForUpdate()->first();
                $cur = $locked->status ?? 'unknown';

                if ($cur === 'delivering') {
                    DB::rollBack();

                    return response()->json(['ok' => true, 'already' => true, 'message' => "Order {$order->order_number} is already out for delivery."]);
                }
                if (! in_array($cur, ['pending', 'approved', 'preparing'], true)) {
                    DB::rollBack();

                    return response()->json(['ok' => false, 'error' => "Order {$order->order_number} is '{$cur}' and cannot be confirmed for delivery."], 409);
                }

                $workflow = new OrderWorkflowService;

                if ($workflow->hasProduction($order)) {
                    // Commissary order: confirmation requires real prepared output
                    // for every line and deducts NO finished stock.
                    $items = OrderItem::where('order_id', $orderId)->get();
                    $prods = OrderProduction::where('order_id', $orderId)->get()->keyBy('order_item_id');
                    foreach ($items as $oi) {
                        $p = $prods->get($oi->id);
                        if (! $p || (int) $p->produced_qty <= 0) {
                            DB::rollBack();

                            return response()->json(['ok' => false, 'error' => 'Prepare produced output for every line before confirming for delivery.'], 422);
                        }
                        $pExpiry = $p->expiry_date ? $p->expiry_date->format('Y-m-d') : null;
                        if ($p->condition === 'damaged' || StockService::isExpired($pExpiry)) {
                            DB::rollBack();

                            return response()->json(['ok' => false, 'error' => "Produced output for '{$oi->item_id}' is expired or damaged and cannot be confirmed."], 422);
                        }
                    }
                } elseif (! $workflow->isAllocated($order)) {
                    // Legacy stock-backed order: allocate finished stock once.
                    $alloc = $workflow->allocate($order, $mainBid, $user->id, $order->branch_name);
                    if (! $alloc['ok']) {
                        DB::rollBack();

                        return response()->json($alloc, 422);
                    }
                }

                $update = ['status' => 'delivering'];
                if (! $locked->approved_by) {
                    $update['approved_by'] = $user->id;
                    $update['approved_at'] = now();
                }
                Order::where('id', $orderId)->update($update);

                DB::commit();

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'order confirmed for delivery',
                    'table_name' => 'orders',
                    'record_id' => $orderId,
                    'details' => "Order {$order->order_number} confirmed for delivery to {$order->branch_name} (stock allocated once).",
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'ok' => true,
                    'message' => "Order {$order->order_number} confirmed for delivery. A receiving QR is now available.",
                ]);
            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json(['ok' => false, 'error' => 'Failed to confirm for delivery. Please try again.'], 500);
            }
        }

        // ── DESTINATION RECEIVING SCAN (delivering → delivered + settled) ──
        if ($action === 'receive_scan') {
            $groupQr = trim((string) $request->input('qr', ''));
            if (str_starts_with($groupQr, 'HV-PROD-') && ! str_contains($groupQr, '-U')) {
                return response()->json((new OrderGroupLabelService)->receive($groupQr, $user, $request->input('quantity'), $request->input('reason'), $request->input('idempotency_key'), $request->boolean('finalize')));
            }
            $qr = trim((string) $request->input('qr', ''));
            $orderId = (int) $request->input('id', 0);

            if ($qr !== '') {
                $resolved = OrderWorkflowService::orderIdFromQr($qr);
                if (! $resolved) {
                    return response()->json(['ok' => false, 'error' => 'Unrecognized shipment code. Scan the order\'s receiving QR.'], 422);
                }
                $orderId = $resolved;
            }
            if ($orderId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Invalid shipment.'], 422);
            }

            $order = Order::select('orders.*', 'branches.branch_name')
                ->join('branches', 'branches.id', '=', 'orders.branch_id')
                ->where('orders.id', $orderId)->first();
            if (! $order) {
                return response()->json(['ok' => false, 'error' => 'Shipment not found.'], 404);
            }

            // Destination authorization — a branch-scoped user may only receive
            // their own branch's shipments (scanning another branch's fails).
            if ($user->isBranchScoped() && (int) $order->branch_id !== (int) $user->branch_id) {
                return response()->json(['ok' => false, 'error' => 'This shipment is for a different branch.'], 403);
            }

            if (! $user->isOwner() && ! $user->isBranchManager()) {
                return response()->json(['ok' => false, 'error' => 'Only the destination Branch Manager or Owner may confirm receipt.'], 403);
            }

            $mainBranch = Branch::commissary();
            $mainBid = $mainBranch ? $mainBranch->id : 0;

            DB::beginTransaction();
            try {
                $locked = Order::where('id', $orderId)->lockForUpdate()->first();
                $cur = $locked->status ?? 'unknown';

                // Idempotent: a repeated scan of an already-received shipment is safe.
                if (in_array($cur, ['delivered', 'settled'], true)) {
                    DB::rollBack();

                    return response()->json(['ok' => true, 'already' => true, 'message' => "Order {$order->order_number} was already received."]);
                }
                if ($cur !== 'delivering') {
                    DB::rollBack();

                    return response()->json(['ok' => false, 'error' => "Order {$order->order_number} is '{$cur}' and is not out for delivery."], 409);
                }

                // Order-level scan = full acceptance unless explicit counts are given.
                $receivedItems = json_decode($request->input('received_items', '[]'), true);
                $receivedMap = [];
                $receivedReasons = [];
                if (! is_array($receivedItems)) {
                    DB::rollBack();

                    return response()->json(['ok' => false, 'error' => 'Invalid received quantities.'], 422);
                }
                if (is_array($receivedItems)) {
                    foreach ($receivedItems as $ri) {
                        if (! is_array($ri) || empty($ri['order_item_id']) || array_key_exists((int) $ri['order_item_id'], $receivedMap)) {
                            DB::rollBack();

                            return response()->json(['ok' => false, 'error' => 'Invalid or duplicate receipt line.'], 422);
                        }
                        $oid = (int) ($ri['order_item_id'] ?? 0);
                        if ($oid > 0) {
                            $receivedMap[$oid] = $ri['received_quantity'] ?? 0;
                            $receivedReasons[$oid] = $ri['reason'] ?? null;
                        }
                    }
                }

                $recv = (new OrderWorkflowService)->receive($order, $user->id, $receivedMap, $mainBid, $receivedReasons);
                if (! ($recv['ok'] ?? false)) {
                    DB::rollBack();

                    return response()->json($recv, 422);
                }
                DB::commit();

                return response()->json($recv);
            } catch (ValidationException $e) {
                DB::rollBack();
                throw $e;
            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json(['ok' => false, 'error' => 'Failed to record receipt. Please try again.'], 500);
            }
        }

        // ── DECLINE A PENDING ORDER (approver rejection) ────
        if ($action === 'decline') {
            $orderId = (int) $request->input('id', 0);
            $reason = trim((string) $request->input('reason', ''));

            if ($orderId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Invalid order.'], 422);
            }
            if ($reason === '') {
                return response()->json(['ok' => false, 'error' => 'A reason for declining is required.'], 422);
            }
            if (mb_strlen($reason) > 500) {
                return response()->json(['ok' => false, 'error' => 'Decline reason is too long (max 500 characters).'], 422);
            }

            // Only roles authorized to approve pending orders may decline them.
            // (A branch requester's own cancellation is a different action.)
            if (! $user->isOwner() && ! $user->isMainBranchManager()) {
                return response()->json(['ok' => false, 'error' => 'You are not authorized to decline orders.'], 403);
            }

            $order = Order::find($orderId);
            if (! $order) {
                return response()->json(['ok' => false, 'error' => 'Order not found.'], 422);
            }

            DB::beginTransaction();
            try {
                // Lock + re-check inside the transaction so approve/decline are
                // mutually exclusive: the first terminal transition wins.
                $locked = Order::where('id', $orderId)->lockForUpdate()->first();
                if (! $locked || $locked->status !== 'pending') {
                    DB::rollBack();
                    $nowStatus = $locked->status ?? 'unknown';

                    return response()->json([
                        'ok' => false,
                        'error' => "Order {$order->order_number} is no longer pending (now '{$nowStatus}'). Please refresh.",
                    ], 409);
                }

                // Pending orders hold no stock allocation, so declining performs
                // no stock movement and restores nothing. Original remarks kept.
                $locked->update([
                    'status' => 'declined',
                    'declined_by' => $user->id,
                    'declined_at' => now(),
                    'decline_reason' => $reason,
                ]);

                Alert::create([
                    'alert_type' => 'operational',
                    'order_id' => $locked->id,
                    'branch_id' => $locked->branch_id,
                    'severity' => 'warning',
                    'message' => Str::limit('Order '.$locked->order_number.' was declined. Reason: '.$reason, 500, ''),
                    'is_resolved' => false,
                ]);

                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'order declined',
                    'table_name' => 'orders',
                    'record_id' => $orderId,
                    'details' => "Order {$order->order_number} declined. Reason: {$reason}",
                    'ip_address' => $request->ip(),
                ]);

                DB::commit();

                return response()->json([
                    'ok' => true,
                    'message' => "Order {$order->order_number} has been declined.",
                ]);
            } catch (\Exception $e) {
                DB::rollBack();

                return response()->json(['ok' => false, 'error' => 'Failed to decline order. Please try again.'], 500);
            }
        }

        // ── VIEW ORDER DETAILS ──────────────────────────────
        if ($action === 'view') {
            $orderId = (int) $request->input('id', 0);

            if ($orderId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Invalid order.'], 422);
            }

            $orderQuery = Order::select('orders.*', 'branches.branch_name',
                'u_ord.full_name as ordered_by_name',
                'u_app.full_name as approved_by_name')
                ->join('branches', 'branches.id', '=', 'orders.branch_id')
                ->leftJoin('users as u_ord', 'u_ord.id', '=', 'orders.ordered_by')
                ->leftJoin('users as u_app', 'u_app.id', '=', 'orders.approved_by')
                ->where('orders.id', $orderId);

            if ($user->isBranchScoped() && ! $user->isWarehouseRole()) {
                $orderQuery->where('orders.branch_id', $user->branch_id);
            }

            $order = $orderQuery->first();

            if (! $order) {
                return response()->json(['ok' => false, 'error' => 'Order not found or access denied.'], 404);
            }

            $items = OrderItem::select('order_items.*', 'items.item_name', 'items.unit')
                ->join('items', 'items.id', '=', 'order_items.item_id')
                ->where('order_items.order_id', $orderId)
                ->orderBy('order_items.id')
                ->get();

            $fmt = function ($dt) {
                return $dt ? Carbon::parse($dt)->format('M j, Y g:i A') : null;
            };

            $preparedBy = $order->prepared_by
                ? User::where('id', $order->prepared_by)->value('full_name')
                : null;
            $confirmedBy = $order->confirmed_by
                ? User::where('id', $order->confirmed_by)->value('full_name')
                : null;
            $declinedBy = $order->declined_by
                ? User::where('id', $order->declined_by)->value('full_name')
                : null;

            $discrepancies = DeliveryDiscrepancy::select('delivery_discrepancies.*', 'items.item_name')
                ->join('order_items', 'order_items.id', '=', 'delivery_discrepancies.order_item_id')
                ->join('items', 'items.id', '=', 'order_items.item_id')
                ->where('delivery_discrepancies.order_id', $orderId)
                ->get();

            $productions = OrderProduction::where('order_id', $order->id)->get();
            $hasPreparedOutput = $productions->contains(fn (OrderProduction $production): bool => $production->stock_batch_id !== null)
                || DB::table('order_production_units')->whereIn('production_id', $productions->pluck('id'))->exists();
            $releasedQuantity = (int) $productions->sum('dispatched_qty');
            $canPrepare = $user->isOwner() || $user->isCommissaryManager();
            $canRelease = $user->isOwner() || $user->isCommissaryRole();
            $canCancel = $canPrepare || ($user->isBranchManager() && (int) $order->branch_id === (int) $user->branch_id);
            $isActiveOrder = $order->archived_at === null;

            return response()->json([
                'ok' => true,
                'order' => [
                    'id' => $order->id,
                    'order_number' => $order->order_number,
                    'branch_name' => $order->branch_name,
                    'proforma_url' => ! $user->isCommissaryStaff() && $order->isProformaAvailable() ? route('orders.proforma', $order) : null,
                    'proforma_awaiting_approval' => ! $user->isCommissaryStaff() && $order->status === 'pending',
                    'payment_reference' => $user->isCommissaryStaff() ? null : $order->payment_reference,
                    'payment_screenshot_url' => ! $user->isCommissaryStaff() && $order->payment_screenshot_path ? route('orders.payment-screenshot', $order) : null,
                    'payment_submitted_at' => $user->isCommissaryStaff() ? null : $fmt($order->payment_submitted_at),
                    'status' => $order->status,
                    'actions' => [
                        'can_prepare' => $isActiveOrder && $canPrepare && in_array($order->status, ['approved', 'preparing'], true) && ! $hasPreparedOutput,
                        'can_release' => $isActiveOrder && $canRelease && in_array($order->status, ['approved', 'preparing', 'delivering'], true) && $hasPreparedOutput && (int) $productions->sum('produced_qty') > $releasedQuantity,
                        'can_receive' => $isActiveOrder && $order->status === 'delivering' && (! $hasPreparedOutput || ((int) $productions->sum('produced_qty') > 0 && $releasedQuantity >= (int) $productions->sum('produced_qty'))) && ($user->isOwner() || ($user->isBranchManager() && (int) $order->branch_id === (int) $user->branch_id)),
                        'can_cancel' => $isActiveOrder && $canCancel && in_array($order->status, ['pending', 'approved', 'preparing', 'delivering'], true) && $releasedQuantity === 0,
                        'labels_url' => $canPrepare && $productions->isNotEmpty() && ! in_array($order->status, ['cancelled', 'declined'], true) ? route('orders.labels', $order) : null,
                        'scan_out_url' => $canRelease ? route('stock', ['mode' => 'out']) : null,
                    ],
                    'shipment_qr' => $order->status === 'delivering' ? OrderWorkflowService::shipmentQr($order) : null,
                    'ordered_by_name' => $order->ordered_by_name,
                    'approved_by_name' => $order->approved_by_name,
                    'prepared_by_name' => $preparedBy,
                    'confirmed_by_name' => $confirmedBy,
                    'total_amount' => $order->total_amount,
                    'fulfilled_amount' => $order->fulfilled_amount,
                    'workflow_stage' => OrderLabelService::stageLabel($order),
                    'remarks' => $order->remarks,
                    'created_at' => $fmt($order->created_at),
                    'approved_at' => $fmt($order->approved_at),
                    'prepared_at' => $fmt($order->prepared_at),
                    'delivered_at' => $fmt($order->delivered_at),
                    'confirmed_at' => $fmt($order->confirmed_at),
                    'settled_at' => $fmt($order->settled_at),
                    'declined_by_name' => $declinedBy,
                    'declined_at' => $fmt($order->declined_at),
                    'decline_reason' => $order->decline_reason,
                    'items' => $items->map(fn ($i) => [
                        'id' => $i->id,
                        'item_name' => $i->item_name,
                        'unit' => $i->unit,
                        'quantity' => (int) $i->quantity,
                        'fulfilled_quantity' => $i->fulfilled_quantity === null ? null : (int) $i->fulfilled_quantity,
                        'fulfillment_reason' => $i->fulfillment_reason,
                        'released_quantity' => (int) OrderProduction::where('order_item_id', $i->id)->sum('dispatched_qty'),
                        'receivable_quantity' => (new OrderLabelService)->hasUnits($order->id) ? DB::table('order_production_units as u')->join('order_productions as p', 'p.id', '=', 'u.production_id')->where('p.order_item_id', $i->id)->whereNotNull('u.dispatched_at')->whereNull('u.received_at')->count() : null,
                        'received_quantity' => $i->received_quantity !== null ? (int) $i->received_quantity : null,
                        'unit_price' => (float) $i->unit_price,
                        'subtotal' => (float) $i->subtotal,
                    ])->toArray(),
                    'discrepancies' => $discrepancies->map(fn ($d) => [
                        'item_name' => $d->item_name,
                        'expected_quantity' => (int) $d->expected_quantity,
                        'received_quantity' => (int) $d->received_quantity,
                        'reason' => $d->reason,
                        'remarks' => $d->remarks,
                    ])->toArray(),
                    'cancel_reason' => $order->cancel_reason,
                    'branch_confirmed_at' => $fmt($order->branch_confirmed_at),
                    'productions' => $productions->map(fn ($p) => [
                        'order_item_id' => (int) $p->order_item_id,
                        'produced_qty' => (int) $p->produced_qty,
                        'dispatched_qty' => (int) $p->dispatched_qty,
                        'expiry_date' => $p->expiry_date ? $p->expiry_date->format('Y-m-d') : null,
                        'condition' => $p->condition,
                        'qr_code' => $p->qr_code,
                    ])->values()->toArray(),
                ],
            ]);
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
}
