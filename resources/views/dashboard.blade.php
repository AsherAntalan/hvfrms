@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

@php
    $statusMap = [
        'pending'    => ['hv-badge-warning',  'Pending'],
        'approved'   => ['hv-badge-info',     'Approved'],
        'preparing'  => ['hv-badge-info',     'Preparing'],
        'delivering' => ['hv-badge-info',     'Delivering'],
        'delivered'  => ['hv-badge-success',  'Delivered'],
        'settled'    => ['hv-badge-success',  'Settled'],
        'cancelled'  => ['hv-badge-danger',   'Cancelled'],
    ];

    $sevBadges = [
        'critical' => 'hv-badge-danger',
        'warning'  => 'hv-badge-warning',
        'info'     => 'hv-badge-info',
    ];
@endphp

{{-- Welcome bar --}}
<div class="hv-page-header">
    <div>
        <span class="fw-semibold" style="color:var(--maroon);">Welcome, {{ $user->full_name }}</span>
        <span class="text-muted small ms-2 d-none d-sm-inline">{{ now()->format('l, F j, Y') }}</span>
    </div>
</div>

{{-- ═══ STAT CARDS ═══════════════════════════════════════ --}}
<div class="row g-3 mb-4">

    {{-- Active Orders --}}
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ route('orders') }}" class="hv-stat hv-stat-link">
            <div class="hv-stat-icon" style="background:#FFF3CD;color:#856404;">
                <i class="bi bi-cart3"></i>
            </div>
            <div>
                <div class="hv-stat-value">{{ $stats['pending_orders'] }}</div>
                <div class="hv-stat-label">Active Orders</div>
            </div>
        </a>
    </div>

    {{-- Deliveries / In Transit --}}
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ route('delivery') }}" class="hv-stat hv-stat-link">
            <div class="hv-stat-icon" style="background:#E3EEF5;color:#2C6E8F;">
                <i class="bi bi-truck"></i>
            </div>
            <div>
                <div class="hv-stat-value">{{ $stats['delivering'] }}</div>
                <div class="hv-stat-label">In Transit</div>
            </div>
        </a>
    </div>

    {{-- Preparing (warehouse roles) --}}
    @if($isWarehouse || $isOwner)
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ route('delivery') }}" class="hv-stat hv-stat-link">
            <div class="hv-stat-icon" style="background:#E8F5E9;color:#2E7D32;">
                <i class="bi bi-box-seam"></i>
            </div>
            <div>
                <div class="hv-stat-value">{{ $stats['preparing'] }}</div>
                <div class="hv-stat-label">Preparing</div>
            </div>
        </a>
    </div>
    @endif

    {{-- Low Stock --}}
    @if($user->canManageInventory() || $isWarehouse)
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ $isWarehouse ? route('warehouse') : route('reports') }}" class="hv-stat hv-stat-link">
            <div class="hv-stat-icon" style="background:#FBEED3;color:#9A6B00;">
                <i class="bi bi-exclamation-triangle"></i>
            </div>
            <div>
                <div class="hv-stat-value">{{ $stats['low_stock'] }}</div>
                <div class="hv-stat-label">Low Stock</div>
            </div>
        </a>
    </div>
    @endif

    {{-- Expired (warehouse/owner) --}}
    @if(($isWarehouse || $isOwner) && isset($stats['expired']) && $stats['expired'] > 0)
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ route('warehouse') }}" class="hv-stat hv-stat-link">
            <div class="hv-stat-icon" style="background:#F6DED9;color:var(--danger);">
                <i class="bi bi-calendar-x"></i>
            </div>
            <div>
                <div class="hv-stat-value">{{ $stats['expired'] }}</div>
                <div class="hv-stat-label">Expired</div>
            </div>
        </a>
    </div>
    @endif

    {{-- Alerts --}}
    @if($user->canManageInventory() || $isWarehouse)
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ route('alerts') }}" class="hv-stat hv-stat-link">
            <div class="hv-stat-icon" style="background:#F6DED9;color:var(--danger);">
                <i class="bi bi-bell"></i>
            </div>
            <div>
                <div class="hv-stat-value">{{ $stats['alerts'] }}</div>
                <div class="hv-stat-label">Open Alerts</div>
            </div>
        </a>
    </div>
    @endif

    {{-- Branches --}}
    @unless($isWS)
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ $isOwner ? route('branches') : '#' }}" class="hv-stat hv-stat-link">
            <div class="hv-stat-icon" style="background:#E3EEF5;color:#2C6E8F;">
                <i class="bi bi-building"></i>
            </div>
            <div>
                @if($isBranchScoped && !$isWarehouse)
                    <div class="hv-stat-value" style="font-size:1.1rem;">{{ $branchName }}</div>
                    <div class="hv-stat-label">My Branch</div>
                @else
                    <div class="hv-stat-value">{{ $stats['branches'] }}</div>
                    <div class="hv-stat-label">Branches</div>
                @endif
            </div>
        </a>
    </div>
    @endunless

    {{-- Users (Owner only) --}}
    @if($isOwner)
    <div class="col-6 col-md-4 col-xl-2">
        <a href="{{ route('users') }}" class="hv-stat hv-stat-link">
            <div class="hv-stat-icon" style="background:var(--gold-soft);color:var(--maroon);">
                <i class="bi bi-people"></i>
            </div>
            <div>
                <div class="hv-stat-value">{{ $stats['users_active'] ?? 0 }}</div>
                <div class="hv-stat-label">Active Users</div>
            </div>
        </a>
    </div>
    @endif
</div>

{{-- ═══ NEEDS YOUR ATTENTION ═════════════════════════════ --}}
@if($isOwner || $user->canManageInventory() || $isWarehouse)
@php
    $attention = [];
    if ($stats['pending'] > 0 && ($isOwner || $isWA))
        $attention[] = ['icon' => 'bi-hourglass-split', 'text' => $stats['pending'] . ' order' . ($stats['pending'] > 1 ? 's' : '') . ' waiting for approval', 'link' => route('orders'), 'color' => '#856404'];
    if (($stats['approved'] ?? 0) > 0 && ($isWarehouse || $isOwner))
        $attention[] = ['icon' => 'bi-box-arrow-in-down', 'text' => $stats['approved'] . ' approved order' . ($stats['approved'] > 1 ? 's' : '') . ' to prepare', 'link' => route('delivery'), 'color' => '#2E7D32'];
    if (($stats['preparing'] ?? 0) > 0 && ($isWarehouse || $isOwner))
        $attention[] = ['icon' => 'bi-box-seam', 'text' => $stats['preparing'] . ' order' . ($stats['preparing'] > 1 ? 's' : '') . ' being prepared', 'link' => route('delivery'), 'color' => '#2C6E8F'];
    if ($stats['delivering'] > 0)
        $attention[] = ['icon' => 'bi-truck', 'text' => $stats['delivering'] . ' deliver' . ($stats['delivering'] > 1 ? 'ies' : 'y') . ' in progress', 'link' => route('delivery'), 'color' => '#2C6E8F'];
    if ($stats['low_stock'] > 0)
        $attention[] = ['icon' => 'bi-exclamation-triangle', 'text' => $stats['low_stock'] . ' low-stock product' . ($stats['low_stock'] > 1 ? 's' : ''), 'link' => $isWarehouse ? route('warehouse') : route('reports'), 'color' => '#9A6B00'];
    if ($stats['alerts_critical'] > 0)
        $attention[] = ['icon' => 'bi-exclamation-circle', 'text' => $stats['alerts_critical'] . ' critical alert' . ($stats['alerts_critical'] > 1 ? 's' : ''), 'link' => route('alerts'), 'color' => 'var(--danger)'];
    if (isset($stats['expired']) && $stats['expired'] > 0)
        $attention[] = ['icon' => 'bi-calendar-x', 'text' => $stats['expired'] . ' expired batch' . ($stats['expired'] > 1 ? 'es' : '') . ' for disposal', 'link' => route('warehouse'), 'color' => 'var(--danger)'];
    if ($isOwner && isset($stats['inactive_branches']) && $stats['inactive_branches'] > 0)
        $attention[] = ['icon' => 'bi-building-x', 'text' => $stats['inactive_branches'] . ' inactive branch' . ($stats['inactive_branches'] > 1 ? 'es' : ''), 'link' => route('branches'), 'color' => 'var(--muted)'];
@endphp
@if(count($attention) > 0)
<div class="hv-card p-3 mb-4">
    <h6 style="color:var(--maroon);font-weight:700;" class="mb-3">
        <i class="bi bi-flag me-1"></i> Needs Your Attention
    </h6>
    <div class="d-flex flex-wrap gap-2">
        @foreach($attention as $a)
        <a href="{{ $a['link'] }}" class="d-flex align-items-center gap-2 px-3 py-2 rounded-3 text-decoration-none"
           style="background:var(--cream);border:1px solid var(--border);color:var(--ink);font-size:13px;">
            <i class="bi {{ $a['icon'] }}" style="color:{{ $a['color'] }};font-size:16px;"></i>
            <span>{{ $a['text'] }}</span>
            <i class="bi bi-chevron-right text-muted" style="font-size:11px;"></i>
        </a>
        @endforeach
    </div>
</div>
@endif
@endif

{{-- ═══ MAIN CONTENT ROW ════════════════════════════════ --}}
<div class="row g-3">
    {{-- Left column: Orders, Low Stock --}}
    <div class="col-lg-8">

        {{-- Recent Orders --}}
        <div class="hv-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 style="color:var(--maroon);font-weight:700;" class="mb-0">
                    <i class="bi bi-clock-history me-1"></i> Recent Orders
                </h6>
                <a href="{{ route('orders') }}" class="btn btn-sm btn-outline-secondary">
                    View All <i class="bi bi-arrow-right ms-1"></i>
                </a>
            </div>
            @if($recentOrders->isEmpty())
                <p class="text-muted small text-center py-3 mb-0">No orders yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0" style="font-size:13px;">
                        <thead>
                            <tr>
                                <th>Order #</th>
                                <th>Branch</th>
                                <th class="text-end">Amount</th>
                                <th class="text-center">Status</th>
                                <th>Date</th>
                            </tr>
                        </thead>
                        <tbody>
                        @foreach($recentOrders as $o)
                            @php $sb = $statusMap[$o->status] ?? ['hv-badge-info', $o->status]; @endphp
                            <tr>
                                <td class="fw-semibold">{{ $o->order_number }}</td>
                                <td>{{ $o->branch_name }}</td>
                                <td class="text-end">{{ number_format($o->total_amount, 2) }}</td>
                                <td class="text-center">
                                    <span class="hv-badge {{ $sb[0] }}">{{ $sb[1] }}</span>
                                </td>
                                <td class="text-muted">{{ \Carbon\Carbon::parse($o->created_at)->format('M j, g:i A') }}</td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @endif
        </div>

        {{-- Low Stock --}}
        @if(($user->canManageInventory() || $isWarehouse) && !empty($lowStock))
        <div class="hv-card p-3 mt-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 style="color:var(--maroon);font-weight:700;" class="mb-0">
                    <i class="bi bi-exclamation-triangle me-1"></i> Low Stock Items
                </h6>
                @if(!$isWS)
                <a href="{{ $isWarehouse ? route('warehouse') : route('reports') }}" class="btn btn-sm btn-outline-secondary">
                    Full Report <i class="bi bi-arrow-right ms-1"></i>
                </a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Branch</th>
                            <th class="text-center">On Hand</th>
                            <th class="text-center">Reorder Lvl</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach($lowStock as $ls)
                        @php $critical = $ls->total_qty <= ($ls->reorder_level / 2); @endphp
                        <tr class="{{ $critical ? 'table-danger' : 'table-warning' }}">
                            <td class="fw-semibold">{{ $ls->item_name }}</td>
                            <td>{{ $ls->branch_name }}</td>
                            <td class="text-center fw-bold">{{ (int) $ls->total_qty }} {{ $ls->unit }}</td>
                            <td class="text-center">{{ (int) $ls->reorder_level }}</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
        </div>
        @endif
    </div>

    {{-- Right column: Quick Actions, Alerts --}}
    <div class="col-lg-4">

        {{-- Quick Actions --}}
        <div class="hv-card p-3 mb-3">
            <h6 style="color:var(--maroon);font-weight:700;" class="mb-3">
                <i class="bi bi-lightning me-1"></i> Quick Actions
            </h6>
            <div class="d-grid gap-2">
                @if($isWS)
                    <a href="{{ route('warehouse') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-box-arrow-in-down me-2"></i> Receive Stock
                    </a>
                    <a href="{{ route('qr') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-qr-code-scan me-2"></i> Scan QR
                    </a>
                    <a href="{{ route('delivery') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-box-seam me-2"></i> Orders to Prepare
                    </a>
                @elseif($isWA)
                    <a href="{{ route('warehouse') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-box-arrow-in-down me-2"></i> Receive Stock
                    </a>
                    <a href="{{ route('delivery') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-box-seam me-2"></i> Prepare Deliveries
                    </a>
                    <a href="{{ route('qr') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-qr-code-scan me-2"></i> QR Management
                    </a>
                    <a href="{{ route('alerts') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-bell me-2"></i> View Alerts
                    </a>
                @elseif($isBM)
                    <a href="{{ route('ordering') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-cart-plus me-2"></i> Order Supplies
                    </a>
                    <a href="{{ route('delivery') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-truck me-2"></i> Incoming Deliveries
                    </a>
                    <a href="{{ route('inventory') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-box-seam me-2"></i> My Inventory
                    </a>
                    <a href="{{ route('qr') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-qr-code-scan me-2"></i> QR / Scanning
                    </a>
                @else
                    <a href="{{ route('orders') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-cart-plus me-2"></i> New Order
                    </a>
                    @if($user->canManageInventory())
                    <a href="{{ route('stock') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-box-arrow-in-down me-2"></i> Stock Management
                    </a>
                    @endif
                    <a href="{{ route('delivery') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-truck me-2"></i> Manage Deliveries
                    </a>
                    @if($isOwner)
                    <a href="{{ route('users') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-person-plus me-2"></i> Manage Users
                    </a>
                    <a href="{{ route('branches') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-building me-2"></i> Manage Branches
                    </a>
                    @endif
                    @if($user->canManageInventory())
                    <a href="{{ route('forecast') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-graph-up-arrow me-2"></i> Forecast & DSS
                    </a>
                    <a href="{{ route('reports') }}" class="btn btn-sm btn-outline-secondary text-start">
                        <i class="bi bi-file-earmark-bar-graph me-2"></i> Reports
                    </a>
                    @endif
                @endif
            </div>
        </div>

        {{-- Active Alerts --}}
        @if($user->canManageInventory() || $isWarehouse)
        <div class="hv-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 style="color:var(--maroon);font-weight:700;" class="mb-0">
                    <i class="bi bi-bell me-1"></i> Active Alerts
                </h6>
                @if(!$isWS)
                <a href="{{ route('alerts') }}" class="btn btn-sm btn-outline-secondary">
                    All <i class="bi bi-arrow-right ms-1"></i>
                </a>
                @endif
            </div>
            @if(empty($activeAlerts) || (is_countable($activeAlerts) && count($activeAlerts) === 0) || ($activeAlerts instanceof \Illuminate\Support\Collection && $activeAlerts->isEmpty()))
                <div class="text-center text-muted small py-3">
                    <i class="bi bi-check-circle fs-4 d-block mb-2" style="color:var(--success);"></i>
                    All clear &mdash; no open alerts.
                </div>
            @else
                @foreach($activeAlerts as $a)
                <div class="border-bottom py-2"
                     style="{{ $a->severity === 'critical' ? 'border-left: 3px solid var(--bs-danger); padding-left:8px;' : '' }}">
                    <div class="d-flex gap-1 mb-1">
                        <span class="hv-badge {{ $sevBadges[$a->severity] ?? 'hv-badge-info' }}" style="font-size:10px;">
                            {{ ucfirst($a->severity) }}
                        </span>
                        <span class="hv-badge hv-badge-info" style="font-size:10px;">
                            {{ str_replace('_', ' ', $a->alert_type) }}
                        </span>
                    </div>
                    <p class="mb-0 small" style="line-height:1.3;">{{ $a->message }}</p>
                    <div class="text-muted" style="font-size:10px;">{{ \Carbon\Carbon::parse($a->created_at)->format('M j, g:i A') }}</div>
                </div>
                @endforeach
            @endif
        </div>
        @endif

    </div>
</div>

@endsection
