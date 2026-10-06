@extends('layouts.app')

@section('title', 'Delivery History')

@section('content')

@php
    $statusMap = [
        'delivered'  => ['hv-badge-success', 'Delivered'],
        'settled'    => ['hv-badge-success', 'Settled'],
        'cancelled'  => ['hv-badge-danger',  'Cancelled'],
    ];
@endphp

<div class="hv-page-header">
    <div>
        <span class="text-muted small">Completed and cancelled deliveries</span>
    </div>
    <a href="{{ route('delivery') }}" class="btn btn-sm btn-outline-secondary">
        <i class="bi bi-truck me-1"></i> Active Deliveries
    </a>
</div>

@if($history->isEmpty())
    <div class="hv-card p-4 text-center">
        <p class="mb-0 text-muted">No delivery history found.</p>
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
                    <th>Completed</th>
                </tr>
            </thead>
            <tbody>
            @foreach($history as $o)
                @php $sb = $statusMap[$o->status] ?? ['hv-badge-info', $o->status]; @endphp
                <tr class="{{ $o->status === 'cancelled' ? 'table-secondary' : '' }}">
                    <td class="ps-3 fw-semibold">{{ $o->order_number }}</td>
                    <td>{{ $o->branch_name }}</td>
                    <td class="text-muted">{{ $o->ordered_by_name ?? '—' }}</td>
                    <td class="text-end">{{ number_format($o->total_amount, 2) }}</td>
                    <td class="text-center">
                        <span class="hv-badge {{ $sb[0] }}">{{ $sb[1] }}</span>
                    </td>
                    <td class="text-muted">{{ \Carbon\Carbon::parse($o->updated_at)->format('M j, Y g:i A') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
</div>

<div class="mt-3 d-flex justify-content-center">
    {{ $history->links() }}
</div>
@endif

@endsection
