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
            <input type="date" class="form-control form-control-sm" name="from" value="{{ $filterFrom }}">
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">To</label>
            <input type="date" class="form-control form-control-sm" name="to" value="{{ $filterTo }}">
        </div>
        <div class="col-md-3">
            <label class="form-label small fw-semibold mb-1">User</label>
            <select class="form-select form-select-sm" name="user">
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
            <select class="form-select form-select-sm" name="action_type">
                <option value="">All Actions</option>
                @foreach($actions as $act)
                    <option value="{{ $act }}" {{ $filterAction === $act ? 'selected' : '' }}>
                        {{ $act }}
                    </option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <button type="submit" class="btn btn-maroon btn-sm w-100">
                <i class="bi bi-funnel me-1"></i> Filter
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

{{-- ═══ PAGINATION ═══════════════════════════════════════ --}}
@if($logs->hasPages())
<nav class="mt-3">
    <ul class="pagination pagination-sm justify-content-center">
        @foreach($logs->getUrlRange(1, $logs->lastPage()) as $page => $url)
            <li class="page-item {{ $page === $logs->currentPage() ? 'active' : '' }}">
                <a class="page-link" href="{{ $url }}">{{ $page }}</a>
            </li>
        @endforeach
    </ul>
</nav>
@endif

<div class="text-muted small mt-2 text-center">
    Showing {{ $logs->count() }} of {{ number_format($totalRows) }} entries
    (page {{ $logs->currentPage() }} of {{ $logs->lastPage() }}).
</div>

@endsection
