@extends('layouts.app')

@section('title', 'Forecast & DSS')

@section('content')


{{-- Info banner --}}
<p class="small text-muted mb-3"><strong>Weighted Moving Average</strong> &mdash; last {{ $forecastMeta['weeks'] }} weeks of stock movement</p>

{{-- Success flash is shown once by the shared success modal (layouts.app). --}}

{{-- Branch + product filter bar (matches the All-Branches Inventory monitor) --}}
<div class="hv-card p-0 mb-3 no-print">
    <div class="hv-inv-filter p-3">
        <div class="row g-2 align-items-center">
            <div class="col-12 col-lg">
                <input type="search" class="form-control form-control-sm" id="fcSearch" placeholder="Search product or category…" aria-label="Search products">
            </div>
            <div class="col-6 col-md-4 col-lg-auto">
                <select class="form-select form-select-sm" id="fcBranch" aria-label="Filter by branch" onchange="if(this.value){window.location.href=this.value;}">
                    @if($allowAllBranches)
                    <option value="{{ route('forecast', ['branch' => 0]) }}" @selected($selBranch === 0)>All Branches</option>
                    @endif
                    @foreach($branches as $br)
                    <option value="{{ route('forecast', ['branch' => $br->id]) }}" @selected($selBranch === (int) $br->id)>{{ $br->branch_name }}@if($mainBranch && (int) $br->id === (int) $mainBranch->id) · Main Branch @endif</option>
                    @endforeach
                </select>
            </div>
            <div class="col-6 col-md-4 col-lg-auto">
                <select class="form-select form-select-sm" id="fcSort" aria-label="Sort products">
                    <option value="az">Product A&ndash;Z</option>
                    <option value="za">Product Z&ndash;A</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="button" class="btn btn-sm btn-outline-secondary" id="fcClear"><i class="ph ph-x" aria-hidden="true"></i> Clear Filters</button>
            </div>
        </div>
    </div>
    <div class="px-3 pb-3 pt-2 small text-muted">
        Forecast figures below are computed from <strong>{{ $selBranch === 0 ? 'all branches (aggregated)' : ($branches->firstWhere('id', $selBranch)->branch_name ?? 'the selected branch') }}</strong>'s own consumption and stock.
    </div>
</div>

<div class="small text-muted text-end mb-3">
    Forecast period: <strong>{{ $forecastMeta['target_start'] }}</strong> to <strong>{{ $forecastMeta['target_end'] }}</strong>
</div>
{{-- ═══ FORECAST TABLE ════════════════════════════════════ --}}
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0" id="forecastTable">
            <thead>
                <tr>
                    <th class="ps-3">Product</th>
                    <th>Category</th>
                    <th class="text-center">Avg/Active Wk</th>
                    <th class="text-center">WMA</th>
                    <th class="text-center">Trend</th>
                    <th class="text-center">Forecast</th>
                    <th class="text-center">On Hand</th>
                    <th class="text-center">Reorder Lvl</th>
                    <th class="text-center fw-bold" style="color:var(--maroon);">Recommend Order</th>
                </tr>
            </thead>
            <tbody>
            @if(empty($forecasts))
                <tr><td colspan="9" class="text-center text-muted py-4">No products found.</td></tr>
            @else
                @foreach($forecasts as $fc)
                    @php
                        $tb = $trendBadges[$fc['trend']] ?? ['hv-badge-secondary', 'ph-question', 'Unknown'];
                        $item = $fc['item'];
                        $lowStock = $fc['stock_on_hand'] > 0 && $fc['stock_on_hand'] <= ($item->reorder_level ?? 0);
                        $needOrder = $fc['recommend_qty'] > 0;
                    @endphp
                <tr class="fc-row {{ $needOrder ? 'table-warning' : '' }}" style="cursor:pointer" data-idx="{{ $loop->index }}" onclick="forecastToggle(this, {{ $loop->index }})">
                    <td class="ps-3">
                        <span class="fw-semibold">{{ $item->item_name }}</span>
                        <span class="text-muted small ms-1">({{ $item->unit }})</span>
                    </td>
                    <td class="small">{{ $item->category->name ?? '&mdash;' }}</td>
                    <td class="text-center">{{ $fc['avg_weekly'] }}</td>
                    <td class="text-center fw-semibold">{{ $fc['wma'] }}</td>
                    <td class="text-center">
                        <span class="hv-badge {{ $tb[0] }}">
                            <i class="ph {{ $tb[1] }} me-1"></i>{{ $tb[2] }}
                        </span>
                    </td>
                    <td class="text-center fw-bold">{{ $fc['forecast_qty'] }}</td>
                    <td class="text-center {{ $lowStock ? 'text-danger fw-bold' : '' }}">
                        {{ $fc['stock_on_hand'] }}
                    </td>
                    <td class="text-center text-muted">{{ $item->reorder_level }}</td>
                    <td class="text-center">
                        @if($fc['trend'] === 'insufficient')
                            <span class="text-muted small">N/A</span>
                        @elseif($needOrder)
                            <span class="fw-bold text-danger fs-6">{{ $fc['recommend_qty'] }}</span>
                        @else
                            <span class="text-success"><i class="ph ph-check-circle me-1"></i>Stocked</span>
                        @endif
                        <i class="ph ph-caret-down chevron text-muted ms-1 small"></i>
                    </td>
                </tr>
                <tr id="detail-{{ $loop->index }}" style="display:none">
                    <td colspan="9" class="bg-light px-4 py-3">
                        <div class="row small">
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Historical period:</strong> {{ $forecastMeta['weeks'] }} weeks
                                    <span class="text-muted">({{ \Carbon\Carbon::parse($forecastMeta['historical_start'])->format('M j, Y') }} &ndash; {{ \Carbon\Carbon::parse($forecastMeta['historical_end'])->format('M j, Y') }})</span></p>
                                <ul class="mb-2 ps-3">
                                    @foreach($fc['weekly'] as $wi => $wv)
                                        <li>Week {{ $wi + 1 }}: <strong>{{ is_array($wv) ? ($wv['total'] ?? 0) : $wv }}</strong> {{ $item->unit }}</li>
                                    @endforeach
                                </ul>
                                <p class="mb-1"><strong>Average per active week:</strong> {{ $fc['avg_weekly'] }} {{ $item->unit }}</p>
                                <p class="mb-1"><strong>Weighted moving average:</strong> {{ $fc['wma'] }} {{ $item->unit }}</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Trend:</strong> <span class="hv-badge {{ $tb[0] }}">{{ $tb[2] }}</span></p>
                                <p class="mb-1"><strong>Safety factor:</strong> {{ rtrim(rtrim(number_format($forecastMeta['safety_factor'], 2), '0'), '.') }}&times;</p>
                                <p class="mb-1"><strong>Forecast (next week):</strong> {{ $fc['forecast_qty'] }} {{ $item->unit }}</p>
                                <p class="mb-1"><strong>Target stock:</strong> {{ $fc['target_stock'] }} {{ $item->unit }}</p>
                                <p class="mb-1"><strong>Stock on hand:</strong> {{ $fc['stock_on_hand'] }} {{ $item->unit }}</p>
                                <hr class="my-2">
                                <p class="mb-0"><strong>Suggested order:</strong>
                                    @if($fc['recommend_qty'] > 0)
                                        <span class="text-danger fw-bold">{{ $fc['recommend_qty'] }} {{ $item->unit }}</span>
                                    @else
                                        <span class="text-success">None needed</span>
                                    @endif
                                </p>
                            </div>
                        </div>
                    </td>
                </tr>
                @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>

<div class="text-muted small mt-2">
    {{ count($forecasts) }} product(s) analyzed.
    @if($needsReorder > 0)
        <span class="text-danger fw-semibold ms-2">{{ $needsReorder }} product(s) need reordering.</span>
    @endif
</div>

{{-- ═══ LEGEND ════════════════════════════════════════════ --}}
<div class="hv-card p-3 mt-3">
    <h6 class="mb-2" style="color:var(--maroon);">How to Read This Report</h6>
    <div class="row small">
        <div class="col-md-6">
            <p class="mb-1"><strong>Avg/Active Wk:</strong> Total movement divided by the number of weeks with movement (active weeks), within the {{ $forecastMeta['weeks'] }}-week period.</p>
            <p class="mb-1"><strong>WMA:</strong> Weighted Moving Average over the most recent {{ $forecastMeta['wma_weeks'] }} weeks ({{ implode('&times;, ', $forecastMeta['wma_weights']) }}&times;, oldest to newest). Falls back to Avg/Active Wk when 0.</p>
            <p class="mb-1"><strong>Forecast:</strong> Predicted movement for next week (WMA adjusted by trend).</p>
        </div>
        <div class="col-md-6">
            <p class="mb-1"><strong>On Hand:</strong> Recorded remaining quantity across all batches (not filtered for expired/damaged).</p>
            <p class="mb-1"><strong>Suggested Order:</strong> max(0, Target &minus; On Hand), where Target = ceil(Forecast &times; {{ rtrim(rtrim(number_format($forecastMeta['safety_factor'], 2), '0'), '.') }}).</p>
            <p class="mb-1"><strong>Trend:</strong> Compares first {{ $forecastMeta['trend_first_group'] }} weeks vs last {{ $forecastMeta['trend_second_group'] }} weeks of movement.</p>
        </div>
    </div>
</div>

@push('scripts')
<script>
function forecastToggle(row, idx) {
    const target = document.getElementById('detail-' + idx);
    if (!target) { return; }
    const willOpen = target.style.display === 'none';
    document.querySelectorAll('tr[id^="detail-"]').forEach(function (d) { d.style.display = 'none'; });
    document.querySelectorAll('.chevron').forEach(function (c) {
        c.classList.add('ph-caret-down');
        c.classList.remove('ph-caret-up');
    });
    if (willOpen) {
        target.style.display = '';
        const chev = row.querySelector('.chevron');
        if (chev) { chev.classList.remove('ph-caret-down'); chev.classList.add('ph-caret-up'); }
    }
}

/* Product search + sort over the forecast table (client-side). Branch is a
   server navigation handled by the Branch dropdown. */
(function () {
    var table = document.getElementById('forecastTable');
    var search = document.getElementById('fcSearch');
    var sort = document.getElementById('fcSort');
    var clear = document.getElementById('fcClear');
    if (!table || !search || !sort) { return; }
    var tbody = table.tBodies[0];
    var rows = Array.prototype.slice.call(tbody.querySelectorAll('tr.fc-row'));
    function detailFor(r) { return document.getElementById('detail-' + r.dataset.idx); }
    function collapse(r) {
        var d = detailFor(r);
        if (d) { d.style.display = 'none'; }
        var chev = r.querySelector('.chevron');
        if (chev) { chev.classList.add('ph-caret-down'); chev.classList.remove('ph-caret-up'); }
    }
    function applySearch() {
        var q = (search.value || '').trim().toLowerCase();
        rows.forEach(function (r) {
            var hit = !q || (r.cells[0].textContent + ' ' + r.cells[1].textContent).toLowerCase().indexOf(q) !== -1;
            r.style.display = hit ? '' : 'none';
            collapse(r);
        });
    }
    function applySort() {
        var dir = sort.value === 'za' ? -1 : 1;
        rows.slice().sort(function (a, b) {
            return dir * a.cells[0].textContent.trim().localeCompare(b.cells[0].textContent.trim());
        }).forEach(function (r) {
            tbody.appendChild(r);
            var d = detailFor(r);
            if (d) { tbody.appendChild(d); }
        });
    }
    search.addEventListener('input', applySearch);
    sort.addEventListener('change', function () { applySort(); applySearch(); });
    if (clear) {
        clear.addEventListener('click', function () { search.value = ''; sort.value = 'az'; applySort(); applySearch(); });
    }
})();
</script>
@endpush

@endsection
