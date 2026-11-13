<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Branch;
use App\Models\Order;
use App\Models\OrderProduction;
use App\Models\ReceivingIssue;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ReceivingIssueService
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['receiving_issue' => $message]);
    }

    /** @return array<int, array{maximum: int, received: ?int, dispatched: int}> */
    public function snapshot(Order $order): array
    {
        $workflow = new OrderWorkflowService;
        $tracked = (new OrderLabelService)->hasUnits($order->id);
        $productions = OrderProduction::where('order_id', $order->id)->get()->keyBy('order_item_id');
        if (! $tracked && DB::table('stock_transactions')->where('branch_id', $order->branch_id)
            ->where('type', 'in')->where('reason', 'Order '.$order->order_number.' delivered')->exists()) {
            $this->fail('This legacy receipt already has stock ledger entries and requires ledger reconciliation.');
        }
        if ($order->status !== 'delivering' || $order->archived_at !== null
            || ! $order->branch?->is_active
            || ($productions->isNotEmpty() ? ! $workflow->isFullyDispatched($order) : ! $workflow->isAllocated($order))) {
            $this->fail('Only active, fully released shipments can be submitted for receiving assistance.');
        }
        $snapshot = [];
        foreach ($order->orderItems()->orderBy('id')->get() as $line) {
            $production = $productions->get($line->id);
            if (! $tracked && $line->received_quantity !== null) {
                $this->fail('This legacy receipt already has recorded quantities and requires ledger reconciliation.');
            }
            $maximum = $tracked
                ? ($production ? DB::table('order_production_units')->where('production_id', $production->id)->whereNotNull('dispatched_at')->whereNull('received_at')->count() : 0)
                : (int) ($production?->dispatched_qty ?? $line->fulfilled_quantity ?? $line->quantity);
            $snapshot[$line->id] = [
                'maximum' => $maximum,
                'received' => $line->received_quantity === null ? null : (int) $line->received_quantity,
                'dispatched' => (int) ($production?->dispatched_qty ?? $maximum),
            ];
        }
        if (array_sum(array_column($snapshot, 'maximum')) === 0) {
            $this->fail('No released quantities remain to receive. Review the existing ledger instead of adding stock.');
        }

        return $snapshot;
    }

    /** @param array<int, array{order_item_id: int, received_quantity: int, reason?: ?string}> $receipt */
    public function report(Order $order, User $reporter, string $reason, array $receipt): ReceivingIssue
    {
        abort_unless($reporter->status === 'active' && $reporter->isBranchManager() && (int) $reporter->branch_id === (int) $order->branch_id, 403);

        return DB::transaction(function () use ($order, $reporter, $reason, $receipt): ReceivingIssue {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            $snapshot = $this->snapshot($order);
            $lines = [];
            foreach ($receipt as $line) {
                $id = (int) $line['order_item_id'];
                if (! isset($snapshot[$id]) || isset($lines[$id]) || $line['received_quantity'] > $snapshot[$id]['maximum']) {
                    $this->fail('Provide each order line once, with a quantity no greater than its remaining released quantity.');
                }
                if ($line['received_quantity'] < $snapshot[$id]['maximum'] && empty($line['reason'])) {
                    $this->fail('A discrepancy reason is required for every short or rejected quantity.');
                }
                $lines[$id] = $line;
            }
            if (count($lines) !== count($snapshot)) {
                $this->fail('Confirm the actual accepted quantity for every order line.');
            }
            ReceivingIssue::where('order_id', $order->id)->where('status', 'pending')->update(['status' => 'superseded']);
            $issue = ReceivingIssue::create([
                'order_id' => $order->id, 'reported_by' => $reporter->id,
                'reason' => $reason, 'receipt' => array_values($lines), 'snapshot' => $snapshot,
            ]);
            $this->audit($reporter, $issue, 'receiving_issue_reported', 'Branch manager confirmed actual accepted quantities; no stock changes.');

            return $issue;
        });
    }

    /**
     * Branch-manager refund request: a sentence describing the problem plus a
     * photo. No per-line quantities and no stock reprocessing — it is a claim
     * for the admin to review.
     */
    /**
     * @param  array<int, array{order_item_id: int, quantity: int}>  $damaged  the damaged lines to refund
     */
    public function reportRefund(Order $order, User $reporter, string $reason, ?string $photoPath, array $damaged): ReceivingIssue
    {
        abort_unless($reporter->status === 'active' && $reporter->isBranchManager() && (int) $reporter->branch_id === (int) $order->branch_id, 403);

        return DB::transaction(function () use ($order, $reporter, $reason, $photoPath, $damaged): ReceivingIssue {
            $order = Order::whereKey($order->id)->lockForUpdate()->firstOrFail();
            // The goods are already stocked in (received) when damage is found, so a
            // refund is filed against a received order.
            if (! in_array($order->status, ['delivered', 'settled'], true) || $order->archived_at !== null) {
                $this->fail('A refund can only be requested for a received order.');
            }
            // Resolve each damaged line against the order, capturing the item id so
            // the confirmation can deduct exactly those goods from branch stock.
            $orderItems = $order->orderItems()->get()->keyBy('id');
            $lines = [];
            foreach ($damaged as $line) {
                $oid = (int) ($line['order_item_id'] ?? 0);
                $qty = (int) ($line['quantity'] ?? 0);
                $orderItem = $orderItems->get($oid);
                if (! $orderItem || $qty < 1 || isset($lines[$oid])) {
                    $this->fail('Select a valid damaged item and a quantity of at least 1.');
                }
                $max = (int) ($orderItem->fulfilled_quantity ?? $orderItem->quantity);
                if ($qty > $max) {
                    $this->fail('Damaged quantity cannot exceed the delivered quantity for an item.');
                }
                $lines[$oid] = ['order_item_id' => $oid, 'item_id' => (int) $orderItem->item_id, 'quantity' => $qty];
            }
            if (empty($lines)) {
                $this->fail('Enter the damaged quantity for at least one item.');
            }
            ReceivingIssue::where('order_id', $order->id)->where('status', 'pending')->update(['status' => 'superseded']);
            $issue = ReceivingIssue::create([
                'order_id' => $order->id, 'type' => 'refund', 'reported_by' => $reporter->id,
                'reason' => $reason, 'receipt' => array_values($lines), 'photo_path' => $photoPath, 'snapshot' => [],
            ]);
            $this->audit($reporter, $issue, 'refund_requested', 'Branch manager requested a refund with photo evidence for '.count($lines).' damaged line(s); no stock changes yet.');

            return $issue;
        });
    }

    /** @return array<string, mixed> */
    public function resolve(ReceivingIssue $issue, User $admin, string $reason): array
    {
        // Admins resolve any issue; a Commissary Manager may confirm refunds.
        abort_unless($admin->status === 'active' && ($admin->isAdmin() || $admin->isCommissaryManager()), 403);

        return DB::transaction(function () use ($issue, $admin, $reason): array {
            $order = Order::whereKey($issue->order_id)->lockForUpdate()->firstOrFail();
            $issue = ReceivingIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();

            // Refund requests: confirming removes the damaged quantities from the
            // branch's inventory (the goods were stocked in as good before the
            // damage was found), then marks the request resolved.
            if ($issue->type === 'refund') {
                if ($issue->status === 'resolved') {
                    return ['ok' => true, 'already' => true, 'message' => 'This refund request was already resolved. No stock was removed again.'];
                }
                if ($issue->status !== 'pending') {
                    $this->fail('This request has been superseded by a newer one.');
                }
                $removed = [];
                $stock = new StockService;
                foreach ($issue->receipt as $line) {
                    $out = $stock->stockOut(
                        (int) $line['item_id'], (int) $order->branch_id, (int) $line['quantity'], $admin->id,
                        'Refund approved — damaged goods, Order '.$order->order_number,
                        'Receiving issue #'.$issue->id, 'refund-issue-'.$issue->id.'-line-'.$line['order_item_id'],
                    );
                    if (! ($out['ok'] ?? false)) {
                        $this->fail($out['error'] ?? 'Could not remove the damaged stock. No changes were saved.');
                    }
                    $removed[] = $line['quantity'];
                }
                $issue->update(['status' => 'resolved', 'resolved_by' => $admin->id, 'resolution_reason' => $reason, 'resolved_at' => now()]);
                $this->audit($admin, $issue, 'refund_resolved', 'Refund confirmed; removed '.array_sum($removed).' damaged unit(s) from branch stock. Reason: '.$reason);

                return ['ok' => true, 'message' => 'Refund confirmed. '.array_sum($removed).' damaged unit(s) removed from inventory.'];
            }

            // Legacy per-line receipt reprocessing stays admin-only.
            abort_unless($admin->isAdmin(), 403);
            if ($issue->status === 'resolved') {
                return ['ok' => true, 'already' => true, 'message' => 'This receiving issue was already resolved. No stock was added again.'];
            }
            if ($issue->status !== 'pending') {
                $this->fail('This report has been superseded. Use the latest branch confirmation.');
            }
            $reporter = User::with('role')->findOrFail($issue->reported_by);
            if ($reporter->status !== 'active' || ! $reporter->isBranchManager() || (int) $reporter->branch_id !== (int) $order->branch_id) {
                $this->fail('The branch confirmation is no longer authorized. Request a new confirmation from the destination branch manager.');
            }
            if ($this->snapshot($order) != $issue->snapshot) {
                $this->fail('Shipment or receipt quantities changed. Ask the branch manager to submit a fresh confirmation.');
            }
            $quantities = [];
            $reasons = [];
            foreach ($issue->receipt as $line) {
                $quantities[$line['order_item_id']] = $line['received_quantity'];
                $reasons[$line['order_item_id']] = $line['reason'] ?? null;
            }
            // Reprocess the stored branch-confirmed receipt. The resolver does
            // not acquire general receiving rights; both actors are audited.
            $result = (new OrderWorkflowService)->receive($order, $reporter->id, $quantities, Branch::commissary()?->id ?? 0, $reasons);
            if (! ($result['ok'] ?? false)) {
                $this->fail($result['error'] ?? 'Receipt could not be reprocessed. No changes were saved.');
            }
            $issue->update(['status' => 'resolved', 'resolved_by' => $admin->id, 'resolution_reason' => $reason, 'resolved_at' => now()]);
            $this->audit($admin, $issue, 'receiving_issue_resolved', 'Admin reprocessed the receipt confirmed by branch user #'.$reporter->id.'. Reason: '.$reason);

            return $result;
        });
    }

    private function audit(User $actor, ReceivingIssue $issue, string $action, string $details): void
    {
        AuditLog::create([
            'user_id' => $actor->id, 'action' => $action, 'table_name' => 'receiving_issues',
            'record_id' => $issue->id, 'details' => $details, 'ip_address' => request()->ip(),
        ]);
    }
}
