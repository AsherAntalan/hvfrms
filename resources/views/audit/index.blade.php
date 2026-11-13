@extends('layouts.app')

@section('title', 'Audit Log')

@section('content')

<div class="hv-page-header">
    <div></div>
    <span class="text-muted small">{{ number_format($totalRows) }} entries</span>
</div>

{{-- ═══ FILTER BAR ═══════════════════════════════════════ --}}
<div class="hv-card p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">From</label>
            <input type="date" class="form-control form-control-sm" name="from" value="{{ $filterFrom }}" onchange="this.form.submit()">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">To</label>
            <input type="date" class="form-control form-control-sm" name="to" value="{{ $filterTo }}" onchange="this.form.submit()">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">User</label>
            <select class="form-select form-select-sm" name="user" onchange="this.form.submit()">
                <option value="0">All Users</option>
                @foreach($users as $u)
                    <option value="{{ $u->id }}" {{ $filterUser === $u->id ? 'selected' : '' }}>
                        {{ $u->full_name }} ({{ $u->email }})
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">Action</label>
            <select class="form-select form-select-sm" name="action_type" onchange="this.form.submit()">
                <option value="">All Actions</option>
                @foreach($actions as $act)
                    <option value="{{ $act }}" {{ $filterAction === $act ? 'selected' : '' }}>
                        {{ $act }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1 d-block">&nbsp;</label>
            <button type="button" class="btn btn-sm btn-outline-secondary w-100" onclick="window.location.href=location.pathname">
                <i class="ph ph-x" aria-hidden="true"></i> Clear Filters
            </button>
        </div>
    </form>
</div>

{{-- ═══ LOG TABLE ═══════════════════════════════════════ --}}
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover table-sm align-middle mb-0" style="font-size:13px;">
            <thead>
                <tr>
                    <th class="ps-3" style="width:5%;">#</th>
                    <th style="width:13%;">Timestamp</th>
                    <th style="width:15%;">User</th>
                    <th style="width:14%;">Action</th>
                    <th style="width:10%;">Table</th>
                    <th style="width:7%;">Record</th>
                    <th>Details</th>
                    <th style="width:10%;">IP</th>
                </tr>
            </thead>
            <tbody>
            @if($logs->isEmpty())
                <tr><td colspan="8" class="text-center text-muted py-4">No log entries found.</td></tr>
            @else
                @foreach($logs as $log)
                <tr>
                    <td class="ps-3 text-muted">{{ $log->id }}</td>
                    <td class="text-muted">{{ \Carbon\Carbon::parse($log->created_at)->format('M j g:i A') }}</td>
                    <td>
                        @if($log->full_name)
                            <span class="fw-semibold">{{ $log->full_name }}</span>
                            <span class="text-muted small d-block">{{ $log->user_email }}</span>
                        @else
                            <span class="text-muted">System</span>
                        @endif
                    </td>
                    <td>
                        <span class="hv-badge hv-badge-info">{{ $log->action }}</span>
                    </td>
                    <td class="small text-muted">{{ $log->table_name ?? "\u{2014}" }}</td>
                    <td class="small text-muted">{{ $log->record_id ?: "\u{2014}" }}</td>
                    <td class="small" style="max-width:300px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;"
                        title="{{ $log->details ?? '' }}">
                        {{ $log->details ?? "\u{2014}" }}
                    </td>
                    <td class="small text-muted">{{ $log->ip_address ?? "\u{2014}" }}</td>
                </tr>
                @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>

{{-- ═══ PAGINATION (matches the All-Branches Inventory pager) ═══ --}}
@php
    $cur = $logs->currentPage(); $last = $logs->lastPage(); $w = 2;
    $start = max(1, $cur - $w); $end = min($last, $cur + $w);
    $seq = [];
    if ($start > 1) { $seq[] = 1; if ($start > 2) { $seq[] = '…'; } }
    for ($p = $start; $p <= $end; $p++) { $seq[] = $p; }
    if ($end < $last) { if ($end < $last - 1) { $seq[] = '…'; } $seq[] = $last; }
@endphp
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-3 px-1">
    <div class="text-muted small">
        Showing {{ number_format($logs->firstItem() ?? 0) }}&ndash;{{ number_format($logs->lastItem() ?? 0) }} of {{ number_format($totalRows) }} match{{ $totalRows === 1 ? '' : 'es' }}
    </div>
    @if($logs->hasPages())
    <nav class="hv-inv-pager" aria-label="Audit log pagination">
        @if($logs->onFirstPage())
            <span class="hv-page-btn" aria-disabled="true" style="opacity:.45;cursor:not-allowed;"><i class="ph ph-caret-left"></i> Prev</span>
        @else
            <a class="hv-page-btn" href="{{ $logs->previousPageUrl() }}"><i class="ph ph-caret-left"></i> Prev</a>
        @endif
        @foreach($seq as $p)
            @if($p === '…')
                <span class="hv-page-gap" aria-hidden="true">…</span>
            @elseif($p === $cur)
                <span class="hv-page-btn active" aria-current="page">{{ $p }}</span>
            @else
                <a class="hv-page-btn" href="{{ $logs->url($p) }}">{{ $p }}</a>
            @endif
        @endforeach
        @if($logs->hasMorePages())
            <a class="hv-page-btn" href="{{ $logs->nextPageUrl() }}">Next <i class="ph ph-caret-right"></i></a>
        @else
            <span class="hv-page-btn" aria-disabled="true" style="opacity:.45;cursor:not-allowed;">Next <i class="ph ph-caret-right"></i></span>
        @endif
    </nav>
    @endif
</div>

@endsection
