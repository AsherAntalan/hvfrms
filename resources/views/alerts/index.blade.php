@extends('layouts.app')

@section('title', $isNotificationCenter ? 'Notifications' : 'Alerts')

@section('content')

@if(auth()->user()->isCommissaryManager())
<p class="small text-muted mb-3">Branch requests, preparation, and dispatch follow-ups. Expiry warnings apply only to remaining order-linked goods awaiting dispatch.</p>
@endif

<p class="small text-muted mb-3">Dismissing a notification clears it from this list. Stock and order issues must still be addressed separately.</p>

<div class="hv-page-header d-flex justify-content-between align-items-center">
    <div>
        @if($countOpen > 0)
            <span class="hv-badge hv-badge-danger">{{ $countOpen }} unreviewed</span>
        @endif
    </div>
    <button type="button" class="btn btn-sm btn-outline-secondary" id="notifClearAll">
        <i class="ph ph-check-circle me-1"></i> Clear all
    </button>
</div>

@if($countCritical > 0)
<div class="alert alert-danger py-2 small mb-3">
    <i class="ph-fill ph-warning me-1"></i>
    <strong>{{ $countCritical }}</strong> critical alert(s) require immediate attention.
</div>
@endif

<!-- Filter Bar -->
<div class="hv-card p-2 mb-3">
    <form method="GET" action="{{ route('alerts') }}" class="row g-2 align-items-center" id="notifFilterForm">
        @if($branches->count() > 0)
        <div class="col-md-3">
            <select class="form-select form-select-sm" name="branch" aria-label="Filter by branch" onchange="document.getElementById('notifFilterForm').requestSubmit()">
                <option value="">All Branches</option>
                @foreach($branches as $br)
                <option value="{{ $br->id }}" @selected((string) $filterBranch === (string) $br->id)>{{ $br->branch_name }}</option>
                @endforeach
            </select>
        </div>
        @endif
        <div class="col-md-3">
            @if($isNotificationCenter)
            <select class="form-select form-select-sm" name="category" aria-label="Notification category" onchange="document.getElementById('notifFilterForm').requestSubmit()">
                @foreach(['all' => 'All', 'recovery' => 'Account Recovery', 'accounts' => 'Account Requests', 'inventory' => 'Inventory Alerts', 'orders' => 'Orders'] as $key => $label)
                    <option value="{{ $key }}" @selected($category === $key)>{{ $label }}</option>
                @endforeach
            </select>
            @else
            <select class="form-select form-select-sm" name="type" onchange="document.getElementById('notifFilterForm').requestSubmit()">
                <option value="">All Types</option>
                @foreach($typeBadges as $type => $badge)
                <option value="{{ $type }}" {{ $filterType === $type ? 'selected' : '' }}>{{ $badge[2] }}</option>
                @endforeach
            </select>
            @endif
        </div>
        @if($isNotificationCenter)
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="notifSort" name="sort" aria-label="Sort order" onchange="document.getElementById('notifFilterForm').requestSubmit()">
                <option value="newest" @selected(request('sort', 'newest') === 'newest')>Newest first</option>
                <option value="oldest" @selected(request('sort') === 'oldest')>Oldest first</option>
            </select>
        </div>
        @endif
        @unless($isNotificationCenter)
        <div class="col-md-3">
            <select class="form-select form-select-sm" name="resolved" onchange="document.getElementById('notifFilterForm').requestSubmit()">
                <option value="0" {{ $filterResolved === '0' ? 'selected' : '' }}>Unreviewed Only</option>
                <option value="1" {{ $filterResolved === '1' ? 'selected' : '' }}>Reviewed Only</option>
                <option value="all" {{ $filterResolved === 'all' ? 'selected' : '' }}>All</option>
            </select>
        </div>
        @endunless
        <div class="col-auto">
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="window.location.href='{{ route('alerts') }}'">
                <i class="ph ph-x" aria-hidden="true"></i> Clear Filters
            </button>
        </div>
        <div class="col text-end">
            <span class="text-muted small" id="notifCount">{{ $alerts->count() + $recoveryRequests->count() + $operationalOrderCount + $refundRequests->count() + count($accountNotices) }} {{ $isNotificationCenter ? 'notification(s)' : 'alert(s)' }}</span>
        </div>
    </form>
</div>

{{-- Unified notification feed: every source in one filtered, paginated flow. --}}
<div id="notifResults">
@if($alerts->isEmpty() && $recoveryRequests->isEmpty() && $operationalOrders->isEmpty() && $refundRequests->isEmpty() && !count($accountNotices))
    <div class="hv-card p-4 text-center text-muted">
        <i class="ph ph-bell-slash fs-3 d-block mb-2"></i>
        {{ $isNotificationCenter ? 'No notifications found matching your filters.' : 'No alerts found matching your filters.' }}
    </div>
@else
<div id="notifFeed">
    @foreach($accountNotices as $notice)
        <div class="notif-item hv-card p-3 mb-2 d-flex justify-content-between align-items-start gap-3" data-ts="{{ $notice['ts'] ?? 0 }}" data-href="{{ $notice['link'] }}" data-dismiss-id="account-{{ $notice['id'] ?? md5($notice['link']) }}">
            <div class="flex-grow-1">
                <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                    <span class="hv-badge hv-badge-info"><i class="ph ph-user-plus me-1"></i>Account Request</span>
                </div>
                <p class="mb-1 small">{{ $notice['message'] }}</p>
                <div class="text-muted" style="font-size:11px;">{{ $notice['context'] }} · {{ $notice['time'] }}</div>
            </div>
            <button type="button" class="notif-dismiss" title="Dismiss" aria-label="Dismiss notification"><i class="ph ph-x"></i></button>
        </div>
    @endforeach
    @foreach($operationalOrders as $order)
        <div class="notif-item hv-card p-3 mb-2 d-flex justify-content-between align-items-start gap-3" data-ts="{{ $order->updated_at->timestamp }}" data-href="{{ route('orders', ['order' => $order->id]) }}" data-dismiss-id="order-{{ $order->id }}-{{ $order->status }}">
            <div class="flex-grow-1">
                <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                    <span class="hv-badge hv-badge-info"><i class="ph ph-shopping-cart me-1"></i>Order</span>
                    <span class="hv-badge" style="background:rgba(92,16,16,0.1);color:var(--maroon-dark);font-size:11px;"><i class="ph ph-map-pin me-1"></i>{{ $order->branch?->branch_name }}</span>
                </div>
                <p class="mb-1 small"><strong>{{ $order->order_number }}</strong> — {{ match ($order->status) {
                    'pending' => 'Order waiting for approval',
                    'approved' => 'Approved request; review preparation and dispatch',
                    'preparing' => 'Preparation in progress',
                    'delivering' => 'Dispatched; awaiting destination receipt',
                    'delivered', 'settled' => 'Delivered — received at branch',
                    default => 'Review order',
                } }}</p>
                <div class="text-muted" style="font-size:11px;">{{ $order->updated_at->format('M j, Y g:i A') }}</div>
            </div>
            <button type="button" class="notif-dismiss" title="Dismiss" aria-label="Dismiss notification"><i class="ph ph-x"></i></button>
        </div>
    @endforeach
    @foreach($refundRequests as $rf)
        <div class="notif-item hv-card p-3 mb-2 d-flex justify-content-between align-items-start gap-3" data-ts="{{ $rf->created_at->timestamp }}" data-href="{{ route('orders', ['order' => $rf->order_id, 'queue' => 'all']) }}" data-dismiss-id="refund-{{ $rf->id }}">
            <div class="flex-grow-1">
                <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                    <span class="hv-badge hv-badge-warning"><i class="ph ph-arrow-u-up-left me-1"></i>Refund Request</span>
                    <span class="hv-badge" style="background:rgba(92,16,16,0.1);color:var(--maroon-dark);font-size:11px;"><i class="ph ph-map-pin me-1"></i>{{ $rf->order?->branch?->branch_name }}</span>
                </div>
                <p class="mb-1 small"><strong>{{ $rf->order?->order_number }}</strong> — Refund requested{{ $rf->reason ? ': '.\Illuminate\Support\Str::limit($rf->reason, 80) : '' }}</p>
                <div class="text-muted" style="font-size:11px;">{{ $rf->created_at->format('M j, Y g:i A') }}</div>
            </div>
            <button type="button" class="notif-dismiss" title="Dismiss" aria-label="Dismiss notification"><i class="ph ph-x"></i></button>
        </div>
    @endforeach
    @foreach($alerts as $a)
        @php
            $tb = $typeBadges[$a->alert_type] ?? ['hv-badge-info', 'ph-info', $a->alert_type];
            $sb = $sevBadges[$a->severity] ?? 'hv-badge-info';
        @endphp
        <div class="notif-item hv-card p-3 mb-2 {{ $a->is_resolved ? 'opacity-50' : '' }}" data-ts="{{ $a->created_at->timestamp }}" data-href="{{ $a->order_id ? route('orders', ['order' => $a->order_id, 'queue' => 'all']) : '' }}" @unless($a->is_resolved)data-resolve-id="{{ $a->id }}"@endunless>
            <div class="d-flex justify-content-between align-items-start gap-3">
                <div class="flex-grow-1">
                    <div class="d-flex gap-2 align-items-center mb-1 flex-wrap">
                        <span class="hv-badge {{ $tb[0] }}"><i class="ph {{ $tb[1] }} me-1"></i>{{ $tb[2] }}</span>
                        <span class="hv-badge {{ $sb }}">{{ ucfirst($a->severity) }}</span>
                        @if($a->branch)
                            <span class="hv-badge" style="background:rgba(92,16,16,0.1);color:var(--maroon-dark);font-size:11px;"><i class="ph ph-map-pin me-1"></i>{{ $a->branch->branch_name }}</span>
                        @endif
                        @if($a->is_resolved)<span class="hv-badge hv-badge-success"><i class="ph ph-check me-1"></i>Reviewed</span>@endif
                    </div>
                    <p class="mb-1 small">{{ $a->message }}</p>
                    <div class="text-muted" style="font-size:11px;">
                        {{ $a->created_at->format('M j, Y g:i A') }}
                        @if($a->is_resolved && $a->resolvedByUser) &middot; Reviewed by {{ $a->resolvedByUser->full_name }}@endif
                    </div>
                </div>
                @if(!$a->is_resolved)
                <button type="button" class="notif-dismiss" title="Dismiss" aria-label="Dismiss notification">
                    <i class="ph ph-x"></i>
                </button>
                @endif
            </div>
        </div>
    @endforeach
</div>
<div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 px-1 no-print">
    <div class="text-muted small" id="notifPagerInfo" role="status" aria-live="polite"></div>
    <nav class="hv-inv-pager" id="notifPagerNav" aria-label="Notifications pagination"></nav>
</div>
@endif
</div>{{-- /notifResults --}}

@push('scripts')
<script>
function initNotifFeed() {
    var feed = document.getElementById('notifFeed');
    if (!feed) { window.__notifResort = null; return; }
    var items = Array.prototype.slice.call(feed.querySelectorAll(':scope > .notif-item'));
    // Live-derived cards (orders/recovery/accounts) aren't resolved server-side,
    // so their X hides them per-browser and we remember that here.
    var DISMISS_KEY = 'hv-notif-dismissed';
    function dismissedSet() {
        try { return new Set(JSON.parse(localStorage.getItem(DISMISS_KEY) || '[]')); } catch (e) { return new Set(); }
    }
    function rememberDismissed(id) {
        try { var s = dismissedSet(); s.add(id); localStorage.setItem(DISMISS_KEY, JSON.stringify(Array.from(s).slice(-500))); } catch (e) {}
    }
    var dset = dismissedSet();
    items = items.filter(function (el) {
        var did = el.getAttribute('data-dismiss-id');
        if (did && dset.has(did)) { el.remove(); return false; }
        return true;
    });
    var info = document.getElementById('notifPagerInfo');
    var nav = document.getElementById('notifPagerNav');
    var per = 10, page = 1;
    var sortSel = document.getElementById('notifSort');
    function ts(el) { return parseInt(el.getAttribute('data-ts'), 10) || 0; }
    function applySort(dir) {
        items.sort(function (a, b) { return dir === 'oldest' ? ts(a) - ts(b) : ts(b) - ts(a); });
        items.forEach(function (el) { feed.appendChild(el); });
    }
    applySort(sortSel ? sortSel.value : 'newest');
    // Whole-card click navigates, like a feed item. Inner buttons/links/forms keep their own behavior.
    items.forEach(function (el) {
        var href = el.getAttribute('data-href');
        if (!href || el.__navBound) { return; }
        el.__navBound = true;
        el.style.cursor = 'pointer';
        el.addEventListener('click', function (e) {
            if (e.target.closest('a, button, form, input')) { return; }
            window.location.href = href;
        });
    });
    function render() {
        var pages = Math.max(1, Math.ceil(items.length / per));
        if (page > pages) { page = pages; }
        if (page < 1) { page = 1; }
        var start = (page - 1) * per, end = start + per;
        items.forEach(function (el, i) { el.style.display = (i >= start && i < end) ? '' : 'none'; });
        if (info) { info.textContent = items.length ? 'Showing ' + (start + 1) + '–' + Math.min(end, items.length) + ' of ' + items.length + ' notification' + (items.length !== 1 ? 's' : '') : ''; }
        if (!nav) { return; }
        nav.innerHTML = '';
        if (pages <= 1) { return; }
        var mk = function (label, target, o) {
            o = o || {};
            var b = document.createElement('button');
            b.type = 'button';
            b.className = 'hv-page-btn' + (o.active ? ' active' : '');
            b.innerHTML = label;
            if (o.disabled) { b.disabled = true; }
            if (o.active) { b.setAttribute('aria-current', 'page'); }
            if (!o.disabled && !o.active) { b.addEventListener('click', function () { page = target; render(); }); }
            return b;
        };
        var w = 2, s = Math.max(1, page - w), e = Math.min(pages, page + w), seq = [];
        if (s > 1) { seq.push(1); if (s > 2) { seq.push('…'); } }
        for (var p = s; p <= e; p++) { seq.push(p); }
        if (e < pages) { if (e < pages - 1) { seq.push('…'); } seq.push(pages); }
        nav.appendChild(mk('<i class="ph ph-caret-left"></i> Prev', page - 1, { disabled: page === 1 }));
        seq.forEach(function (p) {
            if (p === '…') { var g = document.createElement('span'); g.className = 'hv-page-gap'; g.textContent = '…'; nav.appendChild(g); }
            else { nav.appendChild(mk(String(p), p, { active: p === page })); }
        });
        nav.appendChild(mk('Next <i class="ph ph-caret-right"></i>', page + 1, { disabled: page === pages }));
    }
    // X dismisses a card in place — no reload, no success banner.
    var CSRF = '{{ csrf_token() }}';
    var ACTION_URL = '{{ route('alerts.action') }}';
    function removeCard(card) {
        var idx = items.indexOf(card);
        if (idx > -1) { items.splice(idx, 1); }
        if (card) { card.remove(); }
        render();
    }
    feed.addEventListener('click', function (e) {
        var btn = e.target.closest('.notif-dismiss');
        if (!btn) { return; }
        e.preventDefault();
        e.stopPropagation();
        var card = btn.closest('.notif-item');
        if (!card) { return; }
        var resolveId = card.getAttribute('data-resolve-id');
        var dismissId = card.getAttribute('data-dismiss-id');
        if (resolveId) {
            btn.disabled = true;
            var body = new FormData();
            body.append('post_action', 'resolve');
            body.append('resolve_id', resolveId);
            body.append('_token', CSRF);
            fetch(ACTION_URL, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF }, body: body })
                .then(function () { removeCard(card); })
                .catch(function () { btn.disabled = false; });
        } else if (dismissId) {
            rememberDismissed(dismissId);
            removeCard(card);
        } else {
            removeCard(card);
        }
    });

    window.__notifRender = render;
    window.__notifResort = function (dir) { applySort(dir); page = 1; render(); };
    // Clear all: resolve every alert server-side and dismiss derived cards.
    window.__notifClearAllFn = function () {
        if (!items.length) { return; }
        var btn = document.getElementById('notifClearAll');
        if (btn) { btn.disabled = true; }
        var hasAlerts = false;
        items.forEach(function (el) {
            var did = el.getAttribute('data-dismiss-id');
            if (did) { rememberDismissed(did); }
            if (el.getAttribute('data-resolve-id')) { hasAlerts = true; }
        });
        var finish = function () {
            items.slice().forEach(function (el) { el.remove(); });
            items.length = 0;
            render();
            if (btn) { btn.disabled = false; }
        };
        if (hasAlerts) {
            var body = new FormData();
            body.append('post_action', 'resolve_all');
            body.append('category', 'all'); body.append('branch', ''); body.append('type', '');
            body.append('_token', CSRF);
            fetch(ACTION_URL, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF }, body: body })
                .then(finish).catch(finish);
        } else { finish(); }
    };
    if (!window.__notifClearBound) {
        window.__notifClearBound = true;
        var clearBtn = document.getElementById('notifClearAll');
        if (clearBtn) { clearBtn.addEventListener('click', function () { if (window.__notifClearAllFn) { window.__notifClearAllFn(); } }); }
    }
    if (!window.__notifPrintBound) {
        window.__notifPrintBound = true;
        window.addEventListener('beforeprint', function () { document.querySelectorAll('#notifFeed > .notif-item').forEach(function (el) { el.style.display = ''; }); });
        window.addEventListener('afterprint', function () { if (window.__notifRender) { window.__notifRender(); } });
    }
    render();
}

// Filter (branch/category/sort) submits in place via AJAX and swaps only the results,
// so the page always shows exactly what the server returns — never a stale page.
(function () {
    var form = document.getElementById('notifFilterForm');
    if (!form) { return; }
    form.addEventListener('submit', function (e) {
        e.preventDefault();
        var params = new URLSearchParams(new FormData(form)).toString();
        var url = form.getAttribute('action') + '?' + params;
        var submitBtn = form.querySelector('button[type="submit"]');
        if (submitBtn) { submitBtn.disabled = true; }
        fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' }, cache: 'no-store' })
            .then(function (r) { return r.text(); })
            .then(function (html) {
                var doc = new DOMParser().parseFromString(html, 'text/html');
                var freshResults = doc.getElementById('notifResults');
                var freshCount = doc.getElementById('notifCount');
                var curResults = document.getElementById('notifResults');
                var curCount = document.getElementById('notifCount');
                if (freshResults && curResults) { curResults.innerHTML = freshResults.innerHTML; }
                if (freshCount && curCount) { curCount.textContent = freshCount.textContent; }
                try { history.replaceState(null, '', url); } catch (e2) {}
                initNotifFeed();
            })
            .catch(function () { form.submit(); })
            .finally(function () { if (submitBtn) { submitBtn.disabled = false; } });
    });
})();

initNotifFeed();
</script>
@endpush

@endsection
