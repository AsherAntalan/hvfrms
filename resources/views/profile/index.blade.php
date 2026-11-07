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
            <h6 class="fw-bold mb-3">Account Information</h6>
            @php
                $nextNameUpdate = $user->name_updated_at?->copy()->addDays(30);
                $nextEmailUpdate = $user->email_updated_at?->copy()->addDays(30);
                $nameLocked = $nextNameUpdate && now()->lt($nextNameUpdate);
                $emailLocked = $nextEmailUpdate && now()->lt($nextEmailUpdate);
                $profileLocked = $nameLocked && $emailLocked;
            @endphp
            <form method="POST" action="{{ route('profile.information') }}">
            @csrf
            <div class="row g-3">
                <div class="col-sm-6">
                    <label class="small text-muted mb-1" for="profileFullName">Full Name</label>
                    <input id="profileFullName" name="full_name" class="form-control" value="{{ $nameLocked ? $user->full_name : old('full_name', $user->full_name) }}" maxlength="100" required @readonly($nameLocked)>
                    @if($nameLocked)<div class="form-text">Name can be changed again on {{ $nextNameUpdate->format('M j, Y g:i A') }}.</div>@endif
                </div>
                <div class="col-sm-6">
                    <label class="small text-muted mb-1" for="profileEmail">Email Address</label>
                    <input id="profileEmail" type="email" name="email" class="form-control" value="{{ $emailLocked ? $user->email : old('email', $user->email) }}" maxlength="150" required @readonly($emailLocked)>
                    @if($emailLocked)<div class="form-text">Email can be changed again on {{ $nextEmailUpdate->format('M j, Y g:i A') }}.</div>@endif
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
            @if($errors->profileInformation->any())
                <div class="alert alert-danger mt-3" role="alert">{{ $errors->profileInformation->first() }}</div>
            @endif
            @if($profileLocked)
                <p class="small text-muted mt-3 mb-0">Name and email each have their own 30-day waiting period.</p>
            @else
                <div class="mt-3">
                    <label class="form-label small fw-semibold" for="profileCurrentPassword">Confirm Current Password</label>
                    <input id="profileCurrentPassword" type="password" class="form-control" name="current_password" autocomplete="current-password" required>
                    <div class="form-text">Only the field you change is locked for 30 days. The other field remains available.</div>
                </div>
                <button type="submit" class="btn btn-maroon mt-3">Save Account Information</button>
            @endif
            </form>
        </div>

        <!-- Change Password Card -->
        <div class="hv-card p-4">
            <h6 class="fw-bold mb-3">Change Password</h6>
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
                    Update Password
                </button>
            </form>
        </div>
    </div>
</div>

@endsection
