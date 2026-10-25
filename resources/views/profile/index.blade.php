@extends('layouts.app')

@section('title', 'My Profile')

@section('content')


<div class="row g-4">

    <!-- Profile Info Card -->
    <div class="col-md-4">
        <div class="hv-card p-4 text-center">
            <div class="mb-3">
                @if($user->profile_photo)
                    <img src="{{ asset($user->profile_photo) }}" alt="Profile Photo"
                         class="rounded-circle" style="width:100px; height:100px; object-fit:cover; border:3px solid var(--maroon);">
                @else
                    <div class="d-inline-flex align-items-center justify-content-center rounded-circle"
                         style="width:100px; height:100px; background:var(--maroon); color:#fff; font-size:2.5rem;">
                        <i class="ph-fill ph-user"></i>
                    </div>
                @endif
            </div>
            <h5 class="fw-bold mb-1">{{ $user->full_name }}</h5>
            <p class="text-muted small mb-2">{{ $user->email }}</p>
            <span class="hv-badge hv-badge-info">{{ ucwords(str_replace('_', ' ', $user->role->role_name ?? '')) }}</span>
            @if($user->branch)
                <div class="mt-2">
                    <span class="text-muted small"><i class="ph ph-map-pin me-1"></i>{{ $user->branch->branch_name }}</span>
                </div>
            @endif

            <!-- Upload Photo Form -->
            <hr>
            <form method="POST" action="{{ route('profile.photo') }}" enctype="multipart/form-data">
                @csrf
                <div class="mb-2">
                    <label class="form-label small fw-semibold">Update Photo</label>
                    <input type="file" class="form-control form-control-sm" name="profile_photo" accept="image/*" required>
                </div>
                <button type="submit" class="btn btn-maroon btn-sm w-100">
                    <i class="ph ph-upload-simple me-1"></i> Upload Photo
                </button>
            </form>
        </div>
    </div>

    <!-- Account Details -->
    <div class="col-md-8">

        <!-- Info Card -->
        <div class="hv-card p-4 mb-3">
            <h6 class="fw-bold mb-3"><i class="ph ph-info me-2"></i>Account Information</h6>
            <div class="row g-3">
                <div class="col-sm-6">
                    <div class="small text-muted mb-1">Full Name</div>
                    <div class="fw-semibold">{{ $user->full_name }}</div>
                </div>
                <div class="col-sm-6">
                    <div class="small text-muted mb-1">Email Address</div>
                    <div class="fw-semibold">{{ $user->email }}</div>
                </div>
                <div class="col-sm-6">
                    <div class="small text-muted mb-1">Role</div>
                    <div class="fw-semibold">{{ ucwords(str_replace('_', ' ', $user->role->role_name ?? '')) }}</div>
                </div>
                <div class="col-sm-6">
                    <div class="small text-muted mb-1">Branch</div>
                    <div class="fw-semibold">{{ $user->branch->branch_name ?? 'N/A' }}</div>
                </div>
                <div class="col-sm-6">
                    <div class="small text-muted mb-1">Account Status</div>
                    <div>
                        @if($user->status === 'active')
                            <span class="hv-badge hv-badge-success">Active</span>
                        @else
                            <span class="hv-badge hv-badge-danger">{{ ucfirst($user->status) }}</span>
                        @endif
                    </div>
                </div>
                <div class="col-sm-6">
                    <div class="small text-muted mb-1">Member Since</div>
                    <div class="fw-semibold">{{ $user->created_at ? $user->created_at->format('M j, Y') : 'N/A' }}</div>
                </div>
            </div>
        </div>

        <!-- Change Password Card -->
        <div class="hv-card p-4">
            <h6 class="fw-bold mb-3"><i class="ph ph-shield-check me-2"></i>Change Password</h6>
            <form method="POST" action="{{ route('profile.password') }}">
                @csrf
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Current Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="current_password" required>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">New Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="new_password" required minlength="8">
                    <small class="text-muted">Minimum 8 characters.</small>
                </div>
                <div class="mb-3">
                    <label class="form-label small fw-semibold">Confirm New Password <span class="text-danger">*</span></label>
                    <input type="password" class="form-control" name="new_password_confirmation" required minlength="8">
                </div>
                <button type="submit" class="btn btn-maroon">
                    <i class="ph ph-check me-1"></i> Update Password
                </button>
            </form>
        </div>
    </div>
</div>

@endsection
