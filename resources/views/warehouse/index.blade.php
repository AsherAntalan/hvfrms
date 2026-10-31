@extends('layouts.app')

@section('title', 'Inventory')

@section('content')

@php $showExpired = request('tab') === 'expired'; @endphp

@include('partials.production-shipments')

<div class="hv-page-header">
<div><strong>{{ $scopeLabel }}</strong><div class="small text-muted">Branch stock, open commissary orders, and disposal history.</div></div>
    <div class="d-flex align-items-center gap-2"></div>
</div>

@if($inventoryReadOnly ?? false)
<div class="alert alert-light border small py-2 mb-3" role="note">
    <i class="ph ph-info me-1" style="color:var(--maroon);"></i>
    Inventory monitoring only. Disposal and inventory archive actions require an authorized inventory handler.
</div>
@endif

<style>.inventory-branch-toggle[aria-expanded="true"] .ph-caret-right {display:inline-block;transform:rotate(90deg);}</style>
<!-- Statistics Cards -->
<div class="hv-summary-grid mb-4">
    <div>
        <div class="hv-summary-metric">
            <div class="hv-summary-label">Active Stock</div>
            <div class="hv-summary-value text-maroon">{{ number_format($totalStock) }}</div>
        </div>
    </div>
    <div>
        <div class="hv-summary-metric">
            <div class="hv-summary-label">Products</div>
            <div class="hv-summary-value text-maroon">{{ $productCount }}</div>
        </div>
    </div>
    <div>
        <div class="hv-summary-metric">
            <div class="hv-summary-label">Low Stock</div>
            <div class="hv-summary-value text-warning">{{ $lowStockCount }}</div>
        </div>
    </div>
    <div>
        <div class="hv-summary-metric">
            <div class="hv-summary-label">Expired</div>
            <div class="hv-summary-value text-danger">{{ count($expiredBatches) }}</div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item">
        <button class="nav-link {{ $showExpired ? '' : 'active' }}" data-bs-toggle="tab" data-bs-target="#tabActive" type="button">
            <i class="ph ph-check-circle me-1"></i> Active Stock
            <span class="badge bg-success ms-1">{{ count($activeBatches) }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $showExpired ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tabExpired" type="button">
            <i class="ph ph-archive-box me-1"></i> Expired Stock
            <span class="badge bg-danger ms-1">{{ count($expiredBatches) }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDisposal" type="button">
            <i class="ph ph-recycle me-1"></i> Disposal Records
            <span class="badge bg-secondary ms-1">{{ count($disposalRecords) }}</span>
        </button>
    </li>

    @unless($isWarehouseStaff)
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabOrders" type="button">
            <i class="ph ph-shopping-cart-simple me-1"></i> Pending Orders
            <span class="badge bg-warning text-dark ms-1">{{ count($pendingOrders) }}</span>
        </button>
    </li>
    @endunless
</ul>

<div class="tab-content">

    {{-- ═══ ACTIVE STOCK ═══════════════════════════════════ --}}
    <div class="tab-pane fade {{ $showExpired ? '' : 'show active' }}" id="tabActive">
        <div class="hv-card p-0 hv-panel" style="border-top-left-radius:0;">
            @unless($monitorAllBranches)
            <div class="p-3 border-bottom">
                <input type="text" id="searchActive" class="form-control form-control-sm"
                       placeholder="Search active stock..." style="max-width:300px;" oninput="filterRows('active')">
            </div>
            @endunless
            <div id="panelBody-active" class="hv-panel-body" aria-live="polite">
                @if($monitorAllBranches)
                    @include('partials.inventory-branch-groups',['kind'=>'active'])
                @else
                    @include('partials.inventory-active-table')
                @endif
            </div>
            @include('partials.inventory-panel-overlay',['panel'=>'active'])
        </div>
    </div>

    {{-- ═══ EXPIRED STOCK ═══════════════════════════════════ --}}
    <div class="tab-pane fade {{ $showExpired ? 'show active' : '' }}" id="tabExpired">
        <div class="hv-card p-0 hv-panel" style="border-top-left-radius:0;">
            @unless($monitorAllBranches)
            <div class="p-3 border-bottom">
                <input type="text" id="searchExpired" class="form-control form-control-sm"
                       placeholder="Search expired stock..." style="max-width:300px;" oninput="filterRows('expired')">
            </div>
            @endunless
            <div id="panelBody-expired" class="hv-panel-body" aria-live="polite">
                @if($monitorAllBranches)
                    @include('partials.inventory-branch-groups',['kind'=>'expired'])
                @else
                    @include('partials.inventory-expired-table')
                @endif
            </div>
            @include('partials.inventory-panel-overlay',['panel'=>'expired'])
        </div>
    </div>

    {{-- ═══ PENDING ORDERS ════════════════════════════════ --}}
    @unless($isWarehouseStaff)
    <div class="tab-pane fade" id="tabOrders">
        <div class="hv-card p-0 hv-panel" style="border-top-left-radius:0;">
            <div id="panelBody-orders" class="hv-panel-body" aria-live="polite">
                @if($monitorAllBranches)
                    @include('partials.inventory-branch-groups',['kind'=>'orders'])
                @else
                    @include('partials.inventory-orders-table')
                @endif
            </div>
            @include('partials.inventory-panel-overlay',['panel'=>'orders'])
        </div>
    </div>
    @endunless

    {{-- ═══ DISPOSAL RECORDS ═══════════════════════════════ --}}
    <div class="tab-pane fade" id="tabDisposal">
        <div class="hv-card p-0 hv-panel" style="border-top-left-radius:0;">
            @if($canArchiveBatches)
            <div class="p-3 border-bottom d-flex justify-content-end">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="whArchivesBtn"
                        title="View archived expired batches (30-day retention)">
                    <i class="ph ph-archive-box me-1"></i> Archives
                    <span class="badge bg-secondary ms-1" id="whArchivesCount">0</span>
                </button>
            </div>
            @endif
            <div id="panelBody-disposal" class="hv-panel-body" aria-live="polite">
                @if($monitorAllBranches)
                    @include('partials.inventory-branch-groups',['kind'=>'disposal'])
                @else
                    @include('partials.inventory-disposal-table')
                @endif
            </div>
            @include('partials.inventory-panel-overlay',['panel'=>'disposal'])
        </div>
    </div>

</div>

{{-- ═══ CREATE STOCK MODAL ════════════════════════════════ --}}
{{-- ═══ STOCK ADJUSTMENT MODAL ═════════════════════════════ --}}
@if($canEditBatches)
<div class="modal fade" id="adjustModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                    <i class="ph ph-sliders-horizontal me-1"></i> Adjust Stock Quantity
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="aBatchId">
                <div class="mb-2 fw-semibold" id="aItemName" style="color:var(--maroon);"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Current Quantity</label>
                    <input type="number" class="form-control" id="aCurrentQty" readonly style="background:#f5f5f5;">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">New Quantity <span class="text-danger">*</span></label>
                    <input type="number" class="form-control" id="aNewQty" min="0" required>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                    <select class="form-select" id="aReason" required>
                        <option value="">— Select reason —</option>
                        <option value="Physical Count Correction">Physical Count Correction</option>
                        <option value="Damaged">Damaged</option>
                        <option value="Spoilage">Spoilage</option>
                        <option value="Expired">Expired</option>
                        <option value="Missing">Missing</option>
                        <option value="Encoding Error">Encoding Error</option>
                        <option value="Other">Other</option>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Remarks</label>
                    <textarea class="form-control" id="aRemarks" rows="2" placeholder="Optional notes..."></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-maroon" id="btnAdjust" onclick="submitAdjust()">
                    <i class="ph ph-check me-1"></i> Submit Adjustment
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ═══ DISPOSE MODAL (remarks + confirm) ═════════════════ --}}
@if($canDisposeBatches ?? false)
<div class="modal fade" id="disposeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                    <i class="ph ph-recycle me-1"></i> Dispose Expired Batch
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="dBatchId">
                <p class="mb-2">Dispose <strong><span id="dQty"></span></strong> of <strong id="dItemName" style="color:var(--maroon);"></strong> as expired?</p>
                <div class="mb-1">
                    <label class="form-label fw-semibold" for="dRemarks">Remarks <span class="text-muted fw-normal">(optional)</span></label>
                    <textarea class="form-control" id="dRemarks" rows="2" placeholder="Optional notes..."></textarea>
                </div>
                <div class="small text-muted">This records a disposal transaction and zeroes the batch. History is preserved.</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-warning" id="btnDispose">
                    <i class="ph ph-recycle me-1"></i> Dispose
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ═══ GENERIC CONFIRM MODAL (void / archive) ═════════════ --}}
<div class="modal fade" id="whConfirmModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" id="whConfirmTitle" style="color:var(--maroon);font-weight:700;"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body"><p class="mb-0" id="whConfirmBody"></p></div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-maroon" id="whConfirmOk">Confirm</button>
            </div>
        </div>
    </div>
</div>

{{-- ═══ ARCHIVES VIEWER MODAL (reuses reports.archives) ════ --}}
@if($canArchiveBatches)
<div class="modal fade" id="whArchivesModal" tabindex="-1" aria-labelledby="whArchivesLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--maroon-dark);color:#fff;">
                <h5 class="modal-title" id="whArchivesLabel"><i class="ph ph-archive-box me-2"></i> Archived Expired Batches</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="d-flex gap-2 mb-2">
                    <input type="search" id="whArchSearch" class="form-control form-control-sm" placeholder="Search product, branch, or batch #">
                </div>
                <div class="alert alert-light border small py-2">
                    Entries automatically disappear <strong>30 days after they are archived</strong> (Asia/Manila). Archiving is a reporting action only — physical stock, ledger, and audit history are preserved. Disposed batches appear here even when no quantity remains.
                </div>
                <div id="whArchLoading" class="text-center text-muted py-4" style="display:none;"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>
                <div id="whArchError" class="text-center text-danger py-3" style="display:none;"></div>
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0">
                        <thead><tr><th>Batch #</th><th>Product</th><th>Branch</th><th class="text-center">Qty</th><th>Expiry</th><th>Archived</th><th>By</th><th>Removes On</th></tr></thead>
                        <tbody id="whArchBody"></tbody>
                    </table>
                </div>
                <div class="d-flex justify-content-between align-items-center mt-2">
                    <span class="small text-muted" id="whArchTotal"></span>
                    <div class="btn-group btn-group-sm">
                        <button class="btn btn-outline-secondary" id="whArchPrev">Prev</button>
                        <button class="btn btn-outline-secondary" id="whArchNext">Next</button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>
@endif

{{-- Lightweight toast feedback for inventory actions --}}
<div class="hv-wh-toasts" id="whToasts" aria-live="polite" aria-atomic="true"></div>

@endsection

@push('styles')
<style>
.hv-panel{ position:relative; }
.hv-panel-overlay{ position:absolute; inset:0; z-index:5; display:flex; align-items:flex-start;
  justify-content:center; padding-top:48px; background:rgba(251,247,240,.72); backdrop-filter:blur(1px);
  border-radius:inherit; }
.hv-panel-overlay[hidden]{ display:none; }
.hv-panel-overlay-box{ display:inline-flex; align-items:center; gap:.5rem; background:#fff;
  border:1px solid var(--border); border-radius:10px; padding:.5rem .9rem; font-size:13px; color:var(--maroon-dark);
  box-shadow:0 8px 20px -12px rgba(60,8,8,.4); }
.hv-panel-body[aria-busy="true"]{ opacity:.6; }
.hv-wh-toasts{ position:fixed; right:16px; bottom:16px; z-index:1090; display:flex; flex-direction:column;
  gap:8px; max-width:360px; pointer-events:none; }
.hv-wh-toast{ pointer-events:auto; display:flex; align-items:flex-start; gap:.5rem; background:#fff;
  border:1px solid var(--border); border-left:4px solid var(--maroon); border-radius:10px; padding:.6rem .8rem;
  font-size:13px; color:var(--ink); box-shadow:0 10px 26px -14px rgba(60,8,8,.45); animation:whToastIn .2s ease-out; }
.hv-wh-toast.is-success{ border-left-color:var(--success); }
.hv-wh-toast.is-error{ border-left-color:var(--danger); }
.hv-wh-toast i[class*="ph-"]{ font-size:16px; line-height:1.2; }
.hv-wh-toast.is-success i{ color:var(--success); }
.hv-wh-toast.is-error i{ color:var(--danger); }
.hv-wh-toast.is-info i{ color:var(--maroon); }
@keyframes whToastIn{ from{ opacity:0; transform:translateY(6px); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion:reduce){ .hv-wh-toast{ animation:none; } }
</style>
@endpush

@include('partials.inventory-qr-print')

@push('scripts')
<script>
const API   = '{{ route("warehouse.api") }}';
const PANEL = '{{ route("warehouse.panel") }}';
const ARCHIVE_URL  = '{{ route("reports.archive") }}';
const ARCHIVES_URL = '{{ route("reports.archives") }}';
const CSRF  = document.querySelector('meta[name="csrf-token"]').content;

@if($canEditBatches) let adjustModal; @endif
@if($canDisposeBatches ?? false) let disposeModal; @endif
@if($canArchiveBatches) let whArchivesModal; @endif
let whConfirmModal, whConfirmResolver = null;

document.addEventListener('DOMContentLoaded', function () {
    @if($canEditBatches) adjustModal = new bootstrap.Modal(document.getElementById('adjustModal')); @endif
    @if($canDisposeBatches ?? false) disposeModal = new bootstrap.Modal(document.getElementById('disposeModal')); @endif
    whConfirmModal = new bootstrap.Modal(document.getElementById('whConfirmModal'));

    // Resolve a pending confirm as false whenever the modal is dismissed.
    document.getElementById('whConfirmModal').addEventListener('hidden.bs.modal', function () {
        if (whConfirmResolver) { whConfirmResolver(false); whConfirmResolver = null; }
    });
    document.getElementById('whConfirmOk').addEventListener('click', function () {
        if (whConfirmResolver) { var r = whConfirmResolver; whConfirmResolver = null; r(true); }
        whConfirmModal.hide();
    });

    @if($canDisposeBatches ?? false)
    document.getElementById('btnDispose').addEventListener('click', submitDispose);
    @endif

    @if($canArchiveBatches)
    whArchivesModal = new bootstrap.Modal(document.getElementById('whArchivesModal'));
    initArchivesViewer();
    refreshArchivesCount();
    @endif
});

// ── Helpers ──────────────────────────────────────────────────────
async function postAPI(fields) {
    var fd = new FormData();
    for (var k in fields) fd.append(k, fields[k]);
    var res = await fetch(API, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF }
    });
    if (res.status === 419) { throw { handled: true, message: 'Your session expired. Please refresh the page and sign in again.' }; }
    if (res.status === 401 || res.status === 403) {
        var b = await res.json().catch(function () { return {}; });
        throw { handled: true, message: b.error || 'You are not authorized to perform this action.' };
    }
    return res.json();
}

function whToast(type, msg, actionLabel, actionFn) {
    var box = document.getElementById('whToasts');
    if (!box) { return; }
    var icon = type === 'success' ? 'ph-check-circle' : (type === 'error' ? 'ph-warning-circle' : 'ph-info');
    var el = document.createElement('div');
    el.className = 'hv-wh-toast is-' + type;
    el.innerHTML = '<i class="ph ' + icon + '"></i><div class="flex-fill"><div>' + escHtml(msg) + '</div></div>'
        + '<button type="button" class="btn-close btn-close-sm ms-1" aria-label="Dismiss"></button>';
    if (actionLabel && actionFn) {
        var a = document.createElement('button');
        a.type = 'button'; a.className = 'btn btn-sm btn-outline-secondary mt-1';
        a.textContent = actionLabel;
        a.addEventListener('click', function () { el.remove(); actionFn(); });
        el.querySelector('.flex-fill').appendChild(a);
    }
    el.querySelector('.btn-close').addEventListener('click', function () { el.remove(); });
    box.appendChild(el);
    if (type !== 'error') { setTimeout(function () { el.remove(); }, 5000); }
}

function escHtml(t) { var d = document.createElement('div'); d.textContent = (t == null ? '' : t); return d.innerHTML; }

// Styled confirm (replaces window.confirm). Resolves true/false.
function whConfirm(title, body, okLabel, okClass) {
    document.getElementById('whConfirmTitle').textContent = title;
    document.getElementById('whConfirmBody').textContent = body;
    var ok = document.getElementById('whConfirmOk');
    ok.textContent = okLabel || 'Confirm';
    ok.className = 'btn ' + (okClass || 'btn-maroon');
    return new Promise(function (resolve) { whConfirmResolver = resolve; whConfirmModal.show(); });
}

function overlay(panel, text, on) {
    var ov = document.querySelector('.hv-panel-overlay[data-overlay="' + panel + '"]');
    var body = document.getElementById('panelBody-' + panel);
    if (ov) { ov.querySelector('.hv-panel-overlay-text').textContent = text || 'Loading…'; ov.hidden = !on; }
    if (body) { if (on) { body.setAttribute('aria-busy', 'true'); } else { body.removeAttribute('aria-busy'); } }
}

function activePanelKind() {
    var pane = document.querySelector('.tab-pane.active');
    if (!pane) { return 'active'; }
    return (pane.id || 'tabActive').replace('tab', '').toLowerCase();
}

// Re-fetch every tab body from the server and swap in place, preserving the
// active tab, search inputs, expanded branch groups, and scroll position.
async function refreshPanels(busyText) {
    var current = activePanelKind();
    overlay(current, busyText || 'Updating…', true);

    // Capture state before the swap.
    var openIds = Array.prototype.map.call(document.querySelectorAll('.hv-panel-body .collapse.show'), function (c) { return c.id; });
    var searchVals = {};
    document.querySelectorAll('.hv-panel-body input[id^="inventoryBranchSearch-"]').forEach(function (inp) { searchVals[inp.id] = inp.value; });
    var scrollY = window.scrollY;

    var res = await fetch(PANEL, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) { overlay(current, '', false); throw { handled: false }; }
    var json = await res.json();
    if (!json.ok) { overlay(current, '', false); throw { handled: false }; }

    ['active', 'expired', 'disposal', 'orders'].forEach(function (kind) {
        var body = document.getElementById('panelBody-' + kind);
        if (body && json.html[kind] != null) { body.innerHTML = json.html[kind]; }
    });

    updateCounts(json.counts);

    // Restore grouped-view search inputs + re-apply filters.
    Object.keys(searchVals).forEach(function (id) {
        var inp = document.getElementById(id);
        if (inp) { inp.value = searchVals[id]; filterInventoryBranches(inp); }
    });
    // Non-grouped search boxes live outside the swapped body — re-apply them.
    if (document.getElementById('searchActive')) { filterRows('active'); }
    if (document.getElementById('searchExpired')) { filterRows('expired'); }

    // Restore expanded branch groups.
    openIds.forEach(function (id) {
        var c = document.getElementById(id);
        if (c) {
            c.classList.add('show');
            var row = document.querySelector('[data-details="' + id + '"]');
            if (row) { row.setAttribute('aria-expanded', 'true'); }
        }
    });

    window.scrollTo(0, scrollY);
    overlay(current, '', false);
}

function updateCounts(c) {
    if (!c) { return; }
    setBadgeByTarget('#tabActive', c.active);
    setBadgeByTarget('#tabExpired', c.expired);
    setBadgeByTarget('#tabDisposal', c.disposal);
    setBadgeByTarget('#tabOrders', c.orders);
    // Summary cards: Active Stock, Products, Low Stock, Expired (by position)
    var vals = document.querySelectorAll('.hv-summary-value');
    if (vals[0]) { vals[0].textContent = Number(c.totalStock).toLocaleString(); }
    if (vals[2]) { vals[2].textContent = c.lowStockCount; }
    if (vals[3]) { vals[3].textContent = c.expired; }
}
function setBadgeByTarget(target, val) {
    var btn = document.querySelector('.nav-link[data-bs-target="' + target + '"]');
    if (!btn) { return; }
    var badge = btn.querySelector('.badge');
    if (badge) { badge.textContent = val; }
}

// After a mutation succeeds: refresh panels; if only the refresh fails, keep the
// success and offer to retry the READ (never the mutation).
async function afterMutation(successMsg, busyText) {
    whToast('success', successMsg);
    try {
        await refreshPanels(busyText);
    } catch (e) {
        whToast('error', 'Saved, but the view could not refresh. Your change is safe.', 'Retry update', function () {
            refreshPanels(busyText).catch(function () {
                whToast('error', 'Still could not refresh. Please reload the page.');
            });
        });
    }
}

function filterInventoryBranches(input) {
    const query = input.value.trim().toLowerCase();
    document.querySelectorAll('.inventory-branch-row[data-kind="' + input.dataset.kind + '"]').forEach(row => {
        const visible = row.dataset.search.includes(query); row.hidden = !visible;
        document.getElementById(row.dataset.details).closest('tr').hidden = !visible;
    });
}

function filterRows(type) {
    var el = document.getElementById('search' + (type === 'active' ? 'Active' : 'Expired'));
    if (!el) { return; }
    var q = el.value.toLowerCase();
    document.querySelectorAll('.row-' + type).forEach(function (row) {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
}

// ── Adjust Batch ─────────────────────────────────────────────────
@if($canEditBatches)
function openAdjustModal(batchId, itemName, qty) {
    document.getElementById('aBatchId').value = batchId;
    document.getElementById('aItemName').textContent = itemName;
    document.getElementById('aCurrentQty').value = qty;
    document.getElementById('aNewQty').value = qty;
    document.getElementById('aReason').value = '';
    document.getElementById('aRemarks').value = '';
    adjustModal.show();
}

async function submitAdjust() {
    var reason = document.getElementById('aReason').value;
    if (!reason) { whToast('error', 'Please select a reason for the adjustment.'); return; }

    var btn = document.getElementById('btnAdjust');
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving changes…';

    try {
        var r = await postAPI({
            action: 'adjust_batch',
            batch_id: document.getElementById('aBatchId').value,
            new_quantity: document.getElementById('aNewQty').value,
            reason: reason,
            remarks: document.getElementById('aRemarks').value
        });
        if (r.ok) { adjustModal.hide(); await afterMutation(r.message || 'Stock adjusted.', 'Saving changes…'); }
        else { whToast('error', r.error || 'Error adjusting batch.'); }
    } catch (e) { whToast('error', e.handled ? e.message : 'Network error. Please try again.'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="ph ph-check me-1"></i> Submit Adjustment'; }
}
@endif

// ── Void Batch ───────────────────────────────────────────────────
async function voidBatch(batchId, itemName, qty) {
    if (!(await whConfirm('Void batch', 'Void batch for "' + itemName + '" (' + qty + ' units)? This zeroes the batch quantity and records a reversal transaction. History is preserved.', 'Void batch', 'btn-outline-danger'))) { return; }
    try {
        var r = await postAPI({ action: 'void_batch', batch_id: batchId });
        if (r.ok) { await afterMutation(r.message || 'Batch voided.', 'Voiding…'); }
        else { whToast('error', r.error || 'Error voiding batch.'); }
    } catch (e) { whToast('error', e.handled ? e.message : 'Network error. Please try again.'); }
}

// ── Dispose Batch (styled modal with optional remarks) ───────────
@if($canDisposeBatches ?? false)
function disposeBatch(batchId, itemName, qty) {
    document.getElementById('dBatchId').value = batchId;
    document.getElementById('dItemName').textContent = itemName;
    document.getElementById('dQty').textContent = qty + ' unit(s)';
    document.getElementById('dRemarks').value = '';
    disposeModal.show();
}

async function submitDispose() {
    var btn = document.getElementById('btnDispose');
    if (btn.disabled) { return; }                 // guard double-click
    btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Disposing stock…';
    try {
        var r = await postAPI({
            action: 'dispose_batch',
            batch_id: document.getElementById('dBatchId').value,
            remarks: document.getElementById('dRemarks').value
        });
        if (r.ok) { disposeModal.hide(); await afterMutation(r.message || 'Stock disposed.', 'Disposing stock…'); }
        else { whToast('error', r.error || 'Error disposing batch.'); }
    } catch (e) { whToast('error', e.handled ? e.message : 'Network error. Please try again.'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="ph ph-recycle me-1"></i> Dispose'; }
}
@else
function disposeBatch() { whToast('error', 'You are not authorized to dispose stock.'); }
@endif

// ── Archive an expired batch (reporting; 30-day retention) ───────
async function archiveBatch(batchId, itemName) {
    if (!(await whConfirm('Archive expired batch', 'Archive the expired batch of "' + itemName + '"? It moves to the Archives (30-day retention) and leaves the active list. Physical stock, ledger, and audit history are unchanged.', 'Archive', 'btn-outline-secondary'))) { return; }
    try {
        var res = await fetch(ARCHIVE_URL, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
            body: JSON.stringify({ batch_id: batchId })
        });
        if (res.status === 419) { whToast('error', 'Your session expired. Please refresh the page.'); return; }
        var r = await res.json().catch(function () { return { ok: false }; });
        if (!res.ok || !r.ok) { whToast('error', r.error || 'Error archiving batch.'); return; }
        @if($canArchiveBatches) refreshArchivesCount(); @endif
        await afterMutation(r.message || 'Batch archived.', 'Archiving…');
    } catch (e) { whToast('error', 'Network error. Please try again.'); }
}

// ── Archives viewer (reuses reports.archives) ────────────────────
@if($canArchiveBatches)
var whArchPage = 1, whArchQuery = '', whArchDebounce;

function initArchivesViewer() {
    document.getElementById('whArchivesBtn').addEventListener('click', function () { whArchPage = 1; loadWhArchives(); whArchivesModal.show(); });
    document.getElementById('whArchSearch').addEventListener('input', function () {
        clearTimeout(whArchDebounce);
        whArchDebounce = setTimeout(function () { whArchQuery = document.getElementById('whArchSearch').value.trim(); whArchPage = 1; loadWhArchives(); }, 300);
    });
    document.getElementById('whArchPrev').addEventListener('click', function () { if (whArchPage > 1) { whArchPage--; loadWhArchives(); } });
    document.getElementById('whArchNext').addEventListener('click', function () { whArchPage++; loadWhArchives(); });
}

function refreshArchivesCount() {
    fetch(ARCHIVES_URL + '?per_page=5&page=1', { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { return r.json(); })
        .then(function (res) { if (res.ok) { document.getElementById('whArchivesCount').textContent = res.total; } })
        .catch(function () {});
}

function loadWhArchives() {
    var loading = document.getElementById('whArchLoading');
    var errBox = document.getElementById('whArchError');
    var body = document.getElementById('whArchBody');
    loading.style.display = ''; errBox.style.display = 'none'; body.innerHTML = '';
    var qs = new URLSearchParams({ q: whArchQuery, page: whArchPage });
    fetch(ARCHIVES_URL + '?' + qs.toString(), { headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) { if (!r.ok) { throw new Error('http'); } return r.json(); })
        .then(function (res) {
            loading.style.display = 'none';
            if (!res.ok) { errBox.textContent = 'Could not load archives.'; errBox.style.display = ''; return; }
            if (!res.items.length) {
                body.innerHTML = '<tr><td colspan="8" class="text-center text-muted py-4">No archived entries.</td></tr>';
            } else {
                body.innerHTML = res.items.map(function (a) {
                    return '<tr><td>#' + escHtml(a.batch_id) + '</td><td class="fw-semibold">' + escHtml(a.item_name) + '</td>'
                        + '<td class="small">' + escHtml(a.branch_name) + '</td>'
                        + '<td class="text-center">' + escHtml(a.quantity_left) + ' ' + escHtml(a.unit) + '</td>'
                        + '<td>' + escHtml(a.expiry_date) + '</td><td class="small">' + escHtml(a.archived_at) + '</td>'
                        + '<td class="small">' + escHtml(a.archived_by) + '</td>'
                        + '<td class="small">' + escHtml(a.removal_at) + '<div class="text-muted" style="font-size:11px">in ' + escHtml(a.days_left) + ' day(s)</div></td></tr>';
                }).join('');
            }
            document.getElementById('whArchTotal').textContent = res.total + ' entr' + (res.total === 1 ? 'y' : 'ies') + ' · page ' + res.page + ' of ' + res.last_page;
            document.getElementById('whArchPrev').disabled = res.page <= 1;
            document.getElementById('whArchNext').disabled = res.page >= res.last_page;
            document.getElementById('whArchivesCount').textContent = res.total;
        })
        .catch(function () { loading.style.display = 'none'; errBox.textContent = 'Network error loading archives.'; errBox.style.display = ''; });
}
@endif

// ── QR Printing ──────────────────────────────────────────────────
function toggleAll(el) {
    var checked = el.checked;
    el.closest('table').querySelectorAll('.qr-cb').forEach(function (cb) {
        var row = cb.closest('.row-active');
        if (row && row.style.display !== 'none') cb.checked = checked;
    });
    updatePrintBtn();
}

function updatePrintBtn() {
    var count = document.querySelectorAll('.qr-cb:checked').length;
    var btn = document.getElementById('btnPrintSelected');
    if (count > 0) {
        btn.classList.remove('d-none');
        btn.innerHTML = '<i class="ph ph-printer me-1"></i> Print ' + count + ' QR' + (count !== 1 ? 's' : '');
    } else {
        btn.classList.add('d-none');
    }
}

function printSelectedQR() {
    var checked = document.querySelectorAll('.qr-cb:checked');
    if (!checked.length) { alert('Select batches first.'); return; }
    var labels = [];
    checked.forEach(function (cb) {
        labels.push({ qr: cb.dataset.qr, name: cb.dataset.name, unit: cb.dataset.unit });
    });
    printQRLabels(labels);
}


</script>
@endpush
