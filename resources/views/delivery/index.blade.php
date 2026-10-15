@extends('layouts.app')

@section('title', 'Delivery')

@section('content')

@php
    $statusMap = [
        'pending'    => ['hv-badge-warning',  'Pending'],
        'approved'   => ['hv-badge-info',     'Reviewed / Awaiting Preparation'],
        'preparing'  => ['hv-badge-info',     'Preparing'],
        'delivering' => ['hv-badge-info',     'Delivering'],
        'delivered'  => ['hv-badge-success',  'Delivered'],
        'settled'    => ['hv-badge-success',  'Settled'],
        'cancelled'  => ['hv-badge-danger',   'Cancelled'],
        'declined'   => ['hv-badge-danger',   'Declined'],
    ];
    $isOwner   = $user->isOwner();
    $isMBM     = $user->isMainBranchManager();
    $isMainRole = $user->isMainBranchRole();
    $isWarehouse = $user->isWarehouseRole();
    $isBM      = $user->isBranchManager();

    $canApprove       = $isOwner || $isMBM;      // approve + produce, print, decline
    $canDispatch      = $isOwner || $isMainRole; // scan-out
    $canManualDeliver = $isOwner || $isMBM;      // privileged scan-out fallback

    $activeTab = in_array(request('tab'), ['history', 'archived'], true) ? request('tab') : 'active';
    $canArchive = $isOwner || $isMBM || $isBM;
@endphp

<div class="hv-page-header">
    <div>
        <h1 class="h5 mb-1" style="color:var(--maroon);font-weight:700;">Delivery</h1>
        <span class="text-muted small">
            @if($isWarehouse)
                Prepare, dispatch, and track shipments to every branch.
            @else
                Confirm, track, and receive your branch's shipments.
            @endif
        </span>
    </div>
    <div class="btn-group" role="group" aria-label="Delivery view">
        <button type="button" class="btn btn-sm hv-tab-btn {{ $activeTab === 'active' ? 'active' : '' }}"
                id="tabBtnActive" onclick="showDeliveryTab('active')">
            <i class="bi bi-truck me-1"></i> Active Deliveries
            @if($active->count() > 0)<span class="hv-tab-count">{{ $active->count() }}</span>@endif
        </button>
        <button type="button" class="btn btn-sm hv-tab-btn {{ $activeTab === 'history' ? 'active' : '' }}"
                id="tabBtnHistory" onclick="showDeliveryTab('history')">
            <i class="bi bi-clock-history me-1"></i> Delivery History
        </button>
        <button type="button" class="btn btn-sm hv-tab-btn {{ $activeTab === 'archived' ? 'active' : '' }}"
                id="tabBtnArchived" onclick="showDeliveryTab('archived')">
            <i class="bi bi-archive me-1"></i> Archived
            @if($archived->count() > 0)<span class="hv-tab-count">{{ $archived->count() }}</span>@endif
        </button>
    </div>
</div>

<div class="hv-card p-3 mb-3 small">
    <strong>Commissary order flow:</strong> Branch requests &rarr; Main Branch reviews &rarr; Prepare / Pick &rarr; Print labels &rarr; Scan out for delivery &rarr; Branch receives.
    <div class="text-muted mt-1">Preparing records actual quantities. Release changes Main Branch inventory; receiving adds accepted goods to the destination. Printing alone does not release goods.</div>
</div>

{{-- ═══ ACTIVE DELIVERIES — grouped by branch ═══════════════ --}}
<div id="tabActive" class="{{ $activeTab === 'active' ? '' : 'd-none' }}">
@if($branches->isEmpty())
    <div class="hv-card p-5 text-center">
        <i class="bi bi-check-circle d-block mb-2" style="font-size:2.25rem;color:var(--success);"></i>
        <p class="mb-0 text-muted">No active deliveries right now.</p>
    </div>
@else
    <div class="hv-branch-list">
    @foreach($branches as $b)
        @php $collapseId = 'branch'.$b['branch_id']; $openByDefault = $b['new'] > 0; @endphp
        <div class="hv-card p-0 mb-2 overflow-hidden">
            <button class="hv-branch-head {{ $openByDefault ? '' : 'collapsed' }}" type="button"
                    data-bs-toggle="collapse" data-bs-target="#{{ $collapseId }}"
                    aria-expanded="{{ $openByDefault ? 'true' : 'false' }}" aria-controls="{{ $collapseId }}">
                <span class="hv-branch-name">
                    <i class="bi bi-shop me-2" style="color:var(--maroon);"></i>{{ $b['branch_name'] }}
                </span>
                <span class="hv-branch-meta">
                    @if($b['new'] > 0)
                        <span class="hv-notif" title="{{ $b['new'] }} new order{{ $b['new'] !== 1 ? 's' : '' }} awaiting approval">{{ $b['new'] }} new</span>
                    @endif
                    <span class="hv-branch-count">{{ $b['count'] }} active</span>
                    <i class="bi bi-chevron-down hv-branch-chevron"></i>
                </span>
            </button>
            <div id="{{ $collapseId }}" class="collapse {{ $openByDefault ? 'show' : '' }}">
                <div class="table-responsive border-top">
                    <table class="table table-hover align-middle mb-0" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th class="ps-3">Order #</th>
                                <th>Ordered By</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Status</th>
                                <th>Updated</th>
                                <th class="text-end pe-3">Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($b['orders'] as $o)
                            @php $sb = $statusMap[$o->status] ?? ['hv-badge-info', $o->status]; @endphp
                            <tr>
                                <td class="ps-3 fw-semibold">{{ $o->order_number }}</td>
                                <td class="text-muted">{{ $o->ordered_by_name ?? '—' }}</td>
                                <td class="text-end">{{ number_format($o->fulfilled_amount ?? $o->total_amount, 2) }}</td>
                                <td class="text-center">
                                    <span class="hv-badge {{ $sb[0] }}">{{ \App\Services\OrderLabelService::stageLabel($o) }}</span>
                                </td>
                                <td class="text-muted">{{ \Carbon\Carbon::parse($o->updated_at)->format('M j, g:i A') }}</td>
                                <td>
                                    <div class="hv-del-actions">
                                    @if($o->piece_outputs && ($isOwner || $isMBM) && in_array($o->status, ['approved','preparing','delivering']) && $o->released_units < $o->prepared_units)
                                        <button class="btn btn-sm btn-maroon" title="Release this shipment" onclick="openManualDeliver({{ $o->id }}, '{{ e($o->order_number) }}', '{{ e($o->branch_name) }}')"><i class="bi bi-hand-thumbs-up"></i> Manual Release</button>
                                    @endif
                                    @if($o->status === 'delivering' && (!$o->piece_outputs || ($o->prepared_units > 0 && $o->released_units >= $o->prepared_units)) && ($isOwner || ($isBM && (int)$o->branch_id === (int)$user->branch_id)))
                                        <button class="btn btn-sm btn-success" onclick="openConfirmModal({{ $o->id }}, '{{ e($o->order_number) }}')"><i class="bi bi-box-arrow-in-down"></i> Receive / Confirm</button>
                                    @endif
                                        <button class="btn btn-sm btn-ghost-icon" title="Shipment / production details" aria-label="View details"
                                                onclick="viewOrder({{ $o->id }})">
                                            <i class="bi bi-eye"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    @endforeach
    </div>
    <p class="text-muted small mt-2">
        <i class="bi bi-info-circle me-1"></i>
        {{ $active->count() }} active deliver{{ $active->count() !== 1 ? 'ies' : 'y' }} across {{ $branches->count() }} branch{{ $branches->count() !== 1 ? 'es' : '' }}.
    </p>
@endif
</div>

{{-- ═══ DELIVERY HISTORY ════════════════════════════════════ --}}
<div id="tabHistory" class="{{ $activeTab === 'history' ? '' : 'd-none' }}">
    <div class="hv-card p-3 mb-2">
        <div class="row g-2 align-items-center">
            <div class="col-sm">
                <input type="search" class="form-control form-control-sm" id="histSearch"
                       placeholder="Search order # or branch…" oninput="filterHistory()">
            </div>
            <div class="col-sm-auto">
                <select class="form-select form-select-sm" id="histStatus" onchange="filterHistory()">
                    <option value="">All statuses</option>
                    <option value="delivered">Delivered</option>
                    <option value="settled">Settled</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="declined">Declined</option>
                </select>
            </div>
        </div>
    </div>
    @if($history->isEmpty())
        <div class="hv-card p-5 text-center">
            <i class="bi bi-archive d-block mb-2" style="font-size:2.25rem;color:var(--maroon);opacity:.5;"></i>
            <p class="mb-0 text-muted">No delivery history yet.</p>
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
                        <th class="text-center"></th>
                    </tr>
                </thead>
                <tbody id="histBody">
                @foreach($history as $o)
                    @php $sb = $statusMap[$o->status] ?? ['hv-badge-secondary', $o->status]; @endphp
                    <tr class="hist-row" data-number="{{ strtolower($o->order_number) }}"
                        data-branch="{{ strtolower($o->branch_name) }}" data-status="{{ $o->status }}">
                        <td class="ps-3 fw-semibold">{{ $o->order_number }}</td>
                        <td>{{ $o->branch_name }}</td>
                        <td class="text-muted">{{ $o->ordered_by_name ?? '—' }}</td>
                        <td class="text-end">{{ number_format($o->fulfilled_amount ?? $o->total_amount, 2) }}</td>
                        <td class="text-center"><span class="hv-badge {{ $sb[0] }}">{{ $sb[1] }}</span></td>
                        <td class="text-muted">{{ \Carbon\Carbon::parse($o->updated_at)->format('M j, Y g:i A') }}</td>
                        <td>
                            <div class="hv-del-actions">
                                @if($canArchive && (!$isBM || (int)$o->branch_id === (int)$user->branch_id))
                                <button class="btn btn-sm btn-outline-secondary" title="Move to Archived"
                                        onclick="archiveOrder({{ $o->id }}, '{{ e($o->order_number) }}')"><i class="bi bi-archive"></i> Archive</button>
                                @endif
                                <button class="btn btn-sm btn-ghost-icon" title="View record" aria-label="View record" onclick="viewOrder({{ $o->id }})">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    <p class="text-muted small mt-2" id="histEmpty" style="display:none;">
        <i class="bi bi-search me-1"></i> No records match your filter.
    </p>
    @endif
</div>

{{-- ═══ ARCHIVED ═══════════════════════════════════════════ --}}
<div id="tabArchived" class="{{ $activeTab === 'archived' ? '' : 'd-none' }}">
    @if($archived->isEmpty())
        <div class="hv-card p-5 text-center">
            <i class="bi bi-archive d-block mb-2" style="font-size:2.25rem;color:var(--maroon);opacity:.5;"></i>
            <p class="mb-0 text-muted">No archived orders.</p>
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
                        <th>Archived</th>
                        <th class="text-end pe-3">Action</th>
                    </tr>
                </thead>
                <tbody>
                @foreach($archived as $o)
                    @php $sb = $statusMap[$o->status] ?? ['hv-badge-secondary', $o->status]; @endphp
                    <tr>
                        <td class="ps-3 fw-semibold">{{ $o->order_number }}</td>
                        <td>{{ $o->branch_name }}</td>
                        <td class="text-muted">{{ $o->ordered_by_name ?? '—' }}</td>
                        <td class="text-end">{{ number_format($o->fulfilled_amount ?? $o->total_amount, 2) }}</td>
                        <td class="text-center"><span class="hv-badge {{ $sb[0] }}">{{ $sb[1] }}</span></td>
                        <td class="text-muted">{{ $o->archived_at ? \Carbon\Carbon::parse($o->archived_at)->format('M j, Y g:i A') : '—' }}</td>
                        <td>
                            <div class="hv-del-actions">
                                @if($canArchive && (!$isBM || (int)$o->branch_id === (int)$user->branch_id))
                                <button class="btn btn-sm btn-maroon" title="Restore to History"
                                        onclick="restoreOrder({{ $o->id }}, '{{ e($o->order_number) }}')"><i class="bi bi-arrow-counterclockwise"></i> Restore</button>
                                @endif
                                @if($isOwner)
                                <button class="btn btn-sm btn-outline-danger" title="Delete permanently"
                                        onclick="deleteOrder({{ $o->id }}, '{{ e($o->order_number) }}')"><i class="bi bi-trash"></i> Delete</button>
                                @endif
                                <button class="btn btn-sm btn-ghost-icon" title="View record" aria-label="View record" onclick="viewOrder({{ $o->id }})">
                                    <i class="bi bi-eye"></i>
                                </button>
                            </div>
                        </td>
                    </tr>
                @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

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
                <div class="text-center py-4 text-muted">Loading…</div>
            </div>
        </div>
    </div>
</div>

{{-- ═══ APPROVE & PREPARE (expiry-only) MODAL ═══ --}}
<div class="modal fade" id="approveModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable"><div class="modal-content">
    <div class="modal-header" style="background:var(--gold-soft);"><h5 class="modal-title" style="color:var(--maroon);font-weight:700;"><i class="bi bi-check-lg me-1"></i> Approve &amp; Prepare — <span id="amTitle"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body" id="amBody"><div class="text-center py-4 text-muted">Loading…</div></div>
    <div class="modal-footer flex-column align-items-stretch">
      <div class="alert alert-info py-2 small mb-2"><i class="bi bi-info-circle me-1"></i>Approving produces the full ordered quantity and generates QR labels automatically. Enter the expiry per perishable line.</div>
      <div class="d-flex justify-content-end gap-2">
        <div class="text-danger small me-auto" id="amError" style="display:none;"></div>
        <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
        <button type="button" class="btn btn-success" id="btnApprove"><i class="bi bi-check-lg me-1"></i> Approve &amp; Prepare</button>
      </div>
    </div>
  </div></div>
</div>

{{-- ═══ PRINT-AFTER-APPROVE MODAL ═══ --}}
<div class="modal fade" id="printModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:var(--gold-soft);"><h5 class="modal-title" style="color:var(--maroon);font-weight:700;"><i class="bi bi-printer me-1"></i> Labels Ready — <span id="prTitle"></span></h5></div>
    <div class="modal-body">
      <p class="mb-2">Approved and produced. The QR labels are ready.</p>
      <p class="small text-muted mb-0">Print the labels now, or choose Done. Reprinting later uses the same codes — it never creates new ones. Choosing Done does not dispatch the order.</p>
    </div>
    <div class="modal-footer">
      <a class="btn btn-outline-primary" id="prPrintBtn" href="#" target="_blank" rel="noopener"><i class="bi bi-printer me-1"></i> Print Labels / QR</a>
      <button type="button" class="btn btn-maroon" id="prDoneBtn"><i class="bi bi-check-lg me-1"></i> Done</button>
    </div>
  </div></div>
</div>

{{-- ═══ SCAN-OUT (DISPATCH) MODAL ═══ --}}
<div class="modal fade" id="dispatchModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:var(--gold-soft);"><h5 class="modal-title" style="color:var(--maroon);font-weight:700;"><i class="bi bi-upc-scan me-1"></i> Scan-Out — <span id="dmTitle"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="small text-muted mb-2">Scan or enter a production QR to record physical scan-out. Scan the shared product/order QR. Quantity defaults to all remaining units; you can reduce it.</div>
      <label class="form-label small fw-semibold mb-1">Production QR <span class="text-danger">*</span></label>
      <input type="text" class="form-control form-control-sm mb-2" id="dsQr" placeholder="HV-PROD-00001-00001" autocomplete="off">
      <label class="form-label small fw-semibold mb-1">Quantity</label>
      <input type="number" class="form-control form-control-sm" id="dsQty" min="1" step="1" placeholder="All remaining units">
      <div class="text-danger small mt-1" id="dsError" style="display:none;"></div>
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
      <button type="button" class="btn btn-maroon" id="btnDispatch"><i class="bi bi-upc-scan me-1"></i> Record Scan-Out</button></div>
  </div></div>
</div>

{{-- ═══ MANUAL DELIVER (SCAN-OUT FALLBACK) MODAL ═══ --}}
<div class="modal fade" id="manualModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:#fff3cd;"><h5 class="modal-title" style="color:#8a6d00;font-weight:700;"><i class="bi bi-hand-thumbs-up me-1"></i> Manual Release — <span id="mmTitle"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="alert alert-warning py-2 small mb-2"><i class="bi bi-exclamation-triangle me-1"></i>Use this only when Scan-Out cannot be completed. It records <strong>dispatch</strong> (release) for <span id="mmBranch"></span> — the branch must still confirm receipt. It does not verify physical arrival.</div>
      <label class="form-label small fw-semibold mb-1">Reason for bypassing Scan-Out <span class="text-danger">*</span></label>
      <textarea class="form-control form-control-sm" id="mmReason" rows="2" maxlength="500" placeholder="e.g. scanner camera failure"></textarea>
      <div class="text-danger small mt-1" id="mmError" style="display:none;"></div>
      <input type="hidden" id="mmOrderId">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
      <button type="button" class="btn btn-warning" id="btnManual"><i class="bi bi-hand-thumbs-up me-1"></i> Confirm Manual Release</button></div>
  </div></div>
</div>

{{-- ═══ CANCEL ORDER MODAL ═══ --}}
<div class="modal fade" id="cancelModal" tabindex="-1" aria-hidden="true">
  <div class="modal-dialog"><div class="modal-content">
    <div class="modal-header" style="background:#f8d7da;"><h5 class="modal-title" style="color:var(--danger);font-weight:700;"><i class="bi bi-slash-circle me-1"></i> Cancel Order — <span id="cmcTitle"></span></h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
    <div class="modal-body">
      <div class="small text-muted mb-2">Cancellation is only allowed before any physical dispatch.</div>
      <label class="form-label small fw-semibold mb-1">Reason <span class="text-danger">*</span></label>
      <textarea class="form-control form-control-sm" id="cmcReason" rows="2" maxlength="500"></textarea>
      <div class="text-danger small mt-1" id="cmcError" style="display:none;"></div>
      <input type="hidden" id="cmcOrderId">
    </div>
    <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Keep Order</button>
      <button type="button" class="btn btn-danger" id="btnCancelOrder"><i class="bi bi-slash-circle me-1"></i> Cancel Order</button></div>
  </div></div>
</div>

{{-- ═══ DECLINE ORDER MODAL ════════════════════════════ --}}
<div class="modal fade" id="declineModal" tabindex="-1" aria-labelledby="declineModalLabel" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:#f8d7da;">
                <h5 class="modal-title" id="declineModalLabel" style="color:var(--danger);font-weight:700;">
                    <i class="bi bi-x-octagon me-1"></i> Decline Order
                </h5>
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
                <textarea class="form-control form-control-sm" id="dmReasonText" rows="2" maxlength="500"
                          placeholder="Add details (required for Other)" style="display:none;"></textarea>
                <div class="text-danger small mt-1" id="dmError" style="display:none;"></div>
                <input type="hidden" id="dmOrderId">
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-danger" id="btnDecline" onclick="submitDecline()">
                    <i class="bi bi-x-octagon me-1"></i> Decline Order
                </button>
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
                    <i class="bi bi-box-arrow-in-down me-1"></i> Confirm Delivery Receipt
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

@include('partials.order-prepare')
@endsection

@push('styles')
<style>
.diff-ok { color: var(--success); }
.diff-bad { color: var(--danger); font-weight: 600; }

.hv-del-actions { display:flex; flex-wrap:wrap; align-items:center; justify-content:flex-end; gap:.4rem; }
.hv-del-actions .btn { display:inline-flex; align-items:center; gap:.35rem; white-space:nowrap; font-size:12.5px; font-weight:600; }
.hv-del-actions .btn .bi { font-size:.95em; }
.hv-del-actions .btn-ghost-icon { border:1px solid transparent; background:transparent; color:#6E635C; padding:.34rem .5rem; border-radius:9px; }
.hv-del-actions .btn-ghost-icon:hover { background:var(--gold-soft); color:var(--maroon); }

.hv-tab-btn { border:1px solid var(--line, #e7e0d4); background:#fff; color:var(--maroon); font-weight:600; }
.hv-tab-btn.active { background:var(--maroon); color:#fff; border-color:var(--maroon); }
.hv-tab-btn .hv-tab-count { display:inline-block; margin-left:.35rem; min-width:1.2rem; padding:0 .3rem;
    font-size:.7rem; line-height:1.2rem; border-radius:999px; background:var(--gold, #d4a94a); color:#3a2a00; font-weight:700; }
.hv-tab-btn.active .hv-tab-count { background:#fff; color:var(--maroon); }

.hv-branch-head { width:100%; display:flex; align-items:center; justify-content:space-between; gap:1rem;
    background:#fff; border:0; padding:.85rem 1rem; cursor:pointer; text-align:left; transition:background .15s ease; }
.hv-branch-head:hover { background:var(--gold-soft, #faf3e2); }
.hv-branch-name { font-weight:700; color:var(--ink, #2b2b2b); }
.hv-branch-meta { display:flex; align-items:center; gap:.6rem; }
.hv-branch-count { font-size:.78rem; color:#7a7366; }
.hv-notif { font-size:.72rem; font-weight:700; color:#fff; background:var(--maroon); border-radius:999px; padding:.1rem .5rem;
    box-shadow:0 1px 3px rgba(122,23,23,.35); }
.hv-branch-chevron { color:#b7ad99; transition:transform .2s ease; }
.hv-branch-head.collapsed .hv-branch-chevron { transform:rotate(-90deg); }
</style>
@endpush

@push('scripts')
<script>
var csrf = document.querySelector('meta[name="csrf-token"]').content;
var viewModal, confirmModal, declineModal, approveModal, dispatchModal, cancelModal, manualModal, printModal;

document.addEventListener('DOMContentLoaded', function () {
    viewModal     = new bootstrap.Modal(document.getElementById('viewModal'));
    confirmModal  = new bootstrap.Modal(document.getElementById('confirmModal'));
    declineModal  = new bootstrap.Modal(document.getElementById('declineModal'));
    approveModal  = new bootstrap.Modal(document.getElementById('approveModal'));
    dispatchModal = new bootstrap.Modal(document.getElementById('dispatchModal'));
    cancelModal   = new bootstrap.Modal(document.getElementById('cancelModal'));
    manualModal   = new bootstrap.Modal(document.getElementById('manualModal'));
    printModal    = new bootstrap.Modal(document.getElementById('printModal'), { backdrop: 'static', keyboard: false });
});

function _modal(id) { return bootstrap.Modal.getOrCreateInstance(document.getElementById(id)); }

function escHtml(s) {
    return (s == null ? '' : String(s)).replace(/[&<>"']/g, function (c) {
        return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' })[c];
    });
}

async function postAPI(data) {
    var res = await fetch('{{ route("orders.api") }}', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': csrf, 'Accept': 'application/json' },
        body: JSON.stringify(data)
    });
    return res.json();
}

// ── Tabs: Active ⇄ History ⇄ Archived (same page) ──
function showDeliveryTab(tab) {
    if (['active', 'history', 'archived'].indexOf(tab) < 0) tab = 'active';
    document.getElementById('tabActive').classList.toggle('d-none', tab !== 'active');
    document.getElementById('tabHistory').classList.toggle('d-none', tab !== 'history');
    document.getElementById('tabArchived').classList.toggle('d-none', tab !== 'archived');
    document.getElementById('tabBtnActive').classList.toggle('active', tab === 'active');
    document.getElementById('tabBtnHistory').classList.toggle('active', tab === 'history');
    var ab = document.getElementById('tabBtnArchived');
    if (ab) ab.classList.toggle('active', tab === 'archived');
    try { history.replaceState(null, '', '?tab=' + tab); } catch (e) {}
}

// ── Archive / Restore / Delete ──
async function archiveOrder(id, num) {
    if (!confirm('Move order ' + num + ' to Archived?')) return;
    try {
        var r = await postAPI({ action: 'archive_order', id: id });
        if (r.ok) { location.href = '{{ route('delivery') }}?tab=archived'; }
        else { alert(r.error || 'Could not archive.'); }
    } catch (e) { alert('Network error.'); }
}
async function restoreOrder(id, num) {
    if (!confirm('Restore order ' + num + ' to History?')) return;
    try {
        var r = await postAPI({ action: 'restore_order', id: id });
        if (r.ok) { location.href = '{{ route('delivery') }}?tab=history'; }
        else { alert(r.error || 'Could not restore.'); }
    } catch (e) { alert('Network error.'); }
}
async function deleteOrder(id, num) {
    if (!confirm('Permanently DELETE order ' + num + '?\n\nThis removes the order and all its items and production records. It cannot be undone.')) return;
    try {
        var r = await postAPI({ action: 'delete_order', id: id });
        if (r.ok) { alert(r.message || 'Deleted.'); location.href = '{{ route('delivery') }}?tab=archived'; }
        else { alert(r.error || 'Could not delete.'); }
    } catch (e) { alert('Network error.'); }
}

// ── History client-side filter ──
function filterHistory() {
    var q = (document.getElementById('histSearch').value || '').toLowerCase();
    var st = document.getElementById('histStatus').value;
    var shown = 0;
    document.querySelectorAll('.hist-row').forEach(function (row) {
        var ok = (!q || row.dataset.number.indexOf(q) >= 0 || row.dataset.branch.indexOf(q) >= 0)
              && (!st || row.dataset.status === st);
        row.style.display = ok ? '' : 'none';
        if (ok) shown++;
    });
    var empty = document.getElementById('histEmpty');
    if (empty) empty.style.display = shown === 0 ? 'block' : 'none';
}

// ── Approve + auto-produce (expiry only), then print choice ──
async function openApproveModal(orderId, orderNum) {
    document.getElementById('amTitle').textContent = orderNum;
    document.getElementById('amError').style.display = 'none';
    document.getElementById('amBody').innerHTML = '<div class="text-center py-4 text-muted">Loading…</div>';
    var btn = document.getElementById('btnApprove');
    btn.disabled = false;
    btn.onclick = function () { submitApprove(orderId, orderNum, btn); };
    approveModal.show();
    try {
        var r = await postAPI({ action: 'view', id: orderId });
        if (!r.ok) { document.getElementById('amBody').innerHTML = '<div class="text-danger">' + escHtml(r.error || 'Error') + '</div>'; return; }
        var h = '<div class="small text-muted mb-2">Destination: <strong>' + escHtml(r.order.branch_name) + '</strong>. Enter an <strong>expiry date</strong> per perishable line.</div>';
        h += '<table class="table table-sm align-middle"><thead><tr><th>Product</th><th class="text-center">Qty</th><th>Unit</th><th>Expiry</th></tr></thead><tbody>';
        r.order.items.forEach(function (i) {
            h += '<tr data-oi="' + i.id + '"><td>' + escHtml(i.item_name) + '</td>'
               + '<td class="text-center fw-semibold">' + i.quantity + '</td>'
               + '<td>' + escHtml(i.unit) + '</td>'
               + '<td><input type="date" class="form-control form-control-sm am-exp"></td></tr>';
        });
        h += '</tbody></table>';
        document.getElementById('amBody').innerHTML = h;
    } catch (e) { document.getElementById('amBody').innerHTML = '<div class="text-danger">Network error.</div>'; }
}

async function submitApprove(orderId, orderNum, btn) {
    var map = {};
    document.querySelectorAll('#amBody tbody tr').forEach(function (tr) { map[tr.dataset.oi] = tr.querySelector('.am-exp').value || ''; });
    var err = document.getElementById('amError'); err.style.display = 'none';
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
        var r = await postAPI({ action: 'approve_prepare', id: orderId, expiry: JSON.stringify(map) });
        if (r.ok) { approveModal.hide(); openPrintChoice(orderId, orderNum); }
        else { err.textContent = r.error || 'Could not approve.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Approve &amp; Prepare'; }
    } catch (e) { err.textContent = 'Network error.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Approve &amp; Prepare'; }
}

// Print-after-confirm: offer labels/QR, and Done (reload without dispatching).
function openPrintChoice(orderId, orderNum) {
    document.getElementById('prTitle').textContent = orderNum;
    document.getElementById('prPrintBtn').href = '{{ url("orders") }}/' + orderId + '/labels';
    document.getElementById('prDoneBtn').onclick = function () { printModal.hide(); location.reload(); };
    printModal.show();
}

// ── Scan-Out (dispatch) ──
var dispatchRequestKey;
function openDispatchModal(orderId, orderNum) {
    dispatchRequestKey = crypto.randomUUID ? crypto.randomUUID() : Date.now().toString(36)+Math.random().toString(36).slice(2);
    document.getElementById('dmTitle').textContent = orderNum;
    document.getElementById('dsQr').value = ''; document.getElementById('dsQty').value = '';
    document.getElementById('dsError').style.display = 'none';
    var btn = document.getElementById('btnDispatch'); btn.disabled = false; btn.onclick = submitDispatch;
    dispatchModal.show();
}
async function submitDispatch() {
    var qr = document.getElementById('dsQr').value.trim();
    var qtyInput = document.getElementById('dsQty'); if (!qtyInput.reportValidity()) return; var qty = qtyInput.value === '' ? null : Number(qtyInput.value);
    var err = document.getElementById('dsError'); err.style.display = 'none';
    if (!qr) { err.textContent = 'Scan or enter a production QR.'; err.style.display = 'block'; return; }
    var btn = document.getElementById('btnDispatch'); btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
        var data = { action: 'dispatch_scan', qr: qr }; if (qty !== null) data.quantity = qty; data.idempotency_key = dispatchRequestKey;
        var r = await postAPI(data);
        if (r.ok) { dispatchModal.hide(); alert(r.message); location.reload(); }
        else { err.textContent = r.error || 'Could not dispatch.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-upc-scan me-1"></i> Record Scan-Out'; }
    } catch (e) { err.textContent = 'Network error.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-upc-scan me-1"></i> Record Scan-Out'; }
}

// ── Manual Release (scan-out fallback) ──
function openManualDeliver(orderId, orderNum, branch) {
    document.getElementById('mmTitle').textContent = orderNum;
    document.getElementById('mmBranch').textContent = branch;
    document.getElementById('mmOrderId').value = orderId;
    document.getElementById('mmReason').value = '';
    document.getElementById('mmError').style.display = 'none';
    var btn = document.getElementById('btnManual'); btn.disabled = false; btn.onclick = submitManualDeliver;
    manualModal.show();
}
async function submitManualDeliver() {
    var reason = document.getElementById('mmReason').value.trim();
    var err = document.getElementById('mmError'); err.style.display = 'none';
    if (!reason) { err.textContent = 'A reason is required.'; err.style.display = 'block'; return; }
    var btn = document.getElementById('btnManual'); btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
        var r = await postAPI({ action: 'manual_deliver', id: document.getElementById('mmOrderId').value, reason: reason });
        if (r.ok) { manualModal.hide(); alert(r.message); location.reload(); }
        else { err.textContent = r.error || 'Could not record manual delivery.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-hand-thumbs-up me-1"></i> Confirm Manual Release'; }
    } catch (e) { err.textContent = 'Network error.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-hand-thumbs-up me-1"></i> Confirm Manual Release'; }
}

// ── Cancel ──
function openCancelModal(orderId, orderNum) {
    document.getElementById('cmcTitle').textContent = orderNum;
    document.getElementById('cmcOrderId').value = orderId;
    document.getElementById('cmcReason').value = ''; document.getElementById('cmcError').style.display = 'none';
    var btn = document.getElementById('btnCancelOrder'); btn.disabled = false; btn.onclick = submitCancelOrder;
    cancelModal.show();
}
async function submitCancelOrder() {
    var reason = document.getElementById('cmcReason').value.trim();
    var err = document.getElementById('cmcError'); err.style.display = 'none';
    if (!reason) { err.textContent = 'A reason is required.'; err.style.display = 'block'; return; }
    var btn = document.getElementById('btnCancelOrder'); btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
        var r = await postAPI({ action: 'cancel', id: document.getElementById('cmcOrderId').value, reason: reason });
        if (r.ok) { cancelModal.hide(); alert(r.message); location.reload(); }
        else { err.textContent = r.error || 'Could not cancel.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-slash-circle me-1"></i> Cancel Order'; }
    } catch (e) { err.textContent = 'Network error.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-slash-circle me-1"></i> Cancel Order'; }
}

// ── Decline ──
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
    var reason = sel;
    if (sel === 'Other') { reason = extra; }
    else if (extra) { reason = sel + ' — ' + extra; }
    if (!sel) { err.textContent = 'Please select a reason.'; err.style.display = 'block'; return; }
    if (sel === 'Other' && !extra) { err.textContent = 'Please explain the reason.'; err.style.display = 'block'; return; }
    err.style.display = 'none';
    var btn = document.getElementById('btnDecline'); btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
    try {
        var r = await postAPI({ action: 'decline', id: document.getElementById('dmOrderId').value, reason: reason });
        if (r.ok) { declineModal.hide(); alert(r.message); location.reload(); }
        else { err.textContent = r.error || 'Could not decline.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-x-octagon me-1"></i> Decline Order'; }
    } catch (e) { err.textContent = 'Network error.'; err.style.display = 'block'; btn.disabled = false; btn.innerHTML = '<i class="bi bi-x-octagon me-1"></i> Decline Order'; }
}

// ── View order ──
async function viewOrder(orderId) {
    document.getElementById('vmBody').innerHTML = '<div class="text-center py-4 text-muted">Loading…</div>';
    viewModal.show();
    try {
        var r = await postAPI({ action: 'view', id: orderId });
        if (!r.ok) { document.getElementById('vmBody').innerHTML = '<div class="text-danger">' + (r.error || 'Error') + '</div>'; return; }
        var o = r.order;
        document.getElementById('vmTitle').textContent = 'Order ' + o.order_number;
        var html = '<div class="row mb-3">';
        html += '<div class="col-sm-6"><strong>Branch:</strong> ' + escHtml(o.branch_name) + '</div>';
        html += '<div class="col-sm-6"><strong>Status:</strong> ' + escHtml(o.workflow_stage || o.status) + '</div>';
        html += '<div class="col-sm-6"><strong>Ordered by:</strong> ' + escHtml(o.ordered_by_name || '—') + '</div>';
        html += '<div class="col-sm-6"><strong>Created:</strong> ' + (o.created_at || '—') + '</div>';
        if (o.approved_by_name) html += '<div class="col-sm-6"><strong>Approved by:</strong> ' + escHtml(o.approved_by_name) + '</div>';
        if (o.prepared_by_name) html += '<div class="col-sm-6"><strong>Prepared by:</strong> ' + escHtml(o.prepared_by_name) + '</div>';
        if (o.confirmed_by_name) html += '<div class="col-sm-6"><strong>Confirmed by:</strong> ' + escHtml(o.confirmed_by_name) + '</div>';
        if (o.delivered_at) html += '<div class="col-sm-6"><strong>Delivered:</strong> ' + o.delivered_at + '</div>';
        html += '</div>';
        if (o.status === 'declined') {
            html += '<div class="alert alert-danger py-2 small"><strong>Declined</strong>'
                + (o.declined_by_name ? ' by ' + escHtml(o.declined_by_name) : '')
                + (o.declined_at ? ' on ' + escHtml(o.declined_at) : '')
                + '<br><strong>Reason:</strong> ' + escHtml(o.decline_reason || '—') + '</div>';
        }
        if (o.status === 'cancelled' && o.cancel_reason) {
            html += '<div class="alert alert-secondary py-2 small"><strong>Cancelled.</strong> Reason: ' + escHtml(o.cancel_reason) + '</div>';
        }
        html += '<table class="table table-sm" style="font-size:13px;"><thead><tr><th>Item</th><th class="text-center">Qty</th>';
        const hasAnyReceipt = o.items.some(item => item.received_quantity !== null);
        if (hasAnyReceipt) html += '<th class="text-center">Received</th>';
        html += '<th class="text-end">Price</th><th class="text-end">Subtotal</th></tr></thead><tbody>';
        o.items.forEach(function (i) {
            html += '<tr><td>' + escHtml(i.item_name) + ' <span class="text-muted">(' + escHtml(i.unit) + ')</span></td>';
            html += '<td class="text-center">Requested: ' + i.quantity + '<br>Prepared: ' + (i.fulfilled_quantity ?? '—') + '<br>Released: ' + (i.released_quantity || 0) + (i.fulfillment_reason ? '<br><small>' + escHtml(i.fulfillment_reason) + '</small>' : '') + '</td>';
            if (hasAnyReceipt) {
                var recv = i.received_quantity !== null ? i.received_quantity : '—';
                var cls = i.received_quantity === null ? 'text-muted' : (recv == (i.fulfilled_quantity ?? i.quantity) ? 'diff-ok' : (o.status === 'delivering' ? 'text-warning' : 'diff-bad'));
                html += '<td class="text-center ' + cls + '">' + recv + '</td>';
            }
            html += '<td class="text-end">' + Number(i.unit_price).toFixed(2) + '</td>';
            html += '<td class="text-end">' + Number(i.subtotal).toFixed(2) + '</td></tr>';
        });
        html += '</tbody></table>';
        html += '<div class="text-end fw-bold" style="color:var(--maroon);">Total: PHP ' + Number(o.fulfilled_amount ?? o.total_amount).toFixed(2) + '</div>';
        if (o.discrepancies && o.discrepancies.length > 0) {
            html += '<hr><h6 style="color:var(--danger);font-weight:700;"><i class="bi bi-exclamation-triangle me-1"></i> Discrepancies</h6>';
            html += '<table class="table table-sm" style="font-size:12px;"><thead><tr><th>Item</th><th class="text-center">Expected</th><th class="text-center">Received</th><th>Reason</th><th>Remarks</th></tr></thead><tbody>';
            o.discrepancies.forEach(function (d) {
                html += '<tr class="table-warning"><td>' + escHtml(d.item_name) + '</td><td class="text-center">' + d.expected_quantity + '</td><td class="text-center">' + d.received_quantity + '</td><td>' + escHtml(d.reason) + '</td><td>' + escHtml(d.remarks || '—') + '</td></tr>';
            });
            html += '</tbody></table>';
        }
        if (o.shipment_qr) {
            html += '<hr><div class="text-center">'
                + '<div class="small text-muted mb-1"><i class="bi bi-qr-code-scan me-1"></i>Receiving QR — scan at <strong>' + escHtml(o.branch_name) + '</strong> to receive this shipment</div>'
                + '<div id="shipQrBox" class="d-inline-block p-2 bg-white border rounded"></div>'
                + '<div class="small fw-semibold mt-1">' + escHtml(o.shipment_qr) + '</div></div>';
        }
        document.getElementById('vmBody').innerHTML = html;
        if (o.shipment_qr) renderShipQr(o.shipment_qr);
    } catch (e) { document.getElementById('vmBody').innerHTML = '<div class="text-danger">Network error.</div>'; }
}

function renderShipQr(code) {
    function draw() {
        try {
            var q = window.qrcode(0, 'M'); q.addData(code); q.make();
            var box = document.getElementById('shipQrBox');
            if (box) box.innerHTML = q.createImgTag(4, 4);
        } catch (e) {}
    }
    if (window.qrcode) { draw(); return; }
    var s = document.createElement('script');
    s.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js';
    s.onload = draw;
    document.head.appendChild(s);
}

// ── Confirm receipt ──
var confirmItems = [];
async function openConfirmModal(orderId, orderNum) {
    document.getElementById('cmOrderId').value = orderId;
    document.getElementById('cmOrderNum').textContent = 'Order: ' + orderNum;
    document.getElementById('cmItems').innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">Loading…</td></tr>';
    confirmModal.show();
    try {
        var r = await postAPI({ action: 'view', id: orderId });
        if (!r.ok) { document.getElementById('cmItems').innerHTML = '<tr><td colspan="5" class="text-danger">' + (r.error || 'Error') + '</td></tr>'; return; }
        confirmItems = r.order.items.map(item => ({...item, requested_quantity:item.quantity, quantity:item.receivable_quantity ?? (item.released_quantity || (item.fulfilled_quantity ?? item.quantity))}));
        var html = '';
        confirmItems.forEach(function (item, idx) {
            html += '<tr>';
            html += '<td>' + escHtml(item.item_name) + ' <span class="text-muted">(' + escHtml(item.unit) + ')</span></td>';
            html += '<td class="text-center fw-semibold">' + item.quantity + '</td>';
            html += '<td class="text-center"><input type="number" class="form-control form-control-sm text-center cm-recv" min="0" step="1" max="' + item.quantity + '" value="' + item.quantity + '" data-idx="' + idx + '" data-expected="' + item.quantity + '" onchange="updateDiff(this)" style="width:80px;display:inline-block;"></td>';
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
        if (!reasonEl.value) { reasonEl.value = diff < 0 ? 'Missing Quantity' : 'Incorrect Quantity'; }
    }
}
async function submitConfirm() {
    var btn = document.getElementById('btnConfirm');
    btn.disabled = true; btn.textContent = 'Confirming…';
    var receivedItems = [];
    const inputs = [...document.querySelectorAll('.cm-recv')];
    if (inputs.some(input => !input.reportValidity())) { btn.disabled = false; btn.textContent = 'Confirm Received'; return; }
    inputs.forEach(function (el) {
        var idx = parseInt(el.dataset.idx);
        var item = confirmItems[idx];
        receivedItems.push({ order_item_id: item.id, received_quantity: Number(el.value), reason:document.getElementById('cmReason' + idx).value || null });
    });
    var hasDiscrepancy = receivedItems.some(function (ri, i) { return ri.received_quantity !== confirmItems[i].quantity; });
    if (hasDiscrepancy) {
        if (!confirm('There are quantity discrepancies. Discrepancies will be recorded. Continue?')) {
            btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm Received';
            return;
        }
    }
    var data = {
        action: 'receive_scan',
        id: document.getElementById('cmOrderId').value,
        received_items: JSON.stringify(receivedItems),
    };
    try {
        var r = await postAPI(data);
        if (r.ok) { confirmModal.hide(); alert(r.message); location.reload(); }
        else { alert(r.error || 'Error confirming delivery.'); btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm Received'; }
    } catch (e) { alert('Network error.'); btn.disabled = false; btn.innerHTML = '<i class="bi bi-check2-circle me-1"></i> Confirm Received'; }
}
</script>
@endpush
