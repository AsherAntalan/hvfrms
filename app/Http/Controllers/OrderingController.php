<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Category;
use App\Models\Item;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Setting;
use App\Services\ForecastService;
use App\Services\OrderPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class OrderingController extends Controller
{
    public function index()
    {
        $user = Auth::user();
        $user->load('role', 'branch');

        // Find the main branch
        $mainBranch = Branch::commissary();
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

        $items = DB::select('
            SELECT i.id, i.item_name, i.category_id, i.unit, i.pack_description,
                   i.price, i.reorder_level, i.image_path, i.is_active,
                   c.name AS category_name, c.goods_type,
                   COALESCE(my.my_stock, 0) AS my_stock
            FROM items i
            LEFT JOIN categories c ON c.id = i.category_id
            LEFT JOIN (
                SELECT item_id, SUM(quantity_left) AS my_stock
                FROM stock_batches WHERE branch_id = ?
                GROUP BY item_id
            ) my ON my.item_id = i.id
            ORDER BY c.name ASC, i.item_name ASC
        ', [$user->branch_id]);

        $maxOrderQuantity = max(0, (int) Setting::get('order_max_qty_per_line', '0'));

        // Forecast-driven suggested order quantity per item for THIS branch.
        // Decision support only — the branch can override it in the modal.
        $suggested = [];
        foreach ((new ForecastService)->buildForecast($user->branch_id) as $fc) {
            $qty = (int) ($fc['recommend_qty'] ?? 0);
            if ($qty <= 0) {
                continue;
            }
            if ($maxOrderQuantity > 0) {
                $qty = min($qty, $maxOrderQuantity);
            }
            $suggested[$fc['item']->id] = $qty;
        }
        foreach ($items as $item) {
            $item->suggested_qty = $suggested[$item->id] ?? 0;
        }

        // Categories for filter
        $categories = Category::orderBy('name')->get();

        return view('ordering.index', compact(
            'user', 'mainBid', 'isFranchise', 'pendingOrders',
            'recentOrders', 'items', 'categories', 'maxOrderQuantity'
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

            if (empty($cart) || ! is_array($cart)) {
                return response()->json(['ok' => false, 'error' => 'Cart is empty.'], 422);
            }

            // Resolve the active Commissary (supply/production source).
            $mainBranch = Branch::commissary();
            if (! $mainBranch) {
                return response()->json(['ok' => false, 'error' => 'No active Commissary location is configured.'], 500);
            }

            // The Commissary cannot place an order to itself. Tanza (main store)
            // and every other branch may request from it.
            if ($user->branch_id === $mainBranch->id) {
                return response()->json(['ok' => false, 'error' => 'The Commissary cannot place orders to itself.'], 422);
            }

            $remarks = trim($request->input('remarks', ''));

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

                // Aggregate duplicate product lines BEFORE the availability
                // check so two lines of 2 cannot bypass a maximum of 3. Reject
                // malformed quantities rather than silently truncating them.
                $byItem = [];
                foreach ($cart as $entry) {
                    $itemId = (int) ($entry['item_id'] ?? 0);
                    $rawQty = $entry['quantity'] ?? null;

                    if ($itemId <= 0) {
                        continue;
                    }
                    if (! is_numeric($rawQty) || (int) $rawQty != $rawQty || (int) $rawQty <= 0) {
                        DB::rollBack();

                        return response()->json(['ok' => false, 'error' => 'Invalid quantity in cart.'], 422);
                    }
                    $byItem[$itemId] = ($byItem[$itemId] ?? 0) + (int) $rawQty;
                }

                if (empty($byItem)) {
                    DB::rollBack();

                    return response()->json(['ok' => false, 'error' => 'No valid items in cart.'], 422);
                }

                // Tanza is the commissary — made to order, so NOT gated on
                // finished stock. Only the owner-configurable per-line limit.
                $maxPerLine = (int) Setting::get('order_max_qty_per_line', '0');
                $total = 0;
                $lineItems = [];

                foreach ($byItem as $itemId => $qty) {
                    $item = Item::where('id', $itemId)->where('is_active', true)->first();
                    if (! $item) {
                        DB::rollBack();

                        return response()->json(['ok' => false, 'error' => 'A product in your cart is no longer available.'], 422);
                    }

                    if ($maxPerLine > 0 && $qty > $maxPerLine) {
                        DB::rollBack();

                        return response()->json([
                            'ok' => false,
                            'error' => "{$item->item_name}: {$qty} exceeds the per-product limit of {$maxPerLine}.",
                        ], 422);
                    }

                    $subtotal = (float) $item->price * $qty;
                    $total += $subtotal;

                    $lineItems[] = [
                        'item_id' => $itemId,
                        'quantity' => $qty,
                        'unit_price' => (float) $item->price,
                        'name' => $item->item_name,
                    ];
                }

                // Create order
                $order = Order::create([
                    'order_number' => $orderNumber,
                    'branch_id' => $user->branch_id,
                    'status' => 'pending',
                    'ordered_by' => $user->id,
                    'remarks' => $remarks ?: null,
                    'total_amount' => $total,
                ]);

                // Create order items
                foreach ($lineItems as $li) {
                    OrderItem::create([
                        'order_id' => $order->id,
                        'item_id' => $li['item_id'],
                        'quantity' => $li['quantity'],
                        'unit_price' => $li['unit_price'],
                    ]);
                }

                $paymentPath = $payments->attach($order, $payment);

                $itemSummary = implode(', ', array_map(
                    fn ($li) => "{$li['name']} x{$li['quantity']}",
                    $lineItems
                ));
                AuditLog::create([
                    'user_id' => $user->id,
                    'action' => 'placed order',
                    'table_name' => 'orders',
                    'record_id' => $order->id,
                    'details' => "Order #{$orderNumber}, Total: PHP ".number_format($total, 2).", Items: {$itemSummary}",
                    'ip_address' => $request->ip(),
                ]);

                DB::commit();

                return response()->json([
                    'ok' => true,
                    'message' => "Order {$orderNumber} placed successfully! Payment proof submitted for review.",
                    'order_number' => $orderNumber,
                    'order_id' => $order->id,
                    'proforma_url' => null,
                ]);

            } catch (\Exception $e) {
                DB::rollBack();

                if ($paymentPath) {
                    Storage::disk('local')->delete($paymentPath);
                }
                report($e);

                return response()->json(['ok' => false, 'error' => 'Failed to place order. Please try again.'], 500);
            }
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }
}
