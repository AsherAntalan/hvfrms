@extends('layouts.app')

@section('title', 'Active Deliveries')

@section('content')

@php
    $statusMap = [
        'pending'    => ['hv-badge-warning',  'Pending'],
        'approved'   => ['hv-badge-info',     'Approved'],
        'delivering' => ['hv-badge-info',     'Delivering'],
    ];
@endphp

<div class="hv-page-header">
    <div>
        <span class="text-muted small">Orders pending approval, ready to ship, or in transit</span>
    </div>
    <a href="{{ route('delivery.history') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-clock-history me-1"></i> Delivery History
    </a>
</div>

@if($active->isEmpty())
    <div class="hv-card p-4 text-center">
        <i class="bi bi-check-circle fs-1 d-block mb-2" style="color:var(--success);"></i>
        <p class="mb-0 text-muted">No active deliveries at the moment.</p>
    </div>
@else
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:13px;">
            <thead>
                <tr>
                    <th class="ps-3">Order #</th>
                    <th>Branch</th>
                    <th>Ordered By</th>
                    <th class="text-end">Amount</th>
                    <th class="text-center">Status</th>
                    <th>Date</th>
                    <th class="text-center">Action</th>
                </tr>
            </thead>
            <tbody>
            @foreach($active as $o)
                @php $sb = $statusMap[$o->status] ?? ['hv-badge-info', $o->status]; @endphp
                <tr>
                    <td class="ps-3 fw-semibold">{{ $o->order_number }}</td>
                    <td>{{ $o->branch_name }}</td>
                    <td class="text-muted">{{ $o->ordered_by_name ?? '—' }}</td>
                    <td class="text-end">{{ number_format($o->total_amount, 2) }}</td>
                    <td class="text-center">
                        <span class="hv-badge {{ $sb[0] }}">{{ $sb[1] }}</span>
                    </td>
                    <td class="text-muted">{{ \Carbon\Carbon::parse($o->updated_at)->format('M j, g:i A') }}</td>
                    <td class="text-center">
                        @if($o->status === 'pending' && $user->isOwner())
                            <a href="{{ route('warehouse') }}" class="btn btn-sm btn-outline-warning" title="Review & Approve">
                                <i class="bi bi-check-lg"></i> Approve
                            </a>
                        @elseif($o->status === 'approved' && $user->isOwner())
                            <a href="{{ route('warehouse') }}" class="btn btn-sm btn-outline-secondary" title="Ship order">
                                <i class="bi bi-truck"></i> Ship
                            </a>
                        @elseif($o->status === 'delivering' && ($user->isOwner() || $user->isBranchManager()))
                            <a href="{{ $user->isBranchManager() ? route('orders') : route('warehouse') }}" class="btn btn-sm btn-outline-success" title="Confirm delivery">
                                <i class="bi bi-check2-circle"></i> Receive
                            </a>
                        @else
                            <a href="{{ route('orders') }}" class="btn btn-sm btn-outline-secondary" title="View order">
                                <i class="bi bi-eye"></i>
                            </a>
                        @endif
                    </td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-2">
    <i class="bi bi-info-circle me-1"></i>
    {{ $active->count() }} active deliver{{ $active->count() !== 1 ? 'ies' : 'y' }}.
    Use the Warehouse or Orders page to update delivery status.
</p>
@endif

@endsection
