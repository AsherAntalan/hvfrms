<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockBatch;
use App\Services\OrderGroupLabelService;
use App\Services\OrderLabelService;
use App\Services\StockService;
use Carbon\Carbon;
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
            $qr = trim((string) $request->input('qr', ''));
            if (str_starts_with($qr, 'HV-PROD-') && str_contains($qr, '-U')) {
                if ($action !== 'stock_out') {
                    return response()->json(['ok' => false, 'error' => 'Use Stock Out — For Delivery for this label.'], 422);
                }

                return response()->json((new OrderLabelService)->dispatch($qr, Auth::user(), $request->input('quantity', 1)));
            }

            if (str_starts_with($qr, 'HV-PROD-')) {
                return app(StockController::class)->api($request);
            }

            return $this->processStock($request);
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
            'ok' => true,
            'rows' => $rows->map(fn ($r) => [
                'item_name' => $r->item_name,
                'unit' => $r->unit,
                'type' => $r->type,
                'reason' => $r->reason,
                'qty' => (int) $r->qty,
                'time' => Carbon::parse($r->created_at)->format('g:i A'),
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

        if (str_starts_with($query, 'HV-PROD-') && str_contains($query, '-U')) {
            return response()->json((new OrderLabelService)->lookup($query, Auth::user()));
        }

        if (str_starts_with($query, 'HV-PROD-')) {
            return response()->json((new OrderGroupLabelService)->lookup($query, Auth::user(), $request->filled('branch_id') ? (int) $request->input('branch_id') : null));
        }

        // 1. Exact item QR code match
        $product = Item::with('category')
            ->where('qr_code', $query)
            ->where('is_active', true)
            ->first();
        $matchedBy = 'qr';

        // 2. Batch QR code match — remember WHICH physical batch was scanned
        $matchedBatch = null;
        if (! $product) {
            $batch = StockBatch::where('qr_code', $query)->first();
            if ($batch) {
                $product = Item::with('category')
                    ->where('id', $batch->item_id)
                    ->where('is_active', true)
                    ->first();
                if ($product) {
                    $matchedBatch = $batch;
                    $matchedBy = 'batch_qr';
                }
            }
        }

        // 3. Fallback: product name search
        if (! $product) {
            $product = Item::with('category')
                ->where('item_name', 'LIKE', "%{$query}%")
                ->where('is_active', true)
                ->orderBy('item_name')
                ->first();
            $matchedBy = 'name';
        }

        if (! $product) {
            return response()->json(['ok' => false, 'error' => "No product found for \"{$query}\"."], 404);
        }

        // 3. Stock per branch
        $user = Auth::user();
        if ($matchedBatch && ! $user->isOwner() && ! $user->isMainBranchRole()) {
            abort_unless((int) $matchedBatch->branch_id === (int) $user->branch_id, 403);
        }

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
                    'id' => $b->id,
                    'batch_number' => '#'.str_pad($b->id, 3, '0', STR_PAD_LEFT),
                    'branch_id' => (int) $b->branch_id,
                    'quantity_left' => (int) $b->quantity_left,
                    'expiry_date' => $b->expiry_date ? $b->expiry_date->format('Y-m-d') : null,
                    'is_expired' => StockService::isExpired($b->expiry_date ? $b->expiry_date->format('Y-m-d') : null),
                    'received_at' => $b->received_at ? $b->received_at->format('M j, Y') : null,
                ];
            });

        // Main Warehouse location + the exact scanned-batch details. When a batch
        // QR is scanned we report THAT batch's own remaining quantity (the amount
        // the physical label represents) rather than the product's cross-batch or
        // cross-location total, so the scanner shows what was actually scanned.
        $mainBranch = Branch::commissary();

        $matchedBatchPayload = null;
        if ($matchedBatch) {
            $expiry = $matchedBatch->expiry_date ? $matchedBatch->expiry_date->format('Y-m-d') : null;
            $condition = strtolower((string) ($matchedBatch->condition ?? 'good'));
            $matchedBatchPayload = [
                'id' => $matchedBatch->id,
                'batch_number' => '#'.str_pad($matchedBatch->id, 3, '0', STR_PAD_LEFT),
                'branch_id' => (int) $matchedBatch->branch_id,
                'quantity_left' => (int) $matchedBatch->quantity_left,
                'expiry_date' => $expiry,
                'is_expired' => StockService::isExpired($expiry),
                'condition' => $condition,
                'is_damaged' => $condition === StockService::CONDITION_DAMAGED,
                'received_at' => $matchedBatch->received_at ? $matchedBatch->received_at->format('M j, Y') : null,
            ];
        }

        return response()->json([
            'ok' => true,
            'matched_by' => $matchedBy,
            'matched_batch' => $matchedBatchPayload,
            'main_branch' => $mainBranch ? ['id' => (int) $mainBranch->id, 'branch_name' => $mainBranch->branch_name] : null,
            'product' => [
                'id' => $product->id,
                'item_name' => $product->item_name,
                'category_name' => $product->category->name ?? null,
                'goods_type' => $product->category->goods_type ?? null,
                'unit' => $product->unit,
                'pack_description' => $product->pack_description,
                'price' => $product->price,
                'qr_code' => $product->qr_code,
                'reorder_level' => (int) $product->reorder_level,
                'shelf_life_days' => $product->shelf_life_days,
                'stock' => $stock->map(fn ($s) => [
                    'branch_id' => (int) $s->branch_id,
                    'branch_name' => $s->branch_name,
                    'total_qty' => (int) $s->total_qty,
                    'nearest_expiry' => $s->nearest_expiry,
                ])->values()->toArray(),
                'batches' => $batches->values()->toArray(),
            ],
        ]);
    }

    /**
     * Process stock in/out/adjustment via StockService.
     */
    protected function processStock(Request $request): JsonResponse
    {
        $request->validate(['quantity' => ['required', 'integer', 'min:1']]);

        return app(StockController::class)->api($request);
    }
}
