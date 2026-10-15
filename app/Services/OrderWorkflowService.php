<?php

namespace App\Services;

use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\DeliveryDiscrepancy;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * One authoritative order-fulfilment workflow shared by every entry point
 * (Active Deliveries, Orders, receiving scan). Streamlined normal path:
 *
 *   pending --Confirm for Delivery--> delivering --receiving scan--> delivered + settled
 *
 * Allocation (warehouse stock deduction) happens ONCE, at Confirm for Delivery,
 * using the existing "Order {number} approved" ledger reason so downstream
 * branch stock-in still finds its source lots. Legacy approved/preparing orders
 * were already allocated at their approval step and are never deducted twice.
 *
 * "settled" in this app is an OPERATIONAL closure status (there is no payment /
 * invoice / balance field), so a fully healthy receipt may close the shipment
 * automatically without fabricating any financial effect.
 */
class OrderWorkflowService
{
    public function __construct(protected StockService $stock = new StockService) {}

    /** The ledger reason that marks a warehouse allocation for an order. */
    public function allocationReason(Order $order): string
    {
        return "Order {$order->order_number} approved";
    }

    /** True when the order already holds a warehouse allocation. */
    public function isAllocated(Order $order): bool
    {
        return StockTransaction::where('type', 'out')
            ->where('reason', $this->allocationReason($order))
            ->exists();
    }

    /**
     * Deduct eligible Main Warehouse stock for every line, once. Must run inside
     * the caller's transaction (which has already locked the order row). Returns
     * ['ok'=>true] or ['ok'=>false,'error'=>...]; caller rolls back on failure.
     */
    public function allocate(Order $order, int $mainBid, int $userId, string $branchName): array
    {
        $items = OrderItem::where('order_id', $order->id)->get();
        foreach ($items as $oi) {
            $result = $this->stock->deductStockFifo(
                $oi->item_id, $mainBid, (int) $oi->quantity, $userId,
                $this->allocationReason($order),
                "Allocated for {$branchName}"
            );
            if (! $result['ok']) {
                return $result;
            }
        }

        return ['ok' => true];
    }

    /**
     * Record receipt of a dispatched (delivering) shipment at its destination.
     * Caller has locked the order and verified status === 'delivering'.
     *
     * $receivedMap: [order_item_id => accepted_qty]. Empty/absent entries mean
     * full acceptance of the ordered quantity (an order-level scan = accept all).
     * Expiry/condition is re-checked here: a lot that expired in transit is
     * rejected (recorded as an exception), never added as usable stock.
     *
     * A fully healthy receipt (every line accepted in full, nothing expired)
     * records Delivered then Settled automatically. Any shortfall/expiry leaves
     * the shipment Delivered for the existing exception workflow.
     *
     * Idempotent at the caller via the status lock; it never deducts warehouse
     * stock again (that happened at allocation).
     */
    public function receive(Order $order, int $userId, array $receivedMap, int $mainBid, array $receivedReasons = []): array
    {
        if ((new OrderLabelService)->hasUnits($order->id)) {
            return (new OrderGroupLabelService)->receiveOrder($order, User::findOrFail($userId), $receivedMap, $receivedReasons);
        }
        $orderItems = OrderItem::select('order_items.*', 'items.item_name', 'items.unit', 'items.shelf_life_days')
            ->join('items', 'items.id', '=', 'order_items.item_id')
            ->where('order_items.order_id', $order->id)
            ->get();

        $commissary = $this->hasProduction($order);
        if (! $commissary && ! $this->isAllocated($order)) {
            return ['ok' => false, 'error' => 'No recorded prepared shipment or historical stock release exists for this receipt.'];
        }
        // No dispatch, no receipt. A commissary order must be fully scanned out.
        if ($commissary && ! $this->isFullyDispatched($order)) {
            return ['ok' => false, 'error' => "Order {$order->order_number} has not been fully dispatched from the Main Branch yet."];
        }

        $productions = $commissary
            ? OrderProduction::where('order_id', $order->id)->get()->keyBy('order_item_id')
            : collect();

        if (array_diff(array_map('intval', array_keys($receivedMap)), $orderItems->pluck('id')->all())) {
            return ['ok' => false, 'error' => 'Received quantities contain a line from another order.'];
        }
        foreach ($orderItems as $line) {
            $maximum = $commissary ? (int) ($productions->get($line->id)?->dispatched_qty ?? 0) : (int) $line->quantity;
            $received = $receivedMap[$line->id] ?? $maximum;
            if (! is_numeric($received) || (int) $received != $received || $received < 0 || $received > $maximum) {
                return ['ok' => false, 'error' => 'Receipt must be a whole quantity between 0 and the released '.$maximum.'.'];
            }
        }
        foreach ($receivedReasons as $reason) {
            if ($reason !== null && $reason !== '' && ! in_array($reason, ['Missing Quantity', 'Damaged in Transit', 'Incorrect Quantity', 'Incorrect Item', 'Other'], true)) {
                return ['ok' => false, 'error' => 'Invalid receipt discrepancy reason.'];
            }
        }
        $hasException = false;

        foreach ($orderItems as $oi) {
            $lots = [];
            $expectedQuantity = $oi->fulfilled_quantity === null ? (int) $oi->quantity : (int) $oi->fulfilled_quantity;

            if ($commissary) {
                // Source is the order-bound produced output: dispatched quantity
                // at the produced lot's own expiry. No warehouse stock exists or
                // is deducted for commissary orders.
                $prod = $productions->get($oi->id);
                $shipped = $prod ? (int) $prod->dispatched_qty : 0;
                $requested = array_key_exists($oi->id, $receivedMap) ? $receivedMap[$oi->id] : $shipped;
                if (! is_numeric($requested) || (int) $requested != $requested || $requested < 0 || $requested > $shipped) {
                    throw ValidationException::withMessages(['received_quantity' => 'Receipt must be a whole quantity between 0 and the released '.$shipped.'.']);
                }
                $requested = (int) $requested;
                $units = $prod ? DB::table('order_production_units')->where('production_id', $prod->id)->whereNotNull('dispatched_at')->orderBy('unit_number')->get() : collect();
                if ($units->isNotEmpty()) {
                    $remaining = $requested;
                    foreach ($units as $unit) {
                        if ($remaining < 1) {
                            break;
                        }
                        $source = StockBatch::find($unit->stock_batch_id ?? $prod->stock_batch_id);
                        $lots[] = ['qty' => 1, 'expiry' => $prod->source_type === 'picked' ? $source?->expiry_date?->format('Y-m-d') : $prod->expiry_date?->format('Y-m-d'),
                            'damaged' => $source?->condition === 'damaged' || $prod->condition === 'damaged'];
                        $remaining--;
                    }
                } elseif ($requested > 0) {
                    $lots[] = ['qty' => $requested, 'expiry' => $prod?->expiry_date?->format('Y-m-d'), 'damaged' => $prod?->condition === 'damaged'];
                }
            } else {
                $requested = array_key_exists($oi->id, $receivedMap)
                    ? max(0, (int) $receivedMap[$oi->id])
                    : (int) $oi->quantity;

                // Legacy stock-backed order: source lots from its allocation.
                $sourceTxs = StockTransaction::where('type', 'out')
                    ->where('item_id', $oi->item_id)
                    ->where('branch_id', $mainBid)
                    ->where('reason', $this->allocationReason($order))
                    ->whereNotNull('batch_id')
                    ->get();

                if ($sourceTxs->isEmpty()) {
                    $expiry = $oi->shelf_life_days ? now()->addDays((int) $oi->shelf_life_days)->toDateString() : null;
                    $lots[] = ['qty' => $requested, 'expiry' => $expiry];
                } else {
                    $remaining = $requested;
                    foreach ($sourceTxs as $tx) {
                        if ($remaining <= 0) {
                            break;
                        }
                        $sourceBatch = StockBatch::find($tx->batch_id);
                        $take = min(abs((int) $tx->quantity), $remaining);
                        $lots[] = [
                            'qty' => $take,
                            'expiry' => $sourceBatch && $sourceBatch->expiry_date ? $sourceBatch->expiry_date->format('Y-m-d') : null,
                        ];
                        $remaining -= $take;
                    }
                }
            }

            $accepted = 0;
            $expiredRejected = 0;
            foreach ($lots as $lot) {
                // Re-check expiry at receipt: a lot can expire in transit.
                if (StockService::isExpired($lot['expiry']) || ($lot['damaged'] ?? false)) {
                    $expiredRejected += $lot['qty'];
                    $hasException = true;

                    continue; // never stock in expired goods as usable
                }

                $batch = StockBatch::create([
                    'item_id' => $oi->item_id,
                    'branch_id' => $order->branch_id,
                    'quantity_in' => $lot['qty'],
                    'quantity_left' => $lot['qty'],
                    'expiry_date' => $lot['expiry'],
                    'condition' => 'good',
                    'received_at' => now(),
                    'created_by' => $userId,
                ]);

                $clean = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', substr($oi->item_name, 0, 8)));
                $expTag = $lot['expiry'] ? str_replace('-', '', substr($lot['expiry'], 5)) : 'NOEXP';
                $batch->update(['qr_code' => 'HV-'.$clean.'-'.$expTag.'-B'.str_pad($batch->id, 3, '0', STR_PAD_LEFT)]);

                StockTransaction::create([
                    'item_id' => $oi->item_id,
                    'branch_id' => $order->branch_id,
                    'batch_id' => $batch->id,
                    'type' => 'in',
                    'quantity' => $lot['qty'],
                    'reason' => "Order {$order->order_number} delivered",
                    'remarks' => 'Auto stock-in from order delivery',
                    'performed_by' => $userId,
                ]);

                $accepted += $lot['qty'];
            }

            OrderItem::where('id', $oi->id)->update(['received_quantity' => $accepted]);

            if ($accepted != $expectedQuantity) {
                $hasException = true;
                // Reason must be one of the existing discrepancy enum values;
                // expired-in-transit is recorded as "Other" with a clear note.
                $reason = $expiredRejected > 0 ? 'Other'
                    : ($accepted < $expectedQuantity ? 'Missing Quantity' : 'Incorrect Quantity');
                if ($expiredRejected === 0 && ! empty($receivedReasons[$oi->id])) {
                    $reason = $receivedReasons[$oi->id];
                }
                DeliveryDiscrepancy::create([
                    'order_id' => $order->id,
                    'order_item_id' => $oi->id,
                    'expected_quantity' => $expectedQuantity,
                    'received_quantity' => $accepted,
                    'reason' => $reason,
                    'remarks' => $expiredRejected > 0 ? "{$expiredRejected} unit(s) expired or damaged in transit" : null,
                    'reported_by' => $userId,
                ]);
            }
        }

        $now = now();
        $update = [
            'status' => $hasException ? 'delivered' : 'settled',
            'delivered_at' => $now,
            'confirmed_by' => $userId,
            'confirmed_at' => $now,
        ];
        if (! $hasException) {
            $update['settled_at'] = $now;
        }
        Order::where('id', $order->id)->update($update);

        AuditLog::create([
            'user_id' => $userId,
            'action' => 'order delivered + confirmed',
            'table_name' => 'orders',
            'record_id' => $order->id,
            'details' => "Order {$order->order_number} received at {$order->branch_name}."
                .($hasException ? ' Exceptions recorded — left Delivered for review.' : ''),
        ]);

        if (! $hasException) {
            AuditLog::create([
                'user_id' => $userId,
                'action' => 'order settled',
                'table_name' => 'orders',
                'record_id' => $order->id,
                'details' => "Order {$order->order_number} fully received and operationally settled (no payment effect).",
            ]);
        }

        return [
            'ok' => true,
            'settled' => ! $hasException,
            'message' => $hasException
                ? "Order {$order->order_number} received with exceptions — recorded as Delivered for review."
                : "Order {$order->order_number} received in full. Delivered and settled.",
        ];
    }

    // ───────────────── commissary production / dispatch ─────────────────

    /** True when this order has order-bound produced output (a commissary order). */
    public function hasProduction(Order $order): bool
    {
        return OrderProduction::where('order_id', $order->id)->exists();
    }

    /**
     * Record/freeze the actual produced output for an order's lines. One output
     * per line, server-generated unique QR. Expired/damaged output is rejected
     * for normal delivery. Frozen once the order leaves preparation. Idempotent:
     * re-preparing updates produced/expiry but never resets dispatch progress or
     * mints a duplicate QR. $lines: [order_item_id => [produced_qty,expiry_date,condition]].
     */
    public function prepareOutput(Order $order, array $lines, int $userId): array
    {
        if ((new OrderLabelService)->hasUnits($order->id)) {
            return ['ok' => false, 'error' => 'Individual labels are frozen. Do not edit printed production quantities or expiry.'];
        }

        return DB::transaction(function () use ($order, $lines, $userId) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();
            if (in_array($locked->status, ['delivering', 'delivered', 'settled', 'cancelled', 'declined'], true)) {
                return ['ok' => false, 'error' => "Order {$order->order_number} is '{$locked->status}'; its prepared output is frozen. Use the authorized correction process."];
            }

            $items = OrderItem::where('order_id', $order->id)->get()->keyBy('id');
            if (empty($lines)) {
                return ['ok' => false, 'error' => 'No produced output provided.'];
            }

            foreach ($lines as $oiId => $d) {
                $oi = $items[(int) $oiId] ?? null;
                if (! $oi) {
                    return ['ok' => false, 'error' => 'A prepared line does not belong to this order.'];
                }
                $produced = (int) ($d['produced_qty'] ?? 0);
                if ($produced < 0 || $produced > (int) $oi->quantity) {
                    return ['ok' => false, 'error' => "Produced quantity for a line must be between 0 and the requested {$oi->quantity}."];
                }
                $expiry = trim((string) ($d['expiry_date'] ?? '')) ?: null;
                $condition = strtolower(trim((string) ($d['condition'] ?? 'good'))) === 'damaged' ? 'damaged' : 'good';
                if ($produced > 0 && $condition === 'damaged') {
                    return ['ok' => false, 'error' => 'Damaged output cannot be prepared for normal delivery. Route it through disposal.'];
                }
                if ($produced > 0 && StockService::isExpired($expiry)) {
                    return ['ok' => false, 'error' => 'Produced output is already expired and cannot be prepared for delivery.'];
                }

                // Preserve dispatched_qty on re-prepare (not in the update set).
                OrderProduction::updateOrCreate(
                    ['order_item_id' => $oi->id],
                    [
                        'order_id' => $order->id,
                        'item_id' => $oi->item_id,
                        'destination_branch_id' => $order->branch_id,
                        'produced_qty' => $produced,
                        'production_at' => now(),
                        'expiry_date' => $expiry,
                        'condition' => $condition,
                        'qr_code' => OrderProduction::qrFor($order->id, $oi->id),
                        'prepared_by' => $userId,
                    ]
                );
            }

            AuditLog::create([
                'user_id' => $userId,
                'action' => 'order output prepared',
                'table_name' => 'order_productions',
                'record_id' => $order->id,
                'details' => "Produced output prepared for order {$order->order_number} (".count($lines).' line(s)).',
            ]);

            return ['ok' => true, 'message' => "Produced output saved for order {$order->order_number}."];
        });
    }

    /** All prepared lines fully scanned out (physical dispatch complete). */
    public function isFullyDispatched(Order $order): bool
    {
        $prods = OrderProduction::where('order_id', $order->id)->get();
        $lineCount = OrderItem::where('order_id', $order->id)->count();
        if ($prods->count() < $lineCount || $prods->isEmpty()) {
            return false;
        }
        foreach ($prods as $p) {
            if ((int) $p->dispatched_qty < (int) $p->produced_qty) {
                return false;
            }
        }

        return true;
    }

    /**
     * Scan-out dispatch of one produced output. Locks the row, verifies the
     * order is confirmed (delivering) and the quantity does not exceed the
     * unshipped produced amount. Idempotent/safe under retries via the lock.
     */
    public function dispatchOutput(int $orderItemId, int $qty, int $userId): array
    {
        if ($qty <= 0) {
            return ['ok' => false, 'error' => 'Dispatch quantity must be at least 1.'];
        }

        return DB::transaction(function () use ($orderItemId, $qty, $userId) {
            $prod = OrderProduction::where('order_item_id', $orderItemId)->lockForUpdate()->first();
            if (! $prod) {
                return ['ok' => false, 'error' => 'No produced output for this scan.'];
            }
            $order = Order::where('id', $prod->order_id)->lockForUpdate()->first();
            if (! $order || $order->status !== 'delivering') {
                return ['ok' => false, 'error' => "This order is not confirmed For Delivery (status '".($order->status ?? 'unknown')."')."];
            }
            if (! $order->branch_confirmed_at) {
                return ['ok' => false, 'error' => "Order {$order->order_number} has not been confirmed by the destination branch yet."];
            }
            $avail = $prod->undispatched();
            if ($qty > $avail) {
                return ['ok' => false, 'error' => "Only {$avail} unit(s) remain to dispatch for this output."];
            }
            $prod->increment('dispatched_qty', $qty);

            AuditLog::create([
                'user_id' => $userId,
                'action' => 'order dispatched (scan-out)',
                'table_name' => 'order_productions',
                'record_id' => $prod->id,
                'details' => "Dispatched {$qty} unit(s) for order {$order->order_number}, line output #{$prod->id}.",
            ]);

            $full = $this->isFullyDispatched($order->fresh());

            return [
                'ok' => true,
                'full' => $full,
                'message' => "Dispatched {$qty} unit(s). ".($full
                    ? "Order {$order->order_number} fully dispatched — ready for receipt."
                    : "Order {$order->order_number} dispatch in progress."),
            ];
        });
    }

    /**
     * MANUAL DISPATCH fallback — used only when scan-out cannot be completed
     * (camera/scanner failure, unreadable label). Marks every produced line as
     * fully dispatched in one privileged step, exactly once, WITHOUT scanning.
     *
     * This records DISPATCH (release from the Main Branch), never receipt: the
     * order stays 'delivering' and the destination must still receive it. A
     * required reason and a MANUAL flag are written to the audit trail. Applies
     * to commissary (produced) orders only; idempotent once fully dispatched.
     */
    public function manualDispatch(Order $order, int $userId, string $reason): array
    {
        return (new OrderLabelService)->manualRelease($order, User::findOrFail($userId), $reason);
    }

    /**
     * APPROVE + auto-produce. The manager's only production input is expiry per
     * perishable line; product/qty/unit/destination/timestamp/QR come from the
     * persisted order. For every line, produced quantity = ordered quantity.
     * Atomic: any invalid expiry leaves nothing written. Idempotent: an already
     * approved+produced order returns its existing records unchanged.
     * $expiryMap: [order_item_id => 'Y-m-d'|''].
     */
    public function approveAndProduce(Order $order, array $expiryMap, int $userId, bool $notifyBranch = true): array
    {
        return DB::transaction(function () use ($order, $expiryMap, $userId, $notifyBranch) {
            $locked = Order::where('id', $order->id)->lockForUpdate()->first();
            $cur = $locked->status ?? 'unknown';

            if ($cur === 'approved' && $this->hasProduction($order)) {
                return ['ok' => true, 'already' => true, 'message' => "Order {$order->order_number} is already approved; its production and labels are unchanged."];
            }
            if ($cur !== 'pending') {
                return ['ok' => false, 'error' => "Order {$order->order_number} is '{$cur}' and can no longer be approved."];
            }

            $items = OrderItem::select('order_items.*', 'items.item_name', 'items.shelf_life_days')
                ->join('items', 'items.id', '=', 'order_items.item_id')
                ->where('order_items.order_id', $order->id)->get();
            if ($items->isEmpty()) {
                return ['ok' => false, 'error' => 'Order has no lines to produce.'];
            }

            // Validate every expiry BEFORE any write.
            $parsed = [];
            foreach ($items as $oi) {
                $raw = trim((string) ($expiryMap[$oi->id] ?? ''));
                $perishable = (int) $oi->shelf_life_days > 0;
                if ($raw === '') {
                    if ($perishable) {
                        return ['ok' => false, 'error' => "Expiry date is required for {$oi->item_name}."];
                    }
                    $parsed[$oi->id] = null;

                    continue;
                }
                $d = \DateTime::createFromFormat('Y-m-d', $raw);
                if (! $d || $d->format('Y-m-d') !== $raw) {
                    return ['ok' => false, 'error' => "Invalid expiry date for {$oi->item_name}."];
                }
                if (StockService::isExpired($raw)) {
                    return ['ok' => false, 'error' => "Expiry for {$oi->item_name} is already past."];
                }
                $parsed[$oi->id] = $raw;
            }

            foreach ($items as $oi) {
                OrderProduction::updateOrCreate(
                    ['order_item_id' => $oi->id],
                    [
                        'order_id' => $order->id,
                        'item_id' => $oi->item_id,
                        'destination_branch_id' => $order->branch_id,
                        'produced_qty' => (int) $oi->quantity,
                        'production_at' => now(),
                        'expiry_date' => $parsed[$oi->id],
                        'condition' => 'good',
                        'qr_code' => OrderProduction::qrFor($order->id, $oi->id),
                        'prepared_by' => $userId,
                    ]
                );
            }

            Order::where('id', $order->id)->update([
                'status' => 'approved',
                'approved_by' => $userId,
                'approved_at' => now(),
            ]);

            AuditLog::create([
                'user_id' => $userId,
                'action' => 'order approved + produced',
                'table_name' => 'orders',
                'record_id' => $order->id,
                'details' => "Order {$order->order_number} approved; full ordered quantity produced, QR labels generated.",
            ]);

            if ($notifyBranch) {
                // Notify the requesting branch that the order is ready to confirm.
                Alert::create([
                    'alert_type' => 'operational',
                    'severity' => 'info',
                    'message' => "Order {$order->order_number} is approved and ready for delivery — please confirm the shipment.",
                    'branch_id' => $order->branch_id,
                ]);

            }

            return ['ok' => true, 'message' => "Order {$order->order_number} approved. Produced stock and QR labels are ready to print."];
        });
    }

    /** Deterministic order-shipment receiving QR (resolved against records). */
    public static function shipmentQr(Order $order): string
    {
        return 'HV-SHIP-'.str_pad((string) $order->id, 5, '0', STR_PAD_LEFT);
    }

    /** Parse a shipment QR to an order id, or null if it is not one. */
    public static function orderIdFromQr(string $qr): ?int
    {
        if (preg_match('/^HV-SHIP-(\d+)$/i', trim($qr), $m)) {
            return (int) $m[1];
        }

        return null;
    }
}
