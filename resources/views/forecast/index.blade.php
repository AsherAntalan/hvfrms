@extends('layouts.app')

@section('title', 'Forecast & DSS')

@section('content')


{{-- Info banner --}}
<div class="alert alert-info py-2 small mb-3">
    <i class="bi bi-info-circle me-1"></i>
    <strong>Weighted Moving Average</strong> &mdash; Analyzes the last 8 weeks of consumption data.
    Recent weeks are weighted heavier (4&times;, 3&times;, 2&times;, 1&times;) to reflect current demand trends.
    The recommended order quantity accounts for your current stock on hand.
</div>

{{-- Success flash --}}
@if(session('success'))
    <div class="alert alert-success py-2 small mb-3">
        <i class="bi bi-check-circle me-1"></i> {{ session('success') }}
    </div>
@endif

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
                <i class="bi bi-funnel me-1"></i> Generate Forecast
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
                    <th class="text-center">8-Wk Avg</th>
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
                        $tb = $trendBadges[$fc['trend']] ?? ['hv-badge-secondary', 'bi-question-circle', 'Unknown'];
                        $item = $fc['item'];
                        $lowStock = $fc['stock_on_hand'] > 0 && $fc['stock_on_hand'] <= ($item->reorder_level ?? 0);
                        $needOrder = $fc['recommend_qty'] > 0;
                    @endphp
                <tr class="{{ $needOrder ? 'table-warning' : '' }}" style="cursor:pointer" onclick="let r=document.getElementById('detail-{{ $loop->index }}');r.style.display=r.style.display==='none'?'':'none';this.querySelector('.chevron').classList.toggle('bi-chevron-down');this.querySelector('.chevron').classList.toggle('bi-chevron-up');">
                    <td class="ps-3">
                        <span class="fw-semibold">{{ $item->item_name }}</span>
                        <span class="text-muted small ms-1">({{ $item->unit }})</span>
                    </td>
                    <td class="small">{{ $item->category->name ?? '&mdash;' }}</td>
                    <td class="text-center">{{ $fc['avg_weekly'] }}</td>
                    <td class="text-center fw-semibold">{{ $fc['wma'] }}</td>
                    <td class="text-center">
                        <span class="hv-badge {{ $tb[0] }}">
                            <i class="bi {{ $tb[1] }} me-1"></i>{{ $tb[2] }}
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
                            <span class="text-success"><i class="bi bi-check-circle me-1"></i>Stocked</span>
                        @endif
                        <i class="bi bi-chevron-down chevron text-muted ms-1 small"></i>
                    </td>
                </tr>
                {{-- Expandable "Why?" detail row --}}
                <tr id="detail-{{ $loop->index }}" style="display:none">
                    <td colspan="9" class="bg-light px-4 py-3">
                        <div class="row small">
                            <div class="col-md-6">
                                <h6 class="mb-2" style="color:var(--maroon);"><i class="bi bi-lightbulb me-1"></i>Why this recommendation?</h6>
                                <p class="mb-1"><strong>Historical period (8 weeks):</strong></p>
                                <ul class="mb-2 ps-3">
                                    @foreach($fc['weekly'] as $wi => $wv)
                                        <li>Week {{ $wi + 1 }}: <strong>{{ $wv }}</strong> {{ $item->unit }}</li>
                                    @endforeach
                                </ul>
                                <p class="mb-1"><strong>Simple average demand:</strong> {{ $fc['avg_weekly'] }} {{ $item->unit }}/week</p>
                                <p class="mb-1"><strong>Weighted moving average (WMA):</strong> {{ $fc['wma'] }} {{ $item->unit }}/week</p>
                                <p class="mb-2 text-muted" style="font-size:0.8em;">WMA weights: most recent 4 weeks at 4x, 3x, 2x, 1x; older weeks at 1x.</p>
                            </div>
                            <div class="col-md-6">
                                <p class="mb-1"><strong>Trend:</strong>
                                    <span class="hv-badge {{ $tb[0] }}">{{ $tb[2] }}</span>
                                    @if($fc['trend'] === 'up')
                                        &mdash; Demand is increasing (last 4 weeks &gt; first 4 weeks).
                                    @elseif($fc['trend'] === 'down')
                                        &mdash; Demand is decreasing (last 4 weeks &lt; first 4 weeks).
                                    @elseif($fc['trend'] === 'stable')
                                        &mdash; Demand is steady across the 8-week window.
                                    @else
                                        &mdash; Not enough data to determine a trend.
                                    @endif
                                </p>
                                <p class="mb-1"><strong>Safety factor:</strong> 1.5&times; (applied to WMA to buffer against demand spikes)</p>
                                <p class="mb-1"><strong>Forecast demand (next week):</strong> {{ $fc['forecast_qty'] }} {{ $item->unit }}</p>
                                <p class="mb-1"><strong>Current usable stock on hand:</strong> {{ $fc['stock_on_hand'] }} {{ $item->unit }}</p>
                                <hr class="my-2">
                                <p class="mb-0"><strong>Recommended restock:</strong>
                                    @if($fc['recommend_qty'] > 0)
                                        <span class="text-danger fw-bold">{{ $fc['recommend_qty'] }} {{ $item->unit }}</span><br>
                                        <span class="text-muted">= Forecast ({{ $fc['forecast_qty'] }}) &minus; On Hand ({{ $fc['stock_on_hand'] }})</span>
                                    @else
                                        <span class="text-success">None needed</span> &mdash; stock on hand covers forecasted demand.
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
            <p class="mb-1"><strong>8-Wk Avg:</strong> Simple average of weekly consumption over the last 8 weeks.</p>
            <p class="mb-1"><strong>WMA:</strong> Weighted Moving Average &mdash; recent weeks count more.</p>
            <p class="mb-1"><strong>Forecast:</strong> Predicted consumption for next week (WMA adjusted by trend).</p>
        </div>
        <div class="col-md-6">
            <p class="mb-1"><strong>On Hand:</strong> Current stock available across all batches.</p>
            <p class="mb-1"><strong>Recommend Order:</strong> Forecast minus On Hand. If positive, you need to order this much.</p>
            <p class="mb-1"><strong>Trend:</strong> Compares first 4 weeks vs last 4 weeks of consumption.</p>
        </div>
    </div>
</div>

@endsection
