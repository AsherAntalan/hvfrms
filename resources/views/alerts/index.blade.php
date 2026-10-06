@extends('layouts.app')

@section('title', 'Alerts')

@section('content')

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
            <i class="bi bi-check-all me-1"></i> Resolve All
        </button>
    </form>
    @endif
</div>

@if($countCritical > 0)
<div class="alert alert-danger py-2 small mb-3">
    <i class="bi bi-exclamation-triangle-fill me-1"></i>
    <strong>{{ $countCritical }}</strong> critical alert(s) require immediate attention.
</div>
@endif

{{-- Branch filter pills (owner/super_admin only) --}}
@if($branches->count() > 0)
<div class="hv-card p-2 mb-3">
    <div class="d-flex flex-wrap gap-2 align-items-center">
        <span class="text-muted small me-1"><i class="bi bi-funnel me-1"></i>Branch:</span>
        <a href="{{ route('alerts', array_merge(request()->only(['type','resolved']), ['branch' => ''])) }}"
           class="btn btn-sm {{ $filterBranch === '' ? 'btn-maroon' : 'btn-outline-maroon' }}">
            All
            @if($branchCounts->sum() > 0)
                <span class="badge bg-white text-dark ms-1" style="font-size:10px;">{{ $branchCounts->sum() }}</span>
            @endif
        </a>
        @foreach($branches as $b)
            @php $bCount = $branchCounts->get($b->id, 0); @endphp
            <a href="{{ route('alerts', array_merge(request()->only(['type','resolved']), ['branch' => $b->id])) }}"
               class="btn btn-sm {{ (string)$filterBranch === (string)$b->id ? 'btn-maroon' : 'btn-outline-maroon' }}">
                {{ $b->branch_name }}
                @if($bCount > 0)
                    <span class="badge bg-danger text-white ms-1" style="font-size:10px;">{{ $bCount }}</span>
                @endif
            </a>
        @endforeach
    </div>
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
                <option value="low_stock" {{ $filterType === 'low_stock' ? 'selected' : '' }}>Low Stock</option>
                <option value="expiring_soon" {{ $filterType === 'expiring_soon' ? 'selected' : '' }}>Expiring Soon</option>
                <option value="expired" {{ $filterType === 'expired' ? 'selected' : '' }}>Expired</option>
                <option value="unusual_sales" {{ $filterType === 'unusual_sales' ? 'selected' : '' }}>Unusual Sales</option>
                <option value="operational" {{ $filterType === 'operational' ? 'selected' : '' }}>Operational</option>
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
                <i class="bi bi-funnel me-1"></i> Filter
            </button>
        </div>
        <div class="col-md-3 text-end">
            <span class="text-muted small">{{ $alerts->count() }} alert(s)</span>
        </div>
    </form>
</div>

<!-- Alerts List -->
@if($alerts->isEmpty())
    <div class="hv-card p-4 text-center text-muted">
        <i class="bi bi-bell-slash fs-3 d-block mb-2"></i>
        No alerts found matching your filters.
    </div>
@else
    @foreach($alerts as $a)
        @php
            $tb = $typeBadges[$a->alert_type] ?? ['hv-badge-info', 'bi-info-circle', $a->alert_type];
            $sb = $sevBadges[$a->severity] ?? 'hv-badge-info';
        @endphp
        <div class="hv-card p-3 mb-2 {{ $a->is_resolved ? 'opacity-50' : '' }}"
             style="{{ !$a->is_resolved && $a->severity === 'critical' ? 'border-left: 4px solid var(--bs-danger);' : '' }}">
            <div class="d-flex justify-content-between align-items-start">
                <div class="flex-grow-1">
                    <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                        <span class="hv-badge {{ $tb[0] }}">
                            <i class="bi {{ $tb[1] }} me-1"></i>{{ $tb[2] }}
                        </span>
                        <span class="hv-badge {{ $sb }}">{{ ucfirst($a->severity) }}</span>
                        @if($a->branch)
                            <span class="hv-badge" style="background:rgba(92,16,16,0.1);color:var(--maroon-dark);font-size:11px;">
                                <i class="bi bi-geo-alt me-1"></i>{{ $a->branch->branch_name }}
                            </span>
                        @endif
                        @if($a->is_resolved)
                            <span class="hv-badge hv-badge-success"><i class="bi bi-check-lg me-1"></i>Resolved</span>
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
                            <i class="bi bi-check-lg"></i> Resolve
                        </button>
                    </form>
                </div>
                @endif
            </div>
        </div>
    @endforeach
@endif

@endsection
