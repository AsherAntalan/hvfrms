<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Item;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class StockService
{
    public function unreservedQuantity(StockBatch $batch): int
    {
        $reserved = DB::table('order_production_units as u')->join('order_productions as p', 'p.id', '=', 'u.production_id')
            ->join('orders as o', 'o.id', '=', 'p.order_id')
            ->where(fn ($q) => $q->where('p.source_type', '<>', 'picked')->orWhereNotIn('o.status', ['cancelled', 'declined']))
            ->whereNull('u.dispatched_at')->where(function ($query) use ($batch) {
                $query->where('u.stock_batch_id', $batch->id)
                    ->orWhere(fn ($legacy) => $legacy->whereNull('u.stock_batch_id')->where('p.stock_batch_id', $batch->id));
            })->count();

        return max(0, (int) $batch->quantity_left - $reserved);
    }

    /** A batch in this condition is blocked from normal stock in/out. */
    public const CONDITION_DAMAGED = 'damaged';

    /**
     * Expiry-day policy (business rule, resolved explicitly).
     *
     * A batch is usable THROUGH the whole of its expiry date. It is only
     * "expired" once that date is strictly in the past — i.e. expiry_date is
     * before today in the application's business timezone (config/app.php →
     * Asia/Manila). A null expiry date never expires.
     *
     * This single definition is used for:
     *   - rejecting expired goods on stock in,
     *   - excluding expired goods from normal stock out (FEFO),
     * and the warehouse screen's active/expired buckets are aligned to it.
     */
    public static function isExpired(?string $expiryDate): bool
    {
        if ($expiryDate === null || $expiryDate === '') {
            return false;
        }

        return Carbon::parse($expiryDate)->startOfDay()->lt(Carbon::today());
    }

    /**
     * Today's date (business timezone) as a Y-m-d string, for query bounds.
     * A batch is eligible to issue while expiry_date >= this value.
     */
    protected function eligibilityDate(): string
    {
        return Carbon::today()->toDateString();
    }

    /**
     * THE authoritative eligible-availability figure for a product at a branch.
     *
     * Eligible = positive remaining quantity, condition not damaged, and expiry
     * not past (Asia/Manila policy). This is the single definition reused by
     * inventory availability, order creation, and approval so a quantity can
     * never look orderable yet be rejected at allocation. Expired/damaged stock
     * is retained physically for traceability but never counted here.
     */
    public function eligibleAvailable(int $itemId, int $branchId): int
    {
        $today = $this->eligibilityDate();

        return (int) StockBatch::whereNotIn('id', DB::table('order_productions')->whereNotNull('stock_batch_id')->select('stock_batch_id'))
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('quantity_left', '>', 0)
            ->where(function ($q) {
                $q->whereNull('condition')->orWhere('condition', '!=', self::CONDITION_DAMAGED);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            })
            ->get()->sum(fn ($batch) => $this->unreservedQuantity($batch));
    }

    /**
     * Claim a one-time idempotency key inside the current transaction.
     *
     * Returns true when the key is fresh (movement may proceed) and false when
     * the key was already used (duplicate submission — caller must not record a
     * second movement). Must be called INSIDE the DB transaction so that a later
     * failure rolls the key back and a genuine retry stays possible. A null/empty
     * key means "no idempotency protection requested" and always proceeds.
     */
    protected function claimIdempotencyKey(?string $key, string $scope, int $userId): bool
    {
        if ($key === null || $key === '') {
            return true;
        }

        try {
            DB::table('stock_idempotency_keys')->insert([
                'idempotency_key' => $key,
                'scope' => $scope,
                'performed_by' => $userId,
                'created_at' => now(),
            ]);

            return true;
        } catch (QueryException $e) {
            return false;
        }
    }

    /**
     * Record a stock-in transaction.
     * Creates a new batch and logs the transaction.
     *
     * Expired goods are rejected before any write occurs.
     */
    public function stockIn(int $itemId, int $branchId, int $qty, int $userId, ?string $expiryDate = null, string $reason = 'Stock received', ?string $remarks = null, ?string $idempotencyKey = null, string $condition = 'good'): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be at least 1.'];
        }

        if (self::isExpired($expiryDate)) {
            return [
                'ok' => false,
                'error' => 'Cannot stock in expired goods — the expiry date has already passed. Route expired/damaged items through disposal instead.',
            ];
        }

        if (strtolower(trim($condition)) === self::CONDITION_DAMAGED) {
            return [
                'ok' => false,
                'error' => 'Cannot stock in damaged goods — items that fail receiving inspection must not enter available stock. Route them through disposal instead.',
            ];
        }

        return DB::transaction(function () use ($itemId, $branchId, $qty, $userId, $expiryDate, $reason, $remarks, $idempotencyKey) {
            $item = Item::findOrFail($itemId);

            if (! $this->claimIdempotencyKey($idempotencyKey, 'stock_in', $userId)) {
                return ['ok' => true, 'duplicate' => true, 'message' => 'This stock-in was already recorded — no duplicate batch was created.'];
            }

            $batch = StockBatch::create([
                'item_id' => $itemId,
                'branch_id' => $branchId,
                'quantity_in' => $qty,
                'quantity_left' => $qty,
                'expiry_date' => $expiryDate,
                'created_by' => $userId,
            ]);

            $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($item->item_name, 0, 8)));
            $expTag = $expiryDate ? str_replace('-', '', substr($expiryDate, 5)) : 'NOEXP';
            $batchQr = 'HV-'.$clean.'-'.$expTag.'-B'.str_pad($batch->id, 3, '0', STR_PAD_LEFT);
            $batch->update(['qr_code' => $batchQr]);

            // Record the transaction
            StockTransaction::create([
                'item_id' => $itemId,
                'branch_id' => $branchId,
                'batch_id' => $batch->id,
                'type' => 'in',
                'quantity' => $qty,
                'reason' => $reason,
                'remarks' => $remarks,
                'performed_by' => $userId,
            ]);

            // Audit log
            AuditLog::create([
                'user_id' => $userId,
                'action' => 'stock in',
                'table_name' => 'stock_batches',
                'record_id' => $batch->id,
                'details' => "Item: {$item->item_name}, Qty: {$qty}, Expiry: ".($expiryDate ?? 'N/A'),
            ]);

            return [
                'ok' => true,
                'message' => "Stock in recorded: {$qty} {$item->unit}(s) of {$item->item_name}.",
                'batch_id' => $batch->id,
                'qr_code' => $batchQr,
            ];
        });
    }

    /**
     * Record a stock-out transaction using FEFO over ELIGIBLE stock only.
     *
     * Expired batches are excluded — normal stock out must never issue expired
     * goods as usable. Eligible batches are locked for update so concurrent
     * requests cannot over-issue or drive a balance negative.
     */
    public function stockOut(int $itemId, int $branchId, int $qty, int $userId, string $reason, ?string $remarks = null, ?string $idempotencyKey = null): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be at least 1.'];
        }

        return DB::transaction(function () use ($itemId, $branchId, $qty, $userId, $reason, $remarks, $idempotencyKey) {
            $item = Item::findOrFail($itemId);

            $today = $this->eligibilityDate();

            // Eligible batches: in stock, not expired, condition good. Locked so
            // concurrent issues see each other's deductions and cannot over-issue.
            $batches = StockBatch::whereNotIn('id', DB::table('order_productions')->whereNotNull('stock_batch_id')->select('stock_batch_id'))
                ->where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->where('quantity_left', '>', 0)
                ->where(function ($q) {
                    $q->whereNull('condition')->orWhere('condition', '!=', self::CONDITION_DAMAGED);
                })
                ->where(function ($q) use ($today) {
                    $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
                })
                ->orderByRaw("COALESCE(expiry_date, '9999-12-31') ASC")
                ->orderBy('received_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $available = $batches->sum(fn ($batch) => $this->unreservedQuantity($batch));

            if ($available < $qty) {
                return [
                    'ok' => false,
                    'error' => "Insufficient eligible stock. Available: {$available} {$item->unit}(s), Requested: {$qty}. Expired and damaged stock is excluded from stock out.",
                ];
            }

            // Only consume the idempotency key once the movement will actually
            // record (after validation), so a failed attempt can be retried.
            if (! $this->claimIdempotencyKey($idempotencyKey, 'stock_out', $userId)) {
                return ['ok' => true, 'duplicate' => true, 'message' => 'This stock-out was already recorded — no duplicate movement was created.'];
            }

            $remaining = $qty;
            $batchesUsed = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $deduct = min($remaining, $this->unreservedQuantity($batch));

                if ($deduct < 1) {
                    continue;
                }
                $batch->decrement('quantity_left', $deduct);

                StockTransaction::create([
                    'item_id' => $itemId,
                    'branch_id' => $branchId,
                    'batch_id' => $batch->id,
                    'type' => 'out',
                    'quantity' => -$deduct,
                    'reason' => $reason,
                    'remarks' => $remarks,
                    'performed_by' => $userId,
                ]);

                $batchesUsed[] = "Batch #{$batch->id}: -{$deduct}";
                $remaining -= $deduct;
            }

            AuditLog::create([
                'user_id' => $userId,
                'action' => 'stock out (FEFO)',
                'table_name' => 'stock_transactions',
                'record_id' => 0,
                'details' => "Item: {$item->item_name}, Total: -{$qty}, ".implode(', ', $batchesUsed),
            ]);

            return [
                'ok' => true,
                'message' => "Stock out recorded: {$qty} {$item->unit}(s) of {$item->item_name} (FEFO from ".count($batchesUsed).' batch'.(count($batchesUsed) !== 1 ? 'es' : '').').',
            ];
        });
    }

    /**
     * Batch-scoped normal stock out — the movement is sourced from ONE specific
     * scanned batch and nothing else. The batch is locked and its authoritative
     * condition and expiry are re-validated here (never trusting client fields):
     * an expired or damaged batch is blocked from normal stock out. The quantity
     * is bounded by THAT batch's remaining amount, regardless of how much of the
     * product exists in other batches or locations.
     */
    public function stockOutBatch(int $batchId, int $branchId, int $qty, int $userId, string $reason, ?string $remarks = null, ?string $idempotencyKey = null): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be at least 1.'];
        }

        return DB::transaction(function () use ($batchId, $branchId, $qty, $userId, $reason, $remarks, $idempotencyKey) {
            $batch = StockBatch::where('id', $batchId)->lockForUpdate()->first();

            if (! $batch) {
                return ['ok' => false, 'error' => 'Scanned batch was not found.'];
            }

            if (DB::table('order_productions')->where('stock_batch_id', $batch->id)->exists()) {
                return ['ok' => false, 'error' => 'Reserved order stock must be scanned out using its individual delivery labels.'];
            }
            $label = '#'.str_pad($batch->id, 3, '0', STR_PAD_LEFT);

            if ((int) $batch->branch_id !== $branchId) {
                return ['ok' => false, 'error' => "Batch {$label} is stored at a different location and cannot be issued here."];
            }

            $item = Item::find($batch->item_id);
            $unit = $item->unit ?? 'unit';

            // Authoritative condition + expiry — validated against the record,
            // not any submitted form value.
            if (strtolower((string) $batch->condition) === self::CONDITION_DAMAGED) {
                return ['ok' => false, 'error' => "Batch {$label} is marked damaged and cannot be issued as normal stock out. Use disposal instead."];
            }

            $expiry = $batch->expiry_date ? $batch->expiry_date->format('Y-m-d') : null;
            if (self::isExpired($expiry)) {
                return ['ok' => false, 'error' => "Batch {$label} expired on {$expiry} and cannot be issued as normal stock out. Use disposal instead."];
            }

            if ($this->unreservedQuantity($batch) < $qty) {
                return ['ok' => false, 'error' => 'Requested stock is reserved for another commissary order.'];
            }
            if ((int) $batch->quantity_left < $qty) {
                return ['ok' => false, 'error' => "Only {$batch->quantity_left} {$unit}(s) remain in batch {$label}. Cannot issue {$qty}."];
            }

            if (! $this->claimIdempotencyKey($idempotencyKey, 'stock_out', $userId)) {
                return ['ok' => true, 'duplicate' => true, 'message' => 'This stock-out was already recorded — no duplicate movement was created.'];
            }

            $batch->decrement('quantity_left', $qty);

            StockTransaction::create([
                'item_id' => $batch->item_id,
                'branch_id' => $branchId,
                'batch_id' => $batch->id,
                'type' => 'out',
                'quantity' => -$qty,
                'reason' => $reason,
                'remarks' => $remarks,
                'performed_by' => $userId,
            ]);

            AuditLog::create([
                'user_id' => $userId,
                'action' => 'stock out (batch)',
                'table_name' => 'stock_transactions',
                'record_id' => $batch->id,
                'details' => 'Item: '.($item->item_name ?? 'Unknown').", Batch {$label}, Qty: -{$qty}, Reason: {$reason}",
            ]);

            return [
                'ok' => true,
                'message' => "Stock out recorded: {$qty} {$unit}(s) of ".($item->item_name ?? 'item')." from batch {$label}.",
            ];
        });
    }

    /**
     * Batch-scoped disposal/adjustment — removes from ONE specific scanned batch.
     * Unlike normal stock out this deliberately allows expired/damaged batches,
     * because its purpose is to take them out of the balance. Still bounded by the
     * batch's own remaining quantity.
     */
    public function adjustmentBatch(int $batchId, int $branchId, int $qty, int $userId, string $reason, ?string $remarks = null, ?string $idempotencyKey = null): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be at least 1.'];
        }

        return DB::transaction(function () use ($batchId, $branchId, $qty, $userId, $reason, $remarks, $idempotencyKey) {
            $batch = StockBatch::where('id', $batchId)->lockForUpdate()->first();

            if (! $batch) {
                return ['ok' => false, 'error' => 'Scanned batch was not found.'];
            }

            $label = '#'.str_pad($batch->id, 3, '0', STR_PAD_LEFT);

            if ((int) $batch->branch_id !== $branchId) {
                return ['ok' => false, 'error' => "Batch {$label} is stored at a different location."];
            }

            $item = Item::find($batch->item_id);
            $unit = $item->unit ?? 'unit';

            if ((int) $batch->quantity_left < $qty) {
                return ['ok' => false, 'error' => "Only {$batch->quantity_left} {$unit}(s) remain in batch {$label}. Cannot remove {$qty}."];
            }

            $reasonLower = strtolower($reason);
            if (str_contains($reasonLower, 'expired')) {
                $txType = 'expired';
            } elseif (str_contains($reasonLower, 'damaged') || str_contains($reasonLower, 'spoiled')) {
                $txType = 'damaged';
            } else {
                $txType = 'adjustment';
            }

            if (! $this->claimIdempotencyKey($idempotencyKey, 'adjustment', $userId)) {
                return ['ok' => true, 'duplicate' => true, 'message' => 'This adjustment was already recorded — no duplicate movement was created.'];
            }

            $batch->decrement('quantity_left', $qty);

            StockTransaction::create([
                'item_id' => $batch->item_id,
                'branch_id' => $branchId,
                'batch_id' => $batch->id,
                'type' => $txType,
                'quantity' => -$qty,
                'reason' => $reason,
                'remarks' => $remarks,
                'performed_by' => $userId,
            ]);

            AuditLog::create([
                'user_id' => $userId,
                'action' => "stock adjustment ({$txType}, batch)",
                'table_name' => 'stock_transactions',
                'record_id' => $batch->id,
                'details' => 'Item: '.($item->item_name ?? 'Unknown').", Batch {$label}, Qty: -{$qty}, Reason: {$reason}",
            ]);

            return [
                'ok' => true,
                'message' => "Adjustment recorded: -{$qty} {$unit}(s) of ".($item->item_name ?? 'item')." from batch {$label} ({$reason}).",
            ];
        });
    }

    /**
     * FEFO stock deduction for order fulfillment.
     * Validates eligible availability, deducts from oldest eligible batches,
     * locking rows so concurrent allocations cannot over-issue. Expired stock
     * is excluded. Must be called inside a DB transaction by the caller.
     */
    public function deductStockFifo(int $itemId, int $branchId, int $qty, int $userId, string $reason, ?string $remarks = null): array
    {
        $today = $this->eligibilityDate();

        $batches = StockBatch::whereNotIn('id', DB::table('order_productions')->whereNotNull('stock_batch_id')->select('stock_batch_id'))
            ->where('item_id', $itemId)
            ->where('branch_id', $branchId)
            ->where('quantity_left', '>', 0)
            ->where(function ($q) {
                $q->whereNull('condition')->orWhere('condition', '!=', self::CONDITION_DAMAGED);
            })
            ->where(function ($q) use ($today) {
                $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', $today);
            })
            ->orderByRaw("COALESCE(expiry_date, '9999-12-31') ASC")
            ->orderBy('received_at', 'asc')
            ->orderBy('id', 'asc')
            ->lockForUpdate()
            ->get();

        $available = $batches->sum(fn ($batch) => $this->unreservedQuantity($batch));

        if ($available < $qty) {
            $item = Item::find($itemId);
            $name = $item ? $item->item_name : "Item #{$itemId}";

            return [
                'ok' => false,
                'error' => "Not enough eligible stock for {$name}. Available: {$available}, Requested: {$qty}.",
            ];
        }

        $remaining = $qty;

        foreach ($batches as $batch) {
            if ($remaining <= 0) {
                break;
            }

            $deduct = min($remaining, $this->unreservedQuantity($batch));
            $batch->decrement('quantity_left', $deduct);

            StockTransaction::create([
                'item_id' => $itemId,
                'branch_id' => $branchId,
                'batch_id' => $batch->id,
                'type' => 'out',
                'quantity' => -$deduct,
                'reason' => $reason,
                'remarks' => $remarks,
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
                'item_id' => $tx->item_id,
                'branch_id' => $tx->branch_id,
                'batch_id' => $tx->batch_id,
                'type' => 'in',
                'quantity' => $restoreQty,
                'reason' => "Order {$orderNumber} cancelled",
                'remarks' => 'Reversal of approval deduction',
                'performed_by' => $userId,
            ]);

            $totalRestored += $restoreQty;
        }

        return ['ok' => true, 'restored' => $totalRestored];
    }

    /**
     * Record a stock adjustment (expired, damaged, lost).
     * Uses FIFO to deduct from oldest batches. Unlike normal stock out this
     * deliberately INCLUDES expired batches, because its purpose is to remove
     * expired/damaged goods from the balance. Rows are locked for update.
     */
    public function adjustment(int $itemId, int $branchId, int $qty, int $userId, string $reason, ?string $remarks = null): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Quantity must be at least 1.'];
        }

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

            // Lock candidate batches (expired included — this removes them).
            $batches = StockBatch::whereNotIn('id', DB::table('order_productions')->whereNotNull('stock_batch_id')->select('stock_batch_id'))
                ->where('item_id', $itemId)
                ->where('branch_id', $branchId)
                ->where('quantity_left', '>', 0)
                ->orderByRaw("COALESCE(expiry_date, '9999-12-31') ASC")
                ->orderBy('received_at', 'asc')
                ->orderBy('id', 'asc')
                ->lockForUpdate()
                ->get();

            $available = $batches->sum(fn ($batch) => $this->unreservedQuantity($batch));

            if ($available < $qty) {
                return [
                    'ok' => false,
                    'error' => "Insufficient stock for adjustment. Available: {$available} {$item->unit}(s).",
                ];
            }

            $remaining = $qty;
            $batchesUsed = [];

            foreach ($batches as $batch) {
                if ($remaining <= 0) {
                    break;
                }

                $deduct = min($remaining, $this->unreservedQuantity($batch));

                if ($deduct < 1) {
                    continue;
                }
                $batch->decrement('quantity_left', $deduct);

                StockTransaction::create([
                    'item_id' => $itemId,
                    'branch_id' => $branchId,
                    'batch_id' => $batch->id,
                    'type' => $txType,
                    'quantity' => -$deduct,
                    'reason' => $reason,
                    'remarks' => $remarks,
                    'performed_by' => $userId,
                ]);

                $batchesUsed[] = "Batch #{$batch->id}: -{$deduct}";
                $remaining -= $deduct;
            }

            AuditLog::create([
                'user_id' => $userId,
                'action' => "stock adjustment ({$txType})",
                'table_name' => 'stock_transactions',
                'record_id' => 0,
                'details' => "Item: {$item->item_name}, Total: -{$qty}, Reason: {$reason}, ".implode(', ', $batchesUsed),
            ]);

            return [
                'ok' => true,
                'message' => "Adjustment recorded: -{$qty} {$item->unit}(s) of {$item->item_name} ({$reason}).",
            ];
        });
    }
}
