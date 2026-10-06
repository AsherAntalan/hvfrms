<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use Illuminate\Support\Facades\DB;

class StockService
{
    /**
     * Record a stock-in transaction.
     * Creates a new batch and logs the transaction.
     */
    public function stockIn(int $itemId, int $branchId, int $qty, int $userId, ?string $expiryDate = null, string $reason = 'Stock received', ?string $remarks = null): array
    {
        return DB::transaction(function () use ($itemId, $branchId, $qty, $userId, $expiryDate, $reason, $remarks) {
            $item = Item::findOrFail($itemId);

            $batch = StockBatch::create([
                'item_id'       => $itemId,
                'branch_id'     => $branchId,
                'quantity_in'   => $qty,
                'quantity_left' => $qty,
                'expiry_date'   => $expiryDate,
                'created_by'    => $userId,
            ]);

            $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($item->item_name, 0, 8)));
            $expTag = $expiryDate ? str_replace('-', '', substr($expiryDate, 5)) : 'NOEXP';
            $batchQr = 'HV-' . $clean . '-' . $expTag . '-B' . str_pad($batch->id, 3, '0', STR_PAD_LEFT);
            $batch->update(['qr_code' => $batchQr]);

            // Record the transaction
            StockTransaction::create([
                'item_id'      => $itemId,
                'branch_id'    => $branchId,
                'batch_id'     => $batch->id,
                'type'         => 'in',
                'quantity'     => $qty,
                'reason'       => $reason,
                'remarks'      => $remarks,
                'performed_by' => $userId,
            ]);

            // Audit log
            AuditLog::create([
                'user_id'    => $userId,
                'action'     => 'stock in',
                'table_name' => 'stock_batches',
                'record_id'  => $batch->id,
                'details'    => "Item: {$item->item_name}, Qty: {$qty}, Expiry: " . ($expiryDate ?? 'N/A'),
            ]);

            return [
                'ok'       => true,
                'message'  => "Stock in recorded: {$qty} {$item->unit}(s) of {$item->item_name}.",
                'batch_id' => $batch->id,
            ];
        });
    }

    /**
     * Record a stock-out transaction using FIFO.
     * Deducts from the oldest batches first (by expiry, then received date).
     */
    public function stockOut(int $itemId, int $branchId, int $qty, int $userId, string $reason, ?string $remarks = null): array
    {
        return DB::transaction(function () use ($itemId, $branchId, $qty, $userId, $reason, $remarks) {
            $item = Item::findOrFail($itemId);

            // Check available stock
            $available = StockBatch::where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->where('quantity_left', '>', 0)
                ->sum('quantity_left');

            if ($available < $qty) {
                return [
                    'ok'    => false,
                    'error' => "Insufficient stock. Available: {$available} {$item->unit}(s), Requested: {$qty}.",
                ];
            }

            // Get batches in FIFO order
            $batches = StockBatch::where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->where('quantity_left', '>', 0)
                ->orderByRaw("COALESCE(expiry_date, '9999-12-31') ASC")
                ->orderBy('received_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $remaining = $qty;
            $batchesUsed = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;

                $deduct = min($remaining, $batch->quantity_left);

                $batch->decrement('quantity_left', $deduct);

                StockTransaction::create([
                    'item_id'      => $itemId,
                    'branch_id'    => $branchId,
                    'batch_id'     => $batch->id,
                    'type'         => 'out',
                    'quantity'     => -$deduct,
                    'reason'       => $reason,
                    'remarks'      => $remarks,
                    'performed_by' => $userId,
                ]);

                $batchesUsed[] = "Batch #{$batch->id}: -{$deduct}";
                $remaining -= $deduct;
            }

            AuditLog::create([
                'user_id'    => $userId,
                'action'     => 'stock out (FIFO)',
                'table_name' => 'stock_transactions',
                'record_id'  => 0,
                'details'    => "Item: {$item->item_name}, Total: -{$qty}, " . implode(', ', $batchesUsed),
            ]);

            return [
                'ok'      => true,
                'message' => "Stock out recorded: {$qty} {$item->unit}(s) of {$item->item_name} (FIFO from " . count($batchesUsed) . " batch" . (count($batchesUsed) !== 1 ? 'es' : '') . ").",
            ];
        });
    }

    /**
     * FIFO stock deduction for order fulfillment.
     * Validates availability, deducts from oldest batches, returns transaction records.
     */
    public function deductStockFifo(int $itemId, int $branchId, int $qty, int $userId, string $reason, ?string $remarks = null): array
    {
        $available = StockBatch::where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('quantity_left', '>', 0)
            ->sum('quantity_left');

        if ($available < $qty) {
            $item = Item::find($itemId);
            $name = $item ? $item->item_name : "Item #{$itemId}";
            return [
                'ok'    => false,
                'error' => "Not enough stock for {$name}. Available: {$available}, Requested: {$qty}.",
            ];
        }

        $batches = StockBatch::where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('quantity_left', '>', 0)
            ->orderByRaw("COALESCE(expiry_date, '9999-12-31') ASC")
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc')
            ->get();

        $remaining = $qty;

        foreach ($batches as $batch) {
            if ($remaining <= 0) break;

            $deduct = min($remaining, $batch->quantity_left);
            $batch->decrement('quantity_left', $deduct);

            StockTransaction::create([
                'item_id'      => $itemId,
                'branch_id'    => $branchId,
                'batch_id'     => $batch->id,
                'type'         => 'out',
                'quantity'     => -$deduct,
                'reason'       => $reason,
                'remarks'      => $remarks,
                'performed_by' => $userId,
            ]);

            $remaining -= $deduct;
        }

        return ['ok' => true];
    }

    /**
     * Restore stock deducted during order approval.
     * Reverses the exact FIFO deductions by reading the original transactions.
     * Must be called inside a DB transaction.
     */
    public function restoreOrderStock(int $orderId, string $orderNumber, int $mainBranchId, int $userId): array
    {
        $alreadyReversed = StockTransaction::where('reason', "Order {$orderNumber} cancelled")
            ->where('type', 'in')
            ->exists();

        if ($alreadyReversed) {
            return ['ok' => true, 'restored' => 0];
        }

        $transactions = StockTransaction::where('type', 'out')
            ->where('branch_id', $mainBranchId)
            ->where('reason', "Order {$orderNumber} approved")
            ->whereNotNull('batch_id')
            ->get();

        if ($transactions->isEmpty()) {
            return ['ok' => true, 'restored' => 0];
        }

        $totalRestored = 0;

        foreach ($transactions as $tx) {
            $restoreQty = abs($tx->quantity);

            StockBatch::where('id', $tx->batch_id)
                ->increment('quantity_left', $restoreQty);

            StockTransaction::create([
                'item_id'      => $tx->item_id,
                'branch_id'    => $tx->branch_id,
                'batch_id'     => $tx->batch_id,
                'type'         => 'in',
                'quantity'     => $restoreQty,
                'reason'       => "Order {$orderNumber} cancelled",
                'remarks'      => "Reversal of approval deduction",
                'performed_by' => $userId,
            ]);

            $totalRestored += $restoreQty;
        }

        return ['ok' => true, 'restored' => $totalRestored];
    }

    /**
     * Record a stock adjustment (expired, damaged, lost).
     * Also uses FIFO to deduct from oldest batches.
     */
    public function adjustment(int $itemId, int $branchId, int $qty, int $userId, string $reason, ?string $remarks = null): array
    {
        return DB::transaction(function () use ($itemId, $branchId, $qty, $userId, $reason, $remarks) {
            $item = Item::findOrFail($itemId);

            // Determine transaction type based on reason
            $reasonLower = strtolower($reason);
            if (str_contains($reasonLower, 'expired')) {
                $txType = 'expired';
            } elseif (str_contains($reasonLower, 'damaged') || str_contains($reasonLower, 'spoiled')) {
                $txType = 'damaged';
            } else {
                $txType = 'adjustment';
            }

            // Check available stock
            $available = StockBatch::where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->where('quantity_left', '>', 0)
                ->sum('quantity_left');

            if ($available < $qty) {
                return [
                    'ok'    => false,
                    'error' => "Insufficient stock for adjustment. Available: {$available} {$item->unit}(s).",
                ];
            }

            // FIFO deduction
            $batches = StockBatch::where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->where('quantity_left', '>', 0)
                ->orderByRaw("COALESCE(expiry_date, '9999-12-31') ASC")
                ->orderBy('received_at', 'asc')
                ->orderBy('id', 'asc')
                ->get();

            $remaining = $qty;
            $batchesUsed = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) break;

                $deduct = min($remaining, $batch->quantity_left);

                $batch->decrement('quantity_left', $deduct);

                StockTransaction::create([
                    'item_id'      => $itemId,
                    'branch_id'    => $branchId,
                    'batch_id'     => $batch->id,
                    'type'         => $txType,
                    'quantity'     => -$deduct,
                    'reason'       => $reason,
                    'remarks'      => $remarks,
                    'performed_by' => $userId,
                ]);

                $batchesUsed[] = "Batch #{$batch->id}: -{$deduct}";
                $remaining -= $deduct;
            }

            AuditLog::create([
                'user_id'    => $userId,
                'action'     => "stock adjustment ({$txType})",
                'table_name' => 'stock_transactions',
                'record_id'  => 0,
                'details'    => "Item: {$item->item_name}, Total: -{$qty}, Reason: {$reason}, " . implode(', ', $batchesUsed),
            ]);

            return [
                'ok'      => true,
                'message' => "Adjustment recorded: -{$qty} {$item->unit}(s) of {$item->item_name} ({$reason}).",
            ];
        });
    }
}
