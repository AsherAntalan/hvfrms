@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="hv-page-header"><p class="small text-muted mb-0">Staff accounts in your branch.</p><a href="{{ route('account-requests') }}" class="btn btn-sm btn-maroon">Request new account / View requests</a></div>
<div class="hv-card table-responsive"><table class="table mb-0"><thead><tr><th>Name</th><th>Username</th><th>Recovery code</th><th>Role</th><th>Status</th></tr></thead><tbody>
@forelse($staff as $employee)
<tr><td>{{ $employee->full_name }}</td><td>{{ $employee->username }}</td><td><code>{{ $recoveryCodes[$employee->id] ?? 'Unavailable' }}</code></td><td>{{ ucwords(str_replace('_', ' ', $employee->role->role_name)) }}</td><td>{{ ucfirst($employee->status) }}
@if($employee->hasNoRecentActivity())<div class="mt-1"><span class="hv-badge hv-badge-warning">No activity for 30 days</span></div>@endif
</td></tr>
@empty
<tr><td colspan="5" class="text-center p-4 text-muted">No staff accounts.</td></tr>
@endforelse
</tbody></table></div>
@endsection
