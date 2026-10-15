<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderProduction;
use App\Models\StockBatch;
use App\Models\StockTransaction;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderLabelService
{
    private function fail(string $field, string $message): never
    {
        throw ValidationException::withMessages([$field => $message]);
    }

    public function review(Order $order, User $user): array
    {
        abort_unless($user->isOwner() || $user->isMainBranchManager(), 403);

        return DB::transaction(function () use ($order, $user) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->status === 'approved') {
                return ['ok' => true, 'already' => true, 'message' => 'Request is already reviewed.'];
            }
            if ($order->status !== 'pending') {
                $this->fail('order', 'Only pending requests can be reviewed.');
            }
            $order->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now()]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'commissary request reviewed',
                'table_name' => 'orders', 'record_id' => $order->id, 'details' => 'Reviewed; no stock produced or dispatched.']);

            return ['ok' => true, 'message' => 'Request reviewed. Prepare or pick the actual available quantities.'];
        });
    }

    public function prepare(Order $order, array $expiry, User $user, array $fulfillment = []): array
    {
        abort_unless($user->isOwner() || $user->isMainBranchManager(), 403);

        return DB::transaction(function () use ($order, $expiry, $user, $fulfillment) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! in_array($order->status, ['pending', 'approved', 'preparing'], true)) {
                $this->fail('order', 'This order can no longer be prepared.');
            }
            $lines = $order->orderItems()->with('item')->get();
            if ($lines->isEmpty()) {
                $this->fail('order', 'No requested products.');
            }
            foreach ([$expiry, $fulfillment] as $map) {
                if (array_diff(array_map('intval', array_keys($map)), $lines->pluck('id')->all())) {
                    $this->fail('lines', 'Preparation contains a line from another order.');
                }
            }
            $main = Branch::commissary();
            if (! $main) {
                $this->fail('order', 'No active Commissary location is configured.');
            }
            if ((int) $order->branch_id === (int) $main->id) {
                $this->fail('order', 'The Commissary cannot prepare a shipment to itself.');
            }
            if ($this->hasUnits($order->id)) {
                foreach ($fulfillment as $id => $entry) {
                    if (! is_array($entry) || ! isset($entry['quantity']) || (float) $entry['quantity'] !== (float) $lines->firstWhere('id', $id)->fulfilled_quantity) {
                        $this->fail('quantity', 'Saved labels are frozen. Use an authorized correction instead of changing their quantities.');
                    }
                }

                return $this->preparedResponse($order, true);
            }

            $plan = [];
            foreach ($lines as $line) {
                $entry = $fulfillment[$line->id] ?? [];
                if (! is_array($entry)) {
                    $this->fail('quantity', 'Invalid preparation entry.');
                }
                $quantity = $entry['quantity'] ?? $line->quantity;
                if (! is_numeric($quantity) || (int) $quantity != $quantity || $quantity < 0 || $quantity > $line->quantity) {
                    $this->fail('quantity', $line->item->item_name.': prepare a whole quantity between 0 and '.$line->quantity.'.');
                }
                $quantity = (int) $quantity;
                $reason = trim((string) ($entry['reason'] ?? ''));
                if (($quantity < $line->quantity && $reason === '') || strlen($reason) > 500) {
                    $this->fail('reason', 'Provide a shortage reason (maximum 500 characters) for '.$line->item->item_name.'.');
                }
                $source = $entry['source'] ?? 'produced';
                if (! in_array($source, ['produced', 'picked'], true)) {
                    $this->fail('source', 'Choose newly produced goods or pick existing stock.');
                }
                $date = $expiry[$line->id] ?? null;
                if ($date !== null && ! is_string($date)) {
                    $this->fail('expiry', 'Invalid expiry date.');
                }
                $date = trim($date ?? '') ?: null;
                if ($quantity > 0 && $source === 'produced') {
                    if (! $date && $line->item->shelf_life_days > 0) {
                        $this->fail('expiry', 'Expiry date is required for '.$line->item->item_name.'.');
                    }
                    if ($date) {
                        $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
                        if (! $parsed || $parsed->format('Y-m-d') !== $date || StockService::isExpired($date)) {
                            $this->fail('expiry', 'Invalid or expired date for '.$line->item->item_name.'.');
                        }
                    }
                }
                $plan[$line->id] = compact('quantity', 'reason', 'source', 'date');
            }
            if (array_sum(array_column($plan, 'quantity')) < 1) {
                $this->fail('quantity', 'Nothing is available to release. Keep the request pending or decline it with a reason.');
            }

            $value = 0;
            foreach ($lines as $line) {
                $entry = $plan[$line->id];
                $quantity = $entry['quantity'];
                $existing = OrderProduction::where('order_item_id', $line->id)->first();
                if ($existing && ($existing->condition === 'damaged' || StockService::isExpired($existing->expiry_date?->format('Y-m-d')))) {
                    $this->fail('expiry', 'Existing output is expired or damaged and cannot be relabelled.');
                }
                if ($existing && ((int) $existing->dispatched_qty > 0 || (int) $existing->produced_qty !== $quantity)) {
                    $this->fail('order', 'Existing produced goods cannot be silently re-counted. Use their recorded quantity.');
                }
                $production = OrderProduction::updateOrCreate(['order_item_id' => $line->id], [
                    'order_id' => $order->id, 'item_id' => $line->item_id, 'destination_branch_id' => $order->branch_id,
                    'produced_qty' => $quantity, 'production_at' => $existing?->production_at ?? now(),
                    'expiry_date' => $entry['source'] === 'produced' ? ($existing?->expiry_date ?? $entry['date']) : null,
                    'condition' => 'good', 'source_type' => $entry['source'], 'prepared_by' => $user->id,
                    'qr_code' => OrderProduction::qrFor($order->id, $line->id),
                ]);
                $lots = [];
                if ($quantity > 0 && $entry['source'] === 'produced') {
                    $batch = StockBatch::create([
                        'item_id' => $line->item_id, 'branch_id' => $main->id,
                        'quantity_in' => $quantity, 'quantity_left' => $quantity,
                        'expiry_date' => $production->expiry_date, 'condition' => 'good',
                        'received_at' => $production->production_at, 'created_by' => $user->id, 'qr_code' => $production->qr_code,
                    ]);
                    $production->update(['stock_batch_id' => $batch->id]);
                    StockTransaction::create(['item_id' => $line->item_id, 'branch_id' => $main->id, 'batch_id' => $batch->id,
                        'type' => 'in', 'quantity' => $quantity, 'reason' => 'Order '.$order->order_number.' produced for '.$order->branch->branch_name, 'performed_by' => $user->id]);
                    $lots[] = ['batch' => $batch, 'quantity' => $quantity];
                } elseif ($quantity > 0) {
                    $batches = StockBatch::where('item_id', $line->item_id)->where('branch_id', $main->id)->where('quantity_left', '>', 0)
                        ->where(fn ($q) => $q->whereNull('condition')->orWhere('condition', '<>', 'damaged'))
                        ->where(fn ($q) => $q->whereNull('expiry_date')->orWhere('expiry_date', '>=', now()->toDateString()))
                        ->orderByRaw("COALESCE(expiry_date, '9999-12-31') ASC")->orderBy('id')->lockForUpdate()->get();
                    $remaining = $quantity;
                    foreach ($batches as $batch) {
                        $take = min($remaining, (new StockService)->unreservedQuantity($batch));
                        if ($take > 0) {
                            $lots[] = ['batch' => $batch, 'quantity' => $take];
                            $remaining -= $take;
                        }
                        if ($remaining === 0) {
                            break;
                        }
                    }
                    if ($remaining > 0) {
                        $this->fail('quantity', $line->item->item_name.': only '.($quantity - $remaining).' eligible unreserved units can be picked. Reduce the quantity and give a shortage reason.');
                    }
                }
                $number = 0;
                foreach ($lots as $lot) {
                    for ($i = 0; $i < $lot['quantity']; $i++) {
                        $number++;
                        DB::table('order_production_units')->insert(['production_id' => $production->id, 'unit_number' => $number,
                            'stock_batch_id' => $lot['batch']->id, 'qr_code' => $production->qr_code.'-U'.str_pad((string) $number, 5, '0', STR_PAD_LEFT), 'created_at' => now()]);
                    }
                }
                $line->update(['fulfilled_quantity' => $quantity, 'fulfillment_reason' => $reason ?: null]);
                $value += $quantity * (float) $line->unit_price;
            }
            $order->update(['status' => 'preparing', 'approved_by' => $order->approved_by ?? $user->id,
                'approved_at' => $order->approved_at ?? now(), 'prepared_by' => $user->id,
                'prepared_at' => $order->prepared_at ?? now(), 'fulfilled_amount' => $value]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'commissary fulfillment prepared', 'table_name' => 'orders', 'record_id' => $order->id,
                'details' => 'Original request preserved. Prepared quantities, sources and shortage reasons recorded. No physical dispatch yet.']);

            return $this->preparedResponse($order);
        });
    }

    private function preparedResponse(Order $order, bool $already = false): array
    {
        return ['ok' => true, 'already' => $already, 'message' => 'Prepared for release. Print labels as needed, then scan the shared product QR to release the selected quantity for delivery.',
            'labels_url' => route('orders.labels', $order), 'label_count' => DB::table('order_production_units as u')
                ->join('order_productions as p', 'p.id', '=', 'u.production_id')->where('p.order_id', $order->id)->count()];
    }

    public function startPreparing(Order $order, User $user): array
    {
        abort_unless($user->isOwner() || $user->isMainBranchManager(), 403);

        return DB::transaction(function () use ($order, $user) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            if (! $this->hasUnits($order->id) || in_array($order->status, ['pending', 'cancelled', 'declined'], true)) {
                $this->fail('order', 'Prepare valid output before printing labels.');
            }
            if (! $order->labels_print_requested_at) {
                $order->update(['labels_print_requested_at' => now()]);
                AuditLog::create(['user_id' => $user->id, 'action' => 'production labels print requested', 'table_name' => 'orders', 'record_id' => $order->id,
                    'details' => 'Print requested. No status or stock movement changed.']);
            }

            return ['ok' => true, 'message' => 'Print requested. Printing does not release goods or change order status.'];
        });
    }

    public function hasUnits(int $orderId): bool
    {
        return DB::table('order_production_units as u')->join('order_productions as p', 'p.id', '=', 'u.production_id')->where('p.order_id', $orderId)->exists();
    }

    private function blockedReason(Order $order, OrderProduction $production, object $unit, User $user, ?StockBatch $batch): ?string
    {
        if (! $user->isOwner() && ! $user->isMainBranchRole()) {
            return 'Only Owner, Main Branch Manager or Main Branch Staff can release commissary goods.';
        }
        if (! in_array($order->status, ['approved', 'preparing', 'delivering'], true)) {
            return 'Order is '.$order->status.' and cannot be released.';
        }
        if (! $batch) {
            return 'This label has no source stock. Prepare/pick the order first.';
        }
        if ($production->condition === 'damaged' || $batch->condition === 'damaged') {
            return 'Damaged goods cannot be released for delivery.';
        }
        if (StockService::isExpired($batch->expiry_date?->format('Y-m-d')) || ($production->source_type === 'produced' && StockService::isExpired($production->expiry_date?->format('Y-m-d')))) {
            return 'This source lot has expired and cannot be released for delivery.';
        }
        if ($batch->quantity_left < 1 || $production->undispatched() < 1) {
            return 'No stock remains for this prepared label.';
        }

        return null;
    }

    public function lookup(string $qr, User $user): array
    {
        $unit = DB::table('order_production_units')->where('qr_code', $qr)->first();
        if (! $unit) {
            $this->fail('qr', 'This individual production label was not found.');
        }
        $production = OrderProduction::with('order.branch', 'item')->findOrFail($unit->production_id);
        $order = $production->order;
        abort_unless($user->isOwner() || $user->isMainBranchRole() || (int) $user->branch_id === (int) $order->branch_id, 403);
        $batch = StockBatch::find($unit->stock_batch_id ?? $production->stock_batch_id);
        $reason = $this->blockedReason($order, $production, $unit, $user, $batch);

        return ['ok' => true, 'matched_by' => 'production_unit', 'production_unit' => [
            'qr_code' => $unit->qr_code, 'item_name' => $production->item->item_name, 'unit' => $production->item->unit, 'quantity' => 1,
            'unit_number' => $unit->unit_number, 'total_units' => $production->produced_qty,
            'order_number' => $order->order_number, 'destination' => $order->branch->branch_name,
            'expiry_date' => $batch?->expiry_date?->format('Y-m-d'), 'dispatched' => $unit->dispatched_at !== null,
            'can_dispatch' => ! $unit->dispatched_at && $reason === null, 'blocked_reason' => $reason,
        ]];
    }

    public function dispatch(string $qr, User $user, mixed $quantity = 1): array
    {
        abort_unless($user->isOwner() || $user->isMainBranchRole(), 403);
        if (! is_numeric($quantity) || (float) $quantity !== 1.0) {
            $this->fail('quantity', 'Each individual label releases exactly one unit.');
        }
        $unit = DB::table('order_production_units')->where('qr_code', $qr)->first();
        if (! $unit) {
            $this->fail('qr', 'Individual label not found.');
        }
        $production = OrderProduction::findOrFail($unit->production_id);

        return DB::transaction(function () use ($unit, $production, $user) {
            $order = Order::whereKey($production->order_id)->lockForUpdate()->firstOrFail();
            $production = OrderProduction::whereKey($production->id)->lockForUpdate()->firstOrFail();
            $unit = DB::table('order_production_units')->where('id', $unit->id)->lockForUpdate()->first();
            if ($unit->dispatched_at) {
                return ['ok' => true, 'already' => true, 'message' => 'This unit has already been released.'];
            }
            $batch = StockBatch::whereKey($unit->stock_batch_id ?? $production->stock_batch_id)->lockForUpdate()->first();
            if ($reason = $this->blockedReason($order, $production, $unit, $user, $batch)) {
                $this->fail('dispatch', $reason);
            }
            DB::table('order_production_units')->where('id', $unit->id)->update(['dispatched_at' => now(), 'dispatched_by' => $user->id]);
            $production->increment('dispatched_qty');
            $batch->decrement('quantity_left');
            StockTransaction::create(['item_id' => $production->item_id, 'branch_id' => $batch->branch_id, 'batch_id' => $batch->id,
                'type' => 'out', 'quantity' => -1, 'reason' => 'For Delivery: '.$order->order_number.' to '.$order->branch->branch_name,
                'remarks' => $unit->qr_code, 'performed_by' => $user->id]);
            AuditLog::create(['user_id' => $user->id, 'action' => 'individual piece scanned out', 'table_name' => 'order_production_units', 'record_id' => $unit->id,
                'details' => $unit->qr_code.' released for '.$order->order_number.' to '.$order->branch->branch_name]);
            $order->update(['status' => 'delivering']);
            $full = (new OrderWorkflowService)->isFullyDispatched($order);

            return ['ok' => true, 'full' => $full, 'message' => '1 unit released to '.$order->branch->branch_name.($full ? '. Shipment ready for destination receipt.' : '. Continue scanning remaining prepared units.')];
        });
    }

    public function manualRelease(Order $order, User $user, string $reason): array
    {
        abort_unless($user->isOwner() || $user->isMainBranchManager(), 403);
        if (trim($reason) === '' || strlen($reason) > 500) {
            $this->fail('reason', 'Provide a manual-release reason of 1 to 500 characters.');
        }

        return DB::transaction(function () use ($order, $user, $reason) {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $units = DB::table('order_production_units as u')->join('order_productions as p', 'p.id', '=', 'u.production_id')
                ->where('p.order_id', $order->id)->whereNull('u.dispatched_at')->orderBy('u.id')->pluck('u.qr_code');
            if ($units->isEmpty()) {
                if ((new OrderWorkflowService)->isFullyDispatched($order)) {
                    return ['ok' => true, 'already' => true, 'message' => 'This shipment is already fully released.'];
                }
                $this->fail('order', 'Prepare/pick valid stock before manual release.');
            }
            foreach ($units as $code) {
                $this->dispatch($code, $user);
            }
            AuditLog::create(['user_id' => $user->id, 'action' => 'order manually dispatched', 'table_name' => 'orders', 'record_id' => $order->id,
                'details' => 'Privileged manual release. Reason: '.$reason]);

            return ['ok' => true, 'message' => 'Shipment manually released. Destination receipt is still required.'];
        });
    }

    public static function stageLabel(Order $order): string
    {
        if (in_array($order->status, ['cancelled', 'declined'], true)) {
            return ucfirst($order->status);
        }
        if ($order->status === 'settled') {
            return 'Received / Closed';
        }
        if ($order->status === 'delivered') {
            return 'Received — Exceptions';
        }
        $productions = OrderProduction::where('order_id', $order->id)->get();
        if ($productions->isNotEmpty()) {
            if ($productions->sum('dispatched_qty') > 0) {
                return (new OrderWorkflowService)->isFullyDispatched($order) ? 'Released / In Transit' : 'Partially Released';
            }

            return 'Prepared / Ready for Release';
        }

        return match ($order->status) {
            'pending' => 'Awaiting Review', 'approved' => 'Reviewed / Awaiting Preparation',
            'preparing' => 'Preparing', 'delivering' => 'Released / In Transit', default => ucfirst($order->status),
        };
    }
}
