<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — Hidden Vietnam FRMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/css/theme.css') }}?v={{ @filemtime(public_path('assets/css/theme.css')) ?: '1' }}" rel="stylesheet">
    @stack('styles')
</head>
<body>
<script>
    /* Apply persisted collapsed state before paint to avoid a flash */
    try { if (localStorage.getItem('hv-sidebar-collapsed') === '1') document.body.classList.add('sidebar-collapsed'); } catch (e) {}
</script>

<!-- ═══ SIDEBAR ══════════════════════════════════════════ -->
<aside class="hv-sidebar" id="sidebar">

    <!-- Sidebar Header: Centered Logo + Brand -->
    <div class="hv-sidebar-header">
        <img src="{{ asset('assets/img/logo.jpg') }}" alt="Hidden Vietnam" class="hv-sidebar-logo-img"
             onerror="this.src='https://placehold.co/70x70/5C1010/D4A94A?text=HV'">
        <span class="hv-sidebar-title">HIDDEN VIETNAM</span>
    </div>

    <nav class="hv-sidebar-nav">
        @php
            $u = Auth::user();
            $isOwner = $u->isOwner();
            $isBM = $u->isBranchManager();
            $isBS = $u->isBranchStaff();
            $isSA = $u->isSuperAdmin();
            $canInv = $u->canManageInventory();
            $isMainBM = $isBM && $u->branch && $u->branch->branch_type === 'main';
            $isFranchiseBM = $isBM && $u->branch && $u->branch->branch_type !== 'main';
            $isWA = $u->isWarehouseAdmin();
            $isWS = $u->isWarehouseStaff();
            $isWarehouse = $u->isWarehouseRole();
        @endphp

        {{-- ══════════════════════════════════════════════ --}}
        {{-- WAREHOUSE STAFF — operational nav               --}}
        {{-- ══════════════════════════════════════════════ --}}
        @if($isWS)

        {{-- Dashboard --}}
        <a href="{{ route('dashboard') }}"
           class="hv-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
        </a>

        {{-- Warehouse ▾ --}}
        @php $whActive = request()->routeIs('warehouse*','qr*'); @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $whActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="bi bi-building hv-nav-icon"></i>
                <span>Commissary</span>
                <i class="bi {{ $whActive ? 'bi-chevron-down' : 'bi-chevron-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $whActive ? 'open' : '' }}">
                <a href="{{ route('qr') }}"
                   class="hv-nav-link {{ request()->routeIs('qr*') ? 'active' : '' }}">
                    <span>QR Scanner</span>
                </a>
            </div>
        </div>

        {{-- Delivery — single page (Active + History tabs inside) --}}
        <a href="{{ route('delivery') }}"
           class="hv-nav-link {{ request()->routeIs('delivery*') ? 'active' : '' }}">
            <i class="bi bi-truck"></i> <span>Delivery</span>
        </a>

        <hr class="hv-sidebar-divider">

        {{-- My Profile --}}
        <a href="{{ route('profile') }}"
           class="hv-nav-link {{ request()->routeIs('profile*') ? 'active' : '' }}">
            <i class="bi bi-person-circle"></i> <span>My Profile</span>
        </a>

        {{-- ══════════════════════════════════════════════ --}}
        {{-- WAREHOUSE ADMIN — full warehouse nav            --}}
        {{-- ══════════════════════════════════════════════ --}}
        @elseif($isWA)

        {{-- Dashboard --}}
        <a href="{{ route('dashboard') }}"
           class="hv-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
        </a>

        {{-- Warehouse ▾ --}}
        @php $whActive = request()->routeIs('warehouse*','inventory*','stock*','products*'); @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $whActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="bi bi-building hv-nav-icon"></i>
                <span>All Branches</span>
                <i class="bi {{ $whActive ? 'bi-chevron-down' : 'bi-chevron-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $whActive ? 'open' : '' }}">
                <a href="{{ route('inventory') }}"
                   class="hv-nav-link {{ request()->routeIs('inventory*') ? 'active' : '' }}">
                    <span>Stock / Inventory</span>
                </a>
                <a href="{{ route('products') }}"
                   class="hv-nav-link {{ request()->routeIs('products*') ? 'active' : '' }}">
                    <span>Overall Products</span>
                </a>
                <a href="{{ route('stock') }}"
                   class="hv-nav-link {{ request()->routeIs('stock*') ? 'active' : '' }}">
                    <span>Stock Management</span>
                </a>
            </div>
        </div>

        {{-- Orders — order list / creation --}}
        <a href="{{ route('orders') }}"
           class="hv-nav-link {{ request()->routeIs('orders*') ? 'active' : '' }}">
            <i class="bi bi-receipt"></i> <span>Orders</span>
        </a>

        {{-- Delivery — single page (Active + History tabs inside) --}}
        <a href="{{ route('delivery') }}"
           class="hv-nav-link {{ request()->routeIs('delivery*') ? 'active' : '' }}">
            <i class="bi bi-truck"></i> <span>Delivery</span>
            <span class="hv-sidebar-badge" id="sidebarBadgeOrders" style="display:none;">0</span>
        </a>

        <hr class="hv-sidebar-divider">

        {{-- Alerts --}}
        <a href="{{ route('alerts') }}"
           class="hv-nav-link {{ request()->routeIs('alerts*') ? 'active' : '' }}">
            <i class="bi bi-bell"></i> <span>Alerts</span>
            <span class="hv-sidebar-badge" id="sidebarBadgeAlerts" style="display:none;">0</span>
        </a>

        <hr class="hv-sidebar-divider">

        {{-- My Profile --}}
        <a href="{{ route('profile') }}"
           class="hv-nav-link {{ request()->routeIs('profile*') ? 'active' : '' }}">
            <i class="bi bi-person-circle"></i> <span>My Profile</span>
        </a>

        {{-- ══════════════════════════════════════════════ --}}
        {{-- BRANCH STAFF — minimal branch nav               --}}
        {{-- ══════════════════════════════════════════════ --}}
        @elseif($isBS)

        <a href="{{ route('qr') }}"
           class="hv-nav-link {{ request()->routeIs('qr*') ? 'active' : '' }}">
            <i class="bi bi-qr-code-scan"></i> <span>QR Scan</span>
        </a>

        <hr class="hv-sidebar-divider">

        {{-- My Profile --}}
        <a href="{{ route('profile') }}"
           class="hv-nav-link {{ request()->routeIs('profile*') ? 'active' : '' }}">
            <i class="bi bi-person-circle"></i> <span>My Profile</span>
        </a>

        {{-- ══════════════════════════════════════════════ --}}
        {{-- OWNER / SUPER_ADMIN / BRANCH_MANAGER nav        --}}
        {{-- ══════════════════════════════════════════════ --}}
        @else

        {{-- Dashboard --}}
        <a href="{{ route('dashboard') }}"
           class="hv-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="bi bi-speedometer2"></i> <span>Dashboard</span>
        </a>

        {{-- ── Inventory ▾ ──────────────────────────────── --}}
        @if($canInv)
        @php
            $invActive = request()->routeIs('inventory*','stock*','warehouse*','products*');
        @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $invActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="bi bi-box-seam hv-nav-icon"></i>
                <span>Inventory</span>
                <i class="bi {{ $invActive ? 'bi-chevron-down' : 'bi-chevron-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $invActive ? 'open' : '' }}">
                <a href="{{ route('inventory') }}"
                   class="hv-nav-link {{ request()->routeIs('inventory*','warehouse*') ? 'active' : '' }}">
                    <span>{{ ($isOwner || $isMainBM) ? 'All Branches' : 'Inventory' }}</span>
                </a>
                @if($u->canManageCatalog() || $u->canManageProductAvailability())
                <a href="{{ route('products') }}"
                   class="hv-nav-link {{ request()->routeIs('products*') ? 'active' : '' }}">
                    <span>Overall Products</span>
                </a>
                @endif
                <a href="{{ route('stock') }}"
                   class="hv-nav-link {{ request()->routeIs('stock*') ? 'active' : '' }}">
                    <span>Stock Management</span>
                </a>
            </div>
        </div>
        @endif

        {{-- ── Orders ──────────────────────────────────── --}}
        @php
            $odActive = request()->routeIs('orders*','ordering*');
        @endphp
        @if($isBM)
        {{-- Branch manager also has Order Supplies → keep the dropdown --}}
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $odActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="bi bi-cart3 hv-nav-icon"></i>
                <span>Orders</span>
                <i class="bi {{ $odActive ? 'bi-chevron-down' : 'bi-chevron-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $odActive ? 'open' : '' }}">
                <a href="{{ route('orders') }}"
                   class="hv-nav-link {{ request()->routeIs('orders*') ? 'active' : '' }}">
                    <span>All Orders</span>
                </a>
                <a href="{{ route('ordering') }}"
                   class="hv-nav-link {{ request()->routeIs('ordering*') ? 'active' : '' }}">
                    <span>Order Supplies</span>
                </a>
            </div>
        </div>
        @else
        {{-- Owner / Admin / others: a single All Orders link, no dropdown --}}
        <a href="{{ route('orders') }}"
           class="hv-nav-link {{ request()->routeIs('orders*') ? 'active' : '' }}">
            <i class="bi bi-cart3"></i> <span>All Orders</span>
        </a>
        @endif

        {{-- ── Delivery — single page (Active + History tabs inside) ── --}}
        <a href="{{ route('delivery') }}"
           class="hv-nav-link {{ request()->routeIs('delivery*') ? 'active' : '' }}">
            <i class="bi bi-truck"></i> <span>Delivery</span>
            <span class="hv-sidebar-badge" id="sidebarBadgeOrders" style="display:none;">0</span>
        </a>

        <hr class="hv-sidebar-divider">

        {{-- ── Insights ▾ ───────────────────────────────── --}}
        @if($canInv)
        @php
            $insActive = request()->routeIs('forecast*','reports*','alerts*');
        @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $insActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="bi bi-graph-up-arrow hv-nav-icon"></i>
                <span>Insights</span>
                <span class="hv-sidebar-badge" id="sidebarBadgeAlerts" style="display:none;">0</span>
                <i class="bi {{ $insActive ? 'bi-chevron-down' : 'bi-chevron-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $insActive ? 'open' : '' }}">
                <a href="{{ route('forecast') }}"
                   class="hv-nav-link {{ request()->routeIs('forecast*') ? 'active' : '' }}">
                    <span>Forecast & DSS</span>
                </a>
                <a href="{{ route('reports') }}"
                   class="hv-nav-link {{ request()->routeIs('reports*') ? 'active' : '' }}">
                    <span>Reports</span>
                </a>
                <a href="{{ route('alerts') }}"
                   class="hv-nav-link {{ request()->routeIs('alerts*') ? 'active' : '' }}">
                    <span>Alerts</span>
                </a>
            </div>
        </div>
        @endif

        {{-- ── Management ▾ ─────────────────────────────── --}}
        @if($isOwner || $isSA)
        @php
            $mgtActive = request()->routeIs('branches*','users*');
        @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $mgtActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="bi bi-people hv-nav-icon"></i>
                <span>Management</span>
                <i class="bi {{ $mgtActive ? 'bi-chevron-down' : 'bi-chevron-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $mgtActive ? 'open' : '' }}">
                <a href="{{ route('branches') }}"
                   class="hv-nav-link {{ request()->routeIs('branches*') ? 'active' : '' }}">
                    <span>Branches</span>
                </a>
                <a href="{{ route('users') }}"
                   class="hv-nav-link {{ request()->routeIs('users*') ? 'active' : '' }}">
                    <span>Users</span>
                </a>
            </div>
        </div>
        @endif

        {{-- ── System ▾ ─────────────────────────────────── --}}
        @if($isOwner)
        @php
            $sysActive = request()->routeIs('audit*','settings*');
        @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $sysActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="bi bi-gear hv-nav-icon"></i>
                <span>System</span>
                <i class="bi {{ $sysActive ? 'bi-chevron-down' : 'bi-chevron-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $sysActive ? 'open' : '' }}">
                @if($isSA)
                <a href="{{ route('audit') }}"
                   class="hv-nav-link {{ request()->routeIs('audit*') ? 'active' : '' }}">
                    <span>Audit Log</span>
                </a>
                @endif
                <a href="{{ route('settings') }}"
                   class="hv-nav-link {{ request()->routeIs('settings*') ? 'active' : '' }}">
                    <span>Settings</span>
                </a>
                @if($isSA)
                <a href="{{ route('settings') }}#backup"
                   class="hv-nav-link">
                    <i class="bi bi-database-down"></i> <span>Database Backup</span>
                </a>
                @endif
            </div>
        </div>
        @endif

        <hr class="hv-sidebar-divider">

        {{-- My Profile --}}
        <a href="{{ route('profile') }}"
           class="hv-nav-link {{ request()->routeIs('profile*') ? 'active' : '' }}">
            <i class="bi bi-person-circle"></i> <span>My Profile</span>
        </a>

        @endif {{-- end role sections --}}

    </nav>

</aside>

<!-- ═══ BACKDROP (mobile overlay when sidebar is open) ═══ -->
<div class="hv-backdrop" id="backdrop"></div>

<!-- ═══ MAIN CONTENT AREA ════════════════════════════════ -->
<main class="hv-main" id="mainContent">

    <!-- Top bar (inside main, not fixed) -->
    <div class="hv-topbar">
        <div class="d-flex align-items-center gap-2">
            <button class="hv-hamburger-main" id="sidebarToggle" type="button"
                    aria-controls="sidebar" aria-expanded="true"
                    aria-label="Toggle navigation sidebar">
                <i class="bi bi-list"></i>
            </button>
            <h5 class="mb-0 fw-bold" style="color:var(--maroon-dark);">
                @yield('title', 'Dashboard')
            </h5>
        </div>

        <!-- User area (right side) -->
        <div class="d-flex align-items-center gap-3">
            @if(Auth::user()->branch)
            <span class="text-muted small d-none d-md-inline">
                <i class="bi bi-geo-alt"></i> {{ Auth::user()->branch->branch_name }}
            </span>
            @endif

            {{-- Notification bell --}}
            @unless(Auth::user()->isBranchStaff())
            <div class="dropdown" id="notifDropdown">
                <button class="btn btn-link position-relative p-1" style="color:var(--ink);font-size:20px;"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="notifBell">
                    <i class="bi bi-bell"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                          style="font-size:10px;display:none;" id="notifBadge">0</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-sm p-0" style="width:340px;max-height:420px;overflow-y:auto;">
                    <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center" style="background:var(--gold-soft);">
                        <span class="fw-bold small" style="color:var(--maroon);">
                            <i class="bi bi-bell me-1"></i> Notifications
                        </span>
                        <span class="text-muted" style="font-size:11px;" id="notifTotal"></span>
                    </div>
                    <div id="notifList">
                        <div class="text-center text-muted small py-4">Loading...</div>
                    </div>
                    <div class="px-3 py-2 border-top text-center">
                        <a href="{{ route('alerts') }}" class="text-decoration-none small" style="color:var(--maroon);">
                            View All Alerts <i class="bi bi-arrow-right"></i>
                        </a>
                    </div>
                </div>
            </div>
            @endunless

            <div class="dropdown">
                <button class="hv-topbar-user dropdown-toggle" data-bs-toggle="dropdown">
                    @if(Auth::user()->profile_photo)
                        <img src="{{ asset(Auth::user()->profile_photo) }}" alt="Profile" class="hv-topbar-avatar">
                    @else
                        <div class="hv-topbar-avatar-placeholder">
                            <i class="bi bi-person-fill"></i>
                        </div>
                    @endif
                    <span class="d-none d-sm-inline">{{ Auth::user()->full_name }}</span>
                </button>
                <ul class="dropdown-menu dropdown-menu-end">
                    <li>
                        <div class="px-3 py-2">
                            <div class="fw-semibold" style="font-size:14px;">{{ Auth::user()->full_name }}</div>
                            <div class="text-muted" style="font-size:12px;">
                                {{ ucwords(str_replace('_', ' ', Auth::user()->role->role_name)) }}
                                @if(Auth::user()->branch)
                                    &middot; {{ Auth::user()->branch->branch_name }}
                                @endif
                            </div>
                        </div>
                    </li>
                    <li><hr class="dropdown-divider"></li>
                    <li><a class="dropdown-item" href="{{ route('profile') }}">
                        <i class="bi bi-person-gear me-1"></i> My Profile
                    </a></li>
                    <li><a class="dropdown-item" href="{{ route('profile') }}#security">
                        <i class="bi bi-shield-lock me-1"></i> Account Security
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}" data-confirm="Log out of Hidden Vietnam?">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="bi bi-box-arrow-right me-1"></i> Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </div>

    <!-- Flash messages (success/error after redirect) -->
    @if(session('success'))
        <div class="alert alert-success alert-dismissible fade show py-2 small" role="alert">
            {{ session('success') }}
            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @if(session('error'))
        <div class="alert alert-danger alert-dismissible fade show py-2 small" role="alert">
            {{ session('error') }}
            <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="alert"></button>
        </div>
    @endif

    @yield('content')

    <!-- end of page content -->
</main>

<!-- ═══ SCRIPTS ══════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"></script>
<script>
/**
 * Sidebar: mobile slide-in only
 * Desktop (lg+): sidebar is always visible, fixed
 * Mobile (<lg):  slides sidebar in/out as an overlay
 */
var HVSidebar = (function () {
    const sidebar  = document.getElementById('sidebar');
    const backdrop = document.getElementById('backdrop');
    const toggle   = document.getElementById('sidebarToggle');
    const STORE_KEY = 'hv-sidebar-collapsed';
    const isDesktop = function () { return window.innerWidth >= 992; };

    // ---- Mobile slide-in drawer ----
    function openMobile()  { sidebar.classList.add('open'); backdrop.classList.add('show'); setAria(); }
    function closeMobile() { sidebar.classList.remove('open'); backdrop.classList.remove('show'); setAria(); }

    // ---- Desktop compact rail (persisted) ----
    function collapse(on) {
        document.body.classList.toggle('sidebar-collapsed', on);
        try { localStorage.setItem(STORE_KEY, on ? '1' : '0'); } catch (e) {}
        setAria();
    }
    function expandDesktop() { if (document.body.classList.contains('sidebar-collapsed')) collapse(false); }

    // ---- aria-expanded reflects what the hamburger currently controls ----
    function setAria() {
        if (!toggle) return;
        var expanded = isDesktop()
            ? !document.body.classList.contains('sidebar-collapsed')
            : sidebar.classList.contains('open');
        toggle.setAttribute('aria-expanded', expanded ? 'true' : 'false');
    }

    // Restore persisted desktop state on load
    try {
        if (localStorage.getItem(STORE_KEY) === '1') document.body.classList.add('sidebar-collapsed');
    } catch (e) {}

    if (toggle) {
        toggle.addEventListener('click', function () {
            if (isDesktop()) {
                collapse(!document.body.classList.contains('sidebar-collapsed'));
            } else {
                sidebar.classList.contains('open') ? closeMobile() : openMobile();
            }
        });
    }
    if (backdrop) backdrop.addEventListener('click', closeMobile);

    // Close drawer when a real link is tapped (mobile only)
    sidebar.querySelectorAll('.hv-nav-link').forEach(function (link) {
        link.addEventListener('click', function () {
            if (!isDesktop()) closeMobile();
        });
    });

    // Reset transient mobile overlay and keep aria accurate on resize
    window.addEventListener('resize', function () {
        if (isDesktop()) closeMobile();
        setAria();
    });

    setAria();
    return { expandDesktop: expandDesktop, isDesktop: isDesktop };
})();

function toggleNav(btn) {
    // In the collapsed desktop rail, a dropdown parent first expands the
    // sidebar, then opens its own submenu — never navigates, never via hover.
    if (HVSidebar.isDesktop() && document.body.classList.contains('sidebar-collapsed')) {
        HVSidebar.expandDesktop();
        var sub = btn.nextElementSibling;
        var chevron = btn.querySelector('.bi-chevron-down, .bi-chevron-right');
        if (!sub.classList.contains('open')) {
            sub.classList.add('open');
            if (chevron) { chevron.classList.add('bi-chevron-down'); chevron.classList.remove('bi-chevron-right'); }
        }
        return;
    }
    var sub = btn.nextElementSibling;
    var chevron = btn.querySelector('.bi-chevron-down, .bi-chevron-right');
    var isOpen = sub.classList.contains('open');
    sub.classList.toggle('open');
    if (chevron) {
        chevron.classList.toggle('bi-chevron-down', !isOpen);
        chevron.classList.toggle('bi-chevron-right', isOpen);
    }
}
</script>
{{-- Notification toast container --}}
@unless(Auth::user()->isBranchStaff())
<div class="hv-toast-container" id="toastContainer"></div>
<script>
(function() {
    var NOTIF_URL = '{{ route("notifications") }}';
    var CSRF = document.querySelector('meta[name="csrf-token"]').content;
    var badge = document.getElementById('notifBadge');
    var list = document.getElementById('notifList');
    var totalEl = document.getElementById('notifTotal');
    var toastBox = document.getElementById('toastContainer');
    var seenNotificationIds = null;
    var notificationFetchPending = false;
    function notificationId(n) { return n.id || n.type + ':' + n.message; }

    var sevIcons = {
        critical: { icon: 'bi-exclamation-circle-fill', color: 'var(--danger)' },
        warning:  { icon: 'bi-exclamation-triangle-fill', color: '#E6A800' },
        info:     { icon: 'bi-info-circle-fill', color: '#2C6E8F' }
    };

    function fetchNotifs() {
        if (notificationFetchPending) return;
        notificationFetchPending = true;
        fetch(NOTIF_URL, { headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF } })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.ok) return;

            // Badge
            if (data.total > 0) {
                badge.textContent = data.total > 99 ? '99+' : data.total;
                badge.style.display = '';
            } else {
                badge.style.display = 'none';
            }
            totalEl.textContent = data.total + ' item' + (data.total !== 1 ? 's' : '');

            // Dropdown list
            if (data.items.length === 0) {
                list.innerHTML = '<div class="text-center text-muted small py-4"><i class="bi bi-check-circle fs-4 d-block mb-2" style="color:var(--success);"></i>All clear!</div>';
            } else {
                var html = '';
                data.items.forEach(function(n) {
                    var s = sevIcons[n.severity] || sevIcons.info;
                    var typeLabel = n.type === 'order_declined' ? 'Order Declined' : (n.type === 'delivery' ? 'Delivery' : 'Alert');
                    html += '<a href="' + n.link + '" class="hv-notif-item">'
                        + '<i class="bi ' + s.icon + ' hv-notif-icon" style="color:' + s.color + ';"></i>'
                        + '<div class="hv-notif-text">'
                        + '<div>' + escHtml(n.message) + '</div>'
                        + '<div class="hv-notif-time">' + escHtml(n.context) + ' &middot; ' + escHtml(n.time) + '</div>'
                        + '</div></a>';
                });
                list.innerHTML = html;
            }

            // Detect events by identity: a decline can replace a pending order
            // without changing the badge total.
            if (seenNotificationIds !== null) {
                data.items.forEach(function(n) {
                    if (!seenNotificationIds.has(notificationId(n))) showToast(n);
                });
            } else {
                seenNotificationIds = new Set();
            }
            data.items.forEach(function(n) { seenNotificationIds.add(notificationId(n)); });

            // Update sidebar badges
            if (data.sidebar) {
                updateSidebarBadge('sidebarBadgeOrders', data.sidebar.orders);
                updateSidebarBadge('sidebarBadgeAlerts', data.sidebar.alerts);
            }
        })
        .catch(function() {})
        .finally(function() { notificationFetchPending = false; });
    }

    function showToast(n) {
        var s = sevIcons[n.severity] || sevIcons.info;
        var typeLabel = n.type === 'order_declined' ? 'Order Declined' : (n.type === 'delivery' ? 'Delivery' : 'Alert');
        var toast = document.createElement('div');
        toast.className = 'hv-toast severity-' + n.severity;
        toast.innerHTML = '<i class="bi ' + s.icon + ' hv-toast-icon" style="color:' + s.color + ';"></i>'
            + '<div class="hv-toast-body">'
            + '<div class="hv-toast-title" style="color:' + s.color + ';">' + typeLabel + '</div>'
            + '<div class="hv-toast-msg">' + escHtml(n.message) + '</div>'
            + '<div class="hv-toast-meta">' + escHtml(n.context) + '</div>'
            + '</div>'
            + '<button class="hv-toast-close">&times;</button>';
        toast.querySelector('.hv-toast-close').addEventListener('click', function() {
            toast.classList.add('fade-out');
            setTimeout(function() { toast.remove(); }, 300);
        });
        toastBox.appendChild(toast);
        setTimeout(function() {
            toast.classList.add('fade-out');
            setTimeout(function() { toast.remove(); }, 300);
        }, 6000);
    }

    function updateSidebarBadge(id, count) {
        var el = document.getElementById(id);
        if (!el) return;
        if (count > 0) {
            el.textContent = count > 99 ? '99+' : count;
            el.style.display = '';
        } else {
            el.style.display = 'none';
        }
    }

    function escHtml(t) {
        var d = document.createElement('div');
        d.textContent = t;
        return d.innerHTML;
    }

    // Initial fetch + poll every 30 seconds
    fetchNotifs();
    setInterval(fetchNotifs, 30000);
    document.getElementById('notifDropdown').addEventListener('show.bs.dropdown', fetchNotifs);
    window.addEventListener('focus', fetchNotifs);
})();
</script>
@endunless

<script src="{{ asset('assets/js/app.js') }}?v={{ @filemtime(public_path('assets/js/app.js')) ?: '1' }}"></script>
<script>
/* Global confirmation guard: logout, and any save/delete/archive form submit.
   - A form (or control) with data-confirm uses its exact message.
   - Otherwise a form whose submit button reads save/delete/archive/remove/
     dispose/void asks a generic confirm. Opt out with data-noconfirm.
   Fetch-based actions (cancel, decline, dispose, archive) keep their own
   in-handler confirm and are type=button, so they are not double-prompted. */
(function () {
    var VERB = /\b(save|update|delete|archive|remove|dispose|void|deactivate|reset|clear)\b/i;
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!(form instanceof HTMLFormElement) || form.hasAttribute('data-noconfirm')) return;
        var msg = form.getAttribute('data-confirm');
        if (!msg) {
            var btn = (document.activeElement && form.contains(document.activeElement) && document.activeElement.type === 'submit')
                ? document.activeElement
                : form.querySelector('[type=submit]');
            var label = btn ? (btn.getAttribute('data-confirm') || btn.textContent || btn.value || '') : '';
            if (btn && btn.hasAttribute('data-noconfirm')) return;
            if (btn && VERB.test(label)) { msg = 'Are you sure you want to ' + label.trim().toLowerCase() + '?'; }
        }
        if (msg && !window.confirm(msg)) { e.preventDefault(); e.stopPropagation(); }
    }, true);

    // Links/buttons (non-submit) that carry data-confirm.
    document.addEventListener('click', function (e) {
        var el = e.target.closest('[data-confirm]');
        if (!el || el.tagName === 'FORM' || el.type === 'submit') return;
        if (!window.confirm(el.getAttribute('data-confirm'))) { e.preventDefault(); e.stopImmediatePropagation(); }
    }, true);
})();
</script>
@stack('scripts')
</body>
</html>
