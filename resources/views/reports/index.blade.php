@extends('layouts.app')

@section('title', 'Reports')

@section('content')


<!-- Filter Bar -->
<div class="hv-card p-3 mb-3">
    <form method="GET" action="{{ route('reports') }}" class="row g-2 align-items-end">
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Date From</label>
            <input type="date" class="form-control form-control-sm" name="from" value="{{ $dateFrom }}">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Date To</label>
            <input type="date" class="form-control form-control-sm" name="to" value="{{ $dateTo }}">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Branch</label>
            <select class="form-select form-select-sm" name="branch">
                @unless(auth()->user()->isBranchScoped())
                <option value="0">All Branches</option>
                @endunless
                @foreach($branches as $br)
                    <option value="{{ $br->id }}" {{ $branchFilter === $br->id ? 'selected' : '' }}>
                        {{ $br->branch_name }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-maroon btn-sm w-100">
                <i class="bi bi-funnel me-1"></i> Apply Filters
            </button>
        </div>
    </form>
</div>

<!-- Report Tabs -->
<ul class="nav nav-tabs mb-3" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabStock">
            <i class="bi bi-boxes me-1"></i> Stock Summary
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabMovement">
            <i class="bi bi-arrow-left-right me-1"></i> Stock Movements
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabOrders">
            <i class="bi bi-cart3 me-1"></i> Order Summary
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabExpiry">
            <i class="bi bi-calendar-x me-1"></i> Expiry Report
            @if(count($expired) > 0)
                <span class="badge bg-danger ms-1">{{ count($expired) }}</span>
            @endif
        </button>
    </li>
</ul>

<div class="tab-content">

<!-- Tab 1: Stock Summary -->
<div class="tab-pane fade show active" id="tabStock">
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
                <tbody>
                @if(empty($stockRows))
                    <tr><td colspan="8" class="text-center text-muted py-4">No stock data found.</td></tr>
                @else
                    @foreach($stockRows as $sr)
                        @php
                            $low = $sr->total_qty <= $sr->reorder_level;
                            $days = $sr->nearest_expiry ? (int)((strtotime($sr->nearest_expiry) - time()) / 86400) : null;
                        @endphp
                        <tr>
                            <td class="ps-3 fw-semibold">{{ $sr->item_name }}</td>
                            <td class="small">{{ $sr->category_name ?? '&mdash;' }}</td>
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
    <div class="text-muted small mt-2">{{ count($stockRows) }} row(s) with stock on hand.</div>
</div>

<!-- Tab 2: Stock Movements -->
<div class="tab-pane fade" id="tabMovement">
    <div class="text-muted small mb-2">
        Showing movements from <strong>{{ $dateFrom }}</strong> to <strong>{{ $dateTo }}</strong>.
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
                        <th class="text-center text-warning">Adjustments</th>
                        <th class="text-center">Net</th>
                    </tr>
                </thead>
                <tbody>
                @if(empty($moveRows))
                    <tr><td colspan="7" class="text-center text-muted py-4">No movements in this period.</td></tr>
                @else
                    @php
                        $grandIn = $grandOut = $grandAdj = 0;
                    @endphp
                    @foreach($moveRows as $mr)
                        @php
                            $net = $mr->total_in - $mr->total_out - $mr->total_adj;
                            $grandIn  += $mr->total_in;
                            $grandOut += $mr->total_out;
                            $grandAdj += $mr->total_adj;
                        @endphp
                        <tr>
                            <td class="ps-3 fw-semibold">{{ $mr->item_name }}</td>
                            <td class="small">{{ $mr->category_name ?? '&mdash;' }}</td>
                            <td class="small">{{ $mr->branch_name }}</td>
                            <td class="text-center text-success fw-semibold">+{{ (int)$mr->total_in }}</td>
                            <td class="text-center text-danger fw-semibold">-{{ (int)$mr->total_out }}</td>
                            <td class="text-center text-warning fw-semibold">-{{ (int)$mr->total_adj }}</td>
                            <td class="text-center fw-bold {{ $net >= 0 ? 'text-success' : 'text-danger' }}">
                                {{ $net >= 0 ? '+' : '' }}{{ $net }}
                            </td>
                        </tr>
                    @endforeach
                    @php $gn = $grandIn - $grandOut - $grandAdj; @endphp
                    <tr class="table-light fw-bold">
                        <td class="ps-3" colspan="3">GRAND TOTAL</td>
                        <td class="text-center text-success">+{{ $grandIn }}</td>
                        <td class="text-center text-danger">-{{ $grandOut }}</td>
                        <td class="text-center text-warning">-{{ $grandAdj }}</td>
                        <td class="text-center">{{ ($gn >= 0 ? '+' : '') . $gn }}</td>
                    </tr>
                @endif
                </tbody>
            </table>
        </div>
    </div>
    <div class="text-muted small mt-2">{{ count($moveRows) }} row(s).</div>
</div>

<!-- Tab 3: Order Summary -->
<div class="tab-pane fade" id="tabOrders">
    <div class="text-muted small mb-2">
        Orders from <strong>{{ $dateFrom }}</strong> to <strong>{{ $dateTo }}</strong>.
    </div>

    <!-- Summary cards -->
    <div class="row g-2 mb-3">
        <div class="col-6 col-md-3">
            <div class="hv-card p-3 text-center">
                <div class="text-muted small">Total Orders</div>
                <div class="fs-4 fw-bold" style="color:var(--maroon);">{{ $orderGrand['orders'] }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="hv-card p-3 text-center">
                <div class="text-muted small">Pending</div>
                <div class="fs-4 fw-bold text-warning">{{ $orderGrand['pending'] }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="hv-card p-3 text-center">
                <div class="text-muted small">Settled</div>
                <div class="fs-4 fw-bold text-success">{{ $orderGrand['settled'] }}</div>
            </div>
        </div>
        <div class="col-6 col-md-3">
            <div class="hv-card p-3 text-center">
                <div class="text-muted small">Total Value</div>
                <div class="fs-4 fw-bold" style="color:var(--maroon);">&#8369;{{ number_format($orderGrand['value'], 2) }}</div>
            </div>
        </div>
    </div>

    <div class="hv-card p-0">
        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0">
                <thead>
                    <tr>
                        <th class="ps-3">Branch</th>
                        <th class="text-center">Total</th>
                        <th class="text-center">Pending</th>
                        <th class="text-center">Approved</th>
                        <th class="text-center">Delivering</th>
                        <th class="text-center">Delivered</th>
                        <th class="text-center">Settled</th>
                        <th class="text-center">Cancelled</th>
                        <th class="text-end">Value (excl. cancelled)</th>
                    </tr>
                </thead>
                <tbody>
                @if(empty($orderRows))
                    <tr><td colspan="9" class="text-center text-muted py-4">No orders in this period.</td></tr>
                @else
                    @foreach($orderRows as $or)
                    <tr>
                        <td class="ps-3 fw-semibold">{{ $or->branch_name }}</td>
                        <td class="text-center fw-bold">{{ $or->total_orders }}</td>
                        <td class="text-center">{{ $or->pending_count ?: '&mdash;' }}</td>
                        <td class="text-center">{{ $or->approved_count ?: '&mdash;' }}</td>
                        <td class="text-center">{{ $or->delivering_count ?: '&mdash;' }}</td>
                        <td class="text-center">{{ $or->delivered_count ?: '&mdash;' }}</td>
                        <td class="text-center text-success fw-semibold">{{ $or->settled_count ?: '&mdash;' }}</td>
                        <td class="text-center text-danger">{{ $or->cancelled_count ?: '&mdash;' }}</td>
                        <td class="text-end fw-semibold">&#8369;{{ number_format($or->total_value, 2) }}</td>
                    </tr>
                    @endforeach
                @endif
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- Tab 4: Expiry Report -->
<div class="tab-pane fade" id="tabExpiry">
    <!-- Expired -->
    @if(!empty($expired))
    <div class="alert alert-danger py-2 small mb-3">
        <i class="bi bi-exclamation-triangle-fill me-1"></i>
        <strong>{{ count($expired) }}</strong> batch(es) have EXPIRED and still have stock remaining.
    </div>

    <h6 class="text-danger mb-2"><i class="bi bi-x-circle me-1"></i>Expired Batches</h6>
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
                        <th>Days Overdue</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach($expired as $e)
                    <tr class="table-danger">
                        <td class="ps-3">#{{ $e->batch_id }}</td>
                        <td class="fw-semibold">{{ $e->item_name }}</td>
                        <td class="small">{{ $e->branch_name }}</td>
                        <td class="text-center">{{ $e->quantity_left }} {{ $e->unit }}</td>
                        <td>{{ date('M j, Y', strtotime($e->expiry_date)) }}</td>
                        <td class="fw-bold text-danger">{{ abs($e->days_left) }} day(s)</td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    <!-- Expiring soon -->
    @if(!empty($expiringSoon))
    <h6 class="text-warning mb-2"><i class="bi bi-exclamation-circle me-1"></i>Expiring Within 7 Days</h6>
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
            <i class="bi bi-check-circle fs-3 text-success d-block mb-2"></i>
            No expired or soon-to-expire batches found. All stock is within shelf life.
        </div>
    @endif

    <!-- All batches with expiry -->
    @if(!empty($expiryOk))
    <h6 class="text-muted mb-2 mt-3"><i class="bi bi-calendar-check me-1"></i>All Other Batches with Expiry ({{ count($expiryOk) }})</h6>
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

@endsection
