@extends('layouts.app')

@section('title', $showArchivedOrders ? 'Archived Orders' : 'Orders')

@push('styles')
<style>
/* Row actions: one emphasized primary, quiet rest, consistent sizing */
.hv-row-actions { display: flex; flex-wrap: nowrap; align-items: center; justify-content: flex-start; gap: .4rem; }
.hv-row-actions .btn { display: inline-flex; flex-shrink: 0; align-items: center; justify-content: center; min-height: 30px; gap: .35rem; white-space: nowrap; font-size: 12.5px; font-weight: 600; }
.hv-row-actions .btn-ghost-icon { width: 30px; padding: .34rem 0; }
.hv-row-actions .btn i[class*="ph-"] { font-size: .95em; }
.btn-ghost-icon { border: 1px solid transparent; background: transparent; color: #6E635C; padding: .34rem .5rem; border-radius: 9px; }
.btn-ghost-icon:hover { background: var(--gold-soft); color: var(--maroon); }
.btn-ghost-danger:hover { background: #F6DED9; color: var(--danger); }
</style>
@endpush

@if($organizeCommissaryOrders)
@push('styles')
<style>
.order-queue-button[aria-pressed="true"] { background: var(--maroon); color: #fff; border-color: var(--maroon); }
.order-queue-button .badge { background: var(--gold-soft); color: var(--maroon); }
.order-queue-button { white-space: nowrap; }
.commissary-orders-table td { vertical-align: middle; }
.commissary-order-actions { min-width: 240px; }
</style>
@endpush
@endif

@section('content')

@include('partials.orders-navigation')

<div class="hv-page-header">
    <div class="d-flex align-items-start gap-3">
        @if($showArchivedOrders)
        <a class="btn btn-sm btn-outline-secondary d-inline-flex align-items-center gap-2 flex-shrink-0"
           href="{{ route('orders', ['queue' => 'history']) }}" aria-label="Back to Order History" title="Back to Order History">
            <i class="ph ph-arrow-left" aria-hidden="true"></i> Leave
        </a>
        @endif
        <div>
            @if($showArchivedOrders)
            <strong>Archived Orders</strong>
            <div class="small text-muted">{{ $canArchiveOrders ? 'Retained order records. Open details, print a proforma, or restore an order to History.' : 'Retained order records. Open an order to review its history.' }}</div>
            @elseif($organizeCommissaryOrders)
            <strong>Commissary Orders</strong>
            <div class="small text-muted">{{ $user->isCommissaryStaff() ? 'Find prepared orders and release goods for delivery.' : 'Review branch requests, prepare orders, and track releases.' }}</div>
            @endif
        </div>
    </div>
    @if(!$showArchivedOrders)
    <div class="d-flex flex-wrap gap-2">
    <a class="btn btn-sm btn-outline-secondary" href="{{ route('orders', ['archived' => 1]) }}"><i class="ph ph-archive-box me-1"></i> Archived Orders <span class="badge rounded-pill bg-secondary ms-1">{{ $archivedOrderCount }}</span></a>
    @if(!$isBM && !$user->isCommissaryStaff())
    <button class="btn btn-maroon btn-sm" onclick="openOrderModal()">
        <i class="ph ph-plus me-1"></i> New Order
    </button>
    @endif
    </div>
    @endif
</div>

@if(!$showArchivedOrders)
<p class="small text-muted mb-3"><strong>Order flow:</strong> Request &rarr; Review &rarr; Prepare &rarr; Print labels &rarr; Dispatch &rarr; Receive</p>

@endif

@if($organizeCommissaryOrders)
<div class="d-flex flex-wrap gap-2 mb-3" role="group" aria-label="Commissary order work queues">
    @foreach($orderQueues as $queue => $label)
    <button type="button" class="btn btn-sm btn-outline-maroon order-queue-button"
            data-order-queue="{{ $queue }}" aria-pressed="{{ $defaultOrderQueue === $queue ? 'true' : 'false' }}" onclick="selectOrderQueue(this.dataset.orderQueue)">
        {{ $label }} <span class="badge rounded-pill ms-1" data-queue-count="{{ $queue }}">{{ $orderQueueCounts[$queue] }}</span>
    </button>
    @endforeach
</div>
@endif

<!-- Filter Bar -->
<div class="hv-card p-2 mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-12 col-lg">
            <input type="text" class="form-control form-control-sm" id="orderSearch"
                   placeholder="{{ $organizeCommissaryOrders ? 'Search order #, branch, or requester...' : 'Search order #...' }}"
                   oninput="filterOrders()">
        </div>
        <div class="col-6 col-md-4 col-lg-auto {{ $isBranchScoped && !$isWarehouse ? 'd-none' : '' }}">
            <select class="form-select form-select-sm" id="filterBranch" onchange="filterOrders()">
                <option value="">All Branches</option>
                @foreach($branches as $br)
                    <option value="{{ $br->branch_name }}">{{ $br->branch_name }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-4 col-lg-auto">
            <select class="form-select form-select-sm" id="filterStatus" onchange="filterOrders()">
                <option value="">All Statuses</option>
                <option value="pending">Pending</option>
                <option value="approved">Approved</option>
                <option value="preparing">Preparing</option>
                <option value="delivering">Delivering</option>
                <option value="delivered">Delivered</option>
                <option value="settled">Settled</option>
                <option value="cancelled">Cancelled</option>
                <option value="declined">Declined</option>
            </select>
        </div>
        @if($organizeCommissaryOrders)
        <div class="col-6 col-md-4 col-lg-auto">
            <select id="orderSort" class="form-select form-select-sm" aria-label="Sort orders" onchange="filterOrders()">
                <option value="newest">Newest first</option>
                <option value="oldest">Oldest first</option>
                <option value="branch">Branch A–Z</option>
            </select>
        </div>
        @endif
        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="clearOrderFilters()">
                <i class="ph ph-x" aria-hidden="true"></i> Clear Filters
            </button>
        </div>
        <div class="col text-end">
            <span class="text-muted small" id="orderCount" role="status" aria-live="polite">
                {{ $orders->count() }} order{{ $orders->count() !== 1 ? 's' : '' }}
            </span>
        </div>
    </div>
</div>

@if($organizeCommissaryOrders)
<div class="d-flex flex-wrap align-items-center justify-content-between gap-2 mb-2">
    <div class="small text-muted" id="orderQueueHint">Choose a work queue to focus on the next action.</div>
</div>
@endif

<!-- Order Table -->
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 {{ $organizeCommissaryOrders ? 'commissary-orders-table' : '' }}">
            <thead>
                <tr>
                    <th class="ps-3">Order #</th>
                    <th>Branch</th>
                    <th class="text-end">Total</th>
                    <th class="text-center">Status</th>
                    <th>Ordered By</th>
                    <th>Date</th>
                    <th class="text-start pe-3">Actions</th>
                </tr>
            </thead>
            <tbody id="ordersTableBody">
            @forelse($orders as $o)
                @php
                    $statusBadges = [
                        'pending'    => ['hv-badge-warning',  'ph-clock'],
                        'approved'   => ['hv-badge-info',     'ph-check-circle'],
                        'preparing'  => ['hv-badge-info',     'ph-package'],
                        'delivering' => ['hv-badge-info',     'ph-truck'],
                        'delivered'  => ['hv-badge-success',  'ph-package'],
                        'settled'    => ['hv-badge-success',  'ph-checks'],
                        'cancelled'  => ['hv-badge-danger',   'ph-x-circle'],
                        'declined'   => ['hv-badge-danger',   'ph-x-circle'],
                    ];
                    $sb = $statusBadges[$o->status] ?? ['hv-badge-info', 'ph-question'];
                @endphp
                <tr class="order-row"
                    data-number="{{ strtolower($o->order_number) }}"
                    data-branch="{{ $o->branch_name }}"
                    data-status="{{ $o->status }}"
                    @if($organizeCommissaryOrders)
                    data-queue="{{ $o->work_queue }}" data-created="{{ $o->created_at->timestamp }}"
                    data-search="{{ strtolower($o->order_number.' '.$o->branch_name.' '.($o->ordered_by_name ?? '')) }}"
                    @endif>
                    <td class="ps-3">
                        <a href="#" class="fw-semibold text-decoration-none" style="color:var(--maroon);"
                           onclick="viewOrder({{ $o->id }}); return false;">
                            {{ $o->order_number }}
                        </a>
                        @if($showArchivedOrders)
                        <div class="small text-muted mt-1">Archived {{ $o->archived_at->format('M j, Y g:i A') }}<br>by {{ $o->archived_by_name ?? 'Former user' }}</div>
                        @endif
                    </td>
                    <td class="small">{{ $o->branch_name }}</td>
                    <td class="text-end fw-semibold">&#8369;{{ number_format($o->fulfilled_amount ?? $o->total_amount, 2) }}</td>
                    <td class="text-center">
                        <span class="hv-badge {{ $sb[0] }}">
                            <i class="ph {{ $sb[1] }} me-1"></i>{{ \App\Services\OrderLabelService::stageLabel($o) }}
                        </span>
                        @if($o->payment_reference && !$user->isCommissaryStaff())
                        <div class="small text-muted mt-1">Payment proof submitted; review in details.</div>
                        @endif
                        @if($o->receiving_issue_count && $o->status === 'delivering')
                        <div class="small text-warning-emphasis mt-1">Receiving assistance requested — review in details.</div>
                        @endif
                        @if($organizeCommissaryOrders && $o->piece_outputs)
                        <div class="small text-muted mt-1">QR labels released: {{ $o->released_units }} / {{ $o->prepared_units }}</div>
                        @endif
                    </td>
                    <td class="small text-muted">{{ $o->ordered_by_name ?? '—' }}</td>
                    <td class="small text-muted">{{ \Carbon\Carbon::parse($o->created_at)->format('M j, g:i A') }}</td>
                    <td class="pe-3">
                        @php
                            $pendingReview = $o->status === 'pending' && ($user->isOwner() || $user->isMainBranchManager());
                            $prepareAction = !$showArchivedOrders && ($user->isOwner() || $user->isMainBranchManager()) && in_array($o->status, ['approved', 'preparing']) && !$o->piece_outputs;
                            $releaseAction = !$showArchivedOrders && ($user->isOwner() || $user->isMainBranchRole()) && $o->piece_outputs && in_array($o->status, ['approved', 'preparing', 'delivering']) && $o->released_units < $o->prepared_units;
                            $inTransit = !$showArchivedOrders && $o->status === 'delivering' && (!$o->piece_outputs || ($o->prepared_units > 0 && $o->released_units >= $o->prepared_units));
                        @endphp
                        <div class="hv-row-actions {{ $organizeCommissaryOrders ? 'commissary-order-actions' : '' }}">
                            @if($inTransit)
                            <button type="button" class="btn btn-sm btn-outline-maroon" onclick="viewOrder({{ $o->id }})"><i class="ph ph-eye" aria-hidden="true"></i> View Details</button>
                            @elseif($prepareAction || $releaseAction)
                            <button type="button" class="btn btn-sm btn-maroon" data-order-id="{{ $o->id }}" data-order-number="{{ $o->order_number }}"
                                    data-order-workspace="{{ $prepareAction ? 'prepare' : 'release' }}" onclick="{{ $prepareAction ? 'openPiecePrepare(this)' : 'openPieceRelease(this)' }}">
                                <i class="ph {{ $prepareAction ? 'ph-package' : 'ph-truck' }}" aria-hidden="true"></i> {{ $prepareAction ? 'Prepare / Pick' : 'Release' }}
                            </button>
                            @else
                            {{-- A pending request a reviewer can act on exposes ONLY Review Order; the
                                 eye and cancel are hidden since review is the single intended action. --}}
                            @unless($pendingReview)
                            <button class="btn btn-sm btn-ghost-icon" title="View details" aria-label="View details"
                                    onclick="viewOrder({{ $o->id }})">
                                <i class="ph ph-eye"></i>
                            </button>
                            @endunless

                            @if(!$user->isCommissaryStaff() && $o->isProformaAvailable())
                            <a class="btn btn-sm btn-ghost-icon" href="{{ route('orders.proforma', $o) }}" target="_blank" rel="noopener" title="Proforma invoice" aria-label="Proforma invoice"><i class="ph ph-file-text"></i></a>
                            @endif
                            @unless($showArchivedOrders)
                            @if($user->isOwner() || $user->isMainBranchManager())
                                @if($o->status === 'pending')
                                <button class="btn btn-sm btn-maroon" data-order-id="{{ $o->id }}" onclick="viewOrder({{ $o->id }})"><i class="ph ph-clipboard-text"></i> Review Order</button>
                                @endif
                            @endif
                            @if(($user->isOwner() || $user->isMainBranchManager()) && $o->piece_outputs && !in_array($o->status, ['cancelled','declined']))
                            <button type="button" class="btn btn-sm btn-outline-maroon" onclick="hvPrintLabelsUrl('{{ route('orders.labels', $o->id) }}')"><i class="ph ph-printer"></i> Print Labels</button>
                            @endif
                            {{-- Approve/Decline are now inside the Review Order interface, not direct row actions. --}}
                            @if(!$pendingReview && in_array($o->status, ['pending','approved','preparing']) && ($user->isOwner() || $user->isMainBranchManager() || ($isBM && (int)$o->branch_id === (int)$user->branch_id)))
                            <button class="btn btn-sm btn-ghost-icon btn-ghost-danger" data-order-id="{{ $o->id }}" title="Cancel order" aria-label="Cancel order" onclick="cancelPieceOrder(this)"><i class="ph ph-prohibit"></i></button>
                            @endif
                            @endunless
                            @if($canArchiveOrders && ($showArchivedOrders || in_array($o->status, \App\Models\Order::HISTORY_STATUSES, true)))
                            <button type="button" class="btn btn-sm btn-outline-secondary" data-order-archive="{{ $showArchivedOrders ? 'restore' : 'archive' }}" data-order-id="{{ $o->id }}" data-order-number="{{ $o->order_number }}" onclick="setOrderArchive(this)">
                                <i class="ph {{ $showArchivedOrders ? 'ph-arrow-counter-clockwise' : 'ph-archive-box' }}"></i> {{ $showArchivedOrders ? 'Restore' : 'Archive' }}
                            </button>
                            @endif
                            @if($showArchivedOrders && $user->isOwner())
                            <button type="button" class="btn btn-sm btn-outline-danger" data-order-id="{{ $o->id }}" data-order-number="{{ $o->order_number }}" onclick="deleteArchivedOrder(this)">
                                <i class="ph ph-trash"></i> Delete
                            </button>
                            @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        @if($showArchivedOrders)No archived orders yet.@else No orders yet. Click "New Order" to create one.@endif
                    </td>
                </tr>
            @endforelse
            @if(($organizeCommissaryOrders || $showArchivedOrders) && $orders->isNotEmpty())
                <tr id="orderFilterEmpty" hidden><td colspan="7" class="text-center text-muted py-4">No orders match these filters. Clear filters or choose another work queue.</td></tr>
            @endif
            </tbody>
        </table>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 px-3 py-3 no-print">
        <div class="text-muted small" id="ordersPagerInfo" role="status" aria-live="polite"></div>
        <nav class="hv-inv-pager" id="ordersPagerNav" aria-label="Orders pagination"></nav>
    </div>
</div>

@include('partials.order-prepare')

<!-- New Order Modal -->
@unless($isBM)
<div class="modal fade" id="orderModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="orderForm" onsubmit="submitOrder(event)">
                @csrf
                <div class="modal-header" style="background:var(--gold-soft);">
                    <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                        <i class="ph ph-shopping-cart me-1"></i> New Order
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Order for Branch <span class="text-danger">*</span></label>
                        <select class="form-select" name="destination_branch_id" id="o_branch_id" required>
                            @php $canEncodeAny = $user->isOwner() || $user->isCommissaryManager(); @endphp
                            @if(! $canEncodeAny)
                                @foreach($branches->where('branch_type', '!=', 'commissary') as $br)
                                    @if($br->id === $user->branch_id)
                                        <option value="{{ $br->id }}" selected>{{ $br->branch_name }}</option>
                                    @endif
                                @endforeach
                            @else
                                <option value="">Select branch</option>
                                @foreach($branches->where('branch_type', '!=', 'commissary') as $br)
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
                            <i class="ph ph-plus me-1"></i> Add Item
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
                    @include('partials.order-payment-fields', ['paymentPrefix' => 'newOrder'])
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon" id="btnOrder">
                        <i class="ph ph-shopping-cart me-1"></i> Place Order
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- View Order Modal -->
@endunless
@push('styles')
<style>
#viewModal .modal-content { border: 0; border-radius: 14px; overflow: hidden; box-shadow: 0 12px 44px rgba(0, 0, 0, 0.16); }
#viewModal .modal-header { padding: 1rem 1.25rem; }
#viewModal .modal-footer { padding: 0.85rem 1.25rem; }
#viewModalBody { padding: 1.25rem; }
#viewModalBody p { margin-bottom: 0.4rem; }
#viewModalBody section { background: #fafbfc; border-color: #e9ecef !important; border-radius: 10px; }
#viewModalBody .table thead th { color: #6b7280; font-weight: 600; font-size: 0.78rem; text-transform: uppercase; letter-spacing: 0.03em; border-bottom: 1px solid #e9ecef; }
#viewModalBody .table td, #viewModalBody .table th { border-color: #f1f3f5; }
#viewModalBody a[target="_blank"] { color: var(--maroon); font-weight: 600; }
</style>
@endpush
<div class="modal fade" id="viewModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header" style="background:#f6f7f9;border-bottom:1px solid #e9ecef;">
                <h5 class="modal-title" id="viewModalTitle" style="color:var(--maroon);font-weight:700;">
                    Order Details
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewModalBody">
                <div class="text-center py-4 text-muted">Loading...</div>
            </div>
            <div class="modal-footer" id="viewModalFooter" style="background:#f6f7f9;border-top:1px solid #e9ecef;">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

{{-- ═══ DECLINE ORDER MODAL ════════════════════════════ --}}
<div class="modal fade" id="declineModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#f8d7da;">
                <h5 class="modal-title" style="color:var(--danger);font-weight:700;"><i class="ph ph-x-circle me-1"></i> Decline Order</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="small text-muted mb-2">
                    <div><strong>Order:</strong> <span id="dmOrderNum"></span></div>
                    <div><strong>Branch:</strong> <span id="dmBranch"></span></div>
                    <div><strong>Amount:</strong> PHP <span id="dmAmount"></span></div>
                </div>
                <label class="form-label small fw-semibold mb-1">Reason for declining <span class="text-danger">*</span></label>
                <select class="form-select form-select-sm mb-2" id="dmReasonSelect" onchange="onDeclineReasonChange()">
                    <option value="">— Select a reason —</option>
                    <option value="Insufficient usable stock">Insufficient usable stock</option>
                    <option value="Expired/damaged available stock">Expired/damaged available stock</option>
                    <option value="Incorrect order">Incorrect order</option>
                    <option value="Other">Other</option>
                </select>
                <textarea class="form-control form-control-sm" id="dmReasonText" rows="2" maxlength="500" placeholder="Add details (required for Other)" style="display:none;"></textarea>
                <div class="text-danger small mt-1" id="dmError" style="display:none;"></div>
                <input type="hidden" id="dmOrderId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btnDecline" onclick="submitDecline()"><i class="ph ph-x-circle me-1"></i> Decline Order</button>
            </div>
        </div>
    </div>
</div>

@include('partials.receiving-issue-modal')
@endsection

@push('scripts')
<script>
const API  = '{{ route("orders.api") }}';
const CSRF = '{{ csrf_token() }}';
// Only Owner/Admin and the Commissary Manager may approve or decline a request.
const CAN_REVIEW = @json($user->isOwner() || $user->isMainBranchManager());

const products = @json($productsJson);

let orderModal, viewModal, declineModal;
let lineCounter = 0;
// The pending order currently open in the review interface (for its footer controls).
let currentReviewOrder = null;

document.addEventListener('DOMContentLoaded', function () {
    const orderModalElement = document.getElementById('orderModal');
    if (orderModalElement) {
        orderModal = new bootstrap.Modal(orderModalElement);
    }
    viewModal  = new bootstrap.Modal(document.getElementById('viewModal'));
    declineModal = new bootstrap.Modal(document.getElementById('declineModal'));
    filterOrders();
    @if($openOrderId > 0)
    viewOrder(@json($openOrderId));
    @endif
});

function openDeclineModal(orderId, orderNum, branch, amount) {
    document.getElementById('dmOrderId').value = orderId;
    document.getElementById('dmOrderNum').textContent = orderNum;
    document.getElementById('dmBranch').textContent = branch;
    document.getElementById('dmAmount').textContent = amount;
    document.getElementById('dmReasonSelect').value = '';
    var t = document.getElementById('dmReasonText'); t.value = ''; t.style.display = 'none';
    document.getElementById('dmError').style.display = 'none';
    declineModal.show();
}
function onDeclineReasonChange() {
    var sel = document.getElementById('dmReasonSelect').value;
    document.getElementById('dmReasonText').style.display = (sel === 'Other') ? 'block' : 'none';
}
async function submitDecline() {
    var sel = document.getElementById('dmReasonSelect').value;
    var extra = document.getElementById('dmReasonText').value.trim();
    var err = document.getElementById('dmError');
    var reason = sel === 'Other' ? extra : (extra ? sel + ' — ' + extra : sel);
    if (!sel) { err.textContent = 'Please select a reason.'; err.style.display = 'block'; return; }
    if (sel === 'Other' && !extra) { err.textContent = 'Please explain the reason.'; err.style.display = 'block'; return; }
    err.style.display = 'none';
    var btn = document.getElementById('btnDecline');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    var data = new FormData();
    data.append('action', 'decline'); data.append('id', document.getElementById('dmOrderId').value);
    data.append('reason', reason); data.append('_token', CSRF);
    try {
        var res = await fetch(API, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        var json = await res.json();
        if (json.ok) {
            declineModal.hide();
            if (viewModal) { viewModal.hide(); }
            hvOrderToast('Order declined. The reason was recorded.', 'danger');
            await refreshOrdersTable();
        } else {
            // Already-processed / stale state: tell the user and refresh the list.
            if (res.status === 409) {
                err.textContent = json.error || 'This order is no longer pending.';
                err.style.display = 'block';
                declineModal.hide();
                if (viewModal) { viewModal.hide(); }
                await refreshOrdersTable();
            } else {
                err.textContent = json.error || 'Could not decline.';
                err.style.display = 'block';
            }
            btn.disabled = false; btn.innerHTML = '<i class="ph ph-x-circle me-1"></i> Decline Order';
        }
    } catch (e) { err.textContent = 'Network error.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="ph ph-x-circle me-1"></i> Decline Order'; }
}

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
        '<td class="text-center"><button type="button" class="btn btn-sm btn-outline-danger" onclick="removeLine(' + lineCounter + ')"><i class="ph ph-trash"></i></button></td>';
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
    formData.append('destination_branch_id', document.getElementById('o_branch_id').value);
    formData.append('remarks', document.getElementById('o_remarks').value);
    formData.append('line_items', JSON.stringify(lineItems));
    if (!appendOrderPayment('newOrder', formData)) return;

    const btn = document.getElementById('btnOrder');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Placing...';

    try {
        const res = await fetch(API, { method: 'POST', body: formData, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (json.ok) { orderModal.hide(); location.reload(); }
        else { showOrderPaymentError('newOrder', orderPaymentError(json)); }
    } catch (err) { alert('Network error. Please check your connection.'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="ph ph-shopping-cart me-1"></i> Place Order'; }
}

async function setOrderArchive(button) {
    const restore = button.dataset.orderArchive === 'restore';
    const number = button.dataset.orderNumber;
    const result = await window.hvModal.confirm({
        title: (restore ? 'Restore order ' : 'Archive order ') + number + '?',
        message: restore
            ? 'This returns ' + number + ' to Order History. You can archive it again anytime.'
            : 'This moves ' + number + ' to Archived Orders. Its order, payment, and inventory records are retained, and it can be restored.',
        confirmText: restore ? 'Restore' : 'Archive',
        action: async function () {
            const data = new FormData();
            data.append('_token', CSRF);
            data.append('action', restore ? 'restore_order' : 'archive_order');
            data.append('id', button.dataset.orderId);
            const response = await fetch(API, {method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}});
            const json = await response.json();
            if (!json.ok) { return { ok: false, error: json.error || (json.errors ? Object.values(json.errors).flat().join(' ') : json.message) || 'Could not update the order archive.' }; }
            return { ok: true, redirect: json.redirect_url };
        },
        success: { title: restore ? 'Restored to History' : 'Moved to Archived Orders', message: 'Order ' + number + (restore ? ' is back in Order History.' : ' has been archived.') },
    });
    if (result && result.ok) { location.assign(result.redirect); }
}

async function deleteArchivedOrder(button) {
    const number = button.dataset.orderNumber;
    const result = await window.hvModal.confirm({
        title: 'Delete order ' + number + '?',
        message: 'This permanently removes the order and all its items and production records. It cannot be undone.',
        confirmText: 'Delete',
        action: async function () {
            const data = new FormData();
            data.append('_token', CSRF);
            data.append('action', 'delete_order');
            data.append('id', button.dataset.orderId);
            const response = await fetch(API, {method: 'POST', body: data, headers: {'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json'}});
            const json = await response.json();
            if (!json.ok) { return { ok: false, error: json.error || json.message || 'Could not delete the order.' }; }
            return { ok: true, redirect: json.redirect_url || '{{ route('orders', ['archived' => 1]) }}' };
        },
        success: { title: 'Deleted successfully', message: 'Order ' + number + ' has been permanently deleted.' },
    });
    if (result && result.ok) { location.assign(result.redirect); }
}

async function updateStatus(orderId, newStatus) {
    const labels = { approved: 'approve', preparing: 'start preparing', delivering: 'mark as delivering', delivered: 'mark as delivered (stock will be added to the branch)', settled: 'settle', cancelled: 'cancel' };
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
    renderReviewFooter(null);
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
            const statusColors = { pending: 'warning', approved: 'info', preparing: 'info', delivering: 'info', delivered: 'success', settled: 'success', cancelled: 'danger', declined: 'danger' };
            const escH = function(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); };
            let html = '<div class="row mb-3"><div class="col-md-6">';
            html += '<p class="mb-1"><strong>Order #:</strong> ' + o.order_number + '</p>';
            html += '<p class="mb-1"><strong>Branch:</strong> ' + o.branch_name + '</p>';
            html += '<p class="mb-1"><strong>Ordered by:</strong> ' + (o.ordered_by_name || '—') + '</p>';
            html += '<p class="mb-1"><strong>Date:</strong> ' + o.created_at + '</p>';
            html += '</div><div class="col-md-6">';
            var statusLabel = o.workflow_stage || o.status;
            html += '<p class="mb-1"><strong>Status:</strong> <span class="hv-badge hv-badge-' + (statusColors[o.status] || 'info') + '">' + statusLabel + '</span></p>';
            if (o.approved_by_name) html += '<p class="mb-1"><strong>Approved by:</strong> ' + o.approved_by_name + '</p>';
            if (o.approved_at) html += '<p class="mb-1"><strong>Approved at:</strong> ' + o.approved_at + '</p>';
            if (o.prepared_by_name) html += '<p class="mb-1"><strong>Prepared by:</strong> ' + o.prepared_by_name + '</p>';
            if (o.prepared_at) html += '<p class="mb-1"><strong>Prepared at:</strong> ' + o.prepared_at + '</p>';
            if (o.delivered_at) html += '<p class="mb-1"><strong>Delivered at:</strong> ' + o.delivered_at + '</p>';
            if (o.confirmed_by_name) html += '<p class="mb-1"><strong>Confirmed by:</strong> ' + o.confirmed_by_name + '</p>';
            if (o.settled_at) html += '<p class="mb-1"><strong>Settled at:</strong> ' + o.settled_at + '</p>';
            if (o.expected_delivery_date) html += '<p class="mb-1"><strong>Expected delivery:</strong> ' + escH(o.expected_delivery_date) + (o.delivery_date_note ? ' <span class="text-muted small">(' + escH(o.delivery_date_note) + ')</span>' : '') + '</p>';
            if (o.remarks) html += '<p class="mb-1"><strong>Remarks:</strong> ' + escH(o.remarks) + '</p>';
            html += '</div></div>';
            if (o.actions && o.actions.can_set_delivery_date) {
                var ddVal = o.expected_delivery_date_input || '';
                html += '<div class="border rounded p-3 mb-3 bg-light"><div class="fw-semibold small mb-2"><i class="ph ph-truck me-1"></i>Commissary delivery schedule</div>'
                    + '<div class="row g-2 align-items-end">'
                    + '<div class="col-sm-5"><label class="form-label small mb-1" for="deliveryDateInput">Expected delivery date</label><input type="date" id="deliveryDateInput" class="form-control form-control-sm" value="' + ddVal + '"></div>'
                    + '<div class="col-sm-5"><label class="form-label small mb-1" for="deliveryNoteInput">Note (optional)</label><input type="text" id="deliveryNoteInput" class="form-control form-control-sm" maxlength="255" placeholder="e.g. delayed by typhoon" value="' + escH(o.delivery_date_note || '') + '"></div>'
                    + '<div class="col-sm-2"><button type="button" class="btn btn-sm btn-maroon w-100" onclick="setDeliveryDate(' + o.id + ')">Save</button></div>'
                    + '</div><div class="small text-muted mt-2">Saving notifies ' + escH(o.branch_name) + ' in their order notifications.</div></div>';
            }
            if (o.proforma_awaiting_approval && !o.proforma_url) html += '<p class="small text-muted">Proforma printing becomes available after confirmation by the Owner, Admin, or Commissary Manager.</p>';
            if (o.payment_reference) {
                html += '<section class="border rounded p-3 mb-3"><strong>GCash proof submitted — for manual review</strong><p class="mb-1">Reference: ' + escH(o.payment_reference) + '</p><p class="small text-muted">Check the received funds, amount, and reference before approving. Uploading proof does not verify payment.</p>';
                if (o.payment_screenshot_url) html += '<a href="' + escH(o.payment_screenshot_url) + '" target="_blank" rel="noopener">Open payment screenshot<img src="' + escH(o.payment_screenshot_url) + '" alt="Submitted GCash payment screenshot" class="d-block mt-2 rounded" style="max-width:100%;max-height:220px;object-fit:contain"></a>';
                html += '</section>';
            }
            if (o.status === 'declined') {
                html += '<div class="alert alert-danger py-2 small"><strong>Declined</strong>'
                    + (o.declined_by_name ? ' by ' + escH(o.declined_by_name) : '')
                    + (o.declined_at ? ' on ' + escH(o.declined_at) : '')
                    + '<br><strong>Reason:</strong> ' + escH(o.decline_reason || '—') + '</div>';
            }
            var hasReceived = o.items.some(function(i) { return i.received_quantity !== null; });
            var cols = hasReceived ? 5 : 4;
            html += '<table class="table table-sm align-middle mb-0"><thead><tr><th>Product</th><th class="text-center">Qty</th>';
            if (hasReceived) html += '<th class="text-center">Received</th>';
            html += '<th class="text-end">Price</th><th class="text-end">Subtotal</th></tr></thead><tbody>';
            o.items.forEach(function(item) {
                html += '<tr><td>' + item.item_name + ' <span class="text-muted small">(' + item.unit + ')</span></td>';
                html += '<td class="text-center">Requested: ' + item.quantity + '<br>Prepared: ' + (item.fulfilled_quantity ?? '—') + '<br>Released: ' + (item.released_quantity || 0) + (item.fulfillment_reason ? '<br><small>' + escH(item.fulfillment_reason) + '</small>' : '') + '</td>';
                if (hasReceived) {
                    var rcv = item.received_quantity !== null ? item.received_quantity : '—';
                    var cls = (item.received_quantity !== null && item.received_quantity != (item.fulfilled_quantity ?? item.quantity)) ? ' class="text-danger fw-semibold"' : '';
                    html += '<td class="text-center"' + cls + '>' + rcv + '</td>';
                }
                html += '<td class="text-end">&#8369;' + parseFloat(item.unit_price).toFixed(2) + '</td>';
                html += '<td class="text-end fw-semibold">&#8369;' + parseFloat(item.subtotal).toFixed(2) + '</td></tr>';
            });
            html += '</tbody><tfoot><tr><td colspan="' + (cols - 1) + '" class="text-end fw-bold">Total:</td>';
            html += '<td class="text-end fw-bold">&#8369;' + parseFloat(o.fulfilled_amount ?? o.total_amount).toFixed(2) + '</td></tr></tfoot></table>';

            if (o.discrepancies && o.discrepancies.length > 0) {
                html += '<div class="mt-3"><h6 class="text-danger"><i class="ph ph-warning me-1"></i>Delivery Discrepancies</h6>';
                html += '<table class="table table-sm table-bordered mb-0"><thead><tr><th>Item</th><th class="text-center">Expected</th><th class="text-center">Received</th><th>Reason</th><th>Remarks</th></tr></thead><tbody>';
                o.discrepancies.forEach(function(d) {
                    html += '<tr><td>' + d.item_name + '</td><td class="text-center">' + d.expected_quantity + '</td><td class="text-center text-danger fw-semibold">' + d.received_quantity + '</td><td>' + d.reason + '</td><td>' + (d.remarks || '—') + '</td></tr>';
                });
                html += '</tbody></table></div>';
            }

            body.innerHTML = html;
            renderReviewFooter(o);
            void refreshOrdersTable();
        } else {
            body.innerHTML = '<div class="text-danger py-3">' + (json.error || 'Failed to load.') + '</div>';
            renderReviewFooter(null);
        }
    } catch (err) { body.innerHTML = '<div class="text-danger py-3">Network error.</div>'; renderReviewFooter(null); }
}

// Build the review footer: a pending order an authorized user can act on gets
// Approve Request / Decline Request; everything else just gets Close. Opening
// the review NEVER changes status — the decision is an explicit footer action.
function renderReviewFooter(o) {
    const footer = document.getElementById('viewModalFooter');
    const title = document.getElementById('viewModalTitle');
    const reviewable = o && o.status === 'pending' && CAN_REVIEW;
    currentReviewOrder = reviewable ? o : null;
    if (title) {
        title.innerHTML = reviewable
            ? 'Review Order — ' + escapeHtml(o.order_number)
            : 'Order Details';
    }
    let html = '<button type="button" class="btn btn-light" data-bs-dismiss="modal">' + (reviewable ? 'Back' : 'Close') + '</button>';
    @unless($user->isBranchManager())
    if (o && o.proforma_url) {
        html += '<a class="btn btn-outline-secondary" href="' + escapeHtml(o.proforma_url) + '" target="_blank" rel="noopener">Print proforma invoice</a>';
    }
    @endunless
    if (reviewable) {
        html += '<button type="button" class="btn btn-outline-danger" id="btnReviewDecline" onclick="declineReviewOrder()"><i class="ph ph-x-circle me-1"></i> Decline Request</button>';
        html += '<button type="button" class="btn btn-success" id="btnReviewApprove" onclick="approveReviewOrder()"><i class="ph ph-check-circle me-1"></i> Approve Request</button>';
    }
    if (o && o.status === 'delivering' && o.actions) {
        if (o.actions.labels_url) {
            html += '<button type="button" class="btn btn-outline-secondary" id="detailsPrintLabels">Print Labels</button>';
        }
        @unless($user->isAdmin())
        if (o.actions.can_receive) {
            html += '<button type="button" class="btn btn-success" id="btnForceReceive">Force Receive</button>';
        }
        @endunless
    }
    footer.innerHTML = html;
    window.HVReceivingIssue.addAction(footer, o);
    const labelsButton = document.getElementById('detailsPrintLabels');
    if (labelsButton) labelsButton.onclick = () => hvPrintLabelsUrl(o.actions.labels_url);
    const receiveButton = document.getElementById('btnForceReceive');
    if (receiveButton) receiveButton.onclick = () => forceReceiveOrder(o.id, o.order_number);
}

function escapeHtml(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }

// Receive the whole order in full from the modal (confirm first). Reuses the
// order-level receive path (no QR scan = full acceptance) and closes the order.
async function forceReceiveOrder(orderId, number) {
    const result = await window.hvModal.confirm({
        title: 'Receive order ' + number + '?',
        message: 'Confirm you received this order in full. This marks it delivered and adds the goods to your branch stock. It cannot be undone.',
        confirmText: 'Receive',
        action: async function () {
            const data = new FormData();
            data.append('_token', CSRF);
            data.append('action', 'receive_scan');
            data.append('id', orderId);
            const response = await fetch(API, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json' } });
            const json = await response.json();
            if (!json.ok) { return { ok: false, error: json.error || json.message || 'Could not receive the order.' }; }
            return { ok: true };
        },
        success: { title: 'Order received', message: 'Order ' + number + ' has been received and closed.' },
    });
    if (result && result.ok) { if (viewModal) { viewModal.hide(); } await refreshOrdersTable(); }
}

// Approve the pending order under review. Reuses the existing review_order
// transition (which locks + revalidates pending server-side). Does not prepare,
// dispatch, receive, close, or verify payment.
// Commissary sets/updates the expected delivery date; notifies the branch.
async function setDeliveryDate(orderId) {
    var dateEl = document.getElementById('deliveryDateInput');
    var noteEl = document.getElementById('deliveryNoteInput');
    if (!dateEl || !dateEl.value) { hvOrderToast('Pick a delivery date first.', 'error'); return; }
    var btn = dateEl.closest('.border').querySelector('button');
    var original = btn ? btn.innerHTML : '';
    if (btn) { btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>'; }
    var data = new FormData();
    data.append('action', 'set_delivery_date');
    data.append('id', orderId);
    data.append('delivery_date', dateEl.value);
    data.append('delivery_note', noteEl ? noteEl.value : '');
    data.append('_token', CSRF);
    try {
        var res = await fetch(API, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        var json = await res.json();
        if (json.ok) {
            hvOrderToast(json.message || 'Delivery date saved.', 'success');
            await viewOrder(orderId);
        } else {
            var msg = json.error || (json.errors ? Object.values(json.errors).flat().join(' ') : 'Could not save the delivery date.');
            hvOrderToast(msg, 'error');
            if (btn) { btn.disabled = false; btn.innerHTML = original; }
        }
    } catch (e) {
        hvOrderToast('Network error. Please try again.', 'error');
        if (btn) { btn.disabled = false; btn.innerHTML = original; }
    }
}

async function approveReviewOrder() {
    if (!currentReviewOrder) { return; }
    const approveBtn = document.getElementById('btnReviewApprove');
    const declineBtn = document.getElementById('btnReviewDecline');
    const original = approveBtn.innerHTML;
    approveBtn.disabled = true; if (declineBtn) { declineBtn.disabled = true; }
    approveBtn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Approving...';
    const data = new FormData();
    data.append('action', 'review_order');
    data.append('id', currentReviewOrder.id);
    data.append('_token', CSRF);
    try {
        const res = await fetch(API, { method: 'POST', body: data, headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        const json = await res.json();
        if (json.ok) {
            viewModal.hide();
            hvOrderToast(json.message || 'Request approved.', 'success');
            await refreshOrdersTable();
        } else {
            const msg = json.error || (json.errors ? Object.values(json.errors).flat().join(' ') : 'Could not approve this request.');
            hvReviewError(msg);
            if (res.status === 409) { viewModal.hide(); await refreshOrdersTable(); return; }
            approveBtn.disabled = false; approveBtn.innerHTML = original;
            if (declineBtn) { declineBtn.disabled = false; }
        }
    } catch (e) {
        hvReviewError('Network error. Please try again.');
        approveBtn.disabled = false; approveBtn.innerHTML = original;
        if (declineBtn) { declineBtn.disabled = false; }
    }
}

// Decline the pending order under review — reuses the existing decline modal so
// a nonblank reason is captured and validated by the existing flow.
function declineReviewOrder() {
    if (!currentReviewOrder) { return; }
    const o = currentReviewOrder;
    openDeclineModal(o.id, o.order_number, o.branch_name, parseFloat(o.fulfilled_amount ?? o.total_amount).toFixed(2));
}

// Inline error banner inside the open review interface (keeps it open on failure).
function hvReviewError(message) {
    const body = document.getElementById('viewModalBody');
    let banner = document.getElementById('reviewErrorBanner');
    if (!banner) {
        banner = document.createElement('div');
        banner.id = 'reviewErrorBanner';
        banner.className = 'alert alert-danger py-2 small mb-3';
        body.prepend(banner);
    }
    banner.textContent = message;
    banner.scrollIntoView({ block: 'nearest' });
}

// Reload only the orders table (no full browser reload) and re-apply the active
// queue, search, filters and sort so the user's view is preserved.
let ordersRefreshInFlight = null;
async function refreshOrdersTable() {
    if (ordersRefreshInFlight) { return ordersRefreshInFlight; }
    ordersRefreshInFlight = (async function () {
        try {
            const res = await fetch(window.location.href, { cache: 'no-store', headers: { 'Accept': 'text/html', 'X-Requested-With': 'XMLHttpRequest' } });
            if (!res.ok || res.redirected) { return false; }
            const text = await res.text();
            const fresh = new DOMParser().parseFromString(text, 'text/html').getElementById('ordersTableBody');
            const current = document.getElementById('ordersTableBody');
            if (!fresh || !current) { return false; }
            current.replaceWith(fresh);
            filterOrders();
            return true;
        } catch (e) {
            return false;
        }
    })();
    try { return await ordersRefreshInFlight; }
    finally { ordersRefreshInFlight = null; }
}

function refreshVisibleOrders() {
    if (!document.hidden) { void refreshOrdersTable(); }
}
window.addEventListener('focus', refreshVisibleOrders);
document.addEventListener('visibilitychange', refreshVisibleOrders);
window.addEventListener('pageshow', function (event) {
    if (event.persisted) { refreshVisibleOrders(); }
});

// Lightweight success/error toast reusing the shared toast container + styles.
function hvOrderToast(message, severity) {
    const box = document.getElementById('toastContainer');
    if (!box) { return; }
    const sev = severity === 'danger' ? 'danger' : 'success';
    const color = sev === 'danger' ? 'var(--danger)' : '#1a7f37';
    const icon = sev === 'danger' ? 'ph-x-circle' : 'ph-check-circle';
    const toast = document.createElement('div');
    toast.className = 'hv-toast severity-' + sev;
    toast.setAttribute('role', 'status');
    toast.innerHTML = '<i class="ph ' + icon + ' hv-toast-icon" style="color:' + color + ';"></i>'
        + '<div class="hv-toast-body"><div class="hv-toast-title" style="color:' + color + ';">'
        + (sev === 'danger' ? 'Order Declined' : 'Success') + '</div>'
        + '<div class="hv-toast-msg">' + escapeHtml(message) + '</div></div>'
        + '<button class="hv-toast-close" type="button" aria-label="Dismiss">&times;</button>';
    box.appendChild(toast);
    const remove = function () { toast.classList.add('fade-out'); setTimeout(function () { toast.remove(); }, 300); };
    toast.querySelector('.hv-toast-close').onclick = remove;
    setTimeout(remove, 4000);
}

let activeOrderQueue = @json($organizeCommissaryOrders ? $defaultOrderQueue : null);
let orderPage = 1;
const ORDERS_PER_PAGE = 10;

function selectOrderQueue(queue) {
    activeOrderQueue = queue;
    filterOrders();
}

function clearOrderFilters() {
    document.getElementById('orderSearch').value = '';
    document.getElementById('filterBranch').value = '';
    document.getElementById('filterStatus').value = '';
    if (document.getElementById('orderSort')) document.getElementById('orderSort').value = 'newest';
    filterOrders();
}

function filterOrders() {
    orderPage = 1;
    const search = document.getElementById('orderSearch').value.trim().toLowerCase();
    const branch = document.getElementById('filterBranch').value;
    const status = document.getElementById('filterStatus').value;
    const rows = [...document.querySelectorAll('.order-row')];
    const queueCounts = {all:0};
    document.querySelectorAll('[data-queue-count]').forEach(badge => queueCounts[badge.dataset.queueCount] = 0);
    let visible = 0;
    rows.forEach(function (row) {
        let matches = true;
        if (search && !(row.dataset.search || row.dataset.number).includes(search)) matches = false;
        if (branch && row.dataset.branch !== branch) matches = false;
        if (status && row.dataset.status !== status) matches = false;
        if (matches) {
            queueCounts.all++;
            if (row.dataset.queue && row.dataset.queue !== 'all') queueCounts[row.dataset.queue]++;
        }
        const queueOk = !activeOrderQueue || activeOrderQueue === 'all' || activeOrderQueue === 'active' || row.dataset.queue === activeOrderQueue;
        const anyFilter = search || branch || status;
        const show = matches && (anyFilter || queueOk);
        row.dataset.shown = show ? '1' : '0';
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });
    queueCounts.active = queueCounts.all;
    document.getElementById('orderCount').textContent = visible + ' order' + (visible !== 1 ? 's' : '');
    const empty = document.getElementById('orderFilterEmpty');
    if (empty) empty.hidden = visible > 0;
    document.querySelectorAll('[data-order-queue]').forEach(button => button.setAttribute('aria-pressed', button.dataset.orderQueue === activeOrderQueue ? 'true' : 'false'));
    document.querySelectorAll('[data-queue-count]').forEach(badge => badge.textContent = queueCounts[badge.dataset.queueCount]);
    const hint = document.getElementById('orderQueueHint');
    if (hint) {
        const hints = {
            active:'All orders across every stage — narrow with the status filter.',
            review:'Branch requests awaiting approval or decline.',
            prepare:'Reviewed requests that still need preparation or picking.',
            release:'Prepared goods with quantities still to scan out, including partial releases.',
            transit:'Released orders awaiting destination receiving or confirmation.',
            history:'',
            all:@json($showArchivedOrders ? ($canArchiveOrders ? 'Archived records are retained. Restore an order to return it to History.' : 'Archived records are retained for monitoring. Review each order and who archived it.') : 'All unarchived orders across every stage.')
        };
        hint.textContent = hints[activeOrderQueue] ?? hints.all;
        hint.hidden = !hint.textContent;
    }
    const sort = document.getElementById('orderSort');
    if (sort) {
        rows.sort((a,b) => sort.value === 'branch' ? a.dataset.branch.localeCompare(b.dataset.branch) || Number(b.dataset.created)-Number(a.dataset.created)
            : sort.value === 'oldest' ? Number(a.dataset.created)-Number(b.dataset.created) : Number(b.dataset.created)-Number(a.dataset.created));
        const body = document.getElementById('ordersTableBody');
        rows.forEach(row => body.insertBefore(row, empty));
    }
    renderOrderPage();
}

function renderOrderPage() {
    const shown = [...document.querySelectorAll('.order-row')].filter(r => r.dataset.shown === '1');
    const pages = Math.max(1, Math.ceil(shown.length / ORDERS_PER_PAGE));
    if (orderPage > pages) { orderPage = pages; }
    if (orderPage < 1) { orderPage = 1; }
    const start = (orderPage - 1) * ORDERS_PER_PAGE, end = start + ORDERS_PER_PAGE;
    shown.forEach((r, i) => { r.style.display = (i >= start && i < end) ? '' : 'none'; });

    const info = document.getElementById('ordersPagerInfo');
    const nav = document.getElementById('ordersPagerNav');
    if (info) {
        info.textContent = shown.length
            ? 'Showing ' + (start + 1) + '–' + Math.min(end, shown.length) + ' of ' + shown.length + ' order' + (shown.length !== 1 ? 's' : '')
            : '';
    }
    if (!nav) { return; }
    nav.innerHTML = '';
    if (pages <= 1) { return; }
    const mk = function (label, target, opts) {
        opts = opts || {};
        const b = document.createElement('button');
        b.type = 'button';
        b.className = 'hv-page-btn' + (opts.active ? ' active' : '');
        b.innerHTML = label;
        if (opts.disabled) { b.disabled = true; }
        if (opts.active) { b.setAttribute('aria-current', 'page'); }
        if (!opts.disabled && !opts.active) { b.addEventListener('click', function () { orderPage = target; renderOrderPage(); }); }
        return b;
    };
    const win = 2, s = Math.max(1, orderPage - win), e = Math.min(pages, orderPage + win);
    const seq = [];
    if (s > 1) { seq.push(1); if (s > 2) { seq.push('…'); } }
    for (let p = s; p <= e; p++) { seq.push(p); }
    if (e < pages) { if (e < pages - 1) { seq.push('…'); } seq.push(pages); }
    nav.appendChild(mk('<i class="ph ph-caret-left"></i> Prev', orderPage - 1, { disabled: orderPage === 1 }));
    seq.forEach(function (p) {
        if (p === '…') { const g = document.createElement('span'); g.className = 'hv-page-gap'; g.textContent = '…'; nav.appendChild(g); }
        else { nav.appendChild(mk(String(p), p, { active: p === orderPage })); }
    });
    nav.appendChild(mk('Next <i class="ph ph-caret-right"></i>', orderPage + 1, { disabled: orderPage === pages }));
}

window.addEventListener('beforeprint', function () { document.querySelectorAll('.order-row').forEach(function (r) { if (r.dataset.shown === '1') { r.style.display = ''; } }); });
window.addEventListener('afterprint', renderOrderPage);

</script>
@endpush
