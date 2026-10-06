@extends('layouts.app')

@section('title', 'Orders')

@section('content')

<div class="hv-page-header">
    <div></div>
    <button class="btn btn-maroon btn-sm" onclick="openOrderModal()">
        <i class="bi bi-plus-lg me-1"></i> New Order
    </button>
</div>

<!-- Filter Bar -->
<div class="hv-card p-2 mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-md-3">
            <input type="text" class="form-control form-control-sm" id="orderSearch"
                   placeholder="Search order #..." oninput="filterOrders()">
        </div>
        <div class="col-md-3 {{ $isBranchScoped ? 'd-none' : '' }}">
            <select class="form-select form-select-sm" id="filterBranch" onchange="filterOrders()">
                <option value="">All Branches</option>
                @foreach($branches as $br)
                    <option value="{{ $br->branch_name }}">{{ $br->branch_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="filterStatus" onchange="filterOrders()">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="delivering">Delivering</option>
                <option value="delivered">Delivered</option>
                <option value="settled">Settled</option>
                <option value="cancelled">Cancelled</option>
            </select>
        </div>
        <div class="col-md-3 text-end">
            <span class="text-muted small" id="orderCount">
                {{ $orders->count() }} order{{ $orders->count() !== 1 ? 's' : '' }}
            </span>
        </div>
    </div>
</div>

<!-- Order Table -->
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Order #</th>
                    <th>Branch</th>
                    <th class="text-end">Total</th>
                    <th class="text-center">Status</th>
                    <th>Ordered By</th>
                    <th>Date</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($orders as $o)
                @php
                    $statusBadges = [
                        'pending'    => ['hv-badge-warning',  'bi-clock'],
                        'approved'   => ['hv-badge-info',     'bi-check-circle'],
                        'delivering' => ['hv-badge-info',     'bi-truck'],
                        'delivered'  => ['hv-badge-success',  'bi-box-seam-fill'],
                        'settled'    => ['hv-badge-success',  'bi-check-all'],
                        'cancelled'  => ['hv-badge-danger',   'bi-x-circle'],
                    ];
                    $sb = $statusBadges[$o->status] ?? ['hv-badge-info', 'bi-question'];
                @endphp
                <tr class="order-row"
                    data-number="{{ strtolower($o->order_number) }}"
                    data-branch="{{ $o->branch_name }}"
                    data-status="{{ $o->status }}">
                    <td class="ps-3">
                        <a href="#" class="fw-semibold text-decoration-none" style="color:var(--maroon);"
                           onclick="viewOrder({{ $o->id }}); return false;">
                            {{ $o->order_number }}
                        </a>
                    </td>
                    <td class="small">{{ $o->branch_name }}</td>
                    <td class="text-end fw-semibold">&#8369;{{ number_format($o->total_amount, 2) }}</td>
                    <td class="text-center">
                        <span class="hv-badge {{ $sb[0] }}">
                            <i class="bi {{ $sb[1] }} me-1"></i>{{ ucfirst($o->status) }}
                        </span>
                    </td>
                    <td class="small text-muted">{{ $o->ordered_by_name ?? '—' }}</td>
                    <td class="small text-muted">{{ \Carbon\Carbon::parse($o->created_at)->format('M j, g:i A') }}</td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            <button class="btn btn-sm btn-outline-secondary" title="View Details"
                                    onclick="viewOrder({{ $o->id }})">
                                <i class="bi bi-eye"></i>
                            </button>

                            @if($canManage)
                                @if($o->status === 'pending')
                                    <button class="btn btn-sm btn-outline-success" title="Approve"
                                            onclick="updateStatus({{ $o->id }}, 'approved')">
                                        <i class="bi bi-check-lg"></i>
                                    </button>
                                    <button class="btn btn-sm btn-outline-danger" title="Cancel"
                                            onclick="updateStatus({{ $o->id }}, 'cancelled')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                @elseif($o->status === 'approved')
                                    <button class="btn btn-sm btn-outline-info" title="Mark Delivering"
                                            onclick="updateStatus({{ $o->id }}, 'delivering')">
                                        <i class="bi bi-truck"></i>
                                    </button>
                                @elseif($o->status === 'delivering')
                                    <button class="btn btn-sm btn-outline-success" title="Mark Delivered"
                                            onclick="updateStatus({{ $o->id }}, 'delivered')">
                                        <i class="bi bi-box-seam-fill"></i>
                                    </button>
                                @elseif($o->status === 'delivered')
                                    <button class="btn btn-sm btn-outline-success" title="Settle"
                                            onclick="updateStatus({{ $o->id }}, 'settled')">
                                        <i class="bi bi-check-all"></i>
                                    </button>
                                @endif
                            @elseif((int)$o->branch_id === $user->branch_id)
                                @if($o->status === 'pending')
                                    <button class="btn btn-sm btn-outline-danger" title="Cancel my order"
                                            onclick="updateStatus({{ $o->id }}, 'cancelled')">
                                        <i class="bi bi-x-lg"></i>
                                    </button>
                                @elseif($o->status === 'delivering')
                                    <button class="btn btn-sm btn-success" title="Confirm Received"
                                            onclick="updateStatus({{ $o->id }}, 'delivered')">
                                        <i class="bi bi-box-seam-fill me-1"></i> Received
                                    </button>
                                @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        No orders yet. Click "New Order" to create one.
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

<!-- New Order Modal -->
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="orderForm" onsubmit="submitOrder(event)">
                @csrf
                <div class="modal-header" style="background:var(--gold-soft);">
                    <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                        <i class="bi bi-cart-plus me-1"></i> New Order
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Order for Branch <span class="text-danger">*</span></label>
                        <select class="form-select" name="branch_id" id="o_branch_id" required>
                            @if($isBranchScoped)
                                @foreach($branches as $br)
                                    @if($br->id === $user->branch_id)
                                        <option value="{{ $br->id }}" selected>{{ $br->branch_name }}</option>
                                    @endif
                                @endforeach
                            @else
                                <option value="">Select branch</option>
                                @foreach($branches as $br)
                                    <option value="{{ $br->id }}">{{ $br->branch_name }}</option>
                                @endforeach
                            @endif
                        </select>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remarks</label>
                        <input type="text" class="form-control" name="remarks" id="o_remarks"
                               maxlength="500" placeholder="Optional notes for this order">
                    </div>

                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <label class="form-label fw-semibold mb-0">Order Items <span class="text-danger">*</span></label>
                        <button type="button" class="btn btn-sm btn-outline-success" onclick="addLineItem()">
                            <i class="bi bi-plus-lg me-1"></i> Add Item
                        </button>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-0" id="lineItemsTable">
                            <thead>
                                <tr>
                                    <th style="width:45%">Product</th>
                                    <th style="width:15%" class="text-center">Qty</th>
                                    <th style="width:20%" class="text-end">Unit Price</th>
                                    <th style="width:15%" class="text-end">Subtotal</th>
                                    <th style="width:5%"></th>
                                </tr>
                            </thead>
                            <tbody id="lineItemsBody"></tbody>
                            <tfoot>
                                <tr>
                                    <td colspan="3" class="text-end fw-bold">Total:</td>
                                    <td class="text-end fw-bold" id="orderTotal">&#8369;0.00</td>
                                    <td></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon" id="btnOrder">
                        <i class="bi bi-cart-check me-1"></i> Place Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Order Modal -->
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                    <i class="bi bi-receipt me-1"></i> Order Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="text-center py-4 text-muted">Loading...</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
const API  = '{{ route("orders.api") }}';
const CSRF = '{{ csrf_token() }}';

const products = @json($productsJson);

let orderModal, viewModal;
let lineCounter = 0;

document.addEventListener('DOMContentLoaded', function () {
    orderModal = new bootstrap.Modal(document.getElementById('orderModal'));
    viewModal  = new bootstrap.Modal(document.getElementById('viewModal'));
});

function openOrderModal() {
    document.getElementById('orderForm').reset();
    document.getElementById('lineItemsBody').innerHTML = '';
    document.getElementById('orderTotal').innerHTML = '&#8369;0.00';
    lineCounter = 0;
    addLineItem();
    orderModal.show();
}

function addLineItem() {
    lineCounter++;
    const tbody = document.getElementById('lineItemsBody');
    let options = '<option value="">Select product</option>';
    products.forEach(function(p) {
        options += '<option value="' + p.id + '" data-price="' + p.price + '">' +
                   p.name + ' (' + p.category + ') — &#8369;' + p.price.toFixed(2) + '/' + p.unit +
                   '</option>';
    });

    const row = document.createElement('tr');
    row.id = 'line_' + lineCounter;
    row.innerHTML =
        '<td><select class="form-select form-select-sm" name="items[' + lineCounter + '][item_id]" onchange="updateLine(' + lineCounter + ')" required>' + options + '</select></td>' +
        '<td><input type="number" class="form-control form-control-sm text-center" name="items[' + lineCounter + '][quantity]" min="1" value="1" oninput="updateLine(' + lineCounter + ')" required></td>' +
        '<td class="text-end" id="price_' + lineCounter + '">&#8369;0.00</td>' +
        '<td class="text-end fw-semibold" id="subtotal_' + lineCounter + '">&#8369;0.00</td>' +
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(' + lineCounter + ')"><i class="bi bi-trash"></i></button></td>';
    tbody.appendChild(row);
}

function updateLine(n) {
    const row = document.getElementById('line_' + n);
    if (!row) return;
    const select = row.querySelector('select');
    const qtyInput = row.querySelector('input[type="number"]');
    const opt = select.options[select.selectedIndex];
    const price = parseFloat(opt.dataset.price) || 0;
    const qty = parseInt(qtyInput.value) || 0;
    const subtotal = price * qty;
    document.getElementById('price_' + n).innerHTML = '&#8369;' + price.toFixed(2);
    document.getElementById('subtotal_' + n).innerHTML = '&#8369;' + subtotal.toFixed(2);
    recalcTotal();
}

function removeLine(n) {
    const row = document.getElementById('line_' + n);
    if (row) row.remove();
    recalcTotal();
}

function recalcTotal() {
    let total = 0;
    document.querySelectorAll('#lineItemsBody tr').forEach(function(row) {
        const select = row.querySelector('select');
        const qty = parseInt(row.querySelector('input[type="number"]').value) || 0;
        const opt = select.options[select.selectedIndex];
        const price = parseFloat(opt?.dataset?.price) || 0;
        total += price * qty;
    });
    document.getElementById('orderTotal').innerHTML = '&#8369;' + total.toFixed(2);
}

async function submitOrder(e) {
    e.preventDefault();
    const rows = document.querySelectorAll('#lineItemsBody tr');
    if (rows.length === 0) { alert('Please add at least one item to the order.'); return; }

    const lineItems = [];
    let valid = true;
    rows.forEach(function(row) {
        const select = row.querySelector('select');
        const qty = row.querySelector('input[type="number"]');
        if (!select.value) { valid = false; }
        lineItems.push({ item_id: parseInt(select.value), quantity: parseInt(qty.value) || 0 });
    });
    if (!valid) { alert('Please select a product for each line item.'); return; }

    const formData = new FormData();
    formData.append('action', 'create');
    formData.append('_token', CSRF);
    formData.append('branch_id', document.getElementById('o_branch_id').value);
    formData.append('remarks', document.getElementById('o_remarks').value);
    formData.append('line_items', JSON.stringify(lineItems));

    const btn = document.getElementById('btnOrder');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Placing...';

    try {
        const res = await fetch(API, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (json.ok) { orderModal.hide(); location.reload(); }
        else { alert(json.error || 'Something went wrong.'); }
    } catch (err) { alert('Network error. Please check your connection.'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-cart-check me-1"></i> Place Order'; }
}

async function updateStatus(orderId, newStatus) {
    const labels = { approved: 'approve', delivering: 'mark as delivering', delivered: 'mark as delivered (stock will be added to the branch)', settled: 'settle', cancelled: 'cancel' };
    if (!confirm('Are you sure you want to ' + (labels[newStatus] || newStatus) + ' this order?')) return;

    const data = new FormData();
    data.append('action', 'update_status');
    data.append('id', orderId);
    data.append('status', newStatus);
    data.append('_token', CSRF);

    try {
        const res = await fetch(API, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (json.ok) { location.reload(); } else { alert(json.error || 'Something went wrong.'); }
    } catch (err) { alert('Network error. Please check your connection.'); }
}

async function viewOrder(orderId) {
    const body = document.getElementById('viewModalBody');
    body.innerHTML = '<div class="text-center py-4 text-muted"><span class="spinner-border spinner-border-sm me-1"></span> Loading...</div>';
    viewModal.show();

    const data = new FormData();
    data.append('action', 'view');
    data.append('id', orderId);
    data.append('_token', CSRF);

    try {
        const res = await fetch(API, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (json.ok) {
            const o = json.order;
            const statusColors = { pending: 'warning', approved: 'info', delivering: 'info', delivered: 'success', settled: 'success', cancelled: 'danger' };
            let html = '<div class="row mb-3"><div class="col-md-6">';
            html += '<p class="mb-1"><strong>Order #:</strong> ' + o.order_number + '</p>';
            html += '<p class="mb-1"><strong>Branch:</strong> ' + o.branch_name + '</p>';
            html += '<p class="mb-1"><strong>Ordered by:</strong> ' + (o.ordered_by_name || '—') + '</p>';
            html += '<p class="mb-1"><strong>Date:</strong> ' + o.created_at + '</p>';
            html += '</div><div class="col-md-6">';
            html += '<p class="mb-1"><strong>Status:</strong> <span class="hv-badge hv-badge-' + (statusColors[o.status] || 'info') + '">' + o.status.charAt(0).toUpperCase() + o.status.slice(1) + '</span></p>';
            if (o.approved_by_name) html += '<p class="mb-1"><strong>Approved by:</strong> ' + o.approved_by_name + '</p>';
            if (o.approved_at) html += '<p class="mb-1"><strong>Approved at:</strong> ' + o.approved_at + '</p>';
            if (o.delivered_at) html += '<p class="mb-1"><strong>Delivered at:</strong> ' + o.delivered_at + '</p>';
            if (o.settled_at) html += '<p class="mb-1"><strong>Settled at:</strong> ' + o.settled_at + '</p>';
            if (o.remarks) html += '<p class="mb-1"><strong>Remarks:</strong> ' + o.remarks + '</p>';
            html += '</div></div>';
            html += '<table class="table table-sm align-middle mb-0"><thead><tr><th>Product</th><th class="text-center">Qty</th><th class="text-end">Price</th><th class="text-end">Subtotal</th></tr></thead><tbody>';
            o.items.forEach(function(item) {
                html += '<tr><td>' + item.item_name + ' <span class="text-muted small">(' + item.unit + ')</span></td>';
                html += '<td class="text-center">' + item.quantity + '</td>';
                html += '<td class="text-end">&#8369;' + parseFloat(item.unit_price).toFixed(2) + '</td>';
                html += '<td class="text-end fw-semibold">&#8369;' + parseFloat(item.subtotal).toFixed(2) + '</td></tr>';
            });
            html += '</tbody><tfoot><tr><td colspan="3" class="text-end fw-bold">Total:</td>';
            html += '<td class="text-end fw-bold">&#8369;' + parseFloat(o.total_amount).toFixed(2) + '</td></tr></tfoot></table>';
            body.innerHTML = html;
        } else {
            body.innerHTML = '<div class="text-danger py-3">' + (json.error || 'Failed to load.') + '</div>';
        }
    } catch (err) { body.innerHTML = '<div class="text-danger py-3">Network error.</div>'; }
}

function filterOrders() {
    const search = document.getElementById('orderSearch').value.toLowerCase();
    const branch = document.getElementById('filterBranch').value;
    const status = document.getElementById('filterStatus').value;
    const rows = document.querySelectorAll('.order-row');
    let visible = 0;
    rows.forEach(function (row) {
        let show = true;
        if (search && !row.dataset.number.includes(search)) show = false;
        if (branch && row.dataset.branch !== branch) show = false;
        if (status && row.dataset.status !== status) show = false;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    document.getElementById('orderCount').textContent = visible + ' order' + (visible !== 1 ? 's' : '');
}
</script>
@endpush
