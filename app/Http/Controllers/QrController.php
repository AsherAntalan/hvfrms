<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockBatch;
use App\Services\StockService;
use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class QrController extends Controller
{
    /**
     * Show the QR Scanner (Stock In / Out) page.
     */
    public function index()
    {
        $user = Auth::user();

        if ($user->isBranchScoped()) {
            $branches = Branch::where('id', $user->branch_id)
                ->where('is_active', true)
                ->get();
        } else {
            $branches = Branch::where('is_active', true)
                ->orderBy('branch_name')
                ->get();
        }

        return view('qr.index', compact('branches'));
    }

    /**
     * Handle AJAX API actions: lookup, stock_in, stock_out, adjustment.
     */
    public function api(Request $request): JsonResponse
    {
        $action = $request->input('action');

        if ($action === 'lookup') {
            return $this->lookup($request);
        }

        if ($action === 'my_today') {
            return $this->myToday();
        }

        if (in_array($action, ['stock_in', 'stock_out', 'adjustment'])) {
            return $this->processStock($request, $action);
        }

        return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
    }

    /**
     * Return the current user's stock movements recorded today.
     */
    protected function myToday(): JsonResponse
    {
        $user = Auth::user();

        $rows = DB::table('stock_transactions as st')
            ->join('items as i', 'i.id', '=', 'st.item_id')
            ->where('st.performed_by', $user->id)
            ->whereDate('st.created_at', now()->toDateString())
            ->select(
                'i.item_name',
                'i.unit',
                'st.type',
                'st.reason',
                DB::raw('ABS(SUM(st.quantity)) AS qty'),
                DB::raw('MAX(st.created_at) AS created_at')
            )
            ->groupBy('st.item_id', 'i.item_name', 'i.unit', 'st.type', 'st.reason', 'st.created_at')
            ->orderByDesc(DB::raw('MAX(st.created_at)'))
            ->limit(30)
            ->get();

        return response()->json([
            'ok'   => true,
            'rows' => $rows->map(fn($r) => [
                'item_name' => $r->item_name,
                'unit'      => $r->unit,
                'type'      => $r->type,
                'reason'    => $r->reason,
                'qty'       => (int)$r->qty,
                'time'      => \Carbon\Carbon::parse($r->created_at)->format('g:i A'),
            ])->toArray(),
        ]);
    }

    /**
     * Look up a product by QR code or name, return stock info.
     */
    protected function lookup(Request $request): JsonResponse
    {
        $query = trim($request->input('query', ''));

        if ($query === '') {
            return response()->json(['ok' => false, 'error' => 'Please enter a QR code or product name.'], 422);
        }

        // 1. Exact item QR code match
        $product = Item::with('category')
            ->where('qr_code', $query)
            ->where('is_active', true)
            ->first();
        $matchedBy = 'qr';

        // 2. Batch QR code match
        if (!$product) {
            $batch = StockBatch::where('qr_code', $query)->first();
            if ($batch) {
                $product = Item::with('category')
                    ->where('id', $batch->item_id)
                    ->where('is_active', true)
                    ->first();
                $matchedBy = 'batch_qr';
            }
        }

        // 3. Fallback: product name search
        if (!$product) {
            $product = Item::with('category')
                ->where('item_name', 'LIKE', "%{$query}%")
                ->where('is_active', true)
                ->orderBy('item_name')
                ->first();
            $matchedBy = 'name';
        }

        if (!$product) {
            return response()->json(['ok' => false, 'error' => "No product found for \"{$query}\"."], 404);
        }

        // 3. Stock per branch
        $user = Auth::user();

        if ($user->isBranchScoped()) {
            $stock = DB::table('branches as b')
                ->leftJoin(DB::raw("(
                    SELECT branch_id,
                           SUM(quantity_left) AS total_qty,
                           MIN(expiry_date) AS nearest_expiry
                    FROM stock_batches
                    WHERE item_id = {$product->id} AND quantity_left > 0
                    GROUP BY branch_id
                ) sb"), 'sb.branch_id', '=', 'b.id')
                ->where('b.id', $user->branch_id)
                ->select(
                    'b.id as branch_id',
                    'b.branch_name',
                    DB::raw('COALESCE(sb.total_qty, 0) AS total_qty'),
                    'sb.nearest_expiry'
                )
                ->get();
        } else {
            $stock = DB::table('branches as b')
                ->join(DB::raw("(
                    SELECT branch_id,
                           SUM(quantity_left) AS total_qty,
                           MIN(expiry_date) AS nearest_expiry
                    FROM stock_batches
                    WHERE item_id = {$product->id} AND quantity_left > 0
                    GROUP BY branch_id
                ) sb"), 'sb.branch_id', '=', 'b.id')
                ->where('b.is_active', true)
                ->where('sb.total_qty', '>', 0)
                ->select(
                    'b.id as branch_id',
                    'b.branch_name',
                    'sb.total_qty',
                    'sb.nearest_expiry'
                )
                ->orderBy('b.branch_name')
                ->get();
        }

        $batches = StockBatch::where('item_id', $product->id)
            ->where('quantity_left', '>', 0)
            ->when($user->isBranchScoped(), function ($q) use ($user) {
                $q->where('branch_id', $user->branch_id);
            })
            ->orderBy('expiry_date')
            ->orderBy('id')
            ->get()
            ->map(function ($b) {
                return [
                    'id'            => $b->id,
                    'batch_number'  => '#' . str_pad($b->id, 3, '0', STR_PAD_LEFT),
                    'branch_id'     => (int)$b->branch_id,
                    'quantity_left' => (int)$b->quantity_left,
                    'expiry_date'   => $b->expiry_date ? $b->expiry_date->format('Y-m-d') : null,
                    'received_at'   => $b->received_at ? $b->received_at->format('M j, Y') : null,
                ];
            });

        return response()->json([
            'ok'         => true,
            'matched_by' => $matchedBy,
            'product'    => [
                'id'               => $product->id,
                'item_name'        => $product->item_name,
                'category_name'    => $product->category->name ?? null,
                'goods_type'       => $product->category->goods_type ?? null,
                'unit'             => $product->unit,
                'pack_description' => $product->pack_description,
                'price'            => $product->price,
                'qr_code'          => $product->qr_code,
                'reorder_level'    => (int)$product->reorder_level,
                'shelf_life_days'  => $product->shelf_life_days,
                'stock'            => $stock->map(fn($s) => [
                    'branch_id'      => (int)$s->branch_id,
                    'branch_name'    => $s->branch_name,
                    'total_qty'      => (int)$s->total_qty,
                    'nearest_expiry' => $s->nearest_expiry,
                ])->values()->toArray(),
                'batches'          => $batches->values()->toArray(),
            ],
        ]);
    }

    /**
     * Process stock in/out/adjustment via StockService.
     */
    protected function processStock(Request $request, string $action): JsonResponse
    {
        $itemId   = (int)$request->input('item_id', 0);
        $branchId = (int)$request->input('branch_id', 0);
        $quantity = (int)$request->input('quantity', 0);
        $reason   = trim($request->input('reason', ''));
        $remarks  = trim($request->input('remarks', ''));

        if ($itemId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Please select a product.'], 422);
        }
        if ($branchId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Please select a branch.'], 422);
        }
        if ($quantity <= 0) {
            return response()->json(['ok' => false, 'error' => 'Quantity must be at least 1.'], 422);
        }

        // Branch access check
        $user = Auth::user();
        if ($user->isBranchScoped() && $user->branch_id !== $branchId) {
            return response()->json(['ok' => false, 'error' => 'You do not have access to this branch.'], 403);
        }

        // Verify item and branch exist and are active
        $item = Item::where('id', $itemId)->where('is_active', true)->first();
        if (!$item) {
            return response()->json(['ok' => false, 'error' => 'Product not found or is inactive.'], 422);
        }

        $branch = Branch::where('id', $branchId)->where('is_active', true)->first();
        if (!$branch) {
            return response()->json(['ok' => false, 'error' => 'Branch not found or is inactive.'], 422);
        }

        $stockService = new StockService();

        if ($action === 'stock_in') {
            // Calculate expiry date
            $expiryDate = null;
            $rawExpiry = trim($request->input('expiry_date', ''));

            if ($rawExpiry !== '') {
                $parsed = DateTime::createFromFormat('Y-m-d', $rawExpiry);
                if (!$parsed) {
                    return response()->json(['ok' => false, 'error' => 'Invalid expiry date format.'], 422);
                }
                $expiryDate = $parsed->format('Y-m-d');
            } elseif ($item->shelf_life_days) {
                $d = new DateTime();
                $d->modify('+' . (int)$item->shelf_life_days . ' days');
                $expiryDate = $d->format('Y-m-d');
            }

            $result = $stockService->stockIn(
                $itemId, $branchId, $quantity, $user->id,
                $expiryDate, $reason ?: 'Stock received (scan)', $remarks ?: null
            );
        } elseif ($action === 'stock_out') {
            if ($reason === '') {
                return response()->json(['ok' => false, 'error' => 'Reason is required for stock out.'], 422);
            }
            $result = $stockService->stockOut($itemId, $branchId, $quantity, $user->id, $reason, $remarks ?: null);
        } else {
            // adjustment
            if ($reason === '') {
                return response()->json(['ok' => false, 'error' => 'Reason is required for adjustments.'], 422);
            }
            $result = $stockService->adjustment($itemId, $branchId, $quantity, $user->id, $reason, $remarks ?: null);
        }

        if (!$result['ok']) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }
}
