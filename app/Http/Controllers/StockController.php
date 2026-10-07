<?php

namespace App\Http\Controllers;

use App\Models\Branch;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Services\StockService;
use DateTime;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

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

        return view('stock.index', compact('branches'));
    }

    /**
     * Handle AJAX API actions: stock_in, stock_out, adjustment.
     */
    public function api(Request $request): JsonResponse
    {
        $action = $request->input('action');

        if (!in_array($action, ['stock_in', 'stock_out', 'adjustment'])) {
            return response()->json(['ok' => false, 'error' => 'Unknown action.'], 400);
        }

        $user = Auth::user();

        if ($user->isBranchStaff() && !in_array($action, ['stock_in', 'stock_out', 'adjustment'])) {
            return response()->json(['ok' => false, 'error' => 'You do not have permission for this action.'], 403);
        }

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
