@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')

@php
    $statusMap = [
        'pending'    => ['hv-badge-warning',  'Pending'],
        'approved'   => ['hv-badge-info',     'Awaiting Confirmation'],
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

<div class="hv-dash">

{{-- Welcome bar --}}
<header class="hv-dash-head">
    <div class="hv-dash-greeting">
        <h1 class="hv-dash-title">Welcome back, {{ \Illuminate\Support\Str::of($user->full_name)->trim()->explode(' ')->first() }}</h1>
        <p class="hv-dash-sub">{{ $branchName ?? 'All branches' }} &middot; {{ \Illuminate\Support\Str::of($user->role->role_name)->replace('_',' ')->title() }}</p>
    </div>
    <time class="hv-dash-date" datetime="{{ now()->toDateString() }}">
        <span class="hv-dash-dow">{{ now()->format('l') }}</span>
        {{ now()->format('F j, Y') }}
    </time>
</header>

{{-- ═══ SUMMARY CONTAINERS ═══════════════════════════════ --}}
<div class="hv-sumrow mb-4">

    {{-- LEFT — operations --}}
    <section class="hv-sum hv-sum--ops">
        <div class="hv-sum-top">
            {{-- Red tile: Commissary Sales (authorized roles) or Active Orders --}}
            @if($salesAuthorized ?? false)
            <a href="{{ route('reports', ['tab' => 'sales']) }}" class="hv-m is-primary hv-m--sales">
                <span class="hv-m-head">
                    <span class="hv-m-label">{{ ($demoPreview ?? false) ? 'Simulated Sales' : 'Commissary Sales' }}</span>
                </span>
                @if($salesAvailable ?? false)
                <span class="hv-m-value hv-m--money">&#8369;{{ number_format($salesAmount, 2) }}</span>
                <span class="hv-m-sub">This month &middot; {{ $salesRangeLabel }}</span>
                @if($demoPreview ?? false)
                <span class="hv-m-note">Closed demo orders &middot; not verified payments</span>
                @else
                <span class="hv-m-note">Released order value &middot; includes completed orders</span>
                @endif
                @else
                <span class="hv-m-value hv-m--money">&mdash;</span>
                <span class="hv-m-sub">This month &middot; {{ $salesRangeLabel }}</span>
                <span class="hv-m-note">Verified payment data unavailable</span>
                @endif
            </a>
            @else
            <a href="{{ route('orders') }}" class="hv-m is-primary">
                <span class="hv-m-head">
                    <span class="hv-m-ico"><i class="ph ph-shopping-cart-simple"></i></span>
                    <span class="hv-m-label">Active Orders</span>
                </span>
                <span class="hv-m-value">{{ $stats['pending_orders'] }}</span>
            </a>
            @endif

            {{-- In Transit — Commissary dashboard shows the Sales tile only --}}
            @unless($user->isCommissaryManager())
            <a href="{{ route('delivery') }}" class="hv-m">
                <span class="hv-m-head">
                    <span class="hv-m-ico"><i class="ph ph-truck"></i></span>
                    <span class="hv-m-label">In Transit</span>
                </span>
                <span class="hv-m-value">{{ $stats['delivering'] }}</span>
            </a>
            @endunless

        </div>

        @unless($isWS || $user->isCommissaryManager())
        <div class="hv-sum-divider"></div>
        <div class="hv-sum-bottom">
            {{-- Branches / My Branch — not shown for the Commissary (production source) --}}
            @unless($user->isCommissaryManager())
            <a href="{{ $isOwner ? route('branches') : '#' }}" class="hv-m">
                @if($isBranchScoped && !$isWarehouse)
                <span class="hv-m-head">
                    <span class="hv-m-ico"><i class="ph ph-buildings"></i></span>
                    <span class="hv-m-label">My Branch</span>
                </span>
                <span class="hv-m-value hv-m-value--text">{{ $branchName }}</span>
                @else
                <span class="hv-m-head">
                    <span class="hv-m-ico"><i class="ph ph-buildings"></i></span>
                    <span class="hv-m-label">Branches</span>
                </span>
                <span class="hv-m-value">{{ $stats['branches'] }}</span>
                @endif
            </a>
            @endunless

            {{-- Active Orders (moved here for roles whose red tile shows Sales) --}}
            @if($salesAuthorized ?? false)
            <a href="{{ route('orders') }}" class="hv-m">
                <span class="hv-m-head">
                    <span class="hv-m-ico"><i class="ph ph-shopping-cart-simple"></i></span>
                    <span class="hv-m-label">Active Orders</span>
                </span>
                <span class="hv-m-value">{{ $stats['pending_orders'] }}</span>
            </a>
            @endif
        </div>
        @endunless
    </section>

    {{-- RIGHT health panel removed — Low Stock / Expired / Open Alerts are
         already surfaced by "Needs Your Attention" and the Active Alerts card. --}}
</div>

{{-- ═══ NEEDS YOUR ATTENTION ═════════════════════════════ --}}
@if($isOwner || $user->canManageInventory() || $isWarehouse)
@php
    $attention = [];
    if ($stats['pending'] > 0 && ($isOwner || $isWA))
        $attention[] = ['icon' => 'ph-hourglass', 'text' => $stats['pending'] . ' order' . ($stats['pending'] > 1 ? 's' : '') . ' waiting for approval', 'link' => route('orders'), 'color' => '#856404'];
    if (($stats['approved'] ?? 0) > 0 && ($isWarehouse || $isOwner))
        $attention[] = ['icon' => 'ph-tray-arrow-down', 'text' => $stats['approved'] . ' approved order' . ($stats['approved'] > 1 ? 's' : '') . ' to prepare', 'link' => route('orders', ['queue' => 'prepare']), 'color' => '#2E7D32'];
    if (($stats['preparing'] ?? 0) > 0 && ($isWarehouse || $isOwner))
        $attention[] = ['icon' => 'ph-package', 'text' => $stats['preparing'] . ' order' . ($stats['preparing'] > 1 ? 's' : '') . ' being prepared', 'link' => route('orders', ['queue' => 'all']), 'color' => '#2C6E8F'];
    if ($stats['delivering'] > 0)
        $attention[] = ['icon' => 'ph-truck', 'text' => $stats['delivering'] . ' deliver' . ($stats['delivering'] > 1 ? 'ies' : 'y') . ' in progress', 'link' => route('delivery'), 'color' => '#2C6E8F'];
    if ($stats['low_stock'] > 0)
        $attention[] = ['icon' => 'ph-warning', 'text' => $stats['low_stock'] . ' low-stock product' . ($stats['low_stock'] > 1 ? 's' : ''), 'link' => $isWarehouse ? route('warehouse') : route('reports'), 'color' => '#9A6B00'];
    if ($stats['alerts_critical'] > 0)
        $attention[] = ['icon' => 'ph-warning-circle', 'text' => $stats['alerts_critical'] . ' critical alert' . ($stats['alerts_critical'] > 1 ? 's' : ''), 'link' => route('alerts'), 'color' => 'var(--danger)'];
    if (isset($stats['expired']) && $stats['expired'] > 0)
        $attention[] = ['icon' => 'ph-calendar-x', 'text' => $stats['expired'] . ' expired batch' . ($stats['expired'] > 1 ? 'es' : '') . ' for disposal', 'link' => route('warehouse', ['tab' => 'expired']), 'color' => 'var(--danger)'];
    if ($isOwner && isset($stats['inactive_branches']) && $stats['inactive_branches'] > 0)
        $attention[] = ['icon' => 'ph-buildings', 'text' => $stats['inactive_branches'] . ' inactive branch' . ($stats['inactive_branches'] > 1 ? 'es' : ''), 'link' => route('branches'), 'color' => 'var(--muted)'];
@endphp
@if(count($attention) > 0)
<div class="hv-card p-3 mb-4 hv-attn">
    <h6 class="hv-attn-title">Needs Your Attention</h6>
    <div class="hv-attn-list">
        @foreach($attention as $a)
        <a href="{{ $a['link'] }}" class="hv-attn-item">
            <span>{{ $a['text'] }}</span>
            <i class="ph ph-caret-right hv-attn-arrow"></i>
        </a>
        @endforeach
    </div>
</div>
@endif
@endif

{{-- ═══ MAIN CONTENT ROW ════════════════════════════════ --}}
<div class="row g-4">
    {{-- Recent orders --}}
    <div class="col-lg-5 d-flex flex-column">

        {{-- Recent Orders --}}
        <div class="hv-card p-3 h-100 flex-fill">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Recent Orders</h6>
                <a href="{{ route('orders') }}" class="btn btn-sm btn-outline-secondary">
                    View All <i class="ph ph-arrow-right ms-1"></i>
                </a>
            </div>
            @if($recentOrders->isEmpty())
                <p class="text-muted small text-center py-3 mb-0">No orders yet.</p>
            @else
                <div class="table-responsive">
                    <table class="table table-sm table-hover align-middle mb-0 hv-row-rhythm" style="font-size:13px;">
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
                        @foreach($recentOrders->take(6) as $o)
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

    </div>

        {{-- Low Stock --}}
        @if(($user->canManageInventory() || $isWarehouse) && !empty($lowStock))
    <div class="col-lg d-flex flex-column">
        <div class="hv-card p-3 h-100 flex-fill">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Low Stock Items</h6>
                @if(!$isWS)
                <a href="{{ $isWarehouse ? route('warehouse') : route('reports') }}" class="btn btn-sm btn-outline-secondary">
                    Full Report <i class="ph ph-arrow-right ms-1"></i>
                </a>
                @endif
            </div>
            <div class="table-responsive">
                <table class="table table-sm align-middle mb-0 hv-row-rhythm" style="font-size:13px;">
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Branch</th>
                            <th class="text-center">On Hand</th>
                            <th class="text-center">Reorder Lvl</th>
                        </tr>
                    </thead>
                    <tbody>
                    @foreach(collect($lowStock)->take(6) as $ls)
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
    </div>
        @endif

        {{-- Active Alerts --}}
        @if($user->canManageInventory() || $isWarehouse)
    <div class="col-lg d-flex flex-column">
        <div class="hv-card p-3 h-100 flex-fill">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h6 class="mb-0">Active Alerts</h6>
                @if(!$isWS)
                <a href="{{ route('alerts') }}" class="btn btn-sm btn-outline-secondary">
                    All <i class="ph ph-arrow-right ms-1"></i>
                </a>
                @endif
            </div>
            @if(empty($activeAlerts) || (is_countable($activeAlerts) && count($activeAlerts) === 0) || ($activeAlerts instanceof \Illuminate\Support\Collection && $activeAlerts->isEmpty()))
                <div class="text-center text-muted small py-3">
                    <i class="ph ph-check-circle fs-4 d-block mb-2" style="color:var(--success);"></i>
                    All clear &mdash; no open alerts.
                </div>
            @else
                <div class="hv-alert-head">Alert</div>
                @foreach(collect($activeAlerts)->take(6) as $a)
                <div class="hv-alert-row border-bottom {{ $a->severity === 'critical' ? 'hv-alert-crit' : '' }}">
                    <div class="d-flex gap-1 align-items-center">
                        <span class="hv-badge {{ $sevBadges[$a->severity] ?? 'hv-badge-info' }}" style="font-size:10px;">
                            {{ ucfirst($a->severity) }}
                        </span>
                        <span class="hv-badge hv-badge-info" style="font-size:10px;">
                            {{ str_replace('_', ' ', $a->alert_type) }}
                        </span>
                        <span class="text-muted ms-auto" style="font-size:10px;">{{ \Carbon\Carbon::parse($a->created_at)->format('M j, g:i A') }}</span>
                    </div>
                    <p class="mb-0 small hv-alert-msg" style="line-height:1.3;" title="{{ $a->message }}">{{ $a->message }}</p>
                </div>
                @endforeach
            @endif
        </div>
    </div>
        @endif
</div>

</div>{{-- /.hv-dash --}}

@endsection

@push('styles')
<style>
/* ══ Dashboard craft layer — scoped to .hv-dash only ══ */
.hv-dash{ --d-ink:var(--metric-ink); --d-soft:var(--metric-muted); --d-line:var(--border);
  --d-smoke:var(--summary-surface); --d-smoke-line:var(--summary-divider);
  font-family:var(--font-ui); }
/* Dashboard uses the shared workspace typography. */
.hv-dash .hv-dash-title,.hv-dash h1,.hv-dash h6,.hv-dash table,.hv-dash .btn{
  font-family:var(--font-ui); }
.hv-dash ::selection{ background:var(--gold-soft); color:var(--maroon-dark); }
.hv-dash a:focus-visible,.hv-dash .btn:focus-visible,.hv-dash .hv-stat-link:focus-visible{
  outline:2px solid var(--maroon); outline-offset:3px; border-radius:12px; }
.hv-dash .table-responsive::-webkit-scrollbar{ height:8px; }
.hv-dash .table-responsive::-webkit-scrollbar-thumb{ background:rgba(212,169,74,.4); border-radius:8px; }

/* Header */
.hv-dash-head{ display:flex; align-items:flex-end; justify-content:space-between;
  gap:1rem; flex-wrap:wrap; padding-bottom:1rem; margin-bottom:1.5rem;
  border-bottom:1px solid var(--d-line); }
.hv-dash-title{ font-size:clamp(1.9rem,1.4rem + 1.6vw,2.4rem); font-weight:700;
  letter-spacing:-0.02em; color:var(--maroon); margin:0; line-height:1.03; }
.hv-dash-sub{ margin:.35rem 0 0; font-size:12px; font-weight:600; letter-spacing:.09em;
  text-transform:uppercase; color:var(--d-soft); }
.hv-dash-date{ text-align:right; font-size:13px; color:var(--d-soft);
  font-variant-numeric:tabular-nums; line-height:1.3; }
.hv-dash-date .hv-dash-dow{ display:block; font-weight:800; letter-spacing:-.01em;
  color:var(--maroon); font-size:15px; }

/* Summary containers — two matte smoke-white panels, flat finish */
.hv-dash .hv-sumrow{ display:flex; gap:20px; flex-wrap:wrap; align-items:stretch; }
.hv-dash .hv-sum{ background:var(--d-smoke); border-radius:16px; padding:18px 10px; }
.hv-dash .hv-sum--ops{ flex:1 1 100%; min-width:min(100%, 320px); display:flex; flex-direction:column; justify-content:center; }
.hv-dash .hv-sum--health{ flex:1 1 40%; min-width:min(100%, 300px); display:flex; align-items:stretch; }
.hv-dash .hv-sum-top,.hv-dash .hv-sum-bottom{ display:flex; align-items:stretch; }
.hv-dash .hv-sum-divider{ height:1px; background:var(--d-smoke-line); margin:16px 12px; }

/* Metric cell */
.hv-dash .hv-m{ flex:1; min-width:0; position:relative; display:flex; flex-direction:column;
  gap:10px; justify-content:center; padding:10px 18px; text-decoration:none; color:inherit;
  border-left:1px solid var(--d-smoke-line); transition:background .16s; }
.hv-dash .hv-sum-top .hv-m:first-child,
.hv-dash .hv-sum-bottom .hv-m:first-child,
.hv-dash .hv-sum--health .hv-m:first-child,
.hv-dash .hv-m.is-primary{ border-left:0; }
.hv-dash .hv-m:not(.is-primary):hover{ background:rgba(0,0,0,.025); border-radius:10px; }
.hv-dash .hv-m-head{ display:flex; align-items:center; gap:8px; }
.hv-dash .hv-m-ico{ flex-shrink:0; font-size:20px; line-height:1; color:var(--gold); }
.hv-dash .hv-m-label{ font-size:12px; font-weight:700; letter-spacing:.05em;
  text-transform:uppercase; color:var(--d-soft); }
.hv-dash .hv-m-value{ font-size:2.4rem; font-weight:800; letter-spacing:-.03em; line-height:1;
  font-variant-numeric:tabular-nums; color:var(--d-ink); }
.hv-dash .hv-m-value--text{ font-size:1.25rem; line-height:1.15; }

/* Primary metric — solid matte fill, white content */
.hv-dash .hv-m.is-primary{ flex:0 0 50%; background:var(--maroon); border-radius:12px;
  padding:18px 20px; color:#fff; }
.hv-dash .hv-m.is-primary .hv-m-ico,
.hv-dash .hv-m.is-primary .hv-m-label{ color:#fff; }
.hv-dash .hv-m.is-primary .hv-m-value{ color:#fff; font-size:3.4rem; }
.hv-dash .hv-m.is-primary:hover{ background:var(--maroon-dark); }

/* Commissary Sales tile: currency sized to fit, period caption + unavailable note */
.hv-dash .hv-m--sales{ gap:6px; }
.hv-dash .hv-m.is-primary.hv-m--sales .hv-m--money{ font-size:clamp(1.7rem,1.1rem + 2vw,2.5rem);
  white-space:nowrap; letter-spacing:-.02em; }
.hv-dash .hv-m--sales .hv-m-sub{ font-size:12px; font-weight:600; color:rgba(255,255,255,.82); }
.hv-dash .hv-m--sales .hv-m-note{ font-size:11px; font-weight:600; color:rgba(255,255,255,.72);
  font-style:italic; }

/* Right container: larger numbers, existing semantic colors on number + icon */
.hv-dash .hv-sum--health .hv-m-value{ font-size:3.2rem; }
.hv-dash .hv-m[data-tone="warn"] .hv-m-ico,
.hv-dash .hv-m[data-tone="warn"] .hv-m-value{ color:#9A6B00; }
.hv-dash .hv-m[data-tone="alert"] .hv-m-ico,
.hv-dash .hv-m[data-tone="alert"] .hv-m-value{ color:var(--danger); }
.hv-dash .hv-m[data-tone="brand"] .hv-m-ico,
.hv-dash .hv-m[data-tone="brand"] .hv-m-value{ color:var(--maroon); }

@media (max-width:575.98px){
  .hv-dash .hv-sum-top,.hv-dash .hv-sum-bottom,.hv-dash .hv-sum--health{ flex-wrap:wrap; }
  .hv-dash .hv-m{ flex:1 1 45%; border-left:0; }
  .hv-dash .hv-m.is-primary{ flex:1 1 100%; }
}

/* Cards + section headings */
.hv-dash .hv-card{ border-radius:var(--panel-radius); padding:1.25rem !important; }
/* Uniform row rhythm so rows line up across the three panels (boxes stay natural height) */
.hv-dash .hv-row-rhythm thead th{ height:40px; vertical-align:middle; }
.hv-dash .hv-row-rhythm tbody tr{ height:56px; }
.hv-dash .hv-row-rhythm tbody td{ vertical-align:middle; }
.hv-dash .hv-alert-head{ display:flex; align-items:center; height:40px;
  font-size:11px; font-weight:700; letter-spacing:.06em; text-transform:uppercase;
  color:var(--muted); border-bottom:1px solid var(--border); margin-bottom:0; }
.hv-dash .hv-alert-row{ display:flex; flex-direction:column; justify-content:center; gap:3px; height:56px; }
.hv-dash .hv-alert-row .hv-alert-msg{ display:-webkit-box; -webkit-line-clamp:1; -webkit-box-orient:vertical;
  overflow:hidden; }
.hv-dash h6{ font-size:12px; font-weight:800; letter-spacing:.1em;
  text-transform:uppercase; color:var(--maroon); }

/* Tables */
.hv-dash table{ font-variant-numeric:tabular-nums; }
.hv-dash thead th{ font-size:10.5px; font-weight:700; letter-spacing:.08em;
  text-transform:uppercase; color:var(--d-soft); border-bottom:1px solid var(--d-line); }
.hv-dash tbody tr{ transition:background .15s; }
.hv-dash .table-hover tbody tr:hover{ background:var(--cream); }
.hv-dash .table-danger{ --bs-table-bg:rgba(192,57,43,.055); --bs-table-hover-bg:rgba(192,57,43,.1); }
.hv-dash .table-warning{ --bs-table-bg:rgba(212,169,74,.1); --bs-table-hover-bg:rgba(212,169,74,.18); }

/* Needs Your Attention — horizontal links with subtle separators (no pills, no icons) */
.hv-dash .hv-attn-title{ margin:0 0 .5rem; padding-bottom:.6rem;
  border-bottom:1px solid var(--d-line); }
.hv-dash .hv-attn-list{ display:flex; flex-wrap:wrap; align-items:center; }
.hv-dash .hv-attn-item{ display:inline-flex; align-items:center; gap:.45rem;
  padding:.4rem .95rem; color:var(--d-ink); font-size:13.5px; font-weight:500;
  text-decoration:none; border-left:1px solid var(--d-line); transition:color .16s; }
.hv-dash .hv-attn-item:first-child{ border-left:0; padding-left:.2rem; }
.hv-dash .hv-attn-item:hover{ color:var(--maroon); }
.hv-dash .hv-attn-arrow{ font-size:11px; color:var(--muted); transition:transform .18s, color .18s; }
.hv-dash .hv-attn-item:hover .hv-attn-arrow{ transform:translateX(3px); color:var(--maroon); }

/* Critical alert row: tint, not a slab border */
.hv-dash .hv-alert-crit{ background:rgba(192,57,43,.05); border-radius:8px;
  margin:0 -.35rem; padding-left:.6rem !important; padding-right:.6rem; }

/* Quick-action ghost buttons */
.hv-dash .d-grid .btn-outline-secondary{ border-color:var(--d-line); color:var(--d-ink);
  font-weight:500; border-radius:10px; transition:all .16s; }
.hv-dash .d-grid .btn-outline-secondary:hover{ background:var(--gold-soft);
  border-color:var(--gold); color:var(--maroon-dark); }
.hv-dash .d-grid .btn-outline-secondary i[class*="ph-"]{ color:var(--maroon); }

/* One authored entrance: tiles + cards settle in, from visible */
@keyframes hvRise{ from{ opacity:.001; transform:translateY(10px); } to{ opacity:1; transform:none; } }
@media (prefers-reduced-motion:no-preference){
  .hv-dash .hv-sumrow{ animation:hvRise .5s cubic-bezier(.2,.75,.2,1) both; animation-delay:.02s; }
  .hv-dash .row.g-4 > [class*="col-"]{ animation:hvRise .5s cubic-bezier(.2,.75,.2,1) both; }
  .hv-dash .row.g-4 > [class*="col-"]:nth-child(1){ animation-delay:.12s; }
  .hv-dash .row.g-4 > [class*="col-"]:nth-child(2){ animation-delay:.18s; }
  .hv-dash .row.g-4 > [class*="col-"]:nth-child(3){ animation-delay:.24s; }
}
</style>
@endpush
