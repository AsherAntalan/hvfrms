@extends('layouts.app')

@section('title', 'Users')

@section('content')

<div class="hv-page-header">
    <a href="{{ route('account-requests') }}" class="btn btn-sm btn-outline-secondary">Account Requests</a>
    <button class="btn btn-maroon btn-sm" onclick="openModal()">
        <i class="ph ph-plus me-1"></i> Add User
    </button>
</div>

{{-- ═══ USER TABLE ═══════════════════════════════════════ --}}
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Name</th>
                    <th>Username</th>
                    <th>Recovery code</th>
                    <th>Role</th>
                    <th>Branch</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @if($users->isEmpty())
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        No users found. Click "Add User" to create one.
                    </td>
                </tr>
            @else
                @foreach($users as $u)
                <tr class="{{ $u->status === 'active' ? '' : 'table-secondary' }}">
                    <td class="ps-3 fw-semibold">{{ $u->full_name }}</td>
                    <td class="small">{{ $u->username ?? '—' }}</td>
                                        <td class="small">
                        @if(Auth::user()->canViewRecoveryCodeFor($u))
                            <code class="text-dark">{{ $recoveryCodes[$u->id] }}</code>
                        @else
                            <span class="text-muted">Restricted</span>
                        @endif
                    </td>
                    <td>
                        @php
                            $role_badges = [
                                'super_admin'        => 'hv-badge-warning',
                                'owner'              => 'hv-badge-success',
                                'admin'              => 'hv-badge-success',
                                'branch_manager'     => 'hv-badge-info',
                                'branch_staff'       => 'hv-badge-secondary',
                                'commissary_manager' => 'hv-badge-info',
                                'commissary_staff'   => 'hv-badge-secondary',
                            ];
                            $badge_class = $role_badges[$u->role_name] ?? 'hv-badge-info';
                            $role_label  = ucwords(str_replace('_', ' ', $u->role_name));

                        @endphp
                        <span class="hv-badge {{ $badge_class }}">{{ $role_label }}</span>
                    </td>
                    <td class="small">{{ $u->branch_name }}</td>
                    <td class="text-center">
                        @if($u->status === 'active')
                            <span class="hv-badge hv-badge-success">Active</span>
                        @elseif($u->status === 'pending')
                            <span class="hv-badge hv-badge-warning">Pending</span>
                        @else
                            <span class="hv-badge hv-badge-danger">Disabled</span>
                        @endif
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">

                            {{-- Edit --}}
                            <button class="btn btn-sm btn-outline-secondary"
                                    title="Edit"
                                    onclick="openModal({!! htmlspecialchars(json_encode($u), ENT_QUOTES, 'UTF-8') !!})">
                                <i class="ph ph-pencil-simple"></i>
                            </button>
                            {{-- Toggle status --}}
                            @if($u->status === 'active')
                                <button class="btn btn-sm btn-outline-danger"
                                        title="Disable"
                                        onclick="toggleUser({{ $u->id }}, 'disabled')">
                                    <i class="ph ph-x-circle"></i>
                                </button>
                            @else
                                <button class="btn btn-sm btn-outline-success"
                                        title="Activate"
                                        onclick="toggleUser({{ $u->id }}, 'active')">
                                    <i class="ph ph-check-circle"></i>
                                </button>
                            @endif
                            {{-- Reset password --}}
                            <button class="btn btn-sm btn-outline-warning"
                                    title="Reset Password"
                                    onclick="resetPassword({{ $u->id }}, '{{ e($u->full_name) }}')">
                                <i class="ph ph-key"></i>
                            </button>
                        </div>
                    </td>
                </tr>
                @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>

<p class="text-muted small mt-2">
    <i class="ph ph-info me-1"></i>
    Showing {{ $users->count() }} user{{ $users->count() !== 1 ? 's' : '' }}.
    Default password for new accounts: <code>staff123</code>
</p>

{{-- ═══ ADD / EDIT MODAL ═══════════════════════════════════ --}}
<div class="modal fade" id="userModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="userForm" onsubmit="saveUser(event)">
                @csrf
                <input type="hidden" name="id" id="f_id">

                <div class="modal-header" style="background:var(--gold-soft);">
                    <h5 class="modal-title" id="modalTitle" style="color:var(--maroon);font-weight:700;">
                        <i class="ph ph-user-plus me-1"></i> Add User
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    {{-- Full Name --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Full Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="full_name" id="f_full_name"
                               required maxlength="100" placeholder="Juan Dela Cruz">
                    </div>

                    <div class="mb-3">
                        <label for="f_username" class="form-label fw-semibold">Username <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="username" id="f_username"
                               required maxlength="50" pattern="[A-Za-z0-9_.\-]+" placeholder="juan.delacruz"
                               autocomplete="off" autocapitalize="none" spellcheck="false" aria-describedby="usernameHint">
                        <div class="form-text" id="usernameHint">Use letters, numbers, dots, underscores, or hyphens. Users can sign in with their username or email.</div>
                    </div>

                    {{-- Email (optional — required only for a super admin account) --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Email <span class="text-muted small fw-normal">(optional)</span></label>
                        <input type="email" class="form-control" name="email" id="f_email"
                               maxlength="150" placeholder="user@hiddenvietnam.com">
                        <div class="form-text">Leave blank for accounts that recover with a reset code. A super admin still needs an email.</div>
                    </div>

                    {{-- Password (only for new users) --}}
                    <div class="mb-3" id="passwordGroup">
                        <label class="form-label fw-semibold">Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="password" id="f_password"
                                   minlength="6" placeholder="Minimum 6 characters">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePwVis('f_password', this)">
                                <i class="ph ph-eye"></i>
                            </button>
                        </div>
                        <div class="form-text">Leave blank when editing to keep current password.</div>
                    </div>

                    {{-- Role --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Role <span class="text-danger">*</span></label>
                        <select class="form-select" name="role_id" id="f_role_id" required>
                            @foreach($roles as $r)
                                @if($r->role_name === 'super_admin' && !auth()->user()->isSuperAdmin())
                                    @continue
                                @endif
                                <option value="{{ $r->id }}" title="{{ $r->description ?? '' }}">
                                    {{ ucwords(str_replace('_', ' ', $r->role_name)) }}
                                </option>
                            @endforeach
                        </select>
                    </div>

                    {{-- Branch --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Branch <span class="text-danger">*</span></label>
                        <select class="form-select" name="branch_id" id="f_branch_id" required>
                            @foreach($branches as $br)
                                <option value="{{ $br->id }}">
                                    {{ $br->branch_name }}
                                </option>
                            @endforeach
                        </select>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon" id="btnSave">
                        <i class="ph ph-check me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ═══ RESET PASSWORD MODAL ═══════════════════════════════ --}}
<div class="modal fade" id="resetModal" tabindex="-1">
    <div class="modal-dialog modal-sm">
        <div class="modal-content">
            <form id="resetForm" onsubmit="doReset(event)">
                @csrf
                <input type="hidden" name="id" id="r_id">

                <div class="modal-header" style="background:var(--gold-soft);">
                    <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                        <i class="ph ph-key me-1"></i> Reset Password
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <p class="mb-3">Reset password for <strong id="r_name"></strong>?</p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">New Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" name="new_password" id="r_password"
                                   required minlength="6" placeholder="Minimum 6 characters">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePwVis('r_password', this)">
                                <i class="ph ph-eye"></i>
                            </button>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Confirm Password <span class="text-danger">*</span></label>
                        <div class="input-group">
                            <input type="password" class="form-control" id="r_confirm"
                                   required minlength="6" placeholder="Re-enter password">
                            <button class="btn btn-outline-secondary" type="button" onclick="togglePwVis('r_confirm', this)">
                                <i class="ph ph-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-warning" id="btnReset">
                        <i class="ph ph-key me-1"></i> Reset Password
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ═══ ONE-TIME RESET CODE MODAL ═══════════════════════════ --}}
<div class="modal fade" id="codeModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered modal-sm">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" style="color:var(--maroon);font-weight:700;"><i class="ph ph-password me-1"></i> Reset Code</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <p class="small text-muted mb-1">Account recovery code for <strong id="code_name"></strong></p>
                <p class="small text-muted mb-2">Give this with their username and initial password. It remains valid until used; admin approval is required before resetting.</p>
                <div id="code_value" class="fw-bold" style="font-size:26px;letter-spacing:.14em;color:var(--maroon);"></div>
                <button type="button" class="btn btn-sm btn-outline-maroon mt-3" onclick="copyResetCode(this)"><i class="ph ph-copy me-1"></i> Copy code</button>
                <p class="small text-muted mt-3 mb-0">You can view this code again. A new code replaces it after a successful reset.</p>
            </div>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
const API  = '{{ route("users.api") }}';
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
let modal, resetModalEl, codeModalEl;

document.addEventListener('DOMContentLoaded', function () {
    modal        = new bootstrap.Modal(document.getElementById('userModal'));
    resetModalEl = new bootstrap.Modal(document.getElementById('resetModal'));
    codeModalEl  = new bootstrap.Modal(document.getElementById('codeModal'));
});

function togglePwVis(inputId, btn) {
    var inp = document.getElementById(inputId);
    var icon = btn.querySelector('i');
    if (inp.type === 'password') {
        inp.type = 'text';
        icon.className = 'ph ph-eye-slash';
    } else {
        inp.type = 'password';
        icon.className = 'ph ph-eye';
    }
}

/**
 * Open add/edit modal.
 */
function openModal(data) {
    const form    = document.getElementById('userForm');
    const title   = document.getElementById('modalTitle');
    const pwGroup = document.getElementById('passwordGroup');
    const pwInput = document.getElementById('f_password');

    form.reset();

    if (data) {
        title.innerHTML = '<i class="ph ph-pencil-simple me-1"></i> Edit User';
        document.getElementById('f_id').value        = data.id;
        document.getElementById('f_full_name').value  = data.full_name;
        document.getElementById('f_email').value      = data.email;
        document.getElementById('f_username').value   = data.username || '';
        document.getElementById('f_role_id').value    = data.role_id;
        document.getElementById('f_branch_id').value  = data.branch_id;
        // Password optional when editing
        pwInput.removeAttribute('required');
        pwGroup.querySelector('.form-text').style.display = '';
    } else {
        title.innerHTML = '<i class="ph ph-user-plus me-1"></i> Add User';
        document.getElementById('f_id').value = '';
        pwInput.setAttribute('required', 'required');
        pwGroup.querySelector('.form-text').style.display = 'none';
    }

    modal.show();
}

/**
 * Save user (create or update).
 */
async function saveUser(e) {
    e.preventDefault();

    const form = document.getElementById('userForm');
    const data = new FormData(form);
    data.append('action', 'save');

    const btn = document.getElementById('btnSave');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    try {
        const res  = await fetch(API, {
            method: 'POST',
            body: data,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF
            }
        });
        const json = await res.json();

        if (json.ok) {
            modal.hide();
            if (json.recovery_code) {
                document.getElementById('code_name').textContent = data.get('username');
                document.getElementById('code_value').textContent = json.recovery_code;
                codeModalEl.show();
                document.getElementById('codeModal').addEventListener('hidden.bs.modal', function () { location.reload(); }, { once: true });
            } else {
                location.reload();
            }
        } else {
            alert(json.error || 'Something went wrong.');
        }
    } catch (err) {
        alert('Network error. Please check your connection.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph ph-check me-1"></i> Save';
    }
}

/**
 * Toggle user status (active/disabled).
 */
async function toggleUser(id, newStatus) {
    const action = newStatus === 'active' ? 'activate' : 'disable';
    if (!confirm('Are you sure you want to ' + action + ' this user?')) return;

    const data = new FormData();
    data.append('action', 'toggle');
    data.append('id', id);
    data.append('status', newStatus);
    data.append('_token', CSRF);

    try {
        const res  = await fetch(API, {
            method: 'POST',
            body: data,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF
            }
        });
        const json = await res.json();

        if (json.ok) {
            location.reload();
        } else {
            alert(json.error || 'Something went wrong.');
        }
    } catch (err) {
        alert('Network error. Please check your connection.');
    }
}

/**
 * Open reset password modal.
 */
function resetPassword(id, name) {
    document.getElementById('r_id').value = id;
    document.getElementById('r_name').textContent = name;
    document.getElementById('r_password').value = '';
    document.getElementById('r_confirm').value = '';
    resetModalEl.show();
}

/**
 * Submit password reset.
 */
async function doReset(e) {
    e.preventDefault();

    var pw = document.getElementById('r_password').value;
    var cf = document.getElementById('r_confirm').value;
    if (pw !== cf) {
        alert('Passwords do not match.');
        return;
    }

    const form = document.getElementById('resetForm');
    const data = new FormData(form);
    data.append('action', 'reset_password');

    const btn = document.getElementById('btnReset');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Resetting...';

    try {
        const res  = await fetch(API, {
            method: 'POST',
            body: data,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF
            }
        });
        const json = await res.json();

        if (json.ok) {
            // Close the form modal fully, then show the shared success modal (no stacking).
            document.getElementById('resetModal').addEventListener('hidden.bs.modal', function () {
                window.hvModal.success({ title: 'Password reset', message: 'The password has been reset successfully.' });
            }, { once: true });
            resetModalEl.hide();
        } else {
            alert(json.error || 'Something went wrong.');
        }
    } catch (err) {
        alert('Network error. Please check your connection.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph ph-key me-1"></i> Reset';
    }
}

/**
 * View the assigned recovery code without authorizing a password reset.
 */
async function getResetCode(id, name) {
    if (!confirm('View the assigned recovery code for ' + name + '? Viewing does not approve a password reset.')) {
        return;
    }
    const data = new FormData();
    data.append('action', 'reset_code');
    data.append('id', id);
    data.append('_token', CSRF);
    try {
        const res = await fetch(API, {
            method: 'POST',
            body: data,
            headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF, 'Accept': 'application/json' }
        });
        const json = await res.json();
        if (json.ok && json.code) {
            document.getElementById('code_name').textContent = name;
            document.getElementById('code_value').textContent = json.code;
            codeModalEl.show();
        } else {
            alert(json.error || 'Could not generate a reset code.');
        }
    } catch (err) {
        alert('Network error. Please check your connection.');
    }
}

function copyResetCode(btn) {
    const code = document.getElementById('code_value').textContent;
    if (navigator.clipboard) {
        navigator.clipboard.writeText(code).then(function () {
            btn.innerHTML = '<i class="ph ph-check me-1"></i> Copied';
            setTimeout(function () { btn.innerHTML = '<i class="ph ph-copy me-1"></i> Copy code'; }, 1500);
        });
    }
}
</script>
@endpush
