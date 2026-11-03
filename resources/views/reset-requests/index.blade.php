@extends('layouts.app')

@section('title', 'Reset Requests')

@section('content')
@push('styles')
<style>
    tr.hv-row-highlight { animation: hvRowHighlight 3.5s ease-out; }
    @keyframes hvRowHighlight {
        0%, 25% { background: var(--gold-soft, #F3E7CC); }
        100% { background: transparent; }
    }
</style>
@endpush
@php
    $stateBadges = [
        'awaiting_email' => ['hv-badge-secondary', 'Awaiting email verification'],
        'pending'  => ['hv-badge-warning', 'Pending approval'],
        'approved' => ['hv-badge-info', 'Approved'],
        'expired'  => ['hv-badge-secondary', 'Expired'],
        'used'     => ['hv-badge-success', 'Password reset successful'],
        'rejected' => ['hv-badge-danger', 'Rejected'],
        'revoked'  => ['hv-badge-secondary', 'Revoked'],
    ];
    $canAuthorize = $actor->canAuthorizeResets();
@endphp

<div id="resetRequestsContent">
<div id="resetRequestsNotice" class="alert alert-success" role="status" hidden></div>
<div class="hv-page-header">
    <div>
        <div class="small text-muted">
            @if($canAuthorize)
                Staff requests require email verification and manager identity verification before admin approval. Manager requests are verified and approved by admin.
            @else
                Verify the identity of your own branch staff after email verification. Admin will then approve or reject the reset request.
            @endif
        </div>
    </div>
</div>

<div class="hv-card p-3 mb-3 small">
    <strong>How it works:</strong> The account holder verifies their request email. For staff, their manager verifies identity before admin approval. For managers, admin verifies identity and approves. Approval emails a one-time reset link valid for 12 hours. Recovery codes remain in Users; the registered account email is unchanged.
</div>

{{-- Request list --}}
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" style="font-size:13px;">
            <thead>
                <tr>
                    <th class="ps-3">Account</th>
                    <th>State</th>
                    <th>Request email</th>
                    <th>Contact number</th>
                    <th>Requested by</th>
                    <th>Updated</th>
                    <th class="text-end pe-3">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($requests as $r)
                @php
                    $target = $r->user;
                    $state = $r->displayState();
                    $badge = $stateBadges[$state] ?? ['hv-badge-secondary', ucfirst($state)];
                    $isDeliverer = (int) $r->requested_by === (int) $actor->id;
                    $governs = $target && ($actor->canRequestResetFor($target) || ($actor->canAuthorizeResets() && $actor->roleRank() > $target->roleRank()));
                    $mayManage = $isDeliverer || $governs;
                    $managerVerified = $r->hasValidManagerVerification();
                    $canVerify = $r->status === 'pending' && $r->email_verified_at && $r->request_email && $target && $actor->canVerifyResetFor($target) && !$managerVerified;
                    $canApprove = $r->status === 'pending' && $r->email_verified_at && $r->request_email && $target && $actor->canApproveResetFor($target) && (!$target->isStaffRole() || $managerVerified);
                    $canReject = in_array($state, ['awaiting_email', 'pending', 'approved'], true) && $mayManage && $actor->canAuthorizeResets();

                    $canDelete = $mayManage &&  $r->isDeletable();
                @endphp
                <tr id="reset-request-{{ $r->id }}">
                    <td class="ps-3">
                        <div class="fw-semibold">{{ $target->full_name ?? '—' }}</div>
                        <div class="small text-muted">
                            {{ $target->username ?? '' }}
                            @if($target && $target->role) · {{ ucwords(str_replace('_', ' ', $target->role->role_name)) }} @endif
                            @if($target && $target->branch) · {{ $target->branch->branch_name }} @endif
                        </div>
                    </td>
                    <td>
                        <span class="hv-badge {{ $badge[0] }}">{{ $badge[1] }}</span>
                        @if($state === 'used' && $r->used_at)
                            <div class="small text-muted mt-1">Completed {{ $r->used_at->format('M j, Y g:i A') }}</div>
                        @endif
                        @if($r->manager_verified_at)
                            <div class="small mt-1 {{ $managerVerified ? 'text-success' : 'text-muted' }}">{{ $managerVerified ? 'Identity verified by' : 'Previous verification by' }} {{ $r->managerVerifier?->full_name }}</div>
                            <div class="small text-muted">{{ $r->manager_verified_at->format('M j, Y g:i A') }} · {{ \App\Models\PasswordResetRequest::VERIFICATION_METHODS[$r->manager_verification_method] ?? '' }}</div>
                        @endif
                        @if($r->status === 'pending' && $r->email_verified_at && $target?->isStaffRole())
                            <div class="small text-muted">{{ $managerVerified ? 'Awaiting admin approval' : 'Awaiting manager identity verification' }}</div>
                        @endif
                        @if($r->rejection_reason)
                            <div class="small text-muted mt-1">Reason: {{ \App\Models\PasswordResetRequest::REJECTION_REASONS[$r->rejection_reason] ?? $r->rejection_reason }}</div>
                        @endif
                        @if($state === 'approved' && $r->codeWasGenerated() && $r->code_expires_at)
                            <div class="small text-muted mt-1">Reset link emailed · expires {{ $r->code_expires_at->format('M j, g:i A') }}</div>
                        @elseif($state === 'approved')
                            <div class="small text-muted mt-1">Submit a new email recovery request</div>
                        @endif

                    </td>
                    <td class="small">
                        @if($r->request_email)
                            <div>{{ $r->request_email }}</div>
                            <span class="text-muted">{{ $r->email_verified_at ? 'Email verified' : 'Not yet verified' }}</span>
                        @else
                            <span class="text-muted">Legacy request — submit a new email recovery request</span>
                        @endif
                    </td>
                    <td class="small">
                        @if($r->request_contact_number)
                            <div>{{ $r->request_contact_number }}</div>
                            <span class="text-muted">Unverified · contact only</span>
                        @else
                            <span class="text-muted">Not provided</span>
                        @endif
                    </td>
                    <td class="small text-muted">{{ optional($r->requester)->full_name ?? '—' }}</td>
                    <td class="small text-muted">{{ $r->updated_at?->format('M j, g:i A') }}</td>
                    <td class="pe-3">
                        <div class="d-flex flex-wrap gap-2 justify-content-end">
                            @if($canApprove || $canVerify)
                            <label class="small w-100">
                                <input type="checkbox" id="identityVerified{{ $r->id }}" required>
                                {{ $canApprove && $target->isStaffRole() ? 'I reviewed the manager verification and confirmed this request.' : 'I verified identity and confirmed this request email.' }}
                            </label>
                            @endif
                            @if($canVerify)
                            <button type="button" class="btn btn-sm btn-maroon" data-reset-action="verify_identity" data-reset-id="{{ $r->id }}">Verify identity</button>
                            @endif
                            @if($canApprove)
                            <button type="button" class="btn btn-sm btn-success" data-reset-action="approve" data-reset-id="{{ $r->id }}"><i class="ph ph-check"></i> Approve</button>
                            @endif

                            @if($canReject)
                            <button type="button" class="btn btn-sm btn-outline-danger" data-reset-action="reject" data-reset-id="{{ $r->id }}"><i class="ph ph-x"></i> Reject</button>
                            @endif

                            @if($canDelete)
                            <button type="button" class="btn btn-sm btn-outline-danger" data-reset-action="delete" data-reset-id="{{ $r->id }}"><i class="ph ph-trash"></i> Delete</button>
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="7" class="text-center text-muted py-4">No reset requests yet.</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>

</div>
@endsection

@push('scripts')
<script>
var CSRF = document.querySelector('meta[name="csrf-token"]').content;
var API = '{{ route('reset-requests.api') }}';

// Delegated click binding (survives the in-place content refresh and does not
// depend on inline onclick attributes firing).
document.addEventListener('click', function (e) {
    var btn = e.target.closest('[data-reset-action]');
    if (!btn) { return; }
    e.preventDefault();
    resetAction(btn.getAttribute('data-reset-action'), parseInt(btn.getAttribute('data-reset-id'), 10), btn);
});
async function refreshResetRequests(message, url = window.location.href) {
    var res = await fetch(url, {
        headers: { 'Accept': 'text/html' },
        cache: 'no-store'
    });
    if (!res.ok || res.redirected) { throw new Error('Unable to refresh requests.'); }
    var page = new DOMParser().parseFromString(await res.text(), 'text/html');
    var content = page.getElementById('resetRequestsContent');
    if (!content) { throw new Error('Unable to refresh requests.'); }
    document.getElementById('resetRequestsContent').replaceWith(content);
    var notice = document.getElementById('resetRequestsNotice');
    notice.textContent = message || '';
    notice.hidden = !message;
}

async function postReset(payload) {
    var res = await fetch(API, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' },
        body: JSON.stringify(payload),
    });
    return { ok: res.ok, data: await res.json() };
}

async function resetAction(action, id, btn) {
    if (action === 'delete') { return deleteResetRequest(id); }
    var verified = action === 'approve' || action === 'verify_identity' ? document.getElementById('identityVerified' + id) : null;
    if (verified && !verified.reportValidity()) { return; }
    var label = {approve: 'Approve', reject: 'Reject', verify_identity: 'Verify identity for'}[action] || action;
    var result = await window.hvModal.confirm({
        title: label + ' this recovery request?',
        message: action === 'approve' ? 'Confirm the required identity checks. A one-time reset link will be sent only to the verified request email.' : (action === 'verify_identity' ? 'Confirm you verified the actual person and request email. This records verification only; admin approval is still required.' : 'Confirm this action for the selected recovery request.'),
        confirmText: label,
        select: action === 'reject' ? {label: 'Reason for rejection', options: @json(\App\Models\PasswordResetRequest::REJECTION_REASONS)} : (action === 'verify_identity' ? {label: 'Identity verification method', options: @json(\App\Models\PasswordResetRequest::VERIFICATION_METHODS)} : null),
        action: async function (reason) {
            var r = await postReset({action: action, id: id, identity_verified: verified ? verified.checked : false, rejection_reason: reason, verification_method: reason});
            if (!r.data.ok) {
                return {ok: false, error: r.data.error || Object.values(r.data.errors || {}).flat().join(' ') || r.data.message || 'Could not complete this action.'};
            }
            return {ok: true, message: r.data.message};
        },
        success: {title: 'Request updated', message: 'The recovery request has been updated.'}
    });
    if (result && result.ok) { await refreshResetRequests(result.message); }
}
// Deletion uses the shared confirm → success modal; content refreshes in place.
async function deleteResetRequest(id) {
    var result = await window.hvModal.confirm({
        title: 'Delete this request?',
        message: 'This closed reset request will be permanently deleted. This cannot be undone.',
        confirmText: 'Delete',
        action: async function () {
            var r = await postReset({ action: 'delete', id: id });
            if (!r.data.ok) { return { ok: false, error: r.data.error || 'Could not delete the request.' }; }
            return { ok: true };
        },
        success: { title: 'Deleted successfully', message: 'The reset request has been removed.' },
    });
    if (result && result.ok) { await refreshResetRequests(); }
}

// Highlight the exact request when arriving from a notification or Alerts link
// (?request=<id>). Read-only: scrolls and flags the row, approves nothing.
(function () {
    var id = new URLSearchParams(window.location.search).get('request');
    if (!id || !/^\d+$/.test(id)) { return; }
    var row = document.getElementById('reset-request-' + id);
    if (!row) { return; }
    row.scrollIntoView({ block: 'center', behavior: 'smooth' });
    row.classList.add('hv-row-highlight');
    setTimeout(function () { row.classList.remove('hv-row-highlight'); }, 3500);
})();

</script>
@endpush
