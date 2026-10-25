<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Services\OrderGroupLabelService;
use App\Services\OrderLabelService;
use App\Services\StockService;
use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

class StockController extends Controller
{
    /**
     * Show the Scan (Stock In / Out) page.
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

        $mainBranch = $this->mainWarehouse();

        // Warehouse staff/admin operate at the Main Warehouse automatically — the
        // location is fixed and shown read-only (the server enforces it in api()).
        $lockToMain = $user->isWarehouseRole();

        return view('stock.index', compact('branches', 'mainBranch', 'lockToMain'));
    }

    /**
     * Resolve the active Commissary source location (falls back to the main
     * store until a Commissary is configured). Method name kept for callers.
     */
    protected function mainWarehouse(): ?Branch
    {
        return Branch::commissary();
    }

    /**
     * Decide and enforce the branch a movement is recorded against, server-side.
     * Warehouse roles are pinned to the Main Warehouse regardless of any posted
     * value; branch-scoped users keep their own branch; owners/super admins may
     * target a chosen active branch (defaulting to the Main Warehouse). Returns
     * [branchId, errorResponse|null].
     */
    protected function resolveBranchId(Request $request, $user): array
    {
        $main = $this->mainWarehouse();

        if ($user->isWarehouseRole()) {
            if (! $main) {
                return [0, response()->json([
                    'ok' => false,
                    'error' => 'The Commissary is not configured or is inactive. Ask an administrator to set it up before receiving or issuing stock.',
                ], 422)];
            }

            return [(int) $main->id, null];
        }

        if ($user->isBranchScoped()) {
            return [(int) $user->branch_id, null];
        }

        // Owner / super admin: honour an explicit active branch, else Main Warehouse.
        $posted = (int) $request->input('branch_id', 0);
        if ($posted > 0) {
            return [$posted, null];
        }
        if ($main) {
            return [(int) $main->id, null];
        }

        return [0, response()->json(['ok' => false, 'error' => 'Please select a branch.'], 422)];
    }

    /**
     * Ensure a scanned batch belongs to the scanned product. Returns an error
     * response when it does not (or the batch is missing), else null.
     */
    protected function assertBatchItem(int $batchId, int $itemId): ?JsonResponse
    {
        $batch = StockBatch::find($batchId);
        if (! $batch) {
            return response()->json(['ok' => false, 'error' => 'Scanned batch was not found.'], 422);
        }
        if ((int) $batch->item_id !== $itemId) {
            return response()->json(['ok' => false, 'error' => 'Scanned batch does not match the selected product.'], 422);
        }

        return null;
    }

    /**
     * FEFO guard: block releasing a batch while an earlier-expiring usable batch
     * of the same item/branch still has stock, and name that batch as the one to
     * scan instead. Usable = in stock, not damaged, not expired. Returns an error
     * response on violation, else null.
     */
    protected function fefoBlock(int $itemId, int $branchId, int $scannedBatchId): ?JsonResponse
    {
        $today = now()->toDateString();
        $earliest = StockBatch::where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('quantity_left', '>', 0)
            ->where(fn ($q) => $q->whereNull('condition')->orWhere('condition', '<>', 'damaged'))
            ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today))
            ->orderByRaw('expiry_date IS NULL, expiry_date ASC')
            ->orderBy('id')
            ->first();

        if ($earliest && (int) $earliest->id !== $scannedBatchId) {
            $exp = $earliest->expiry_date
                ? Carbon::parse($earliest->expiry_date)->format('M j, Y')
                : 'no expiry';

            return response()->json([
                'ok' => false,
                'error' => "FEFO: release the earliest-expiring stock first — batch #{$earliest->id} (expires {$exp}). Scan that batch instead.",
                'suggest_batch_id' => (int) $earliest->id,
            ], 422);
        }

        return null;
    }

    /**
     * Handle AJAX API actions: stock_in, stock_out, adjustment.
     */
    public function api(Request $request): JsonResponse
    {
        $action = $request->input('action');

        if (! in_array($action, ['stock_in', 'stock_out', 'adjustment'])) {
            return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
        }

        $user = Auth::user();
        $qr = trim((string) $request->input('qr', ''));
        if (str_starts_with($qr, 'HV-PROD-') && str_contains($qr, '-U')) {
            if ($action !== 'stock_out') {
                return response()->json(['ok' => false, 'error' => 'Use Stock Out — For Delivery for an individual production label.'], 422);
            }

            return response()->json((new OrderLabelService)->dispatch($qr, $user, $request->input('quantity', 1)));
        }

        if (str_starts_with($qr, 'HV-PROD-')) {
            $service = new OrderGroupLabelService;
            $key = $request->input('idempotency_key');
            if ($action === 'stock_out') {
                $movement = $request->input('movement', $user->isBranchManager() || $user->isBranchStaff() ? 'usage' : 'delivery');
                if (! in_array($movement, ['usage', 'delivery'], true)) {
                    return response()->json(['ok' => false, 'error' => 'Choose delivery release or branch usage.'], 422);
                }
                if ($movement === 'usage') {
                    [$branchId, $error] = $this->resolveBranchId($request, $user);
                    if ($error) {
                        return $error;
                    }

                    return response()->json($service->useStock($qr, $user, $branchId, $request->input('quantity', 1), (string) $request->input('reason', ''), $request->input('remarks'), $key));
                }

                return response()->json($service->dispatch($qr, $user, $request->input('quantity'), $key));
            }
            if ($action === 'stock_in') {
                return response()->json($service->receive($qr, $user, $request->input('quantity'), $request->input('reason'), $key, $request->boolean('finalize')));
            }

            return response()->json(['ok' => false, 'error' => 'Use delivery release or delivery receipt for this QR.'], 422);
        }

        if ($user->isBranchStaff() && ! in_array($action, ['stock_in', 'stock_out', 'adjustment'])) {
            return response()->json(['ok' => false, 'error' => 'You do not have permission for this action.'], 403);
        }

        $itemId = (int) $request->input('item_id', 0);
        $quantity = (int) $request->input('quantity', 0);
        $reason = trim($request->input('reason', ''));
        $remarks = trim($request->input('remarks', ''));
        $idemKey = trim($request->input('idempotency_key', '')) ?: null;
        $batchId = (int) $request->input('batch_id', 0);
        // Receiving-inspection condition for stock in ('good' | 'damaged').
        $condition = strtolower(trim($request->input('condition', 'good'))) === 'damaged' ? 'damaged' : 'good';

        if ($itemId <= 0) {
            return response()->json(['ok' => false, 'error' => 'Please select a product.'], 422);
        }
        if ($quantity <= 0) {
            return response()->json(['ok' => false, 'error' => 'Quantity must be at least 1.'], 422);
        }

        // Resolve and enforce the location server-side (ignores any posted
        // branch_id for warehouse roles — they are pinned to the Main Warehouse).
        [$branchId, $locError] = $this->resolveBranchId($request, $user);
        if ($locError) {
            return $locError;
        }

        // Branch access check for branch-scoped (non-warehouse) users.
        if ($user->isBranchScoped() && ! $user->isWarehouseRole() && $user->branch_id !== $branchId) {
            return response()->json(['ok' => false, 'error' => 'You do not have access to this branch.'], 403);
        }

        // Verify item and branch exist and are active
        $item = Item::where('id', $itemId)->where('is_active', true)->first();
        if (! $item) {
            return response()->json(['ok' => false, 'error' => 'Product not found or is inactive.'], 422);
        }

        $branch = Branch::where('id', $branchId)->where('is_active', true)->first();
        if (! $branch) {
            return response()->json(['ok' => false, 'error' => 'Location not found or is inactive.'], 422);
        }

        $stockService = new StockService;

        if ($action === 'stock_in') {
            // Calculate expiry date
            $expiryDate = null;
            $rawExpiry = trim($request->input('expiry_date', ''));

            if ($rawExpiry !== '') {
                $parsed = DateTime::createFromFormat('Y-m-d', $rawExpiry);
                if (! $parsed) {
                    return response()->json(['ok' => false, 'error' => 'Invalid expiry date format.'], 422);
                }
                $expiryDate = $parsed->format('Y-m-d');
            } elseif ($item->shelf_life_days) {
                $d = new DateTime;
                $d->modify('+'.(int) $item->shelf_life_days.' days');
                $expiryDate = $d->format('Y-m-d');
            }

            $result = $stockService->stockIn(
                $itemId, $branchId, $quantity, $user->id,
                $expiryDate, $reason ?: 'Stock received (scan)', $remarks ?: null, $idemKey, $condition
            );
        } elseif ($action === 'stock_out') {
            if ($reason === '') {
                return response()->json(['ok' => false, 'error' => 'Reason is required for stock out.'], 422);
            }
            // When a specific batch was scanned, the movement is sourced from THAT
            // batch only (and its item must match the scanned product).
            if ($batchId > 0) {
                if ($batchError = $this->assertBatchItem($batchId, $itemId)) {
                    return $batchError;
                }
                // FEFO: a normal consumption (Sold / Used) must come from the
                // earliest-expiring usable batch. Disposal reasons target the
                // specific scanned batch and are exempt.
                if ($reason === 'Sold / Used' && $fefo = $this->fefoBlock($itemId, $branchId, $batchId)) {
                    return $fefo;
                }
                $result = $stockService->stockOutBatch($batchId, $branchId, $quantity, $user->id, $reason, $remarks ?: null, $idemKey);
            } else {
                $result = $stockService->stockOut($itemId, $branchId, $quantity, $user->id, $reason, $remarks ?: null, $idemKey);
            }
        } else {
            // adjustment / disposal
            if ($reason === '') {
                return response()->json(['ok' => false, 'error' => 'Reason is required for adjustments.'], 422);
            }
            if ($batchId > 0) {
                if ($batchError = $this->assertBatchItem($batchId, $itemId)) {
                    return $batchError;
                }
                $result = $stockService->adjustmentBatch($batchId, $branchId, $quantity, $user->id, $reason, $remarks ?: null, $idemKey);
            } else {
                $result = $stockService->adjustment($itemId, $branchId, $quantity, $user->id, $reason, $remarks ?: null);
            }
        }

        if (! $result['ok']) {
            return response()->json($result, 422);
        }

        return response()->json($result);
    }

    public function history(Request $request, Item $item)
    {
        $user = Auth::user();

        $query = StockTransaction::where('item_id', $item->id)
            ->with(['branch', 'batch', 'performer']);

        if ($user->isBranchScoped()) {
            $query->where('branch_id', $user->branch_id);
        }

        $transactions = $query->orderByDesc('created_at')->paginate(50);

        $batches = StockBatch::where('item_id', $item->id)
            ->when($user->isBranchScoped(), fn ($q) => $q->where('branch_id', $user->branch_id))
            ->with('branch')
            ->orderByDesc('id')
            ->get();

        if ($request->ajax() || $request->wantsJson()) {
            return view('partials.stock-history-content', compact('item', 'transactions', 'batches'));
        }

        return view('stock.history', compact('item', 'transactions', 'batches'));
    }

    public function batchDetail(Request $request, StockBatch $batch)
    {
        $user = Auth::user();

        if ($user->isBranchScoped() && $batch->branch_id !== $user->branch_id) {
            abort(403);
        }

        $batch->load(['item', 'branch', 'creator']);

        $transactions = StockTransaction::where('batch_id', $batch->id)
            ->with('performer')
            ->orderByDesc('created_at')
            ->get();

        if ($request->ajax() || $request->wantsJson()) {
            return view('partials.stock-batch-content', compact('batch', 'transactions'));
        }

        return view('stock.batch-detail', compact('batch', 'transactions'));
    }
}
