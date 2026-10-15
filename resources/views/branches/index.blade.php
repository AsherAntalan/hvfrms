@extends('layouts.app')

@section('title', 'Branches')

@section('content')

<div class="hv-page-header">
    <div></div>
    <button class="btn btn-maroon btn-sm" onclick="openModal()">
        <i class="bi bi-plus-lg me-1"></i> Add Branch
    </button>
</div>

{{-- ═══ BRANCH TABLE ═══════════════════════════════════════ --}}
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Branch Name</th>
                    <th>Type</th>
                    <th>Address</th>
                    <th>Contact</th>
                    <th class="text-center">Staff</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @if($branches->isEmpty())
                <tr>
                    <td colspan="7" class="text-center text-muted py-4">
                        No branches found. Click "Add Branch" to create one.
                    </td>
                </tr>
            @else
                @foreach($branches as $b)
                <tr class="{{ $b->is_active ? '' : 'table-secondary' }}">
                    <td class="ps-3 fw-semibold">
                        {{ $b->branch_name }}
                    </td>
                    <td>
                        @if($b->branch_type === 'main')
                            <span class="hv-badge hv-badge-warning">
                                <i class="bi bi-star-fill me-1"></i>Main Store
                            </span>
                        @elseif($b->branch_type === 'commissary')
                            <span class="hv-badge hv-badge-gold">
                                <i class="bi bi-building me-1"></i>Commissary
                            </span>
                        @else
                            <span class="hv-badge hv-badge-info">Franchise</span>
                        @endif
                    </td>
                    <td class="text-muted small">{{ $b->address ?? "\u{2014}" }}</td>
                    <td class="text-muted small">{{ $b->contact_number ?? "\u{2014}" }}</td>
                    <td class="text-center">
                        <span class="hv-badge hv-badge-info">{{ (int) $b->staff_count }}</span>
                    </td>
                    <td class="text-center">
                        @if($b->is_active)
                            <span class="hv-badge hv-badge-success">Active</span>
                        @else
                            <span class="hv-badge hv-badge-danger">Inactive</span>
                        @endif
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            {{-- Edit button --}}
                            <button class="btn btn-sm btn-outline-secondary"
                                    title="Edit"
                                    onclick="openModal({!! htmlspecialchars(json_encode($b), ENT_QUOTES, 'UTF-8') !!})">
                                <i class="bi bi-pencil"></i>
                            </button>
                            {{-- Toggle active/inactive --}}
                            <button class="btn btn-sm {{ $b->is_active ? 'btn-outline-danger' : 'btn-outline-success' }}"
                                    title="{{ $b->is_active ? 'Deactivate' : 'Activate' }}"
                                    onclick="toggleBranch({{ $b->id }}, {{ $b->is_active ? 0 : 1 }})">
                                <i class="bi {{ $b->is_active ? 'bi-x-circle' : 'bi-check-circle' }}"></i>
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
    <i class="bi bi-info-circle me-1"></i>
    Showing {{ $branches->count() }} branch{{ $branches->count() !== 1 ? 'es' : '' }}.
    Main branch is the commissary / central kitchen.
</p>

{{-- ═══ ADD / EDIT MODAL ═══════════════════════════════════ --}}
<div class="modal fade" id="branchModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="branchForm" onsubmit="saveBranch(event)">
                @csrf
                <input type="hidden" name="id" id="f_id">

                <div class="modal-header" style="background:var(--gold-soft);">
                    <h5 class="modal-title" id="modalTitle" style="color:var(--maroon);font-weight:700;">
                        <i class="bi bi-building me-1"></i> Add Branch
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    {{-- Branch Name --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Branch Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="branch_name" id="f_branch_name"
                               required maxlength="100" placeholder="e.g. Silang">
                    </div>

                    {{-- Type --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                        <select class="form-select" name="branch_type" id="f_branch_type" required>
                            <option value="franchise">Franchise</option>
                            <option value="main">Main Store (Tanza)</option>
                            <option value="commissary">Commissary (production / dispatch source)</option>
                        </select>
                    </div>

                    {{-- Address --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Address</label>
                        <input type="text" class="form-control" name="address" id="f_address"
                               maxlength="255" placeholder="Street, City, Province">
                    </div>

                    {{-- Contact Number --}}
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Contact Number</label>
                        <input type="text" class="form-control" name="contact_number" id="f_contact_number"
                               maxlength="30" placeholder="09XX XXX XXXX">
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon" id="btnSave">
                        <i class="bi bi-check-lg me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
/**
 * Branches -- Client-side logic
 */

const API  = '{{ route("branches.api") }}';
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
let modal;

// Initialize Bootstrap modal once the page loads
document.addEventListener('DOMContentLoaded', function () {
    modal = new bootstrap.Modal(document.getElementById('branchModal'));
});

/**
 * Open the modal for adding or editing.
 */
function openModal(data) {
    const form  = document.getElementById('branchForm');
    const title = document.getElementById('modalTitle');

    form.reset();

    if (data) {
        // EDIT mode
        title.innerHTML = '<i class="bi bi-pencil me-1"></i> Edit Branch';
        document.getElementById('f_id').value             = data.id;
        document.getElementById('f_branch_name').value    = data.branch_name;
        document.getElementById('f_branch_type').value    = data.branch_type;
        document.getElementById('f_address').value        = data.address || '';
        document.getElementById('f_contact_number').value = data.contact_number || '';
    } else {
        // ADD mode
        title.innerHTML = '<i class="bi bi-building me-1"></i> Add Branch';
        document.getElementById('f_id').value = '';
    }

    modal.show();
}

/**
 * Save branch (create or update) via AJAX.
 */
async function saveBranch(e) {
    e.preventDefault();

    const form = document.getElementById('branchForm');
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
            location.reload();
        } else {
            alert(json.error || 'Something went wrong.');
        }
    } catch (err) {
        alert('Network error. Please check your connection.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Save';
    }
}

/**
 * Toggle branch active/inactive.
 */
async function toggleBranch(id, newStatus) {
    const action = newStatus ? 'activate' : 'deactivate';
    if (!confirm('Are you sure you want to ' + action + ' this branch?')) return;

    const data = new FormData();
    data.append('action', 'toggle');
    data.append('id', id);
    data.append('is_active', newStatus);
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
</script>
@endpush
