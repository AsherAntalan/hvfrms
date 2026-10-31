@extends('layouts.app')

@section('title', 'Forecast & DSS')

@section('content')


{{-- Info banner --}}
<div class="alert alert-info py-2 small mb-3">
    <i class="ph ph-info me-1"></i>
    <strong>Weighted Moving Average</strong> &mdash; Analyzes the last {{ $forecastMeta['weeks'] }} completed weeks of stock movements
    (out, expiry, damage and adjustments &mdash; not verified sales).
    The most recent {{ $forecastMeta['wma_weeks'] }} weeks are weighted {{ implode('&times;, ', $forecastMeta['wma_weights']) }}&times; (oldest to newest) to reflect current demand trends.
    The suggested order quantity accounts for a safety factor of {{ rtrim(rtrim(number_format($forecastMeta['safety_factor'], 2), '0'), '.') }}&times; and your current recorded stock on hand.
</div>

{{-- Success flash --}}
@if(session('success'))
    <div class="alert alert-success py-2 small mb-3">
        <i class="ph ph-check-circle me-1"></i> {{ session('success') }}
    </div>
@endif

{{-- Branch filter chips (inline navigation, no modal) --}}
<div class="hv-card p-3 mb-3 no-print">
    @include('partials.branch-chips', [
        'chipRoute'    => 'forecast',
        'chipBranches' => $branches,
        'chipSelected' => $selBranch,
        'chipAllValue' => 0,
        'chipAllowAll' => $allowAllBranches,
        'chipMainId'   => $mainBranch?->id,
        'chipPreserve' => [],
    ])
    <div class="small text-muted mt-2">
        Forecast figures below are computed from <strong>{{ $selBranch === 0 ? 'all branches (aggregated)' : ($branches->firstWhere('id', $selBranch)->branch_name ?? 'the selected branch') }}</strong>'s own consumption and stock.
    </div>
</div>

{{-- Branch filter --}}
<div class="hv-card p-3 mb-3">
    <form method="GET" action="{{ route('forecast') }}" class="row g-2 align-items-end">
        <div class="col-md-4">
            <label class="form-label small fw-semibold mb-1">Branch</label>
            <select class="form-select form-select-sm" name="branch">
                @if(!auth()->user()->isBranchScoped())
                <option value="0">All Branches (aggregated)</option>
                @endif
                @foreach($branches as $br)
                    <option value="{{ $br->id }}" {{ $selBranch === (int)$br->id ? 'selected' : '' }}>
                        {{ $br->branch_name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-maroon btn-sm w-100">
                <i class="ph ph-funnel me-1"></i> Generate Forecast
            </button>
        </div>
        <div class="col-md-5 text-end">
            @php
                $nextWeek = \Carbon\Carbon::now()->startOfWeek(\Carbon\Carbon::MONDAY)->addDays(7)->format('Y-m-d');
            @endphp
            <span class="text-muted small">
                Forecast period: <strong>{{ $nextWeek }}</strong> (next week)
            </span>
        </div>
    </form>
</div>

{{-- ═══ FORECAST TABLE ════════════════════════════════════ --}}
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0">
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
                <tr class="{{ $needOrder ? 'table-warning' : '' }}" style="cursor:pointer" onclick="let r=document.getElementById('detail-{{ $loop->index }}');r.style.display=r.style.display==='none'?'':'none';this.querySelector('.chevron').classList.toggle('ph-caret-down');this.querySelector('.chevron').classList.toggle('ph-caret-up');">
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
                {{-- Expandable "Why?" detail row --}}
                <tr id="detail-{{ $loop->index }}" style="display:none">
                    <td colspan="9" class="bg-light px-4 py-3">
                        <div class="row small">
                            <div class="col-md-6">
                                <h6 class="mb-2" style="color:var(--maroon);"><i class="ph ph-lightbulb me-1"></i>Why this recommendation?</h6>
                                <p class="mb-1"><strong>Historical period: {{ $forecastMeta['weeks'] }} weeks</strong>
                                    <span class="text-muted">({{ \Carbon\Carbon::parse($forecastMeta['historical_start'])->format('M j, Y') }} &ndash; {{ \Carbon\Carbon::parse($forecastMeta['historical_end'])->format('M j, Y') }})</span></p>
                                <ul class="mb-2 ps-3">
                                    @foreach($fc['weekly'] as $wi => $wv)
                                        <li>Week {{ $wi + 1 }}: <strong>{{ is_array($wv) ? ($wv['total'] ?? 0) : $wv }}</strong> {{ $item->unit }}</li>
                                    @endforeach
                                </ul>
                                <p class="mb-1"><strong>Average per active week:</strong> {{ $fc['avg_weekly'] }} {{ $item->unit }}/week
                                    <span class="text-muted">({{ $fc['active_weeks'] }} active week{{ $fc['active_weeks'] == 1 ? '' : 's' }} within the {{ $forecastMeta['weeks'] }}-week historical period)</span></p>
                                <p class="mb-1"><strong>Weighted moving average (WMA):</strong> {{ $fc['wma'] }} {{ $item->unit }}/week
                                    @if($fc['wma_fallback'])
                                        <span class="text-muted">(WMA was 0 for the recent weeks; the active-week average is used as the base instead)</span>
                                    @endif
                                </p>
                                <p class="mb-2 text-muted" style="font-size:0.8em;">WMA uses only the most recent {{ $forecastMeta['wma_weeks'] }} completed weeks, weighted {{ implode('&times;, ', $forecastMeta['wma_weights']) }}&times; (oldest to newest). Earlier weeks do not contribute to the WMA.</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Trend:</strong>
                                    <span class="hv-badge {{ $tb[0] }}">{{ $tb[2] }}</span>
                                    @php $g1 = $forecastMeta['trend_first_group']; $g2 = $forecastMeta['trend_second_group']; @endphp
                                    @if($fc['trend'] === 'rising')
                                        &mdash; Demand is increasing (last {{ $g2 }} weeks &gt; first {{ $g1 }} weeks).
                                    @elseif($fc['trend'] === 'falling')
                                        &mdash; Demand is decreasing (last {{ $g2 }} weeks &lt; first {{ $g1 }} weeks).
                                    @elseif($fc['trend'] === 'stable')
                                        &mdash; Demand is steady (first {{ $g1 }} weeks vs last {{ $g2 }} weeks within 15%).
                                    @elseif($fc['trend'] === 'flat')
                                        &mdash; No recorded movement in the period.
                                    @else
                                        &mdash; Not enough data to determine a trend (fewer than 2 active weeks).
                                    @endif
                                </p>
                                <p class="mb-1"><strong>Safety factor:</strong> {{ rtrim(rtrim(number_format($forecastMeta['safety_factor'], 2), '0'), '.') }}&times;
                                    <span class="text-muted">(Target stock = rounded-up forecast &times; safety factor)</span></p>
                                <p class="mb-1"><strong>Forecast demand (next week):</strong> {{ $fc['forecast_qty'] }} {{ $item->unit }}</p>
                                <p class="mb-1"><strong>Target stock:</strong> ceil({{ $fc['forecast_qty'] }} &times; {{ rtrim(rtrim(number_format($forecastMeta['safety_factor'], 2), '0'), '.') }}) = {{ $fc['target_stock'] }} {{ $item->unit }}</p>
                                <p class="mb-1"><strong>Current recorded stock on hand:</strong> {{ $fc['stock_on_hand'] }} {{ $item->unit }}
                                    <span class="text-muted d-block" style="font-size:0.8em;">Recorded remaining quantity; this figure is not filtered for expired or damaged batches.</span></p>
                                <hr class="my-2">
                                <p class="mb-0"><strong>Suggested order:</strong>
                                    @if($fc['recommend_qty'] > 0)
                                        <span class="text-danger fw-bold">{{ $fc['recommend_qty'] }} {{ $item->unit }}</span><br>
                                        <span class="text-muted">= max(0, Target ({{ $fc['target_stock'] }}) &minus; On Hand ({{ $fc['stock_on_hand'] }}))</span>
                                    @else
                                        <span class="text-success">None needed</span> &mdash; recorded stock on hand covers the target.
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

@endsection
