@extends('layouts.app')

@section('title', 'Inventory')

@section('content')

@include('partials.production-shipments')

<div class="hv-page-header">
<div><strong>{{ $scopeLabel }}</strong><div class="small text-muted">Branch stock, open commissary orders, and disposal history.</div></div>
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-sm d-none" id="btnPrintSelected" onclick="printSelectedQR()">
            <i class="bi bi-printer me-1"></i> Print Selected QR
        </button>

    </div>
</div>

<style>.inventory-branch-toggle[aria-expanded="true"] .bi-chevron-right {display:inline-block;transform:rotate(90deg);}</style>
<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="hv-card p-3 text-center">
            <div class="text-muted small mb-1">Active Stock</div>
            <div class="display-6 fw-bold text-maroon">{{ number_format($totalStock) }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="hv-card p-3 text-center">
            <div class="text-muted small mb-1">Products</div>
            <div class="display-6 fw-bold text-maroon">{{ $productCount }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="hv-card p-3 text-center">
            <div class="text-muted small mb-1">Low Stock</div>
            <div class="display-6 fw-bold text-warning">{{ $lowStockCount }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="hv-card p-3 text-center">
            <div class="text-muted small mb-1">Expired</div>
            <div class="display-6 fw-bold text-danger">{{ count($expiredBatches) }}</div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabActive" type="button">
            <i class="bi bi-check-circle me-1"></i> Active Stock
            <span class="badge bg-success ms-1">{{ count($activeBatches) }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabExpired" type="button">
            <i class="bi bi-archive me-1"></i> Expired Stock
            <span class="badge bg-danger ms-1">{{ count($expiredBatches) }}</span>
        </button>
    </li>
    @unless($isWarehouseStaff)
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabOrders" type="button">
            <i class="bi bi-cart me-1"></i> Pending Orders
            <span class="badge bg-warning text-dark ms-1">{{ count($pendingOrders) }}</span>
        </button>
    </li>
    @endunless

    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDisposal" type="button">
            <i class="bi bi-recycle me-1"></i> Disposal Records
            <span class="badge bg-secondary ms-1">{{ count($disposalRecords) }}</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    {{-- ═══ ACTIVE STOCK ═══════════════════════════════════ --}}
    <div class="tab-pane fade show active" id="tabActive">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
            @unless($monitorAllBranches)
            <div class="p-3 border-bottom">
                <input type="text" id="searchActive" class="form-control form-control-sm"
                       placeholder="Search active stock..." style="max-width:300px;" oninput="filterRows('active')">
            </div>
            @endunless
            @if($monitorAllBranches)
                @include('partials.inventory-branch-groups',['kind'=>'active'])
            @else
                @include('partials.inventory-active-table')
            @endif
        </div>
    </div>

    {{-- ═══ EXPIRED STOCK ═══════════════════════════════════ --}}
    <div class="tab-pane fade" id="tabExpired">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
            @unless($monitorAllBranches)
            <div class="p-3 border-bottom">
                <input type="text" id="searchExpired" class="form-control form-control-sm"
                       placeholder="Search expired stock..." style="max-width:300px;" oninput="filterRows('expired')">
            </div>
            @endunless
            @if($monitorAllBranches)
                @include('partials.inventory-branch-groups',['kind'=>'expired'])
            @else
                @include('partials.inventory-expired-table')
            @endif
        </div>
    </div>

    {{-- ═══ PENDING ORDERS ════════════════════════════════ --}}
    @unless($isWarehouseStaff)
    <div class="tab-pane fade" id="tabOrders">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
            @if($monitorAllBranches)
                @include('partials.inventory-branch-groups',['kind'=>'orders'])
            @else
                @include('partials.inventory-orders-table')
            @endif
        </div>
    </div>
    @endunless

    {{-- ═══ PRODUCTS ═════════════════════════════════════════ --}}


    {{-- ═══ DISPOSAL RECORDS ═══════════════════════════════ --}}
    <div class="tab-pane fade" id="tabDisposal">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
            @if($monitorAllBranches)
                @include('partials.inventory-branch-groups',['kind'=>'disposal'])
            @else
                @include('partials.inventory-disposal-table')
            @endif
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
                    <i class="bi bi-sliders me-1"></i> Adjust Stock Quantity
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
                    <i class="bi bi-check-lg me-1"></i> Submit Adjustment
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ═══ ADD / EDIT PRODUCT MODAL ══════════════════════════ --}}


@endsection

@include('partials.inventory-qr-print')

@push('scripts')
<script>
const API  = '{{ route("warehouse.api") }}';
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

@if($canEditBatches) let adjustModal; @endif

document.addEventListener('DOMContentLoaded', function () {
    @if($canEditBatches) adjustModal = new bootstrap.Modal(document.getElementById('adjustModal')); @endif


});

// -- Helpers --
async function postAPI(fields) {
    var fd = new FormData();
    for (var k in fields) fd.append(k, fields[k]);
    var res = await fetch(API, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF }
    });
    return res.json();
}

function filterInventoryBranches(input) {
    const query=input.value.trim().toLowerCase();
    document.querySelectorAll('.inventory-branch-row[data-kind="'+input.dataset.kind+'"]').forEach(row=>{
        const visible=row.dataset.search.includes(query); row.hidden=!visible;
        document.getElementById(row.dataset.details).closest('tr').hidden=!visible;
    });
}

// -- Filter --
function filterRows(type) {
    var q = document.getElementById('search' + (type === 'active' ? 'Active' : 'Expired')).value.toLowerCase();
    document.querySelectorAll('.row-' + type).forEach(function (row) {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
}

// -- Adjust Batch --
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
    if (!reason) { alert('Please select a reason for the adjustment.'); return; }

    var btn = document.getElementById('btnAdjust');
    btn.disabled = true; btn.textContent = 'Saving...';

    try {
        var r = await postAPI({
            action: 'adjust_batch',
            batch_id: document.getElementById('aBatchId').value,
            new_quantity: document.getElementById('aNewQty').value,
            reason: reason,
            remarks: document.getElementById('aRemarks').value
        });
        if (r.ok) { adjustModal.hide(); location.reload(); }
        else { alert(r.error || 'Error adjusting batch.'); }
    } catch (e) { alert('Network error.'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Submit Adjustment'; }
}
@endif

// -- Void Batch --
async function voidBatch(batchId, itemName, qty) {
    if (!confirm('Void batch for "' + itemName + '" (' + qty + ' units)?\n\nThis will zero out the batch quantity and create a reversal transaction. The batch history is preserved.')) return;

    try {
        var r = await postAPI({ action: 'void_batch', batch_id: batchId });
        if (r.ok) { alert(r.message || 'Batch voided.'); location.reload(); }
        else { alert(r.error || 'Error voiding batch.'); }
    } catch (e) { alert('Network error.'); }
}

// -- Dispose Batch --
async function disposeBatch(batchId, itemName, qty) {
    var remarks = prompt('Dispose ' + qty + ' unit(s) of "' + itemName + '" as expired?\n\nOptional remarks:');
    if (remarks === null) return; // cancelled

    try {
        var r = await postAPI({ action: 'dispose_batch', batch_id: batchId, remarks: remarks });
        if (r.ok) { alert(r.message); location.reload(); }
        else { alert(r.error || 'Error disposing batch.'); }
    } catch (e) { alert('Network error.'); }
}

// Archive an expired batch (hides it from the active pending-review list;
// 30-day retention). Posts to the branch-scoped reports.archive endpoint.
async function archiveBatch(batchId, itemName) {
    if (!confirm('Archive the expired batch of "' + itemName + '"?\n\nIt moves to the Expired Archive and leaves the active list.')) return;
    try {
        var res = await fetch('{{ route('reports.archive') }}', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': '{{ csrf_token() }}', 'Accept': 'application/json' },
            body: JSON.stringify({ batch_id: batchId })
        });
        var r = await res.json();
        if (r.ok) { alert(r.message || 'Batch archived.'); location.reload(); }
        else { alert(r.error || 'Error archiving batch.'); }
    } catch (e) { alert('Network error.'); }
}

// -- Fulfill Order --
@unless($isWarehouseStaff)

@endunless

// -- QR Printing --
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
        btn.innerHTML = '<i class="bi bi-printer me-1"></i> Print ' + count + ' QR' + (count !== 1 ? 's' : '');
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
