@extends('layouts.app')

@section('title', 'Settings')

@section('content')


<div class="row">
    <div class="col-lg-8">
        <form method="POST" action="{{ route('settings.update') }}">
            @csrf

            {{-- App Name --}}
            <div class="hv-card p-3 mb-3">
                <h6 style="color:var(--maroon);" class="mb-3"><i class="bi bi-shop me-1"></i>General</h6>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Application Name</label>
                    <input type="text" class="form-control" name="app_name"
                           value="{{ $cfg['app_name']['value'] ?? 'Hidden Vietnam FRMS' }}" maxlength="100">
                    <div class="form-text">Display name shown in the sidebar and page titles.</div>
                </div>
            </div>

            {{-- Forecast Settings --}}
            <div class="hv-card p-3 mb-3">
                <h6 style="color:var(--maroon);" class="mb-3"><i class="bi bi-graph-up-arrow me-1"></i>Forecast & DSS</h6>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Forecast Weeks</label>
                        <input type="number" class="form-control" name="forecast_weeks"
                               value="{{ $cfg['forecast_weeks']['value'] ?? '8' }}" min="4" max="52">
                        <div class="form-text">Number of weeks of historical data to analyze.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label fw-semibold">Safety Stock Factor</label>
                        <input type="number" class="form-control" name="safety_stock_factor"
                               value="{{ $cfg['safety_stock_factor']['value'] ?? '1.5' }}" min="1" max="5" step="0.1">
                        <div class="form-text">Multiplier for safety stock in recommendations.</div>
                    </div>
                </div>
            </div>

            {{-- Alert Settings --}}
            <div class="hv-card p-3 mb-3">
                <h6 style="color:var(--maroon);" class="mb-3"><i class="bi bi-bell me-1"></i>Alert Thresholds</h6>
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Unusual Sales (σ)</label>
                        <input type="number" class="form-control" name="unusual_sales_sigma"
                               value="{{ $cfg['unusual_sales_sigma']['value'] ?? '2' }}" min="1" max="5" step="0.5">
                        <div class="form-text">Z-score threshold for unusual sales alerts.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Idle Branch (days)</label>
                        <input type="number" class="form-control" name="idle_branch_days"
                               value="{{ $cfg['idle_branch_days']['value'] ?? '3' }}" min="1" max="30">
                        <div class="form-text">Days without activity to trigger idle alert.</div>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Expiry Warning (days)</label>
                        <input type="number" class="form-control" name="expiry_warning_days"
                               value="{{ $cfg['expiry_warning_days']['value'] ?? '7' }}" min="1" max="30">
                        <div class="form-text">Days before expiry to trigger a warning.</div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-maroon">
                <i class="bi bi-check-lg me-1"></i> Save Settings
            </button>
        </form>
    </div>

    <div class="col-lg-4">
        @if(auth()->user()?->role?->role_name === 'super_admin')
        <div class="hv-card p-3 mb-3" id="backup">
            <h6 style="color:var(--maroon);" class="mb-3"><i class="bi bi-database-down me-1"></i>Database Backup</h6>
            <p class="small text-muted mb-3">Create a timestamped MySQL database backup. Backups are stored securely on the server.</p>
            <form method="POST" action="{{ route('backup') }}">
                @csrf
                <button type="submit" class="btn btn-maroon w-100" onclick="this.disabled=true;this.innerHTML='<i class=\'bi bi-hourglass-split me-1\'></i>Creating backup...';this.form.submit();">
                    <i class="bi bi-download me-1"></i> Create Backup Now
                </button>
            </form>
            <div class="small text-muted mt-2">
                <i class="bi bi-shield-lock me-1"></i> Super Admin only. Backups saved to <code>storage/app/backups/</code>
            </div>
        </div>
        @endif

        <div class="hv-card p-3">
            <h6 style="color:var(--maroon);" class="mb-2"><i class="bi bi-info-circle me-1"></i>About</h6>
            <div class="small text-muted">
                <p><strong>Hidden Vietnam FRMS</strong></p>
                <p>Franchise Restaurant Management System with inventory tracking, FIFO stock management, ordering, and decision support.</p>
                <hr>
                <p class="mb-1"><strong>Version:</strong> 1.0.0</p>
                <p class="mb-1"><strong>Stack:</strong> Laravel 12 + MySQL + Bootstrap 5</p>
                <p class="mb-1"><strong>Database:</strong> hidden_vietnam</p>
                <p class="mb-0"><strong>Timezone:</strong> Asia/Manila (UTC+8)</p>
            </div>
        </div>
    </div>
</div>

@endsection
