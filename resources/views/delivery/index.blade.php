@extends('layouts.app')

@section('title', 'Active Deliveries')

@section('content')

@php
    $statusMap = [
        'pending'    => ['hv-badge-warning',  'Pending'],
        'approved'   => ['hv-badge-info',     'Approved'],
        'preparing'  => ['hv-badge-info',     'Preparing'],
        'delivering' => ['hv-badge-info',     'Delivering'],
    ];
    $isOwner = $user->isOwner();
    $isWA = $user->isWarehouseAdmin();
    $isWS = $user->isWarehouseStaff();
    $isWarehouse = $user->isWarehouseRole();
    $isBM = $user->isBranchManager();
@endphp

<div class="hv-page-header">
    <div>
        <span class="text-muted small">
            @if($isWarehouse)
                Orders to prepare, in-progress, and in transit
            @else
                Orders pending approval, being prepared, or in transit
            @endif
        </span>
    </div>
    <a href="{{ route('delivery.history') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i> Delivery History
    </a>
</div>

@if($active->isEmpty())
    <div class="hv-card p-4 text-center">
        <i class="bi bi-check-circle fs-1 d-block mb-2" style="color:var(--success);"></i>
        <p class="mb-0 text-muted">No active deliveries at the moment.</p>
    </div>
@else
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:13px;">
            <thead>
                <tr>
                    <th class="ps-3">Order #</th>
                    <th>Branch</th>
                    <th>Ordered By</th>
                    <th class="text-end">Amount</th>
                    <th class="text-center">Status</th>
                    <th>Updated</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
            @foreach($active as $o)
                @php $sb = $statusMap[$o->status] ?? ['hv-badge-info', $o->status]; @endphp
                <tr>
                    <td class="ps-3 fw-semibold">{{ $o->order_number }}</td>
                    <td>{{ $o->branch_name }}</td>
                    <td class="text-muted">{{ $o->ordered_by_name ?? '—' }}</td>
                    <td class="text-end">{{ number_format($o->total_amount, 2) }}</td>
                    <td class="text-center">
                        <span class="hv-badge {{ $sb[0] }}">{{ $sb[1] }}</span>
                    </td>
                    <td class="text-muted">{{ \Carbon\Carbon::parse($o->updated_at)->format('M j, g:i A') }}</td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center flex-wrap">
                        @if($o->status === 'pending' && $isOwner)
                            <button class="btn btn-sm btn-outline-warning" title="Approve order"
                                    onclick="updateStatus({{ $o->id }}, 'approved', '{{ e($o->order_number) }}')">
                                <i class="bi bi-check-lg"></i> Approve
                            </button>
                        @elseif($o->status === 'approved' && ($isWarehouse || $isOwner))
                            <button class="btn btn-sm btn-outline-success" title="Start preparing"
                                    onclick="updateStatus({{ $o->id }}, 'preparing', '{{ e($o->order_number) }}')">
                                <i class="bi bi-box-seam"></i> Prepare
                            </button>
                        @elseif($o->status === 'preparing')
                            @if($isWA || $isOwner)
                            <button class="btn btn-sm btn-outline-primary" title="Release for delivery"
                                    onclick="updateStatus({{ $o->id }}, 'delivering', '{{ e($o->order_number) }}')">
                                <i class="bi bi-truck"></i> Ship
                            </button>
                            @else
                            <span class="text-muted small">
                                @if($o->prepared_by_name)
                                    <i class="bi bi-person"></i> {{ $o->prepared_by_name }}
                                @else
                                    In progress
                                @endif
                            </span>
                            @endif
                        @elseif($o->status === 'delivering' && ($isBM || $isOwner))
                            <button class="btn btn-sm btn-outline-success" title="Confirm receipt"
                                    onclick="openConfirmModal({{ $o->id }}, '{{ e($o->order_number) }}')">
                                <i class="bi bi-check2-circle"></i> Confirm Receipt
                            </button>
                        @else
                            <button class="btn btn-sm btn-outline-secondary" title="View order"
                                    onclick="viewOrder({{ $o->id }})">
                                <i class="bi bi-eye"></i>
                            </button>
                        @endif
                        </div>
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>
    {{ $active->count() }} active deliver{{ $active->count() !== 1 ? 'ies' : 'y' }}.
</p>
@endif

{{-- ═══ VIEW ORDER MODAL ═══════════════════════════════ --}}
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                    <i class="bi bi-receipt me-1"></i> <span id="vmTitle">Order Details</span>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="vmBody">
                <div class="text-center py-4 text-muted">Loading...</div>
            </div>
        </div>
    </div>
</div>

{{-- ═══ CONFIRM RECEIPT MODAL ══════════════════════════ --}}
<div class="modal fade" id="confirmModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                    <i class="bi bi-check2-circle me-1"></i> Confirm Delivery Receipt
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <p class="fw-semibold mb-3" style="color:var(--maroon);" id="cmOrderNum"></p>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th>Item</th>
                                <th class="text-center">Expected</th>
                                <th class="text-center">Received</th>
                                <th class="text-center">Difference</th>
                                <th>Reason</th>
                            </tr>
                        </thead>
                        <tbody id="cmItems"></tbody>
                    </table>
                </div>
                <input type="hidden" id="cmOrderId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-maroon" id="btnConfirm" onclick="submitConfirm()">
                    <i class="bi bi-check2-circle me-1"></i> Confirm Received
                </button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('styles')
<style>
.diff-ok { color: var(--success); }
.diff-bad { color: var(--danger); font-weight: 600; }
</style>
@endpush

@section('scripts')
<script>
var viewModal, confirmModal;
document.addEventListener('DOMContentLoaded', function() {
    viewModal = new bootstrap.Modal(document.getElementById('viewModal'));
    confirmModal = new bootstrap.Modal(document.getElementById('confirmModal'));
});

var csrf = document.querySelector('meta[name="csrf-token"]').content;

async function postAPI(data) {
    var res = await fetch('{{ route("orders.api") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(data)
    });
    return res.json();
}

async function updateStatus(orderId, status, orderNum) {
    var labels = {
        approved: 'Approve order ' + orderNum + '?\n\nThis will allocate warehouse stock.',
        preparing: 'Start preparing order ' + orderNum + '?',
        delivering: 'Release order ' + orderNum + ' for delivery?',
        cancelled: 'Cancel order ' + orderNum + '?',
    };
    if (!confirm(labels[status] || ('Change ' + orderNum + ' to ' + status + '?'))) return;

    try {
        var r = await postAPI({ action: 'update_status', id: orderId, status: status });
        if (r.ok) { alert(r.message); location.reload(); }
        else { alert(r.error || 'Error.'); }
    } catch (e) { alert('Network error.'); }
}

async function viewOrder(orderId) {
    document.getElementById('vmBody').innerHTML = '<div class="text-center py-4 text-muted">Loading...</div>';
    viewModal.show();

    try {
        var r = await postAPI({ action: 'view', id: orderId });
        if (!r.ok) { document.getElementById('vmBody').innerHTML = '<div class="text-danger">' + (r.error || 'Error') + '</div>'; return; }
        var o = r.order;
        document.getElementById('vmTitle').textContent = 'Order ' + o.order_number;

        var html = '<div class="row mb-3">';
        html += '<div class="col-sm-6"><strong>Branch:</strong> ' + o.branch_name + '</div>';
        html += '<div class="col-sm-6"><strong>Status:</strong> ' + o.status + '</div>';
        html += '<div class="col-sm-6"><strong>Ordered by:</strong> ' + (o.ordered_by_name || '—') + '</div>';
        html += '<div class="col-sm-6"><strong>Created:</strong> ' + (o.created_at || '—') + '</div>';
        if (o.approved_by_name) html += '<div class="col-sm-6"><strong>Approved by:</strong> ' + o.approved_by_name + '</div>';
        if (o.prepared_by_name) html += '<div class="col-sm-6"><strong>Prepared by:</strong> ' + o.prepared_by_name + '</div>';
        if (o.confirmed_by_name) html += '<div class="col-sm-6"><strong>Confirmed by:</strong> ' + o.confirmed_by_name + '</div>';
        if (o.delivered_at) html += '<div class="col-sm-6"><strong>Delivered:</strong> ' + o.delivered_at + '</div>';
        html += '</div>';

        html += '<table class="table table-sm" style="font-size:13px;"><thead><tr><th>Item</th><th class="text-center">Qty</th>';
        if (o.status === 'delivered' || o.status === 'settled') html += '<th class="text-center">Received</th>';
        html += '<th class="text-end">Price</th><th class="text-end">Subtotal</th></tr></thead><tbody>';
        o.items.forEach(function(i) {
            html += '<tr><td>' + i.item_name + ' <span class="text-muted">(' + i.unit + ')</span></td>';
            html += '<td class="text-center">' + i.quantity + '</td>';
            if (o.status === 'delivered' || o.status === 'settled') {
                var recv = i.received_quantity !== null ? i.received_quantity : i.quantity;
                var cls = recv == i.quantity ? 'diff-ok' : 'diff-bad';
                html += '<td class="text-center ' + cls + '">' + recv + '</td>';
            }
            html += '<td class="text-end">' + Number(i.unit_price).toFixed(2) + '</td>';
            html += '<td class="text-end">' + Number(i.subtotal).toFixed(2) + '</td></tr>';
        });
        html += '</tbody></table>';
        html += '<div class="text-end fw-bold" style="color:var(--maroon);">Total: PHP ' + Number(o.total_amount).toFixed(2) + '</div>';

        if (o.discrepancies && o.discrepancies.length > 0) {
            html += '<hr><h6 style="color:var(--danger);font-weight:700;"><i class="bi bi-exclamation-triangle me-1"></i> Discrepancies</h6>';
            html += '<table class="table table-sm" style="font-size:12px;"><thead><tr><th>Item</th><th class="text-center">Expected</th><th class="text-center">Received</th><th>Reason</th><th>Remarks</th></tr></thead><tbody>';
            o.discrepancies.forEach(function(d) {
                html += '<tr class="table-warning"><td>' + d.item_name + '</td><td class="text-center">' + d.expected_quantity + '</td><td class="text-center">' + d.received_quantity + '</td><td>' + d.reason + '</td><td>' + (d.remarks || '—') + '</td></tr>';
            });
            html += '</tbody></table>';
        }

        document.getElementById('vmBody').innerHTML = html;
    } catch (e) { document.getElementById('vmBody').innerHTML = '<div class="text-danger">Network error.</div>'; }
}

var confirmItems = [];

async function openConfirmModal(orderId, orderNum) {
    document.getElementById('cmOrderId').value = orderId;
    document.getElementById('cmOrderNum').textContent = 'Order: ' + orderNum;
    document.getElementById('cmItems').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Loading...</td></tr>';
    confirmModal.show();

    try {
        var r = await postAPI({ action: 'view', id: orderId });
        if (!r.ok) { document.getElementById('cmItems').innerHTML = '<tr><td colspan="5" class="text-danger">' + (r.error || 'Error') + '</td></tr>'; return; }

        confirmItems = r.order.items;
        var html = '';
        confirmItems.forEach(function(item, idx) {
            html += '<tr>';
            html += '<td>' + item.item_name + ' <span class="text-muted">(' + item.unit + ')</span></td>';
            html += '<td class="text-center fw-semibold">' + item.quantity + '</td>';
            html += '<td class="text-center"><input type="number" class="form-control form-control-sm text-center cm-recv" min="0" value="' + item.quantity + '" data-idx="' + idx + '" data-expected="' + item.quantity + '" onchange="updateDiff(this)" style="width:80px;display:inline-block;"></td>';
            html += '<td class="text-center cm-diff" id="cmDiff' + idx + '"><span class="diff-ok">0</span></td>';
            html += '<td><select class="form-select form-select-sm cm-reason" id="cmReason' + idx + '" style="display:none;min-width:140px;"><option value="">—</option><option value="Missing Quantity">Missing Quantity</option><option value="Damaged in Transit">Damaged in Transit</option><option value="Incorrect Quantity">Incorrect Quantity</option><option value="Incorrect Item">Incorrect Item</option><option value="Other">Other</option></select></td>';
            html += '</tr>';
        });
        document.getElementById('cmItems').innerHTML = html;
    } catch (e) { document.getElementById('cmItems').innerHTML = '<tr><td colspan="5" class="text-danger">Network error.</td></tr>'; }
}

function updateDiff(el) {
    var idx = el.dataset.idx;
    var expected = parseInt(el.dataset.expected);
    var received = parseInt(el.value) || 0;
    var diff = received - expected;
    var diffEl = document.getElementById('cmDiff' + idx);
    var reasonEl = document.getElementById('cmReason' + idx);

    if (diff === 0) {
        diffEl.innerHTML = '<span class="diff-ok">0</span>';
        reasonEl.style.display = 'none';
    } else {
        diffEl.innerHTML = '<span class="diff-bad">' + (diff > 0 ? '+' : '') + diff + '</span>';
        reasonEl.style.display = 'inline-block';
        if (!reasonEl.value) {
            reasonEl.value = diff < 0 ? 'Missing Quantity' : 'Incorrect Quantity';
        }
    }
}

async function submitConfirm() {
    var btn = document.getElementById('btnConfirm');
    btn.disabled = true; btn.textContent = 'Confirming...';

    var receivedItems = [];
    document.querySelectorAll('.cm-recv').forEach(function(el) {
        var idx = parseInt(el.dataset.idx);
        var item = confirmItems[idx];
        receivedItems.push({
            order_item_id: item.id,
            received_quantity: parseInt(el.value) || 0,
        });
    });

    var hasDiscrepancy = receivedItems.some(function(ri, i) {
        return ri.received_quantity !== confirmItems[i].quantity;
    });

    if (hasDiscrepancy) {
        if (!confirm('There are quantity discrepancies. Discrepancies will be recorded. Continue?')) {
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm Received';
            return;
        }
    }

    var data = {
        action: 'update_status',
        id: document.getElementById('cmOrderId').value,
        status: 'delivered',
        received_items: JSON.stringify(receivedItems),
    };

    // Attach discrepancy reasons
    document.querySelectorAll('.cm-reason').forEach(function(el) {
        if (el.style.display !== 'none' && el.value) {
            var idx = el.id.replace('cmReason', '');
            var item = confirmItems[parseInt(idx)];
            data['discrepancy_reason_' + item.id] = el.value;
        }
    });

    try {
        var r = await postAPI(data);
        if (r.ok) { confirmModal.hide(); alert(r.message); location.reload(); }
        else { alert(r.error || 'Error confirming delivery.'); }
    } catch (e) { alert('Network error.'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm Received'; }
}
</script>
@endsection
