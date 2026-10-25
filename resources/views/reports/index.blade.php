@extends('layouts.app')

@section('title', 'Reports')

@section('content')

@php
    $requestedReport = request('tab') === 'orders' ? 'sales' : request('tab');
    $activeReport = in_array($requestedReport, ['stock', 'movements', 'sales', 'expiry'], true) ? $requestedReport : 'stock';
    $dateFilterDescriptions = [
        'stock' => 'Filter by batch received date. Quantities are the current remaining stock of those batches; older batches are excluded.',
        'movements' => 'Filter by stock movement date. Only transactions within the selected dates are included.',
        'sales' => 'Selected sales period. Only verified payments qualify as Commissary Sales; verified payment data is currently unavailable.',
        'expiry' => 'Filter by batch expiry date. Remaining quantities and expiry status are current.',
    ];
@endphp

@if(!empty($dateNotice))
<div class="alert alert-warning py-2 small mb-3"><i class="ph ph-warning me-1"></i>{{ $dateNotice }}</div>
@endif

<!-- One filter panel; the selected report determines which date is filtered. -->
<div class="hv-card p-3 mb-3">
    <form method="GET" action="{{ route('reports') }}" class="row g-2 align-items-end">
        <input type="hidden" name="tab" id="reportTabField" value="{{ $activeReport }}">
        <div class="col-12" id="presetRow">
            <label class="form-label small fw-semibold mb-1 d-block">Period</label>
            <div class="btn-group btn-group-sm" role="group" aria-label="Date presets">
                <button type="button" class="btn hv-preset {{ $datePreset === 'today' ? 'active' : '' }}" data-preset="today">Today</button>
                <button type="button" class="btn hv-preset {{ $datePreset === 'last7' ? 'active' : '' }}" data-preset="last7">Last 7 Days</button>
                <button type="button" class="btn hv-preset {{ $datePreset === 'month' ? 'active' : '' }}" data-preset="month">This Month</button>
                <button type="button" class="btn hv-preset {{ $datePreset === 'custom' ? 'active' : '' }}" data-preset="custom">Custom Range</button>
            </div>
        </div>
        <div class="col-md-3" id="colDateFrom">
            <label class="form-label small fw-semibold mb-1" for="reportDateFrom">Date From</label>
            <input type="date" class="form-control form-control-sm" id="reportDateFrom" name="from" value="{{ $dateFrom }}" aria-describedby="reportDateHelp">
        </div>
        <div class="col-md-3" id="colDateTo">
            <label class="form-label small fw-semibold mb-1" for="reportDateTo">Date To</label>
            <input type="date" class="form-control form-control-sm" id="reportDateTo" name="to" value="{{ $dateTo }}" aria-describedby="reportDateHelp">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Branch</label>
            @if(auth()->user()->isBranchScoped())
                {{-- Branch Managers are pinned to their own branch (enforced server-side) --}}
                <div class="form-control form-control-sm bg-light" style="pointer-events:none;">
                    {{ $branches->firstWhere('id', $branchFilter)->branch_name ?? ($branches->first()->branch_name ?? 'Your branch') }}
                </div>
            @else
                <select class="form-select form-select-sm" name="branch">
                    <option value="0" {{ $branchFilter === 0 ? 'selected' : '' }}>All Branches</option>
                    @foreach($branches as $br)
                        <option value="{{ $br->id }}" {{ $branchFilter === $br->id ? 'selected' : '' }}>
                            {{ $br->branch_name }}
                        </option>
                    @endforeach
                </select>
            @endif
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-maroon btn-sm w-100">
                <i class="ph ph-funnel me-1"></i> Apply Filters
            </button>
        </div>
        <div class="col-12">
            <p class="small text-muted mb-0" id="reportDateHelp" role="status" aria-live="polite">{{ $dateFilterDescriptions[$activeReport] }}</p>
        </div>
    </form>
</div>

<!-- Grouped actions: Archives · Export CSV · Print -->
<div class="d-flex justify-content-end gap-2 mb-3 no-print">
    <button type="button" class="btn btn-outline-secondary btn-sm" id="archivesBtn" data-branch="{{ $branchFilter }}">
        <i class="ph ph-archive-box me-1"></i> Archives
        <span class="badge bg-secondary ms-1" id="archivesCount">{{ $archiveCount }}</span>
    </button>
    <a id="csvExportBtn" href="#" class="btn btn-outline-secondary btn-sm">
        <i class="ph ph-file-csv me-1"></i> Export CSV
    </a>
    <button type="button" class="btn btn-outline-secondary btn-sm" onclick="window.print()">
        <i class="ph ph-printer me-1"></i> Print
    </button>
</div>

<!-- Report Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link {{ $activeReport === 'stock' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tabStock">
            <i class="ph ph-stack me-1"></i> Stock Summary
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link {{ $activeReport === 'movements' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tabMovement">
            <i class="ph ph-arrows-left-right me-1"></i> Stock Movements
        </button>
    </li>
    @if($salesAuthorized)
    <li class="nav-item">
        <button class="nav-link {{ $activeReport === 'sales' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tabSales">
            <i class="ph ph-currency-circle-dollar me-1"></i> Commissary Sales
        </button>
    </li>
    @endif
    <li class="nav-item">
        <button class="nav-link {{ $activeReport === 'expiry' ? 'active' : '' }}" data-bs-toggle="tab" data-bs-target="#tabExpiry">
            <i class="ph ph-calendar-x me-1"></i> Expiry Report
            @if(count($expired) > 0)
                <span class="badge bg-danger ms-1">{{ count($expired) }}</span>
            @endif
        </button>
    </li>
</ul>

<div class="tab-content">

<!-- Tab 1: Stock Summary -->
<div class="tab-pane fade {{ $activeReport === 'stock' ? 'show active' : '' }}" id="tabStock">
    <div class="small text-muted mb-2" id="stockScopeLabel">
        <strong>{{ $scopeText }}</strong> &middot; Batches received: {{ $periodText }} &middot; Remaining stock as of {{ now('Asia/Manila')->format('M j, Y g:i A') }}
    </div>
    <div class="hv-card p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Product</th>
                        <th>Category</th>
                        <th>Type</th>
                        <th>Branch</th>
                        <th class="text-center">Qty on Hand</th>
                        <th class="text-center">Reorder Lvl</th>
                        <th class="text-center">Batches</th>
                        <th>Nearest Expiry</th>
                    </tr>
                </thead>
                <tbody id="stockSummaryBody">
                @if(empty($stockRows))
                    <tr><td colspan="8" class="text-center text-muted py-4">No remaining stock from batches received in this period.</td></tr>
                @else
                    @foreach($stockRows as $sr)
                        @php
                            $low = $sr->total_qty <= $sr->reorder_level;
                            $days = $sr->nearest_expiry ? (int)((strtotime($sr->nearest_expiry) - time()) / 86400) : null;
                        @endphp
                        <tr>
                            <td class="ps-3 fw-semibold">{{ $sr->item_name }}</td>
                            <td class="small">{{ $sr->category_name ?? '—' }}</td>
                            <td><span class="hv-badge {{ ($sr->goods_type ?? '') === 'wet' ? 'hv-badge-info' : 'hv-badge-warning' }}">{{ ucfirst($sr->goods_type ?? 'N/A') }}</span></td>
                            <td class="small">{{ $sr->branch_name }}</td>
                            <td class="text-center fw-semibold {{ $low ? 'text-danger' : '' }}">
                                {{ $sr->total_qty }} {{ $sr->unit }}
                            </td>
                            <td class="text-center text-muted">{{ $sr->reorder_level }}</td>
                            <td class="text-center">{{ $sr->active_batches }}</td>
                            <td>
                                @if($sr->nearest_expiry)
                                    @if($days !== null && $days < 0)
                                        <span class="hv-badge hv-badge-danger">EXPIRED</span>
                                    @elseif($days !== null && $days <= 7)
                                        <span class="hv-badge hv-badge-warning">{{ $days }}d left</span>
                                    @else
                                        <span class="small text-muted">{{ date('M j, Y', strtotime($sr->nearest_expiry)) }}</span>
                                    @endif
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                @endif
                </tbody>
            </table>
        </div>
    </div>
    <div class="text-muted small mt-2">{{ count($stockRows) }} row(s) with remaining stock from batches received in this period.</div>
</div>

<!-- Tab 2: Stock Movements -->
<div class="tab-pane fade {{ $activeReport === 'movements' ? 'show active' : '' }}" id="tabMovement">
    <div class="text-muted small mb-2">
        <strong>{{ $scopeText }}</strong> &middot; {{ $periodText }}
    </div>
    <div class="hv-card p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Product</th>
                        <th>Category</th>
                        <th>Branch</th>
                        <th class="text-center text-success">Stock In</th>
                        <th class="text-center text-danger">Stock Out</th>
                        <th class="text-center">Net</th>
                    </tr>
                </thead>
                <tbody>
                @if(empty($moveRows))
                    <tr><td colspan="6" class="text-center text-muted py-4">No movements in this period.</td></tr>
                @else
                    @php
                        $grandIn = $grandOut = 0;
                    @endphp
                    @foreach($moveRows as $mr)
                        @php
                            // Stock Out already includes sales/usage, disposals, expiry and damage.
                            $net = $mr->total_in - $mr->total_out;
                            $grandIn  += $mr->total_in;
                            $grandOut += $mr->total_out;
                        @endphp
                        <tr>
                            <td class="ps-3 fw-semibold">{{ $mr->item_name }}</td>
                            <td class="small">{{ $mr->category_name ?? '—' }}</td>
                            <td class="small">{{ $mr->branch_name }}</td>
                            <td class="text-center text-success fw-semibold">+{{ (int)$mr->total_in }}</td>
                            <td class="text-center text-danger fw-semibold">{{ (int)$mr->total_out ? '-'.(int)$mr->total_out : 0 }}</td>
                            <td class="text-center fw-bold {{ $net >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $net >= 0 ? '+' : '' }}{{ $net }}
                            </td>
                        </tr>
                    @endforeach
                    @php $gn = $grandIn - $grandOut; @endphp
                    <tr class="table-light fw-bold">
                        <td class="ps-3" colspan="3">GRAND TOTAL</td>
                        <td class="text-center text-success">+{{ $grandIn }}</td>
                        <td class="text-center text-danger">{{ $grandOut ? '-'.$grandOut : 0 }}</td>
                        <td class="text-center">{{ ($gn >= 0 ? '+' : '') . $gn }}</td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
    <div class="text-muted small mt-2">{{ count($moveRows) }} row(s).</div>
    <div class="alert alert-light border small py-2 mt-2 mb-0">
        <strong>How to read this:</strong> <strong>Net = Stock In − Stock Out.</strong>
        Stock Out includes everything that left the shelf — sales/usage, disposals, expiry, and damage.
        A <span class="text-danger">negative Net</span> means stock decreased over the period;
        a <span class="text-success">positive Net</span> means it grew.
    </div>
</div>

<!-- Tab 3: Commissary Sales -->
@if($salesAuthorized)
<div class="tab-pane fade {{ $activeReport === 'sales' ? 'show active' : '' }}" id="tabSales">
    <div class="text-muted small mb-2">
        <strong>{{ $scopeText }}</strong> &middot; {{ $periodText }}
    </div>

    <div class="hv-card p-4">
        <h6 class="mb-2">Commissary Sales</h6>
        <p class="text-muted small mb-3">Verified payments received from branch orders for the selected period.</p>
        <div class="hv-summary-metric p-0 mb-3">
            <div class="hv-summary-label">Verified Sales</div>
            <div class="hv-summary-value" style="color:var(--maroon);">Unavailable</div>
        </div>
        <p class="small mb-0" role="status">Verified payment data is not available yet. Submitted payment proofs and received/closed orders are excluded until payment verification is recorded. Sales totals and CSV export will be available once that data is available.</p>
    </div>
</div>
@endif

<!-- Tab 4: Expiry Report -->
<div class="tab-pane fade {{ $activeReport === 'expiry' ? 'show active' : '' }}" id="tabExpiry">
    <div class="small text-muted mb-2">
        <strong>{{ $scopeText }}</strong> &middot; Expiry dates: {{ $periodText }} &middot; Status as of {{ now('Asia/Manila')->format('M j, Y g:i A') }}
    </div>
    <!-- Expired -->
    @if(!empty($expired))
    <div class="alert alert-danger py-2 small mb-3">
        <i class="ph-fill ph-warning me-1"></i>
        <strong>{{ count($expired) }}</strong> expired batch(es) pending review.
        @if($expiredArchivedCount > 0)
            <span class="text-muted">({{ $expiredArchivedCount }} archived, hidden here but still physically expired.)</span>
        @endif
        <span class="d-block mt-1 text-muted">
            Physical expired stock remaining within this expiry range: <strong>{{ $expiredPhysicalQty }}</strong> unit(s). Archiving is a reporting action — it does not dispose stock or change balances.
        </span>
    </div>

    <h6 class="text-danger mb-2"><i class="ph ph-x-circle me-1"></i>Expired Batches <span class="text-muted fw-normal small">(pending review)</span></h6>
    <div class="hv-card p-0 mb-3">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0" id="expiredTable">
                <thead>
                    <tr>
                        <th class="ps-3">Batch #</th>
                        <th>Product</th>
                        <th>Branch</th>
                        <th class="text-center">Qty Left</th>
                        <th>Expiry Date</th>
                        <th>Days Overdue</th>
                        @if($canArchive)<th class="text-end pe-3 no-print">Action</th>@endif
                    </tr>
                </thead>
                <tbody>
                    @foreach($expired as $e)
                    <tr class="table-danger" data-expired-row="{{ $e->batch_id }}">
                        <td class="ps-3">#{{ $e->batch_id }}</td>
                        <td class="fw-semibold">{{ $e->item_name }}</td>
                        <td class="small">{{ $e->branch_name }}</td>
                        <td class="text-center">{{ $e->quantity_left }} {{ $e->unit }}</td>
                        <td>{{ date('M j, Y', strtotime($e->expiry_date)) }}</td>
                        <td class="fw-bold text-danger">{{ abs($e->days_left) }} day(s)</td>
                        @if($canArchive)
                        <td class="text-end pe-3 no-print">
                            <button type="button" class="btn btn-sm btn-outline-secondary hv-archive-btn"
                                    data-batch="{{ $e->batch_id }}"
                                    data-product="{{ $e->item_name }}"
                                    data-branch="{{ $e->branch_name }}"
                                    data-qty="{{ $e->quantity_left }} {{ $e->unit }}"
                                    data-expiry="{{ date('M j, Y', strtotime($e->expiry_date)) }}">
                                <i class="ph ph-archive-box me-1"></i>Archive
                            </button>
                        </td>
                        @endif
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <!-- Expiring soon -->
    @if(!empty($expiringSoon))
    <h6 class="text-warning mb-2"><i class="ph ph-warning-circle me-1"></i>Expiring Within 7 Days</h6>
    <div class="hv-card p-0 mb-3">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Batch #</th>
                        <th>Product</th>
                        <th>Branch</th>
                        <th class="text-center">Qty Left</th>
                        <th>Expiry Date</th>
                        <th>Days Left</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($expiringSoon as $e)
                    <tr class="table-warning">
                        <td class="ps-3">#{{ $e->batch_id }}</td>
                        <td class="fw-semibold">{{ $e->item_name }}</td>
                        <td class="small">{{ $e->branch_name }}</td>
                        <td class="text-center">{{ $e->quantity_left }} {{ $e->unit }}</td>
                        <td>{{ date('M j, Y', strtotime($e->expiry_date)) }}</td>
                        <td class="fw-bold text-warning">{{ $e->days_left }} day(s)</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    @if(empty($expired) && empty($expiringSoon))
        <div class="hv-card p-4 text-center text-muted">
            <i class="ph ph-check-circle fs-3 text-success d-block mb-2"></i>
            No expired or soon-to-expire batches found within the selected expiry dates.
        </div>
    @endif

    <!-- All batches with expiry -->
    @if(!empty($expiryOk))
    <h6 class="text-muted mb-2 mt-3"><i class="ph ph-calendar-check me-1"></i>All Other Batches with Expiry ({{ count($expiryOk) }})</h6>
    <div class="hv-card p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Batch #</th>
                        <th>Product</th>
                        <th>Branch</th>
                        <th class="text-center">Qty Left</th>
                        <th>Expiry Date</th>
                        <th>Days Left</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($expiryOk as $e)
                    <tr>
                        <td class="ps-3">#{{ $e->batch_id }}</td>
                        <td class="fw-semibold">{{ $e->item_name }}</td>
                        <td class="small">{{ $e->branch_name }}</td>
                        <td class="text-center">{{ $e->quantity_left }} {{ $e->unit }}</td>
                        <td>{{ date('M j, Y', strtotime($e->expiry_date)) }}</td>
                        <td class="text-success">{{ $e->days_left }} day(s)</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif
</div>

</div><!-- /tab-content -->

<!-- ═══ Archives modal ═══ -->
<div class="modal fade" id="archivesModal" tabindex="-1" aria-labelledby="archivesModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-lg modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header" style="background:var(--maroon-dark);color:#fff;">
        <h5 class="modal-title" id="archivesModalLabel"><i class="ph ph-archive-box me-2"></i>Archived Expired Batches</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
      </div>
      <div class="modal-body">
        <div class="d-flex gap-2 mb-2">
          <input type="search" id="archSearch" class="form-control form-control-sm" placeholder="Search product, branch, or batch #">
        </div>
        <div class="alert alert-light border small py-2">
          Entries automatically disappear <strong>30 days after they are archived</strong> (Asia/Manila). Archiving and removal are reporting actions only — physical stock, ledger, and audit history are preserved.
        </div>
        <div id="archLoading" class="text-center text-muted py-4" style="display:none;"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>
        <div class="table-responsive">
          <table class="table table-sm table-hover align-middle mb-0">
            <thead><tr><th>Batch #</th><th>Product</th><th>Branch</th><th class="text-center">Qty</th><th>Expiry</th><th>Archived</th><th>By</th><th>Removes On</th><th class="text-end">Action</th></tr></thead>
            <tbody id="archBody"></tbody>
          </table>
        </div>
        <div class="d-flex justify-content-between align-items-center mt-2">
          <span class="small text-muted" id="archTotal"></span>
          <div class="btn-group btn-group-sm">
            <button class="btn btn-outline-secondary" id="archPrev">Prev</button>
            <button class="btn btn-outline-secondary" id="archNext">Next</button>
          </div>
        </div>
      </div>
    </div>
  </div>
</div>

@push('styles')
<style>
/* Date quick-choice presets — burgundy active state, flat and compact */
.hv-preset{ border:1px solid var(--border); background:#fff; color:var(--ink); font-size:12.5px; }
.hv-preset:hover{ background:var(--gold-soft); border-color:var(--gold); color:var(--maroon-dark); }
.hv-preset.active{ background:var(--maroon); border-color:var(--maroon); color:#fff; }
.hv-preset.active:hover{ background:var(--maroon-dark); color:#fff; }
.hv-preset:focus-visible{ outline:2px solid var(--maroon); outline-offset:2px; }
@media print {
    .hv-sidebar, .hv-topbar, .no-print, .nav-tabs, form, .alert { display: none !important; }
    .tab-pane { display: block !important; opacity: 1 !important; }
    .tab-pane:not(.active) { display: none !important; }
    .hv-card { box-shadow: none !important; border: 1px solid #ddd !important; }
    body { font-size: 12px; }
}
</style>
@endpush

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    'use strict';
    var CSRF = document.querySelector('meta[name="csrf-token"]').content;
    var ROUTES = {
        archives:   "{{ route('reports.archives') }}",
        archive:    "{{ route('reports.archive') }}",
        archiveRm:  "{{ route('reports.archiveRemove') }}",
        export:     "{{ route('reports.export') }}"
    };
    // The authoritative selected branch comes from the server-rendered page.
    function currentBranch(){
        var b = $('archivesBtn'); return b ? (b.dataset.branch || '') : '';
    }
    var $ = function(id){ return document.getElementById(id); };
    function esc(s){ return (s==null?'':String(s)).replace(/[&<>"']/g, function(c){ return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'})[c]; }); }
    function dateVals(){
        var f = document.querySelector('input[name=from]'), t = document.querySelector('input[name=to]');
        return { from: f ? f.value : '', to: t ? t.value : '' };
    }

    // ---- CSV link for the full-page export ----
    var typeMap = {tabStock:'stock', tabMovement:'movements', tabSales:'sales', tabExpiry:'expiry'};
    function updateCsvLink() {
        var activePane = document.querySelector('.tab-content > .tab-pane.active');
        var type = activePane ? (typeMap[activePane.id] || 'stock') : 'stock';
        var exportButton = $('csvExportBtn');
        var salesUnavailable = type === 'sales';
        exportButton.classList.toggle('disabled', salesUnavailable);
        exportButton.setAttribute('aria-disabled', String(salesUnavailable));
        if (salesUnavailable) {
            exportButton.removeAttribute('href');
            exportButton.setAttribute('tabindex', '-1');
            exportButton.title = 'Verified payment data is not available yet.';
            return;
        }
        exportButton.removeAttribute('tabindex');
        exportButton.removeAttribute('title');
        var params = new URLSearchParams(window.location.search);
        params.set('type', type);
        params.set('from', @json($dateFrom));
        params.set('to', @json($dateTo));
        params.set('branch', @json($branchFilter));
        $('csvExportBtn').href = ROUTES.export + "?" + params.toString();
    }
    updateCsvLink();
    document.querySelectorAll('.nav-tabs [data-bs-toggle="tab"]').forEach(function(tab){
        tab.addEventListener('shown.bs.tab', updateCsvLink);
    });

    // Date controls stay visible; explain the date basis of the selected report.
    var dateDescriptions = @json($dateFilterDescriptions);
    function syncFilterControls(){
        var pane = document.querySelector('.tab-content > .tab-pane.active');
        var type = pane ? (typeMap[pane.id] || 'stock') : 'stock';
        $('reportDateHelp').textContent = dateDescriptions[type];
    }
    syncFilterControls();
    document.querySelectorAll('.nav-tabs [data-bs-toggle="tab"]').forEach(function(tab){
        tab.addEventListener('shown.bs.tab', syncFilterControls);
    });

    // ---- Date quick-choice presets (Asia/Manila business date) ----
    function ymd(d){ var m=d.getMonth()+1, day=d.getDate(); return d.getFullYear()+'-'+(m<10?'0':'')+m+'-'+(day<10?'0':'')+day; }
    function presetDates(p){
        var parts = new Intl.DateTimeFormat('en-US', {
            timeZone: 'Asia/Manila', year: 'numeric', month: 'numeric', day: 'numeric'
        }).formatToParts(new Date());
        var businessDate = {};
        parts.forEach(function(part){ businessDate[part.type] = part.value; });
        var now = new Date(Number(businessDate.year), Number(businessDate.month) - 1, Number(businessDate.day));
        if (p === 'today')  { return { from: ymd(now), to: ymd(now) }; }
        if (p === 'last7')  { var s = new Date(now); s.setDate(s.getDate()-6); return { from: ymd(s), to: ymd(now) }; }
        if (p === 'month')  { return { from: ymd(new Date(now.getFullYear(), now.getMonth(), 1)), to: ymd(now) }; }
        return null; // custom → leave dates as-is
    }
    function setActivePreset(p){
        document.querySelectorAll('.hv-preset').forEach(function(b){ b.classList.toggle('active', b.dataset.preset === p); });
    }
    document.querySelectorAll('.hv-preset').forEach(function(btn){
        btn.addEventListener('click', function(){
            var p = btn.dataset.preset;
            var d = presetDates(p);
            if (d) {
                var f = document.querySelector('input[name=from]'), t = document.querySelector('input[name=to]');
                if (f) f.value = d.from;
                if (t) t.value = d.to;
            }
            setActivePreset(p);
        });
    });
    // Editing either date switches the selection to Custom Range.
    ['from','to'].forEach(function(n){
        var el = document.querySelector('input[name='+n+']');
        if (el) el.addEventListener('input', function(){ setActivePreset('custom'); });
    });

    // ---- Keep the open tab across "Apply Filters" (GET reload) ----
    // The URL hash is not submitted with the form, so remember the tab in a
    // hidden field that posts as ?tab=... and re-open it on load.
    var tabField = document.getElementById('reportTabField');
    var paneByType = {stock:'#tabStock', movements:'#tabMovement', sales:'#tabSales', expiry:'#tabExpiry'};
    document.querySelectorAll('.nav-tabs [data-bs-toggle="tab"]').forEach(function(tab){
        tab.addEventListener('shown.bs.tab', function(){
            var pane = document.querySelector('.tab-content > .tab-pane.active');
            if (pane && tabField) tabField.value = typeMap[pane.id] || 'stock';
        });
    });
    (function openSavedTab(){
        var sel = paneByType[tabField ? tabField.value : 'stock'];
        if (sel && sel !== '#tabStock' && !window.location.hash) {
            var tr = document.querySelector('.nav-tabs [data-bs-target="'+sel+'"]');
            if (tr) { try { bootstrap.Tab.getOrCreateInstance(tr).show(); } catch(e){} }
        }
    })();

    // ---- Preserve the active report tab across branch navigation ----
    // Branch chips are plain links (page navigation = one authoritative scope).
    // We remember the open tab in the URL hash so a branch change keeps it.
    document.querySelectorAll('.nav-tabs [data-bs-toggle="tab"]').forEach(function(tab){
        tab.addEventListener('shown.bs.tab', function(){
            var target = tab.getAttribute('data-bs-target');
            if (target) history.replaceState(null, '', window.location.pathname + window.location.search + target);
        });
    });
    (function restoreTab(){
        var h = window.location.hash;
        if (!h) return;
        if (h === '#tabOrders') h = '#tabSales';
        var trigger = document.querySelector('.nav-tabs [data-bs-target="'+h+'"]');
        if (trigger) { try { bootstrap.Tab.getOrCreateInstance(trigger).show(); } catch(e){} }
    })();
    // Carry the active tab onto each branch chip link so navigation keeps it.
    document.querySelectorAll('.hv-branch-chip').forEach(function(chip){
        chip.addEventListener('click', function(){
            var pane = document.querySelector('.tab-content > .tab-pane.active');
            if (pane && chip.href) {
                try { var u = new URL(chip.href); u.hash = '#'+pane.id; chip.href = u.toString(); } catch(e){}
            }
        });
    });

    // ---- Archive a single expired batch ----
    document.querySelectorAll('.hv-archive-btn').forEach(function(btn){
        btn.addEventListener('click', function(){
            var b = btn.dataset;
            if (!confirm('Archive this expired batch?\n\nProduct: '+b.product+'\nBatch: #'+b.batch+'\nBranch: '+b.branch+'\nRemaining: '+b.qty+'\nExpiry: '+b.expiry+'\n\nThis is a reporting action only. Physical stock, balances and ledger are unchanged, and the stock stays blocked.')) return;
            btn.disabled = true; btn.innerHTML = '<span class="spinner-border spinner-border-sm"></span>';
            var fd = new FormData(); fd.append('batch_id', b.batch);
            fetch(ROUTES.archive, { method:'POST', headers:{'X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:fd })
              .then(function(r){ return r.json(); })
              .then(function(res){
                  if(!res.ok){ alert(res.error||'Could not archive.'); btn.disabled=false; btn.innerHTML='<i class="ph ph-archive-box me-1"></i>Archive'; return; }
                  var row = document.querySelector('[data-expired-row="'+b.batch+'"]');
                  if(row) row.remove();
                  bumpArchiveCount(res.already ? 0 : 1);
              })
              .catch(function(){ alert('Network error.'); btn.disabled=false; btn.innerHTML='<i class="ph ph-archive-box me-1"></i>Archive'; });
        });
    });
    function bumpArchiveCount(delta){
        var el = $('archivesCount'); if(!el) return;
        el.textContent = Math.max(0, (parseInt(el.textContent,10)||0) + delta);
    }

    // ---- Archives modal ----
    var archivesModal = new bootstrap.Modal($('archivesModal'));
    var archPage = 1, archQuery = '', archDebounce;
    $('archivesBtn').addEventListener('click', function(){ archPage = 1; loadArchives(); archivesModal.show(); });
    $('archSearch').addEventListener('input', function(){ clearTimeout(archDebounce); archDebounce = setTimeout(function(){ archQuery = $('archSearch').value.trim(); archPage = 1; loadArchives(); }, 300); });
    $('archPrev').addEventListener('click', function(){ if(archPage>1){ archPage--; loadArchives(); } });
    $('archNext').addEventListener('click', function(){ archPage++; loadArchives(); });

    function loadArchives(){
        $('archLoading').style.display=''; $('archBody').innerHTML='';
        var qs = new URLSearchParams({ q: archQuery, page: archPage, branch: currentBranch() });
        fetch(ROUTES.archives + '?' + qs.toString(), { headers:{'X-Requested-With':'XMLHttpRequest'} })
          .then(function(r){ return r.json(); })
          .then(function(res){
              $('archLoading').style.display='none';
              if(!res.ok){ $('archBody').innerHTML='<tr><td colspan="9" class="text-danger text-center py-3">Could not load.</td></tr>'; return; }
              if(!res.items.length){ $('archBody').innerHTML='<tr><td colspan="9" class="text-center text-muted py-4">No archived entries.</td></tr>'; }
              else {
                  $('archBody').innerHTML = res.items.map(function(a){
                      return '<tr><td>#'+esc(a.batch_id)+'</td><td class="fw-semibold">'+esc(a.item_name)+'</td><td class="small">'+esc(a.branch_name)+'</td>'
                        + '<td class="text-center">'+esc(a.quantity_left)+' '+esc(a.unit)+'</td><td>'+esc(a.expiry_date)+'</td>'
                        + '<td class="small">'+esc(a.archived_at)+'</td><td class="small">'+esc(a.archived_by)+'</td>'
                        + '<td class="small">'+esc(a.removal_at)+'<div class="text-muted" style="font-size:11px">in '+esc(a.days_left)+' day(s)</div></td>'
                        + '<td class="text-end"><button class="btn btn-sm btn-outline-danger arch-del" data-id="'+a.id+'"><i class="ph ph-trash"></i></button></td></tr>';
                  }).join('');
                  bindArchDelete();
              }
              $('archTotal').textContent = res.total + ' entr' + (res.total===1?'y':'ies') + ' · page ' + res.page + ' of ' + res.last_page;
              $('archPrev').disabled = res.page <= 1;
              $('archNext').disabled = res.page >= res.last_page;
              $('archivesCount').textContent = res.total;
          })
          .catch(function(){ $('archLoading').style.display='none'; $('archBody').innerHTML='<tr><td colspan="9" class="text-danger text-center py-3">Network error.</td></tr>'; });
    }
    function bindArchDelete(){
        document.querySelectorAll('.arch-del').forEach(function(btn){
            btn.addEventListener('click', function(){
                if(!confirm('Remove this entry from the archive list?\n\nHistorical records, ledger and audit history are preserved.')) return;
                btn.disabled = true;
                var fd = new FormData(); fd.append('id', btn.dataset.id);
                fetch(ROUTES.archiveRm, { method:'POST', headers:{'X-CSRF-TOKEN':CSRF,'X-Requested-With':'XMLHttpRequest'}, body:fd })
                  .then(function(r){ return r.json(); })
                  .then(function(res){ if(!res.ok){ alert(res.error||'Could not remove.'); btn.disabled=false; return; } loadArchives(); })
                  .catch(function(){ alert('Network error.'); btn.disabled=false; });
            });
        });
    }
});
</script>
@endpush

@endsection
