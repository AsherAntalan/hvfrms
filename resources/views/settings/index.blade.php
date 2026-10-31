@extends('layouts.app')

@section('title', 'System Settings')

@section('content')


<div class="row">
    <div class="col-lg-8">
        <form method="POST" action="{{ route('settings.update') }}">
            @csrf

            {{-- App Name --}}
            <div class="hv-card p-3 mb-3">
                <h6 style="color:var(--maroon);" class="mb-3"><i class="ph ph-storefront me-1"></i>General</h6>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Application Name</label>
                    <input type="text" class="form-control" name="app_name"
                           value="{{ $cfg['app_name']['value'] ?? 'Hidden Vietnam FRMS' }}" maxlength="100">
                    <div class="form-text">Display name shown in the sidebar and page titles.</div>
                </div>
            </div>

            {{-- Forecast Settings --}}
            <div class="hv-card p-3 mb-3">
                <h6 style="color:var(--maroon);" class="mb-3"><i class="ph ph-trend-up me-1"></i>Forecast & DSS</h6>
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
                <h6 style="color:var(--maroon);" class="mb-3"><i class="ph ph-bell me-1"></i>Alert Thresholds</h6>
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
                    <div class="col-md-4">
                        <label class="form-label fw-semibold">Order Max Qty / Product Line</label>
                        <input type="number" class="form-control" name="order_max_qty_per_line"
                               value="{{ $cfg['order_max_qty_per_line']['value'] ?? '0' }}" min="0" step="1">
                        <div class="form-text">Max units per product line on an order. <strong>0 = unlimited.</strong></div>
                    </div>
                </div>
            </div>

            <button type="submit" class="btn btn-maroon">
                <i class="ph ph-check me-1"></i> Save Settings
            </button>
        </form>
    </div>

    <div class="col-lg-4">
        @if(auth()->user()?->role?->role_name === 'super_admin')
        <div class="hv-card p-3 mb-3" id="backup">
            <h6 style="color:var(--maroon);" class="mb-3"><i class="ph ph-database me-1"></i>Database Backup</h6>
            <p class="small text-muted mb-3">Create a timestamped MySQL database backup. Backups are stored securely on the server.</p>
            <form method="POST" action="{{ route('backup') }}">
                @csrf
                <button type="submit" class="btn btn-maroon w-100" onclick="this.disabled=true;this.innerHTML='<i class=\'ph ph-hourglass me-1\'></i>Creating backup...';this.form.submit();">
                    <i class="ph ph-download-simple me-1"></i> Create Backup Now
                </button>
            </form>
            <div class="small text-muted mt-2">
                <i class="ph ph-shield-check me-1"></i> Super Admin only. Backups saved to <code>storage/app/backups/</code>
            </div>
        </div>
        @endif

        <div class="hv-card p-3 mb-3">
            <h6 class="fw-semibold mb-3" style="color:var(--maroon);">GCash receiving QR</h6>
            <p class="small text-muted">Upload the QR from GCash &gt; Receive Money via QR Code. A Pay QR/Barcode shown to a cashier is a different code.</p>
            @if(!empty($cfg['gcash_receiving_qr_path']['value']))
                <a href="{{ route('payments.gcash-qr') }}" target="_blank" rel="noopener"><img src="{{ route('payments.gcash-qr') }}" alt="Current GCash receiving QR" class="mb-2" style="max-width:100%;max-height:220px;"></a>
                <p class="small">Current recipient: <strong>{{ $cfg['gcash_account_name']['value'] ?? '' }}</strong></p>
            @else
                <p class="small text-muted">No receiving QR configured yet.</p>
            @endif
            <form method="POST" action="{{ route('settings.gcash-qr') }}" enctype="multipart/form-data">
                @csrf
                <label for="gcashAccountName" class="form-label small">Recipient name</label>
                <input id="gcashAccountName" name="gcash_account_name" class="form-control mb-2" required maxlength="100" value="{{ old('gcash_account_name', $cfg['gcash_account_name']['value'] ?? '') }}">
                <label for="gcashAccountNumber" class="form-label small">GCash number (optional)</label>
                <input id="gcashAccountNumber" name="gcash_account_number" class="form-control mb-2" inputmode="numeric" pattern="09[0-9]{9}" maxlength="11" placeholder="09XXXXXXXXX" value="{{ old('gcash_account_number', $cfg['gcash_account_number']['value'] ?? '') }}">
                <label for="gcashReceivingQr" class="form-label small">Receiving QR image</label>
                <input id="gcashReceivingQr" name="gcash_receiving_qr" type="file" class="form-control mb-2" required accept="image/jpeg,image/png,image/webp">
                <p class="small text-muted">JPG, PNG, or WebP, up to 5 MB.</p>
                <div class="form-check mb-3"><input class="form-check-input" id="confirmReceivingQr" name="confirm_receiving_qr" type="checkbox" value="1" required><label class="form-check-label small" for="confirmReceivingQr">I confirm this is the correct Receive Money QR and recipient for commissary orders.</label></div>
                <button class="btn btn-maroon" type="submit">Save receiving QR</button>
            </form>
        </div>

    </div>
</div>

@endsection
