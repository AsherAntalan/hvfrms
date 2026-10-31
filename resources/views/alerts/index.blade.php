@extends('layouts.app')

@section('title', auth()->user()->isCommissaryManager() ? 'Orders Notification' : 'Alerts')

@section('content')

@if(auth()->user()->isCommissaryManager())
<p class="small text-muted mb-3">Branch requests, preparation, and dispatch follow-ups. Expiry warnings apply only to remaining order-linked goods awaiting dispatch.</p>
@endif

<div class="hv-page-header">
    <div>
        @if($countOpen > 0)
            <span class="hv-badge hv-badge-danger">{{ $countOpen }} open</span>
        @endif
    </div>
    @if($countOpen > 0)
    <form method="POST" action="{{ route('alerts.action') }}" class="d-inline"
          onsubmit="return confirm('Resolve all {{ $countOpen }} open alert(s)?');">
        @csrf
        <input type="hidden" name="post_action" value="resolve_all">
        <button type="submit" class="btn btn-sm btn-outline-success">
            <i class="ph ph-checks me-1"></i> Resolve All
        </button>
    </form>
    @endif
</div>

@if($countCritical > 0)
<div class="alert alert-danger py-2 small mb-3">
    <i class="ph-fill ph-warning me-1"></i>
    <strong>{{ $countCritical }}</strong> critical alert(s) require immediate attention.
</div>
@endif

{{-- Branch filter chips (aggregate users only; inline navigation, no modal) --}}
@if($branches->count() > 0)
<div class="hv-card p-3 mb-3 no-print">
    @include('partials.branch-chips', [
        'chipRoute'    => 'alerts',
        'chipBranches' => $branches,
        'chipSelected' => $filterBranch,
        'chipAllValue' => '',
        'chipAllowAll' => $allowAllBranches,
        'chipMainId'   => $mainBranch?->id,
        'chipPreserve' => request()->only(['type', 'resolved']),
        'chipCounts'   => $branchCounts,
    ])
</div>
@endif

<!-- Filter Bar -->
<div class="hv-card p-2 mb-3">
    <form method="GET" action="{{ route('alerts') }}" class="row g-2 align-items-center">
        @if($filterBranch !== '')
            <input type="hidden" name="branch" value="{{ $filterBranch }}">
        @endif
        <div class="col-md-3">
            <select class="form-select form-select-sm" name="type">
                <option value="">All Types</option>
                @foreach($typeBadges as $type => $badge)
                <option value="{{ $type }}" {{ $filterType === $type ? 'selected' : '' }}>{{ $badge[2] }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" name="resolved">
                <option value="0" {{ $filterResolved === '0' ? 'selected' : '' }}>Open Only</option>
                <option value="1" {{ $filterResolved === '1' ? 'selected' : '' }}>Resolved Only</option>
                <option value="all" {{ $filterResolved === 'all' ? 'selected' : '' }}>All</option>
            </select>
        </div>
        <div class="col-md-3">
            <button type="submit" class="btn btn-maroon btn-sm">
                <i class="ph ph-funnel me-1"></i> Filter
            </button>
        </div>
        <div class="col-md-3 text-end">
            <span class="text-muted small">{{ $alerts->count() }} alert(s)</span>
        </div>
    </form>
</div>

@if($operationalOrderCount > 0)
<section class="hv-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center gap-2 mb-3">
        <h6 class="mb-0">Orders needing follow-up <span class="text-muted">({{ $operationalOrderCount }})</span></h6>
        <a href="{{ route('orders') }}" class="btn btn-sm btn-outline-secondary">All Orders</a>
    </div>
    <p class="small text-muted">These follow-ups update with the order status. Use the order workflow to complete them.</p>
    @foreach($operationalOrders as $order)
    <div class="d-flex justify-content-between align-items-center gap-3 border-top py-2">
        <div>
            <strong>{{ $order->order_number }}</strong>
            <span class="small text-muted ms-2">{{ $order->branch?->branch_name }}</span>
            <div class="small">{{ match ($order->status) {
                'pending' => 'Request awaiting review',
                'approved' => 'Approved request; review preparation and dispatch',
                'preparing' => 'Preparation in progress',
                'delivering' => 'Dispatched; awaiting destination receipt',
                default => 'Review order',
            } }}</div>
        </div>
        <a href="{{ route('orders', ['order' => $order->id]) }}" class="btn btn-sm btn-outline-secondary text-nowrap">Open Order</a>
    </div>
    @endforeach
    @if($operationalOrderCount > $operationalOrders->count())
    <p class="small text-muted mt-2 mb-0">Showing {{ $operationalOrders->count() }} of {{ $operationalOrderCount }} orders. Open All Orders to review the rest.</p>
    @endif
</section>
@endif

<!-- Alerts List -->
@if($alerts->isEmpty())
    <div class="hv-card p-4 text-center text-muted">
        <i class="ph ph-bell-slash fs-3 d-block mb-2"></i>
        No alerts found matching your filters.
    </div>
@else
    @foreach($alerts as $a)
        @php
            $tb = $typeBadges[$a->alert_type] ?? ['hv-badge-info', 'ph-info', $a->alert_type];
            $sb = $sevBadges[$a->severity] ?? 'hv-badge-info';
        @endphp
        <div class="hv-card p-3 mb-2 {{ $a->is_resolved ? 'opacity-50' : '' }}"
             style="{{ !$a->is_resolved && $a->severity === 'critical' ? 'border-left: 4px solid var(--bs-danger);' : '' }}">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                        <span class="hv-badge {{ $tb[0] }}">
                            <i class="ph {{ $tb[1] }} me-1"></i>{{ $tb[2] }}
                        </span>
                        <span class="hv-badge {{ $sb }}">{{ ucfirst($a->severity) }}</span>
                        @if($a->branch)
                            <span class="hv-badge" style="background:rgba(92,16,16,0.1);color:var(--maroon-dark);font-size:11px;">
                                <i class="ph ph-map-pin me-1"></i>{{ $a->branch->branch_name }}
                            </span>
                        @endif
                        @if($a->is_resolved)
                            <span class="hv-badge hv-badge-success"><i class="ph ph-check me-1"></i>Resolved</span>
                        @endif
                    </div>
                    <p class="mb-1 small">{{ $a->message }}</p>
                    <div class="text-muted" style="font-size:11px;">
                        {{ $a->created_at->format('M j, Y g:i A') }}
                        @if($a->is_resolved && $a->resolvedByUser)
                            &middot; Resolved by {{ $a->resolvedByUser->full_name }} on {{ $a->resolved_at ? \Carbon\Carbon::parse($a->resolved_at)->format('M j, g:i A') : '' }}
                        @endif
                    </div>
                </div>
                @if(!$a->is_resolved)
                <div class="ms-2 flex-shrink-0">
                    <form method="POST" action="{{ route('alerts.action') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="post_action" value="resolve">
                        <input type="hidden" name="resolve_id" value="{{ $a->id }}">
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Mark as resolved">
                            <i class="ph ph-check"></i> Resolve
                        </button>
                    </form>
                </div>
                @endif
            </div>
        </div>
    @endforeach
@endif

@endsection
