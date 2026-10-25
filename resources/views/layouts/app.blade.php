<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — Hidden Vietnam FRMS</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/regular/style.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/@phosphor-icons/web@2.1.1/src/fill/style.css" rel="stylesheet">
    <link href="{{ asset('assets/css/theme.css') }}?v={{ @filemtime(public_path('assets/css/theme.css')) ?: '1' }}" rel="stylesheet">
    @stack('styles')
</head>
<body class="hv-app">
<script>
    /* Apply persisted collapsed state before paint to avoid a flash */
    try { if (localStorage.getItem('hv-sidebar-collapsed') === '1') document.body.classList.add('sidebar-collapsed'); } catch (e) {}
</script>

{{-- Auth transition overlay: continues the login loader until this page is ready --}}
<div id="hvAuthLoading" class="hv-auth-loading" role="status" aria-live="polite" aria-hidden="true" hidden>
    <div class="hv-auth-loading-inner">
        <div class="hv-auth-spinner" aria-hidden="true"></div>
        <p class="hv-auth-loading-msg">Signing you in…</p>
    </div>
</div>
<script>
    /* Show the overlay immediately (before the dashboard renders) only when we
       arrived from a successful login, so the transition stays continuous. */
    (function () {
        try {
            if (sessionStorage.getItem('hv-signing-in') === '1') {
                var o = document.getElementById('hvAuthLoading');
                if (o) { o.hidden = false; o.setAttribute('aria-hidden', 'false'); }
            }
        } catch (e) {}
    })();
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
            <i class="hv-dashboard-icon" aria-hidden="true"></i> <span>Dashboard</span>
        </a>

        {{-- Warehouse ▾ --}}
        @php $whActive = request()->routeIs('warehouse*','qr*'); @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $whActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="ph ph-buildings hv-nav-icon"></i>
                <span>Commissary</span>
                <i class="ph {{ $whActive ? 'ph-caret-down' : 'ph-caret-right' }}"></i>
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
            <i class="ph ph-truck"></i> <span>Delivery Status</span>
        </a>

        <hr class="hv-sidebar-divider">


        {{-- ══════════════════════════════════════════════ --}}
        {{-- WAREHOUSE ADMIN — full warehouse nav            --}}
        {{-- ══════════════════════════════════════════════ --}}
        @elseif($isWA)

        {{-- Dashboard --}}
        <a href="{{ route('dashboard') }}"
           class="hv-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="hv-dashboard-icon" aria-hidden="true"></i> <span>Dashboard</span>
        </a>

        {{-- Warehouse ▾ --}}
        @php $whActive = request()->routeIs('warehouse*','inventory*','stock*','products*'); @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $whActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="ph ph-buildings hv-nav-icon"></i>
                <span>All Branches Inventory</span>
                <i class="ph {{ $whActive ? 'ph-caret-down' : 'ph-caret-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $whActive ? 'open' : '' }}">
                <a href="{{ route('inventory') }}"
                   class="hv-nav-link {{ request()->routeIs('inventory*') ? 'active' : '' }}">
                    <span>Stock / Inventory</span>
                </a>
                <a href="{{ route('products') }}"
                   class="hv-nav-link {{ request()->routeIs('products*') ? 'active' : '' }}">
                    <span>Commissary Products</span>
                </a>
                <a href="{{ route('stock') }}"
                   class="hv-nav-link {{ request()->routeIs('stock*') ? 'active' : '' }}">
                    <span>Stock Management</span>
                </a>
            </div>
        </div>

        {{-- Orders ▾ — order list + delivery --}}
        @php $odActive = request()->routeIs('orders*','delivery*'); @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $odActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="ph ph-shopping-cart hv-nav-icon"></i>
                <span>Orders</span>
                <span class="hv-sidebar-badge" id="sidebarBadgeOrders" style="display:none;">0</span>
                <i class="ph {{ $odActive ? 'ph-caret-down' : 'ph-caret-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $odActive ? 'open' : '' }}">
                <a href="{{ route('orders') }}"
                   class="hv-nav-link {{ request()->routeIs('orders*') ? 'active' : '' }}">
                    <span>All Orders</span>
                </a>
                <a href="{{ route('delivery') }}"
                   class="hv-nav-link {{ request()->routeIs('delivery*') ? 'active' : '' }}">
                    <span>Delivery Status</span>
                </a>
            </div>
        </div>

        <hr class="hv-sidebar-divider">

        {{-- Alerts --}}
        <a href="{{ route('alerts') }}"
           class="hv-nav-link {{ request()->routeIs('alerts*') ? 'active' : '' }}">
            <i class="ph ph-bell"></i> <span>Alerts</span>
            <span class="hv-sidebar-badge" id="sidebarBadgeAlerts" style="display:none;">0</span>
        </a>

        <hr class="hv-sidebar-divider">


        {{-- ══════════════════════════════════════════════ --}}
        {{-- BRANCH STAFF — minimal branch nav               --}}
        {{-- ══════════════════════════════════════════════ --}}
        @elseif($isBS)

        <a href="{{ route('qr') }}"
           class="hv-nav-link {{ request()->routeIs('qr*') ? 'active' : '' }}">
            <i class="ph ph-qr-code"></i> <span>QR Scan</span>
        </a>

        <hr class="hv-sidebar-divider">


        {{-- ══════════════════════════════════════════════ --}}
        {{-- OWNER / SUPER_ADMIN / BRANCH_MANAGER nav        --}}
        {{-- ══════════════════════════════════════════════ --}}
        @else

        {{-- Dashboard --}}
        <a href="{{ route('dashboard') }}"
           class="hv-nav-link {{ request()->routeIs('dashboard') ? 'active' : '' }}">
            <i class="hv-dashboard-icon" aria-hidden="true"></i> <span>Dashboard</span>
        </a>

        {{-- ── Inventory ▾ ──────────────────────────────── --}}
        @if($canInv)
        @php
            $invActive = request()->routeIs('inventory*','stock*','warehouse*','products*');
        @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $invActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="ph ph-package hv-nav-icon"></i>
                <span>Inventory</span>
                <i class="ph {{ $invActive ? 'ph-caret-down' : 'ph-caret-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $invActive ? 'open' : '' }}">
                <a href="{{ route('inventory') }}"
                   class="hv-nav-link {{ request()->routeIs('inventory*','warehouse*') ? 'active' : '' }}">
                    <span>{{ ($isOwner || $isMainBM) ? 'All Branches Inventory' : 'Inventory' }}</span>
                </a>
                @if($u->canManageCatalog() || $u->canManageProductAvailability())
                <a href="{{ route('products') }}"
                   class="hv-nav-link {{ request()->routeIs('products*') ? 'active' : '' }}">
                    <span>Commissary Products</span>
                </a>
                @endif
                <a href="{{ route('stock') }}"
                   class="hv-nav-link {{ request()->routeIs('stock*') ? 'active' : '' }}">
                    <span>Stock Management</span>
                </a>
            </div>
        </div>
        @endif

        {{-- ── Orders ▾ (All Orders + Delivery, and Order Supplies for branch managers) ── --}}
        @php
            $odActive = request()->routeIs('orders*','ordering*','delivery*');
        @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $odActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="ph ph-shopping-cart hv-nav-icon"></i>
                <span>Orders</span>
                <span class="hv-sidebar-badge" id="sidebarBadgeOrders" style="display:none;">0</span>
                <i class="ph {{ $odActive ? 'ph-caret-down' : 'ph-caret-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $odActive ? 'open' : '' }}">
                <a href="{{ route('orders') }}"
                   class="hv-nav-link {{ request()->routeIs('orders*') ? 'active' : '' }}">
                    <span>All Orders</span>
                </a>
                @if($isBM)
                <a href="{{ route('ordering') }}"
                   class="hv-nav-link {{ request()->routeIs('ordering*') ? 'active' : '' }}">
                    <span>Order Supplies</span>
                </a>
                @endif
                <a href="{{ route('delivery') }}"
                   class="hv-nav-link {{ request()->routeIs('delivery*') ? 'active' : '' }}">
                    <span>Delivery Status</span>
                </a>
            </div>
        </div>

        <hr class="hv-sidebar-divider">

        @if($canInv)
        {{-- ── Reports (standalone, immediately after Orders) ── --}}
        <a href="{{ route('reports') }}"
           class="hv-nav-link {{ request()->routeIs('reports*') ? 'active' : '' }}">
            <i class="ph ph-chart-bar"></i> <span>Reports</span>
        </a>

        {{-- ── Insights ▾ ───────────────────────────────── --}}
        @php
            $insActive = request()->routeIs('forecast*','alerts*');
        @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $insActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="ph ph-trend-up hv-nav-icon"></i>
                <span>Insights</span>
                <span class="hv-sidebar-badge" id="sidebarBadgeAlerts" style="display:none;">0</span>
                <i class="ph {{ $insActive ? 'ph-caret-down' : 'ph-caret-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $insActive ? 'open' : '' }}">
                <a href="{{ route('forecast') }}"
                   class="hv-nav-link {{ request()->routeIs('forecast*') ? 'active' : '' }}">
                    <span>Forecast & DSS</span>
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
                <i class="ph ph-users hv-nav-icon"></i>
                <span>Management</span>
                <i class="ph {{ $mgtActive ? 'ph-caret-down' : 'ph-caret-right' }}"></i>
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

        {{-- ── System ────────────────────────────────────── --}}
        @if($isOwner)
        @if($isSA)
        {{-- Super admin has multiple system tools → keep the dropdown --}}
        @php
            $sysActive = request()->routeIs('audit*','settings*');
        @endphp
        <div class="hv-nav-group">
            <button class="hv-nav-group-toggle {{ $sysActive ? 'active' : '' }}" onclick="toggleNav(this)">
                <i class="ph ph-gear hv-nav-icon"></i>
                <span>System</span>
                <i class="ph {{ $sysActive ? 'ph-caret-down' : 'ph-caret-right' }}"></i>
            </button>
            <div class="hv-nav-submenu {{ $sysActive ? 'open' : '' }}">
                <a href="{{ route('audit') }}"
                   class="hv-nav-link {{ request()->routeIs('audit*') ? 'active' : '' }}">
                    <span>Audit Log</span>
                </a>
                <a href="{{ route('settings') }}"
                   class="hv-nav-link {{ request()->routeIs('settings*') ? 'active' : '' }}">
                    <span>Settings</span>
                </a>
                <a href="{{ route('settings') }}#backup"
                   class="hv-nav-link">
                    <i class="ph ph-database"></i> <span>Database Backup</span>
                </a>
            </div>
        </div>
        @else
        {{-- Single system item → direct link, no dropdown --}}
        <a href="{{ route('settings') }}"
           class="hv-nav-link {{ request()->routeIs('settings*') ? 'active' : '' }}">
            <i class="ph ph-gear"></i> <span>System Setting</span>
        </a>
        @endif
        @endif

        <hr class="hv-sidebar-divider">


        @endif {{-- end role sections --}}

    </nav>

    {{-- ══ Sidebar footer: My Profile pinned at the bottom ══ --}}
    <div class="hv-sidebar-foot">
        <a href="{{ route('profile') }}"
           class="hv-nav-link {{ request()->routeIs('profile*') ? 'active' : '' }}">
            <i class="ph ph-user-circle"></i> <span>My Profile</span>
        </a>
    </div>

</aside>

<!-- ═══ BACKDROP (mobile overlay when sidebar is open) ═══ -->
<div class="hv-backdrop" id="backdrop"></div>

<!-- ═══ SHELL: red header over cream workspace ═══════════ -->
<div class="hv-shell" id="appShell">

    <!-- Top bar on the continuous red field (above the cream panel) -->
    <header class="hv-topbar">
        <div class="d-flex align-items-center gap-2">
            <button class="hv-hamburger-main" id="sidebarToggle" type="button"
                    aria-controls="sidebar" aria-expanded="true"
                    aria-label="Toggle navigation sidebar">
                <i class="ph ph-list"></i>
            </button>
            <h5 class="mb-0 fw-bold">
                @yield('title', 'Dashboard')
            </h5>
        </div>

        <!-- User area (right side) -->
        <div class="d-flex align-items-center gap-3">
            @if(Auth::user()->branch)
            <span class="text-muted small d-none d-md-inline">
                <i class="ph ph-map-pin"></i> {{ Auth::user()->branch->branch_name }}
            </span>
            @endif

            {{-- Notification bell --}}
            @unless(Auth::user()->isBranchStaff())
            <div class="dropdown" id="notifDropdown">
                <button class="btn btn-link position-relative p-1" style="font-size:20px;"
                        data-bs-toggle="dropdown" data-bs-auto-close="outside" aria-expanded="false" id="notifBell">
                    <i class="ph ph-bell"></i>
                    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger"
                          style="font-size:10px;display:none;" id="notifBadge">0</span>
                </button>
                <div class="dropdown-menu dropdown-menu-end shadow-sm p-0" style="width:340px;max-height:420px;overflow-y:auto;">
                    <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center" style="background:var(--gold-soft);">
                        <span class="fw-bold small" style="color:var(--maroon);">
                            <i class="ph ph-bell me-1"></i> Notifications
                        </span>
                        <span class="text-muted" style="font-size:11px;" id="notifTotal"></span>
                    </div>
                    <div id="notifList">
                        <div class="text-center text-muted small py-4">Loading...</div>
                    </div>
                    <div class="px-3 py-2 border-top text-center">
                        <a href="{{ route('alerts') }}" class="text-decoration-none small" style="color:var(--maroon);">
                            View All Alerts <i class="ph ph-arrow-right"></i>
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
                            <i class="ph-fill ph-user"></i>
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
                        <i class="ph ph-user-gear me-1"></i> My Profile
                    </a></li>
                    <li><a class="dropdown-item" href="{{ route('profile') }}#security">
                        <i class="ph ph-shield-check me-1"></i> Account Security
                    </a></li>
                    <li><hr class="dropdown-divider"></li>
                    <li>
                        <form method="POST" action="{{ route('logout') }}" data-confirm="Log out of Hidden Vietnam?">
                            @csrf
                            <button type="submit" class="dropdown-item text-danger">
                                <i class="ph ph-sign-out me-1"></i> Logout
                            </button>
                        </form>
                    </li>
                </ul>
            </div>
        </div>
    </header>

    <!-- ═══ CREAM WORKSPACE PANEL ════════════════════════ -->
    <main class="hv-main" id="mainContent">

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

</div><!-- /.hv-shell -->

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
        var chevron = btn.querySelector('.ph-caret-down, .ph-caret-right');
        if (!sub.classList.contains('open')) {
            sub.classList.add('open');
            if (chevron) { chevron.classList.add('ph-caret-down'); chevron.classList.remove('ph-caret-right'); }
        }
        return;
    }
    var sub = btn.nextElementSibling;
    var chevron = btn.querySelector('.ph-caret-down, .ph-caret-right');
    var isOpen = sub.classList.contains('open');
    sub.classList.toggle('open');
    if (chevron) {
        chevron.classList.toggle('ph-caret-down', !isOpen);
        chevron.classList.toggle('ph-caret-right', isOpen);
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
        critical: { icon: 'ph-warning-circle', color: 'var(--danger)' },
        warning:  { icon: 'ph-warning', color: '#E6A800' },
        info:     { icon: 'ph-info', color: '#2C6E8F' }
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
                list.innerHTML = '<div class="text-center text-muted small py-4"><i class="ph ph-check-circle fs-4 d-block mb-2" style="color:var(--success);"></i>All clear!</div>';
            } else {
                var html = '';
                data.items.forEach(function(n) {
                    var s = sevIcons[n.severity] || sevIcons.info;
                    var typeLabel = n.type === 'order_declined' ? 'Order Declined' : (n.type === 'delivery' ? 'Delivery' : 'Alert');
                    html += '<a href="' + n.link + '" class="hv-notif-item">'
                        + '<i class="ph ' + s.icon + ' hv-notif-icon" style="color:' + s.color + ';"></i>'
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
        toast.innerHTML = '<i class="ph ' + s.icon + ' hv-toast-icon" style="color:' + s.color + ';"></i>'
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

/* ── Same-page printing via a hidden iframe (never a new tab) ──────────
   hvPrintLabelsUrl(url): load a same-origin label page, wait for its QR
   images, then open the print dialog over the current page. The current
   page, filters, and inputs stay intact. Printing never POSTs, so it
   cannot release stock or change order status. */
window.hvPrintLabelsUrl = function (url) {
    var old = document.getElementById('hvLabelFrame');
    if (old) { old.remove(); }
    var f = document.createElement('iframe');
    f.id = 'hvLabelFrame';
    f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0;';
    f.src = url;
    var done = false;
    function cleanup() { setTimeout(function () { f.remove(); }, 200); }
    f.onload = function () {
        var win = f.contentWindow, doc;
        try { doc = win.document; } catch (e) { alert('Could not open the labels for printing.'); f.remove(); return; }
        win.onafterprint = cleanup;
        var tries = 0;
        (function waitForQr() {
            tries++;
            var imgs = doc.querySelectorAll('.qrimg img, .qrimg svg, img');
            var ready = imgs.length > 0 && Array.prototype.every.call(doc.images, function (im) { return im.complete; });
            if (done) { return; }
            if (ready || tries > 40) {           // ~6s max, then print anyway
                done = true;
                try { win.focus(); win.print(); } catch (e) { alert('Printing was blocked by the browser.'); f.remove(); }
            } else {
                setTimeout(waitForQr, 150);
            }
        })();
    };
    f.onerror = function () { alert('Could not load the labels to print.'); f.remove(); };
    document.body.appendChild(f);
};

/* hvPrintNodes(html): print caller-built label markup (already-rendered QR
   SVG, no external assets) in the same hidden-iframe way. */
window.hvPrintNodes = function (bodyHtml, css) {
    var old = document.getElementById('hvNodesFrame');
    if (old) { old.remove(); }
    var f = document.createElement('iframe');
    f.id = 'hvNodesFrame';
    f.style.cssText = 'position:fixed;width:0;height:0;border:0;right:0;bottom:0;';
    document.body.appendChild(f);
    var d = f.contentWindow.document;
    d.open();
    d.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>Print</title><style>' + (css || '') + '</style></head><body>' + bodyHtml + '</body></html>');
    d.close();
    var win = f.contentWindow;
    win.onafterprint = function () { setTimeout(function () { f.remove(); }, 200); };
    setTimeout(function () { try { win.focus(); win.print(); } catch (e) { alert('Printing was blocked by the browser.'); f.remove(); } }, 150);
};
</script>
<script>
/* Remove the auth transition overlay once the destination is usable, and clear
   the one-time flag so ordinary page visits never show it again. */
(function () {
    function hide(fade) {
        var o = document.getElementById('hvAuthLoading');
        try { sessionStorage.removeItem('hv-signing-in'); } catch (e) {}
        if (!o || o.hidden) { return; }
        if (fade) {
            o.classList.add('is-fading');
            setTimeout(function () { o.hidden = true; o.setAttribute('aria-hidden', 'true'); o.classList.remove('is-fading'); }, 320);
        } else {
            o.hidden = true; o.setAttribute('aria-hidden', 'true'); o.classList.remove('is-fading');
        }
    }
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function () { hide(true); });
    } else {
        hide(true);
    }
    // bfcache restore must never leave the overlay stuck.
    window.addEventListener('pageshow', function (e) { if (e.persisted) { hide(false); } });
})();
</script>
@stack('scripts')
</body>
</html>
