<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\DeliveryDiscrepancy;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\OrderProduction;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder as EloquentBuilder;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderGroupLabelService
{
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    private function production(string $qr): OrderProduction
    {
        return OrderProduction::with('order.branch', 'item')->where('qr_code', $qr)->firstOrFail();
    }

    private function canReceive(Order $order, User $user): bool
    {
        return $user->isOwner()
            || (($user->isBranchManager() || $user->isBranchStaff()) && (int) $user->branch_id === (int) $order->branch_id);
    }

    private function pendingReceipt(OrderProduction $production): Builder
    {
        return DB::table('order_production_units')->where('production_id', $production->id)
            ->whereNotNull('dispatched_at')->whereNull('received_at')->orderBy('unit_number');
    }

    private function claim(?string $key, string $scope, int $productionId, int $userId): bool
    {
        if ($key === null || $key === '') {
            return true;
        }
        if (strlen($key) > 128) {
            $this->fail('idempotency_key', 'Invalid request key.');
        }

        return DB::table('stock_idempotency_keys')->insertOrIgnore([
            'idempotency_key' => hash('sha256', $scope.':'.$productionId.':'.$userId.':'.$key),
            'scope' => $scope, 'performed_by' => $userId, 'created_at' => now(),
        ]) === 1;
    }

    private function quantity(mixed $quantity, int $maximum, bool $allowZero = false): int
    {
        $quantity ??= $maximum;
        if (! is_numeric($quantity) || (int) $quantity != $quantity || $quantity < ($allowZero ? 0 : 1) || $quantity > $maximum) {
            $this->fail('quantity', 'Enter a whole quantity between '.($allowZero ? 0 : 1).' and '.$maximum.'.');
        }

        return (int) $quantity;
    }

    private function canUse(OrderProduction $production, User $user, int $branchId): bool
    {
        return (int) $production->order->branch_id === $branchId
            && $production->order->branch->is_active
            && $production->item->is_active
            && ($user->isOwner() || (($user->isBranchManager() || $user->isBranchStaff()) && (int) $user->branch_id === $branchId));
    }

    /** Match only destination lots created from this order line, including partial receipts. */
    private function receivedBatches(OrderProduction $production): EloquentBuilder
    {
        return StockBatch::where('item_id', $production->item_id)
            ->where('branch_id', $production->order->branch_id)
            ->where('qr_code', 'like', 'HV-RECEIVED-'.$production->order_id.'-'.$production->id.'-B%')
            ->where('quantity_left', '>', 0)
            ->where(fn ($query) => $query->whereNull('condition')->orWhere('condition', '<>', StockService::CONDITION_DAMAGED))
            ->where(fn ($query) => $query->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
            ->orderByRaw('expiry_date IS NULL')->orderBy('expiry_date')->orderBy('id');
    }

    /** Consume accepted branch inventory without changing release or receipt totals. */
    public function useStock(string $qr, User $user, int $branchId, mixed $quantity, string $reason, ?string $remarks = null, ?string $key = null): array
    {
        $production = $this->production($qr);
        abort_unless($this->canUse($production, $user, $branchId), 403);
        $reason = trim($reason);
        if (! in_array($reason, ['Consumed', 'Dispose'], true)) {
            $this->fail('reason', 'Choose a usage reason for this branch stock out.');
        }

        return DB::transaction(function () use ($production, $user, $branchId, $quantity, $reason, $remarks, $key) {
            Order::whereKey($production->order_id)->lockForUpdate()->firstOrFail();
            $production = OrderProduction::whereKey($production->id)->lockForUpdate()->firstOrFail();
            if (! $this->claim($key, 'group_usage', $production->id, $user->id)) {
                return ['ok' => true, 'already' => true, 'message' => 'This branch usage was already recorded.'];
            }
            $batches = $this->receivedBatches($production)->lockForUpdate()->get();
            $stock = new StockService;
            $available = (int) $batches->sum(fn ($batch) => $stock->unreservedQuantity($batch));
            if ($available === 0) {
                $this->fail('quantity', 'No usable stock remains from this delivery at this branch.');
            }
            $quantity = $this->quantity($quantity, $available);
            $remaining = $quantity;
            foreach ($batches as $batch) {
                $deduct = min($remaining, $stock->unreservedQuantity($batch));
                if ($deduct === 0) {
                    continue;
                }
                $result = $stock->stockOutBatch($batch->id, $branchId, $deduct, $user->id, $reason, $production->qr_code.($remarks ? '; '.$remarks : ''));
                if (! $result['ok']) {
                    $this->fail('quantity', $result['error']);
                }
                $remaining -= $deduct;
                if ($remaining === 0) {
                    break;
                }
            }

            return ['ok' => true, 'used_quantity' => $quantity, 'remaining_usable_quantity' => $available - $quantity,
                'message' => $quantity.' '.$production->item->unit.' used at '.$production->order->branch->branch_name.'. '.($available - $quantity).' remain from this delivery.'];
        });
    }

    public function lookup(string $qr, User $user, ?int $branchId = null): array
    {
        $production = $this->production($qr);
        $order = $production->order;
        abort_unless($user->isOwner() || $user->isMainBranchRole() || (int) $user->branch_id === (int) $order->branch_id, 403);
        $usageBranchId = $user->isOwner() ? ($branchId ?? (int) $order->branch_id) : (int) $user->branch_id;
        $canUse = $this->canUse($production, $user, $usageBranchId);
        $usageQuantity = $canUse ? (int) $this->receivedBatches($production)->get()->sum(fn ($batch) => (new StockService)->unreservedQuantity($batch)) : 0;
        $units = DB::table('order_production_units')->where('production_id', $production->id)->get();
        $remaining = $units->whereNull('dispatched_at')->count();
        $receivable = $units->whereNotNull('dispatched_at')->whereNull('received_at')->count();
        $blocked = null;
        if (! $user->isOwner() && ! $user->isMainBranchRole()) {
            $blocked = 'Only the Main Branch or Owner can release this order.';
        } elseif (! in_array($order->status, ['approved', 'preparing', 'delivering'], true)) {
            $blocked = 'This order is '.$order->status.'.';
        } elseif (! $remaining) {
            $blocked = 'All prepared quantities have already been released.';
        } else {
            foreach ($units->whereNull('dispatched_at') as $unit) {
                $result = (new OrderLabelService)->lookup($unit->qr_code, $user);
                if (! $result['production_unit']['can_dispatch']) {
                    $blocked = $result['production_unit']['blocked_reason'];
                    break;
                }
            }
        }
        $expiries = StockBatch::whereIn('id', $units->map(fn ($u) => $u->stock_batch_id ?? $production->stock_batch_id)->filter()->unique())
            ->pluck('expiry_date')->filter()->map(fn ($date) => substr((string) $date, 0, 10))->unique()->values()->all();

        return ['ok' => true, 'matched_by' => 'production_group', 'production_group' => [
            'qr_code' => $production->qr_code, 'item_name' => $production->item->item_name, 'unit' => $production->item->unit,
            'quantity' => (int) $production->produced_qty, 'remaining_quantity' => $remaining, 'released_quantity' => (int) $production->dispatched_qty,
            'receivable_quantity' => $receivable, 'received_quantity' => (int) $production->order->orderItems->firstWhere('id', $production->order_item_id)?->received_quantity,
            'order_number' => $order->order_number, 'destination' => $order->branch->branch_name, 'expiry_dates' => $expiries,
            'can_dispatch' => $remaining > 0 && $blocked === null && ($user->isOwner() || $user->isMainBranchRole()),
            'can_receive' => $receivable > 0 && $order->status === 'delivering' && $this->canReceive($order, $user),
            'can_use' => $canUse && $usageQuantity > 0,
            'usage_quantity' => $usageQuantity, 'usage_branch_id' => (int) $order->branch_id,
            'usage_message' => $canUse ? 'No usable stock remains from this delivery at this branch. Stock may be used up, expired, or damaged.' : 'Usage stock out is available only at the destination branch.',
            'blocked_reason' => $blocked,
            'receive_message' => $receivable
                ? 'In transit: '.$receivable.' '.$production->item->unit.' awaiting stock-in at '.$order->branch->branch_name.'.'
                : ($remaining > 0 ? 'Awaiting Commissary release.' : 'Released goods were already received at the destination branch.'),
        ]];
    }

    public function dispatch(string $qr, User $user, mixed $quantity = null, ?string $key = null): array
    {
        abort_unless($user->isOwner() || $user->isMainBranchRole(), 403);
        $production = $this->production($qr);

        return DB::transaction(function () use ($production, $user, $quantity, $key) {
            $order = Order::whereKey($production->order_id)->lockForUpdate()->firstOrFail();
            $production = OrderProduction::whereKey($production->id)->lockForUpdate()->firstOrFail();
            if (! $this->claim($key, 'group_dispatch', $production->id, $user->id)) {
                return ['ok' => true, 'already' => true, 'message' => 'This release was already recorded.'];
            }
            if (! in_array($order->status, ['approved', 'preparing', 'delivering'], true)) {
                $this->fail('order', 'This order cannot be released.');
            }
            $units = DB::table('order_production_units')->where('production_id', $production->id)->whereNull('dispatched_at')->orderBy('unit_number')->lockForUpdate()->get();
            if ($units->isEmpty()) {
                if ($production->produced_qty > 0 && $production->dispatched_qty >= $production->produced_qty) {
                    return ['ok' => true, 'already' => true, 'message' => 'All quantities covered by this QR are already released.'];
                }
                $this->fail('order', 'Prepare this order line before releasing it.');
            }
            $quantity = $this->quantity($quantity, $units->count());
            foreach ($units->take($quantity) as $unit) {
                (new OrderLabelService)->dispatch($unit->qr_code, $user);
            }
            $remaining = $units->count() - $quantity;
            AuditLog::create(['user_id' => $user->id, 'action' => 'order group released', 'table_name' => 'order_productions', 'record_id' => $production->id,
                'details' => $production->qr_code.': '.$quantity.' released, '.$remaining.' remaining.']);

            return ['ok' => true, 'dispatched_quantity' => $quantity, 'remaining_quantity' => $remaining, 'full' => (new OrderWorkflowService)->isFullyDispatched($order),
                'message' => $quantity.' units released for delivery. '.$remaining.' units remain to release for this product.'];
        });
    }

    public function receive(string $qr, User $user, mixed $quantity = null, ?string $reason = null, ?string $key = null, bool $finalize = false, ?string $receiptStatus = null): array
    {
        $production = $this->production($qr);
        abort_unless($this->canReceive($production->order, $user), 403);

        return DB::transaction(function () use ($production, $user, $quantity, $reason, $key, $finalize, $receiptStatus) {
            $order = Order::whereKey($production->order_id)->lockForUpdate()->firstOrFail();
            $production = OrderProduction::whereKey($production->id)->lockForUpdate()->firstOrFail();
            if (in_array($order->status, ['delivered', 'settled'], true)) {
                return ['ok' => true, 'already' => true, 'message' => 'This order was already received.'];
            }
            if ($order->status !== 'delivering') {
                $this->fail('order', 'The Main Branch must release these goods before receiving.');
            }
            if (! $this->claim($key, 'group_receive', $production->id, $user->id)) {
                return ['ok' => true, 'already' => true, 'message' => 'This receipt was already recorded.'];
            }
            $units = $this->pendingReceipt($production)->lockForUpdate()->get();
            if ($units->isEmpty()) {
                return ['ok' => true, 'already' => true, 'message' => 'No released quantities remain to receive for this product.'];
            }
            $quantity = $this->quantity($quantity, $units->count(), true);
            if ($reason && ! in_array($reason, ['Missing Quantity', 'Damaged in Transit', 'Incorrect Quantity', 'Incorrect Item', 'Other'], true)) {
                $this->fail('reason', 'Invalid receipt discrepancy reason.');
            }
            if ($receiptStatus !== null && ! in_array($receiptStatus, ['good', 'issue'], true)) {
                $this->fail('receipt_status', 'Choose No issue or Report a delivery issue.');
            }
            if ($receiptStatus === 'good' && ($reason || ($finalize && $quantity < $units->count()))) {
                $this->fail('receipt_status', 'A no-issue partial receipt must leave the remaining quantity pending.');
            }
            if ($receiptStatus === 'issue' && (! $reason || ! $finalize || $quantity >= $units->count())) {
                $this->fail('reason', 'Select an issue reason, accept only the good quantity, and confirm closing this product receipt.');
            }
            $lots = [];
            $acceptedIds = [];
            $invalidIds = [];
            $invalid = 0;
            foreach ($units as $unit) {
                $source = StockBatch::find($unit->stock_batch_id ?? $production->stock_batch_id);
                $expiry = $production->source_type === 'picked' ? $source?->expiry_date?->format('Y-m-d') : $production->expiry_date?->format('Y-m-d');
                if (! $source || $source->condition === 'damaged' || $production->condition === 'damaged' || StockService::isExpired($expiry) || StockService::isExpired($source->expiry_date?->format('Y-m-d'))) {
                    $invalid++;
                    $invalidIds[] = $unit->id;

                    continue;
                }
                if (count($acceptedIds) >= $quantity) {
                    continue;
                }
                $acceptedIds[] = $unit->id;
                $lotKey = $source->id;
                $lots[$lotKey] ??= ['quantity' => 0, 'expiry' => $expiry];
                $lots[$lotKey]['quantity']++;
            }
            $accountedIds = $finalize ? $units->pluck('id')->all() : array_merge($acceptedIds, $invalidIds);
            if (! $accountedIds) {
                $this->fail('quantity', 'Select at least one received unit, or complete the receipt to record a missing delivery.');
            }
            $timestamp = now();
            foreach ($lots as $sourceId => $lot) {
                $batch = StockBatch::create(['item_id' => $production->item_id, 'branch_id' => $order->branch_id, 'quantity_in' => $lot['quantity'], 'quantity_left' => $lot['quantity'],
                    'expiry_date' => $lot['expiry'], 'condition' => 'good', 'received_at' => $timestamp, 'created_by' => $user->id]);
                $batch->update(['qr_code' => 'HV-RECEIVED-'.$order->id.'-'.$production->id.'-B'.$batch->id]);
                StockTransaction::create(['item_id' => $production->item_id, 'branch_id' => $order->branch_id, 'batch_id' => $batch->id, 'type' => 'in', 'quantity' => $lot['quantity'],
                    'reason' => 'Order '.$order->order_number.' delivered', 'remarks' => $production->qr_code.'; original source lot #'.$sourceId, 'performed_by' => $user->id]);
            }
            DB::table('order_production_units')->whereIn('id', $accountedIds)->update(['received_at' => $timestamp, 'received_by' => $user->id, 'receipt_accepted' => false]);
            DB::table('order_production_units')->whereIn('id', $acceptedIds)->update(['receipt_accepted' => true]);
            $accepted = count($acceptedIds);
            $line = OrderItem::findOrFail($production->order_item_id);
            $line->update(['received_quantity' => (int) $line->received_quantity + $accepted]);
            if ($accepted < count($accountedIds)) {
                DeliveryDiscrepancy::create(['order_id' => $order->id, 'order_item_id' => $line->id, 'expected_quantity' => count($accountedIds), 'received_quantity' => $accepted,
                    'reason' => $invalid ? 'Other' : ($reason ?: 'Missing Quantity'), 'remarks' => $invalid ? $invalid.' units expired, damaged or missing source records; excluded from usable stock.' : null, 'reported_by' => $user->id]);
            }
            AuditLog::create(['user_id' => $user->id, 'action' => 'order group received', 'table_name' => 'order_productions', 'record_id' => $production->id,
                'details' => $production->qr_code.': '.$accepted.' accepted, '.($units->count() - count($accountedIds)).' awaiting receipt.']);
            $this->closeIfComplete($order, $user);
            $status = $order->fresh()->status;

            return ['ok' => true, 'received_quantity' => $accepted, 'remaining_to_receive' => $units->count() - count($accountedIds), 'settled' => $status === 'settled', 'status' => $status,
                'message' => $accepted.' units added to '.$order->branch->branch_name.' inventory.'.($status === 'settled' ? ' Order received and closed.' : ($status === 'delivered' ? ' Receipt exceptions recorded for review.' : ' Remaining order goods are still awaiting release or receipt.'))];
        });
    }

    private function closeIfComplete(Order $order, User $user): void
    {
        if (! (new OrderWorkflowService)->isFullyDispatched($order)) {
            return;
        }
        $unreceived = DB::table('order_production_units as u')->join('order_productions as p', 'p.id', '=', 'u.production_id')
            ->where('p.order_id', $order->id)->whereNull('u.received_at')->exists();
        if ($unreceived) {
            return;
        }
        $exceptions = DeliveryDiscrepancy::where('order_id', $order->id)->exists();
        $order->update(['status' => $exceptions ? 'delivered' : 'settled', 'delivered_at' => now(), 'confirmed_at' => now(), 'confirmed_by' => $user->id, 'settled_at' => $exceptions ? null : now()]);
        AuditLog::create(['user_id' => $user->id, 'action' => 'order delivered + confirmed', 'table_name' => 'orders', 'record_id' => $order->id, 'details' => 'All released order groups accounted for at destination.']);
        if (! $exceptions) {
            AuditLog::create(['user_id' => $user->id, 'action' => 'order settled', 'table_name' => 'orders', 'record_id' => $order->id, 'details' => 'Fully accepted. Operational closure only.']);
        }
    }

    public function receiveOrder(Order $order, User $user, array $quantities, array $reasons): array
    {
        abort_unless($this->canReceive($order, $user), 403);

        return DB::transaction(function () use ($order, $user, $quantities, $reasons) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! (new OrderWorkflowService)->isFullyDispatched($order)) {
                return ['ok' => false, 'error' => 'Release all prepared goods before confirming the whole order.'];
            }
            $lines = $order->orderItems;
            $productions = OrderProduction::where('order_id', $order->id)->get()->keyBy('order_item_id');
            if (array_diff(array_map('intval', array_keys($quantities)), $lines->pluck('id')->all())) {
                return ['ok' => false, 'error' => 'Received quantities contain a line from another order.'];
            }
            foreach ($lines as $line) {
                $production = $productions->get($line->id);
                $maximum = $production ? $this->pendingReceipt($production)->count() : 0;
                $this->quantity($quantities[$line->id] ?? $maximum, $maximum, true);
                if (! empty($reasons[$line->id]) && ! in_array($reasons[$line->id], ['Missing Quantity', 'Damaged in Transit', 'Incorrect Quantity', 'Incorrect Item', 'Other'], true)) {
                    $this->fail('reason', 'Invalid receipt discrepancy reason.');
                }
            }
            foreach ($lines as $line) {
                $production = $productions->get($line->id);
                if ($production && $production->produced_qty > 0) {
                    $this->receive($production->qr_code, $user, $quantities[$line->id] ?? null, $reasons[$line->id] ?? null, null, true);
                } elseif ($line->received_quantity === null) {
                    $line->update(['received_quantity' => 0]);
                }
            }
            $status = $order->fresh()->status;

            return ['ok' => true, 'settled' => $status === 'settled', 'message' => $status === 'settled' ? 'Order received in full and closed.' : 'Receipt recorded with exceptions for review.'];
        });
    }
}
