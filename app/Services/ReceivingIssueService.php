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

    /** @return array<string, mixed> */
    public function resolve(ReceivingIssue $issue, User $admin, string $reason): array
    {
        abort_unless($admin->status === 'active' && $admin->isAdmin(), 403);

        return DB::transaction(function () use ($issue, $admin, $reason): array {
            $order = Order::whereKey($issue->order_id)->lockForUpdate()->firstOrFail();
            $issue = ReceivingIssue::whereKey($issue->id)->lockForUpdate()->firstOrFail();
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
