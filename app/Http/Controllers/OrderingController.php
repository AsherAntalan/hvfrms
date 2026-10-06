<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class OrderingController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $user->load('role', 'branch');

        // Find the main branch
        $mainBranch = Branch::where('branch_type', 'main')->where('is_active', true)->first();
        $mainBid = $mainBranch ? $mainBranch->id : 0;

        // Check if user is from a franchise branch
        $isFranchise = $mainBid > 0 && $user->branch_id !== $mainBid;

        // Pending orders count for this branch
        $pendingOrders = Order::where('branch_id', $user->branch_id)
            ->where('status', 'pending')
            ->count();

        // Recent orders (last 10) for this branch
        $recentOrders = Order::select('orders.*')
            ->selectRaw('(SELECT COUNT(*) FROM order_items WHERE order_id = orders.id) AS item_count')
            ->where('orders.branch_id', $user->branch_id)
            ->orderByDesc('orders.created_at')
            ->limit(10)
            ->get();

        // Fetch items with main branch stock and user's branch stock
        $items = DB::select("
            SELECT i.id, i.item_name, i.category_id, i.unit, i.pack_description,
                   i.price, i.reorder_level, i.image_path,
                   c.name AS category_name, c.goods_type,
                   COALESCE(my.my_stock, 0) AS my_stock,
                   COALESCE(main.main_stock, 0) AS main_stock
            FROM items i
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN (
                SELECT item_id, SUM(quantity_left) AS my_stock
                FROM stock_batches WHERE branch_id = ?
                GROUP BY item_id
            ) my ON my.item_id = i.id
            LEFT JOIN (
                SELECT item_id, SUM(quantity_left) AS main_stock
                FROM stock_batches WHERE branch_id = ?
                GROUP BY item_id
            ) main ON main.item_id = i.id
            WHERE i.is_active = 1
            ORDER BY c.name ASC, i.item_name ASC
        ", [$user->branch_id, $mainBid]);

        // Categories for filter
        $categories = Category::orderBy('name')->get();

        return view('ordering.index', compact(
            'user', 'mainBid', 'isFranchise', 'pendingOrders',
            'recentOrders', 'items', 'categories'
        ));
    }

    public function api(Request $request)
    {
        $user = Auth::user();
        $user->load('role', 'branch');
        $action = $request->input('action', '');

        // ── PLACE ORDER ────────────────────────────────────
        if ($action === 'place_order') {
            $cartJson = $request->input('cart', '[]');
            $cart = json_decode($cartJson, true);

            if (empty($cart) || !is_array($cart)) {
                return response()->json(['ok' => false, 'error' => 'Cart is empty.'], 422);
            }

            // Get main branch ID
            $mainBranch = Branch::where('branch_type', 'main')->where('is_active', true)->first();
            if (!$mainBranch) {
                return response()->json(['ok' => false, 'error' => 'Main branch not configured.'], 500);
            }

            // Can't order if you ARE the main branch
            if ($user->branch_id === $mainBranch->id) {
                return response()->json(['ok' => false, 'error' => 'Main branch cannot place orders to itself.'], 422);
            }

            $remarks = trim($request->input('remarks', ''));

            // Generate order number: ORD-YYYY-NNNN
            $year = date('Y');
            $count = Order::where('order_number', 'like', "ORD-{$year}-%")->count();
            $orderNumber = sprintf('ORD-%s-%04d', $year, $count + 1);

            DB::beginTransaction();
            try {
                $total = 0;
                $lineItems = [];

                foreach ($cart as $entry) {
                    $itemId = (int) ($entry['item_id'] ?? 0);
                    $qty = (int) ($entry['quantity'] ?? 0);

                    if ($itemId <= 0 || $qty <= 0) {
                        continue;
                    }

                    $item = Item::where('id', $itemId)->where('is_active', true)->first();
                    if (!$item) {
                        continue;
                    }

                    // Validate warehouse has enough stock
                    $warehouseStock = DB::table('stock_batches')
                        ->where('item_id', $itemId)
                        ->where('branch_id', $mainBranch->id)
                        ->where('quantity_left', '>', 0)
                        ->sum('quantity_left');

                    if ($warehouseStock < $qty) {
                        DB::rollBack();
                        return response()->json([
                            'ok' => false,
                            'error' => "Not enough stock for {$item->item_name}. Available: {$warehouseStock}, Requested: {$qty}."
                        ], 422);
                    }

                    $subtotal = (float) $item->price * $qty;
                    $total += $subtotal;

                    $lineItems[] = [
                        'item_id'    => $itemId,
                        'quantity'   => $qty,
                        'unit_price' => (float) $item->price,
                        'name'       => $item->item_name,
                    ];
                }

                if (empty($lineItems)) {
                    DB::rollBack();
                    return response()->json(['ok' => false, 'error' => 'No valid items in cart.'], 422);
                }

                // Create order
                $order = Order::create([
                    'order_number' => $orderNumber,
                    'branch_id'    => $user->branch_id,
                    'status'       => 'pending',
                    'ordered_by'   => $user->id,
                    'remarks'      => $remarks ?: null,
                    'total_amount' => $total,
                ]);

                // Create order items
                foreach ($lineItems as $li) {
                    OrderItem::create([
                        'order_id'   => $order->id,
                        'item_id'    => $li['item_id'],
                        'quantity'   => $li['quantity'],
                        'unit_price' => $li['unit_price'],
                    ]);
                }

                DB::commit();

                $itemSummary = implode(', ', array_map(
                    fn($li) => "{$li['name']} x{$li['quantity']}",
                    $lineItems
                ));
                AuditLog::create([
                    'user_id'    => $user->id,
                    'action'     => 'placed order',
                    'table_name' => 'orders',
                    'record_id'  => $order->id,
                    'details'    => "Order #{$orderNumber}, Total: PHP " . number_format($total, 2) . ", Items: {$itemSummary}",
                    'ip_address' => $request->ip(),
                ]);

                return response()->json([
                    'ok'           => true,
                    'message'      => "Order {$orderNumber} placed successfully! Waiting for approval.",
                    'order_number' => $orderNumber,
                    'order_id'     => $order->id,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json(['ok' => false, 'error' => 'Failed to place order. Please try again.'], 500);
            }
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
}
