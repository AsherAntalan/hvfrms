@extends('layouts.app')
@section('title', 'Account Requests')
@section('content')
<div class="d-flex gap-2 mb-3">
    <a href="{{ route('users') }}" class="btn btn-sm btn-outline-secondary">Users</a>
    <a href="{{ route('account-requests') }}" class="btn btn-sm btn-outline-secondary">All account requests</a>
</div>
@if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
@if($actor->isManagerRole())
<form method="POST" action="{{ route('account-requests.store') }}" class="hv-card p-3 mb-3">
    @csrf
    <h6>Request a new staff account</h6>
    <p class="small text-muted">For {{ $actor->branch?->branch_name }} only. Admin approval is required. The employee will set their own password on first login.</p>
    <div class="row g-3 align-items-end">
        <div class="col-md-5"><label for="full_name" class="form-label">Employee full name</label><input id="full_name" name="full_name" class="form-control" value="{{ old('full_name') }}" required maxlength="150"></div>
        <div class="col-md-4"><label for="username" class="form-label">Requested username</label><input id="username" name="username" class="form-control" value="{{ old('username') }}" required maxlength="50" pattern="[A-Za-z0-9_.-]+" autocomplete="off"></div>
        <div class="col-md-3"><button class="btn btn-maroon w-100">Submit request</button></div>
    </div>
</form>
@endif
<div class="hv-card table-responsive"><table class="table align-middle mb-0">
<thead><tr><th>Employee</th><th>Branch / role</th><th>Requested by</th><th>Status</th><th>Actions</th></tr></thead><tbody>
@forelse($entries as $entry)
<tr id="account-request-{{ $entry->id }}" @class(['table-warning' => (int) request('request') === $entry->id])>
    <td><strong>{{ $entry->full_name }}</strong><div class="small text-muted">{{ $entry->username }}</div></td>
    <td>{{ $entry->branch?->branch_name }}<div class="small text-muted">{{ ucwords(str_replace('_', ' ', $entry->requested_role)) }}</div></td>
    <td>{{ $entry->requester?->full_name }}<div class="small text-muted">{{ $entry->created_at->format('M j, Y g:i A') }}</div></td>
    <td>{{ ucfirst($entry->status) }}
        @if($entry->rejection_reason)<div class="small text-muted">{{ $entry->rejection_reason }}</div>@endif
        @if($entry->status === 'approved')
            <div class="small text-muted">{{ $entry->user?->must_change_password ? 'Awaiting first-login password change' : 'Password set' }}</div>
            @if($entry->credential_revealed_at)<div class="small text-muted">Temporary password already revealed</div>@endif
            @if($entry->user?->must_change_password && $entry->credential_expires_at)<div class="small text-muted">Credentials expire {{ $entry->credential_expires_at->format('M j, g:i A') }}</div>@endif
        @endif
    </td>
    <td>
        @if($actor->canAuthorizeResets() && $entry->status === 'pending')
            <button class="btn btn-sm btn-success" data-account-action="approve" data-id="{{ $entry->id }}">Approve</button>
            <button class="btn btn-sm btn-outline-danger" data-account-action="reject" data-id="{{ $entry->id }}">Reject</button>
        @endif
        @if($actor->canAuthorizeResets() && $entry->status === 'approved' && $entry->user?->must_change_password)
            <button class="btn btn-sm btn-outline-secondary" data-account-action="replace" data-id="{{ $entry->id }}">Issue replacement</button>
        @endif
        @if((int) $entry->requested_by === (int) $actor->id && $entry->status === 'approved' && $entry->user?->must_change_password)
            @if(!$entry->credential_revealed_at && $entry->credential_expires_at?->isFuture())
                <button class="btn btn-sm btn-maroon" data-reveal-id="{{ $entry->id }}">Reveal temporary password once</button>
            @else
                <span class="small text-muted">If credentials were lost or expired, ask admin for a replacement.</span>
            @endif
        @endif
    </td>
</tr>
@empty
<tr><td colspan="5" class="text-center text-muted p-4">No account requests.</td></tr>
@endforelse
</tbody></table></div>
<div class="mt-3">{{ $entries->links() }}</div>
<div class="modal fade" id="credentialModal" tabindex="-1" aria-labelledby="credentialTitle" aria-hidden="true" data-bs-backdrop="static">
<div class="modal-dialog modal-dialog-centered"><div class="modal-content">
    <div class="modal-header"><h5 class="modal-title" id="credentialTitle">Temporary account credentials</h5><button class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
    <div class="modal-body">
        <div id="credentialError" class="alert alert-danger" hidden role="alert"></div>
        <form id="revealForm">
            <p>This password can be revealed once. Give it privately to the employee. They must change it on first login.</p>
            <label for="managerPassword" class="form-label">Confirm your own password</label>
            <input id="managerPassword" type="password" class="form-control mb-3" required autocomplete="current-password">
            <button id="revealSubmit" class="btn btn-maroon">Reveal once</button>
        </form>
        <div id="credentialResult" hidden>
            <p>Record these credentials privately before closing. They cannot be viewed again.</p>
            <p>Username: <strong id="credentialUsername"></strong></p>
            <p>Temporary password: <code id="credentialPassword" style="overflow-wrap:anywhere;"></code></p>
            <p class="small text-muted" id="credentialExpiry"></p>
        </div>
    </div>
</div></div></div>
@endsection
@push('scripts')
<script>
(function () {
    const api = @json(route('account-requests.api'));
    const csrf = document.querySelector('meta[name="csrf-token"]').content;
    async function post(payload) {
        const response = await fetch(api, {method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': csrf}, body: JSON.stringify(payload)});
        const data = await response.json();
        if (!response.ok || !data.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Unable to complete this action.');
        return data;
    }
    document.addEventListener('click', async function (event) {
        const button = event.target.closest('[data-account-action]');
        if (!button) return;
        const action = button.dataset.accountAction;
        const result = await window.hvModal.confirm({
            title: {approve: 'Approve this account request?', reject: 'Reject this account request?', replace: 'Issue replacement credentials?'}[action],
            message: action === 'replace' ? 'The previous temporary password will stop working. The manager can reveal the replacement once.' : 'Review the employee and branch before continuing.',
            confirmText: {approve: 'Approve', reject: 'Reject', replace: 'Issue replacement'}[action],
            select: action === 'reject' ? {label: 'Reason', options: {'Duplicate employee account': 'Duplicate employee account', 'Employee details could not be confirmed': 'Employee details could not be confirmed', 'Account not authorized': 'Account not authorized', 'Request cancelled': 'Request cancelled'}} : null,
            action: async function (reason) {return post({action: action, id: button.dataset.id, reason: reason});},
            success: {title: 'Request updated', message: 'The account request has been updated.'}
        });
        if (result && result.ok) window.location.reload();
    });
    const modalElement = document.getElementById('credentialModal');
    const modal = new bootstrap.Modal(modalElement);
    let requestId = null, revealed = false, pending = false;
    document.addEventListener('click', function (event) {
        const button = event.target.closest('[data-reveal-id]');
        if (!button) return;
        requestId = button.dataset.revealId; modal.show();
    });
    document.getElementById('revealForm').addEventListener('submit', async function (event) {
        event.preventDefault();
        if (pending) return;
        pending = true;
        const submit = document.getElementById('revealSubmit'); submit.disabled = true;
        document.getElementById('credentialError').hidden = true;
        try {
            const data = await post({action: 'reveal', id: requestId, current_password: document.getElementById('managerPassword').value});
            document.getElementById('managerPassword').value = '';
            document.getElementById('revealForm').hidden = true;
            document.getElementById('credentialUsername').textContent = data.username;
            document.getElementById('credentialPassword').textContent = data.temporary_password;
            document.getElementById('credentialExpiry').textContent = 'Expires ' + data.expires_at;
            document.getElementById('credentialResult').hidden = false;
            revealed = true;
        } catch (error) {
            document.getElementById('credentialError').textContent = error.message;
            document.getElementById('credentialError').hidden = false;
        } finally {submit.disabled = false; pending = false;}
    });
    modalElement.addEventListener('hide.bs.modal', function (event) {if (pending) event.preventDefault();});
    function clearCredentials() {
        document.getElementById('managerPassword').value = '';
        ['credentialPassword', 'credentialUsername', 'credentialExpiry'].forEach(function (id) {document.getElementById(id).textContent = '';});
    }
    modalElement.addEventListener('hidden.bs.modal', function () {
        clearCredentials();
        if (revealed) {window.location.reload(); return;}
        document.getElementById('credentialError').hidden = true;
    });
    window.addEventListener('pagehide', clearCredentials);
})();
</script>
@endpush
