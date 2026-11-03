@extends('layouts.app')

@section('title', $isNotificationCenter ? 'Notifications' : 'Alerts')

@section('content')

@if(auth()->user()->isCommissaryManager())
<p class="small text-muted mb-3">Branch requests, preparation, and dispatch follow-ups. Expiry warnings apply only to remaining order-linked goods awaiting dispatch.</p>
@endif

<p class="small text-muted mb-3">Marking an alert as reviewed acknowledges it. Stock and order issues must still be addressed separately.</p>

<div class="hv-page-header">
    <div>
        @if($countOpen > 0)
            <span class="hv-badge hv-badge-danger">{{ $countOpen }} unreviewed</span>
        @endif
    </div>
    @if($countOpen > 0)
    <form method="POST" action="{{ route('alerts.action') }}" class="d-inline"
          onsubmit="return confirm('Mark all {{ $countOpen }} unreviewed alert(s) as reviewed? This does not fix stock or order issues.');">
        @csrf
        <input type="hidden" name="post_action" value="resolve_all">
        <input type="hidden" name="category" value="{{ $category }}">
        <input type="hidden" name="branch" value="{{ $filterBranch }}">
        <input type="hidden" name="type" value="{{ $filterType }}">
        <button type="submit" class="btn btn-sm btn-outline-success">
            <i class="ph ph-checks me-1"></i> Mark all as reviewed
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
        'chipPreserve' => request()->only($isNotificationCenter ? ['category'] : ['type', 'resolved', 'category']),
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
            @if($isNotificationCenter)
            <select class="form-select form-select-sm" name="category" aria-label="Notification category">
                @foreach(['all' => 'All', 'recovery' => 'Account Recovery', 'accounts' => 'Account Requests', 'inventory' => 'Inventory Alerts', 'orders' => 'Orders'] as $key => $label)
                    <option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @else
            <select class="form-select form-select-sm" name="type">
                <option value="">All Types</option>
                @foreach($typeBadges as $type => $badge)
                <option value="{{ $type }}" {{ $filterType === $type ? 'selected' : '' }}>{{ $badge[2] }}</option>
                @endforeach
            </select>
            @endif
        </div>
        @unless($isNotificationCenter)
        <div class="col-md-3">
            <select class="form-select form-select-sm" name="resolved">
                <option value="0" {{ $filterResolved === '0' ? 'selected' : '' }}>Unreviewed Only</option>
                <option value="1" {{ $filterResolved === '1' ? 'selected' : '' }}>Reviewed Only</option>
                <option value="all" {{ $filterResolved === 'all' ? 'selected' : '' }}>All</option>
            </select>
        </div>
        @endunless
        <div class="col-md-3">
            <button type="submit" class="btn btn-maroon btn-sm">
                <i class="ph ph-funnel me-1"></i> Filter
            </button>
        </div>
        <div class="col-md-3 text-end">
            <span class="text-muted small">{{ $alerts->count() + $recoveryRequests->count() + $operationalOrderCount + count($accountNotices) }} {{ $isNotificationCenter ? 'notification(s)' : 'alert(s)' }}</span>
        </div>
    </form>
</div>

@if(count($accountNotices))
<section class="hv-card p-3 mb-3" aria-label="Account requests">
    <h6>Account Requests</h6>
    @foreach($accountNotices as $notice)
        <div class="d-flex justify-content-between align-items-center gap-3 border-top py-2">
            <div><strong>{{ $notice['message'] }}</strong><div class="small text-muted">{{ $notice['context'] }} · {{ $notice['time'] }}</div></div>
            <a href="{{ $notice['link'] }}" class="btn btn-sm btn-outline-secondary text-nowrap">Review request</a>
        </div>
    @endforeach
</section>
@endif

@if($recoveryRequests->isNotEmpty())
<section class="hv-card p-3 mb-3" aria-label="Account recovery requests">
    <h6>Account Recovery <span class="text-muted">({{ $recoveryRequests->count() }})</span></h6>
    <p class="small text-muted">Staff requests need manager identity verification before admin approval. Managers are verified and approved by admin.</p>
    @foreach($recoveryRequests as $recovery)
        <div class="d-flex justify-content-between align-items-center gap-3 border-top py-2">
            <div>
                <strong>{{ $recovery->user->full_name }}</strong>
                <span class="hv-badge hv-badge-warning">{{ $recovery->user->isStaffRole() && !$recovery->hasValidManagerVerification() ? 'Awaiting manager verification' : 'Awaiting admin approval' }}</span>
                <div class="small text-muted">{{ $recovery->user->branch?->branch_name }} · {{ $recovery->email_verified_at->format('M j, Y g:i A') }}</div>
            </div>
            <a href="{{ route('reset-requests', ['request' => $recovery->id]) }}" class="btn btn-sm btn-outline-secondary text-nowrap">Review request</a>
        </div>
    @endforeach
</section>
@endif

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
@if($alerts->isEmpty() && $recoveryRequests->isEmpty() && $operationalOrders->isEmpty() && !count($accountNotices))
    <div class="hv-card p-4 text-center text-muted">
        <i class="ph ph-bell-slash fs-3 d-block mb-2"></i>
        {{ $isNotificationCenter ? 'No notifications found matching your filters.' : 'No alerts found matching your filters.' }}
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
                            <span class="hv-badge hv-badge-success"><i class="ph ph-check me-1"></i>Reviewed</span>
                        @endif
                    </div>
                    <p class="mb-1 small">{{ $a->message }}</p>
                    <div class="text-muted" style="font-size:11px;">
                        {{ $a->created_at->format('M j, Y g:i A') }}
                        @if($a->is_resolved && $a->resolvedByUser)
                            &middot; Reviewed by {{ $a->resolvedByUser->full_name }} on {{ $a->resolved_at ? \Carbon\Carbon::parse($a->resolved_at)->format('M j, g:i A') : '' }}
                        @endif
                    </div>
                </div>
                @if(!$a->is_resolved)
                <div class="ms-2 flex-shrink-0">
                    @if($a->order_id)
                        <a href="{{ route('orders', ['order' => $a->order_id, 'queue' => 'all']) }}" class="btn btn-sm btn-outline-secondary">Review order</a>
                    @endif
                    <form method="POST" action="{{ route('alerts.action') }}" class="d-inline">
                        @csrf
                        <input type="hidden" name="post_action" value="resolve">
                        <input type="hidden" name="resolve_id" value="{{ $a->id }}">
                        <button type="submit" class="btn btn-sm btn-outline-success" title="Mark as reviewed">
                            <i class="ph ph-check"></i> Mark as reviewed
                        </button>
                    </form>
                </div>
                @endif
            </div>
        </div>
    @endforeach
@endif

@endsection
