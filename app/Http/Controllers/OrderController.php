<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Services\StockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $canManage = $user->isOwner();
        $isBranchScoped = $user->isBranchScoped();

        // Fetch orders with branch and user names
        $ordersQuery = Order::select('orders.*', 'branches.branch_name',
                'u_ord.full_name as ordered_by_name',
                'u_app.full_name as approved_by_name')
            ->join('branches', 'branches.id', '=', 'orders.branch_id')
            ->leftJoin('users as u_ord', 'u_ord.id', '=', 'orders.ordered_by')
            ->leftJoin('users as u_app', 'u_app.id', '=', 'orders.approved_by');

        if ($isBranchScoped) {
            $ordersQuery->where('orders.branch_id', $user->branch_id);
        }

        $orders = $ordersQuery->orderByDesc('orders.created_at')->get();

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

        return view('orders.index', compact('user', 'canManage', 'isBranchScoped', 'orders', 'items', 'branches', 'productsJson'));
    }

    public function api(Request $request)
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $action = $request->input('action', '');

        // ── CREATE ORDER ────────────────────────────────────
        if ($action === 'create') {
            $branchId = (int) $request->input('branch_id', 0);
            $remarks = trim($request->input('remarks', ''));
            $lineItems = json_decode($request->input('line_items', '[]'), true);

            if ($branchId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Please select a branch.'], 422);
            }

            // Branch access check
            if ($user->isBranchScoped() && $user->branch_id !== $branchId) {
                return response()->json(['ok' => false, 'error' => 'You do not have access to this branch.'], 403);
            }

            $branch = Branch::where('id', $branchId)->where('is_active', true)->first();
            if (!$branch) {
                return response()->json(['ok' => false, 'error' => 'Branch not found or is inactive.'], 422);
            }

            if (!is_array($lineItems) || count($lineItems) === 0) {
                return response()->json(['ok' => false, 'error' => 'Please add at least one item.'], 422);
            }

            // Validate each line item
            $validatedItems = [];
            foreach ($lineItems as $li) {
                $itemId = (int) ($li['item_id'] ?? 0);
                $quantity = (int) ($li['quantity'] ?? 0);

                if ($itemId <= 0 || $quantity <= 0) {
                    return response()->json(['ok' => false, 'error' => 'Invalid product or quantity in line items.'], 422);
                }

                $item = Item::where('id', $itemId)->where('is_active', true)->first();
                if (!$item) {
                    return response()->json(['ok' => false, 'error' => "Product ID {$itemId} not found or is inactive."], 422);
                }

                $validatedItems[] = [
                    'item_id'    => $itemId,
                    'item_name'  => $item->item_name,
                    'unit'       => $item->unit,
                    'quantity'   => $quantity,
                    'unit_price' => (float) $item->price,
                ];
            }

            // Generate order number: ORD-YYYY-NNNN
            $year = date('Y');
            $count = Order::where('order_number', 'like', "ORD-{$year}-%")->count();
            $orderNumber = sprintf('ORD-%s-%04d', $year, $count + 1);

            $totalAmount = 0;
            foreach ($validatedItems as $vi) {
                $totalAmount += $vi['unit_price'] * $vi['quantity'];
            }

            DB::beginTransaction();
            try {
                $order = Order::create([
                    'order_number' => $orderNumber,
                    'branch_id'    => $branchId,
                    'status'       => 'pending',
                    'ordered_by'   => $user->id,
                    'remarks'      => $remarks ?: null,
                    'total_amount' => $totalAmount,
                ]);

                foreach ($validatedItems as $vi) {
                    OrderItem::create([
                        'order_id'   => $order->id,
                        'item_id'    => $vi['item_id'],
                        'quantity'   => $vi['quantity'],
                        'unit_price' => $vi['unit_price'],
                    ]);
                }

                DB::commit();

                $summary = implode(', ', array_map(fn($vi) => "{$vi['quantity']}x {$vi['item_name']}", $validatedItems));
                AuditLog::create([
                    'user_id'    => $user->id,
                    'action'     => 'order created',
                    'table_name' => 'orders',
                    'record_id'  => $order->id,
                    'details'    => "Order {$orderNumber}, Branch ID: {$branchId}, Total: PHP " . number_format($totalAmount, 2) . ", Items: {$summary}",
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'ok'           => true,
                    'message'      => "Order {$orderNumber} placed successfully!",
                    'order_id'     => $order->id,
                    'order_number' => $orderNumber,
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['ok' => false, 'error' => 'Failed to create order. Please try again.'], 500);
            }
        }

        // ── UPDATE STATUS ───────────────────────────────────
        if ($action === 'update_status') {
            $orderId = (int) $request->input('id', 0);
            $newStatus = trim($request->input('status', ''));

            if ($orderId <= 0) {
                return response()->json(['ok' => false, 'error' => 'Invalid order.'], 422);
            }

            $validStatuses = ['approved', 'delivering', 'delivered', 'settled', 'cancelled'];
            if (!in_array($newStatus, $validStatuses)) {
                return response()->json(['ok' => false, 'error' => 'Invalid status.'], 422);
            }

            $order = Order::select('orders.*', 'branches.branch_name')
                ->join('branches', 'branches.id', '=', 'orders.branch_id')
                ->where('orders.id', $orderId)
                ->first();

            if (!$order) {
                return response()->json(['ok' => false, 'error' => 'Order not found.'], 422);
            }

            $allowedTransitions = [
                'pending'    => ['approved', 'cancelled'],
                'approved'   => ['delivering', 'cancelled'],
                'delivering' => ['delivered'],
                'delivered'  => ['settled'],
                'settled'    => [],
                'cancelled'  => [],
            ];

            $current = $order->status;
            if (!in_array($newStatus, $allowedTransitions[$current] ?? [])) {
                return response()->json(['ok' => false, 'error' => "Cannot change from '{$current}' to '{$newStatus}'."], 422);
            }

            // Branch managers: own branch only, limited transitions
            if (!$user->isOwner()) {
                $managerTransitions = [
                    'pending'    => ['cancelled'],
                    'delivering' => ['delivered'],
                ];
                if ((int) $order->branch_id !== $user->branch_id
                    || !in_array($newStatus, $managerTransitions[$current] ?? [], true)) {
                    return response()->json(['ok' => false, 'error' => 'Only the owner can make this change to the order.'], 403);
                }
            }

            // Load order items
            $orderItems = OrderItem::select('order_items.*', 'items.item_name', 'items.unit', 'items.shelf_life_days')
                ->join('items', 'items.id', '=', 'order_items.item_id')
                ->where('order_items.order_id', $orderId)
                ->get();

            // Get main branch for warehouse stock deduction
            $mainBranch = Branch::where('branch_type', 'main')->where('is_active', true)->first();
            $mainBid = $mainBranch ? $mainBranch->id : 0;

            DB::beginTransaction();
            try {
                $updateData = ['status' => $newStatus];

                if ($newStatus === 'approved') {
                    $updateData['approved_by'] = $user->id;
                    $updateData['approved_at'] = now();
                } elseif ($newStatus === 'delivered') {
                    $updateData['delivered_at'] = now();
                } elseif ($newStatus === 'settled') {
                    $updateData['settled_at'] = now();
                }

                Order::where('id', $orderId)->update($updateData);

                // On cancelled from approved: restore warehouse stock
                if ($newStatus === 'cancelled' && $current === 'approved') {
                    $stockService = new StockService();
                    $stockService->restoreOrderStock($orderId, $order->order_number, $mainBid, $user->id);
                }

                // On approved: validate and deduct warehouse stock using FIFO
                if ($newStatus === 'approved') {
                    $stockService = new StockService();
                    foreach ($orderItems as $oi) {
                        $result = $stockService->deductStockFifo(
                            $oi->item_id, $mainBid, $oi->quantity, $user->id,
                            "Order {$order->order_number} approved",
                            "Allocated for {$order->branch_name}"
                        );
                        if (!$result['ok']) {
                            DB::rollBack();
                            return response()->json($result, 422);
                        }
                    }
                }

                // On "delivered": auto stock-in at receiving branch preserving source expiry
                if ($newStatus === 'delivered') {
                    foreach ($orderItems as $oi) {
                        $sourceTxs = StockTransaction::where('type', 'out')
                            ->where('item_id', $oi->item_id)
                            ->where('branch_id', $mainBid)
                            ->where('reason', "Order {$order->order_number} approved")
                            ->whereNotNull('batch_id')
                            ->get();

                        if ($sourceTxs->isEmpty()) {
                            $expiryDate = $oi->shelf_life_days
                                ? now()->addDays((int) $oi->shelf_life_days)->toDateString()
                                : null;
                            $sourceTxs = collect([['qty' => $oi->quantity, 'expiry' => $expiryDate]]);
                        } else {
                            $sourceTxs = $sourceTxs->map(function ($tx) {
                                $sourceBatch = StockBatch::find($tx->batch_id);
                                return [
                                    'qty'    => abs($tx->quantity),
                                    'expiry' => $sourceBatch ? $sourceBatch->expiry_date : null,
                                ];
                            });
                        }

                        foreach ($sourceTxs as $src) {
                            $batch = StockBatch::create([
                                'item_id'       => $oi->item_id,
                                'branch_id'     => $order->branch_id,
                                'quantity_in'   => $src['qty'],
                                'quantity_left' => $src['qty'],
                                'expiry_date'   => $src['expiry'],
                                'received_at'   => now(),
                                'created_by'    => $user->id,
                            ]);

                            $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($oi->item_name, 0, 8)));
                            $expTag = $src['expiry'] ? str_replace('-', '', substr($src['expiry'], 5)) : 'NOEXP';
                            $batchQr = 'HV-' . $clean . '-' . $expTag . '-B' . str_pad($batch->id, 3, '0', STR_PAD_LEFT);
                            $batch->update(['qr_code' => $batchQr]);

                            StockTransaction::create([
                                'item_id'      => $oi->item_id,
                                'branch_id'    => $order->branch_id,
                                'batch_id'     => $batch->id,
                                'type'         => 'in',
                                'quantity'     => $src['qty'],
                                'reason'       => "Order {$order->order_number} delivered",
                                'remarks'      => 'Auto stock-in from order delivery',
                                'performed_by' => $user->id,
                            ]);
                        }
                    }

                    AuditLog::create([
                        'user_id'    => $user->id,
                        'action'     => 'order delivered + stock in',
                        'table_name' => 'orders',
                        'record_id'  => $orderId,
                        'details'    => "Order {$order->order_number} delivered to {$order->branch_name}. Auto stock-in for {$orderItems->count()} item(s).",
                        'ip_address' => $request->ip(),
                    ]);
                }

                DB::commit();

                if ($newStatus !== 'delivered') {
                    AuditLog::create([
                        'user_id'    => $user->id,
                        'action'     => "order {$newStatus}",
                        'table_name' => 'orders',
                        'record_id'  => $orderId,
                        'details'    => "Order {$order->order_number} status: {$current} -> {$newStatus}",
                        'ip_address' => $request->ip(),
                    ]);
                }

                $labels = [
                    'approved'   => 'approved',
                    'delivering' => 'marked as delivering',
                    'delivered'  => 'marked as delivered (stock added to branch)',
                    'settled'    => 'settled',
                    'cancelled'  => 'cancelled',
                ];

                return response()->json([
                    'ok'      => true,
                    'message' => "Order {$order->order_number} has been {$labels[$newStatus]}.",
                ]);
            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['ok' => false, 'error' => 'Failed to update order. Please try again.'], 500);
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

            if ($user->isBranchScoped()) {
                $orderQuery->where('orders.branch_id', $user->branch_id);
            }

            $order = $orderQuery->first();

            if (!$order) {
                return response()->json(['ok' => false, 'error' => 'Order not found or access denied.'], 404);
            }

            $items = OrderItem::select('order_items.*', 'items.item_name', 'items.unit')
                ->join('items', 'items.id', '=', 'order_items.item_id')
                ->where('order_items.order_id', $orderId)
                ->orderBy('order_items.id')
                ->get();

            $fmt = function ($dt) {
                return $dt ? \Carbon\Carbon::parse($dt)->format('M j, Y g:i A') : null;
            };

            return response()->json([
                'ok'    => true,
                'order' => [
                    'id'               => $order->id,
                    'order_number'     => $order->order_number,
                    'branch_name'      => $order->branch_name,
                    'status'           => $order->status,
                    'ordered_by_name'  => $order->ordered_by_name,
                    'approved_by_name' => $order->approved_by_name,
                    'total_amount'     => $order->total_amount,
                    'remarks'          => $order->remarks,
                    'created_at'       => $fmt($order->created_at),
                    'approved_at'      => $fmt($order->approved_at),
                    'delivered_at'     => $fmt($order->delivered_at),
                    'settled_at'       => $fmt($order->settled_at),
                    'items'            => $items->map(fn($i) => [
                        'item_name'  => $i->item_name,
                        'unit'       => $i->unit,
                        'quantity'   => (int) $i->quantity,
                        'unit_price' => (float) $i->unit_price,
                        'subtotal'   => (float) $i->subtotal,
                    ])->toArray(),
                ],
            ]);
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
}
