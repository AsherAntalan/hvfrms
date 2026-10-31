@extends('layouts.app')

@section('title', 'Scan — Stock In / Out')

@push('styles')
<style>
/* -- Scan-specific styles (matches reference theme) -- */
.hv-scan-wrap { max-width: 520px; margin: 0 auto; }
#hv-reader { width: 100%; border-radius: 12px; overflow: hidden; border: 2px solid var(--gold-soft); }
.hv-scan-result {
    background: #fff; border: 1px solid var(--gold-soft);
    border-radius: 12px; padding: 1rem; margin-top: 1rem;
}
.hv-mode-toggle .btn { flex: 1; }
.hv-qty-stepper { display: flex; align-items: center; gap: .5rem; }
.hv-qty-stepper input { text-align: center; font-size: 1.4rem; font-weight: 700; max-width: 110px; }
.hv-qty-stepper button { width: 46px; height: 46px; font-size: 1.4rem; line-height: 1; }
.hv-divider {
    display: flex; align-items: center; gap: 10px; margin: 14px 0;
    color: var(--muted); font-size: 13px;
}
.hv-divider::before, .hv-divider::after {
    content: ''; flex: 1; height: 1px; background: var(--border);
}
.hv-activity-item {
    display: flex; align-items: center; gap: 10px; padding: 8px 0;
    border-bottom: 1px solid var(--border); font-size: 13px;
}
.hv-activity-item:last-child { border-bottom: 0; }
.hv-activity-icon {
    width: 32px; height: 32px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    font-size: 14px; flex-shrink: 0;
}

/* ── Scan success confirmation ─────────────────────────── */
.hv-scan-ok {
    position: fixed; inset: 0; z-index: 1080;
    display: flex; align-items: center; justify-content: center;
    padding: 20px;
    background: color-mix(in srgb, var(--maroon-dark) 46%, transparent);
    -webkit-backdrop-filter: blur(3px); backdrop-filter: blur(3px);
    opacity: 0; pointer-events: none;
    transition: opacity .18s ease;
}
.hv-scan-ok.is-open { opacity: 1; pointer-events: auto; }
.hv-scan-ok-card {
    position: relative; width: 100%; max-width: 360px;
    background: var(--cream);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 30px 26px 22px;
    text-align: center;
    box-shadow: 0 24px 60px -18px color-mix(in srgb, var(--maroon-dark) 55%, transparent),
                0 4px 14px -6px rgba(43,43,43,.18);
    transform: translateY(10px) scale(.965);
    transition: transform .32s cubic-bezier(.16,1,.3,1);
    overflow: hidden;
}
.hv-scan-ok.is-open .hv-scan-ok-card { transform: translateY(0) scale(1); }
.hv-scan-ok-top { height: 5px; position: absolute; inset: 0 0 auto 0;
    background: linear-gradient(90deg, var(--gold), var(--maroon-light)); }
.hv-scan-ok-top[data-tone="out"] { background: linear-gradient(90deg, var(--maroon-light), var(--gold)); }
.hv-scan-ok-badge {
    width: 72px; height: 72px; margin: 6px auto 16px; border-radius: 50%;
    display: flex; align-items: center; justify-content: center;
    background: #E1EFE3; color: var(--success);
    box-shadow: 0 0 0 6px color-mix(in srgb, var(--success) 12%, transparent);
}
.hv-scan-ok-badge svg { width: 36px; height: 36px; }
.hv-scan-ok-badge path {
    fill: none; stroke: var(--success); stroke-width: 3.2;
    stroke-linecap: round; stroke-linejoin: round;
    stroke-dasharray: 32; stroke-dashoffset: 32;
}
.hv-scan-ok.is-open .hv-scan-ok-badge path {
    animation: hvScanCheck .5s .12s cubic-bezier(.65,0,.35,1) forwards;
}
@keyframes hvScanCheck { to { stroke-dashoffset: 0; } }
.hv-scan-ok-kicker {
    font-size: 11.5px; font-weight: 800; letter-spacing: .12em; text-transform: uppercase;
    color: var(--success);
}
.hv-scan-ok-title {
    margin: 2px 0 14px; font-size: 1.35rem; font-weight: 800; letter-spacing: -.015em;
    color: var(--maroon); line-height: 1.15;
}
.hv-scan-ok-qty {
    display: inline-flex; align-items: baseline; gap: 6px;
    font-variant-numeric: tabular-nums;
}
.hv-scan-ok-qty b { font-size: 2.1rem; font-weight: 800; color: var(--ink); letter-spacing: -.02em; line-height: 1; }
.hv-scan-ok-qty span { font-size: .95rem; font-weight: 600; color: color-mix(in srgb, var(--ink) 62%, var(--cream)); }
.hv-scan-ok-meta {
    margin: 14px auto 0; display: grid; gap: 7px; text-align: left;
    background: #fff; border: 1px solid var(--border); border-radius: 12px; padding: 11px 13px;
}
.hv-scan-ok-meta div { display: flex; justify-content: space-between; gap: 14px; font-size: 12.5px; }
.hv-scan-ok-meta dt { color: color-mix(in srgb, var(--ink) 55%, var(--cream)); font-weight: 600; margin: 0; }
.hv-scan-ok-meta dd { color: var(--ink); font-weight: 700; margin: 0; text-align: right; font-variant-numeric: tabular-nums; }
.hv-scan-ok-next {
    width: 100%; margin-top: 18px; border: none; cursor: pointer;
    background: var(--maroon); color: #fff; font-weight: 700; font-size: .95rem;
    padding: 12px 16px; border-radius: 12px;
    display: inline-flex; align-items: center; justify-content: center; gap: 8px;
    transition: background .15s ease;
}
.hv-scan-ok-next:hover { background: var(--maroon-dark); }
.hv-scan-ok-next:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; }
.hv-scan-ok-timer { position: absolute; left: 0; bottom: 0; height: 3px; width: 100%;
    transform-origin: left; transform: scaleX(1); background: var(--gold); opacity: .85; }
.hv-scan-ok.is-open .hv-scan-ok-timer { animation: hvScanTimer 2.6s linear forwards; }
@keyframes hvScanTimer { to { transform: scaleX(0); } }
.hv-scan-ok.is-paused .hv-scan-ok-timer { animation-play-state: paused; }
@media (prefers-reduced-motion: reduce) {
    .hv-scan-ok, .hv-scan-ok-card { transition: none; }
    .hv-scan-ok.is-open .hv-scan-ok-badge path { animation: none; stroke-dashoffset: 0; }
    .hv-scan-ok.is-open .hv-scan-ok-timer { animation: none; }
}
</style>
@endpush

@section('content')

<div class="hv-scan-wrap">
    <div class="hv-card p-3 mb-3">
        {{-- ═══ MODE TOGGLE ═══════════════════════════════════ --}}
        {{-- The Commissary is a production/dispatch source: its only scan action is
             releasing prepared goods for delivery, so it sees a single Deliver
             Product control instead of the Stock In / Stock Out toggle. --}}
        <div class="d-flex hv-mode-toggle gap-2 mb-3">
            @if(!empty($lockToMain))
            <button type="button" class="btn btn-outline-danger active" data-mode="out" id="btnOut">
                <i class="ph ph-truck"></i> Deliver Product
            </button>
            @else
            <button type="button" class="btn btn-outline-success active" data-mode="in" id="btnIn">
                <i class="ph ph-tray-arrow-down"></i> Stock In
            </button>
            <button type="button" class="btn btn-outline-danger" data-mode="out" id="btnOut">
                <i class="ph ph-export"></i> Stock Out
            </button>
            @endif
        </div>

        {{-- ═══ LOCATION ══════════════════════════════════════ --}}
        @if(!empty($lockToMain))
            {{-- Commissary roles: fixed location, enforced on the server --}}
            <input type="hidden" id="branchSelect" value="{{ $mainBranch->id ?? '' }}">
            <label class="form-label small text-muted mb-1">Location</label>
            <div class="form-control mb-3 d-flex align-items-center bg-light">
                <i class="ph ph-buildings me-2" style="color:var(--maroon)"></i>
                <strong>{{ $mainBranch->branch_name ?? 'Commissary' }}</strong>
            </div>
            @unless($mainBranch)
                <div class="alert alert-danger small py-2 mb-3">
                    <i class="ph ph-warning-octagon me-1"></i>No active Commissary is configured. Stock in/out is blocked until an administrator sets it up.
                </div>
            @endunless
        @elseif(!auth()->user()->isBranchScoped())
            <label class="form-label small text-muted mb-1">Location</label>
            <select id="branchSelect" class="form-select mb-3">
                @foreach($branches as $b)
                    <option value="{{ (int)$b->id }}" {{ ($mainBranch && $b->id === $mainBranch->id) ? 'selected' : '' }}>
                        {{ $b->branch_name }}
                    </option>
                @endforeach
            </select>
        @else
            <input type="hidden" id="branchSelect" value="{{ auth()->user()->branch_id }}">
            <div class="alert alert-light border small py-2 mb-3">
                Recording for <strong>{{ $branches->first()->branch_name ?? 'your branch' }}</strong>.
            </div>
        @endif

        {{-- ═══ CAMERA ════════════════════════════════════════ --}}
        <div id="hv-reader"></div>

        <div class="d-flex gap-2 mt-2">
            <button type="button" class="btn btn-maroon flex-grow-1" id="btnStartCam">
                <i class="ph ph-video-camera"></i> Start camera
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnStopCam" disabled>
                <i class="ph ph-stop-circle"></i> Stop
            </button>
        </div>

        {{-- ═══ MANUAL CODE ENTRY ════════════════════════════ --}}
        <div class="hv-divider mt-3">or enter the code</div>
        <form id="manualForm" class="d-flex gap-2">
            <input type="text" id="manualCode" class="form-control" placeholder="e.g. HV-ITEM-0007" autocomplete="off">
            <button type="submit" class="btn btn-outline-maroon">Look up</button>
        </form>
        <div class="form-text">Tip: a USB barcode scanner types into this box automatically — just scan.</div>
    </div>

    <div id="scanResult"></div>
    <div id="scanFeed" class="mt-3"></div>
</div>

{{-- Success confirmation (shown after a recorded Stock In / Stock Out) --}}
<div class="hv-scan-ok" id="scanOk" role="dialog" aria-modal="true" aria-labelledby="scanOkTitle" hidden>
    <div class="hv-scan-ok-card">
        <span class="hv-scan-ok-top" id="scanOkTop"></span>
        <div class="hv-scan-ok-badge" aria-hidden="true">
            <svg viewBox="0 0 24 24"><path d="M4.5 12.5l5 5 10-11"/></svg>
        </div>
        <div class="hv-scan-ok-kicker" id="scanOkKicker">Recorded</div>
        <h3 class="hv-scan-ok-title" id="scanOkTitle">Stock In recorded</h3>
        <div class="hv-scan-ok-qty"><b id="scanOkQty">0</b><span id="scanOkUnit">unit</span></div>
        <dl class="hv-scan-ok-meta" id="scanOkMeta"></dl>
        <button type="button" class="hv-scan-ok-next" id="scanOkNext">
            <i class="ph ph-barcode"></i> Scan next item
        </button>
        <span class="hv-scan-ok-timer" aria-hidden="true"></span>
    </div>
</div>

@endsection

@include('partials.commissary-group-scanner')

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    "use strict";

    const QR_API    = '{{ route("qr.api") }}';
    const STOCK_API = '{{ route("stock.api") }}';
    const CSRF      = document.querySelector('meta[name="csrf-token"]').content;

    let mode       = @json(!empty($lockToMain)) ? 'out' : 'in';
    let modeSelected = false;
    let current    = null;     // last looked-up product
    let currentGroup = null;
    let scanner    = null;
    let scanning   = false;
    let lastCode   = '';
    let lastAt     = 0;
    let pendingKey = null;     // idempotency key for the prepared transaction
    let currentMax = null;     // max releasable qty for the prepared movement

    const $ = (id) => document.getElementById(id);
    const branchVal = () => ($('branchSelect') ? $('branchSelect').value : '');

    // RFC4122-ish unique token; one per prepared movement so a double-click or
    // retry can't record the same stock movement twice (server enforces it too).
    function uuid() {
        if (window.crypto && crypto.randomUUID) return crypto.randomUUID();
        return 'k-' + Date.now() + '-' + Math.random().toString(16).slice(2) + Math.random().toString(16).slice(2);
    }

    // -- Helpers --
    function escapeHtml(s) {
        return (s == null ? '' : String(s)).replace(/[&<>"']/g,
            c => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c]));
    }

    function beep(good) {
        try {
            const ctx = new (window.AudioContext || window.webkitAudioContext)();
            const o = ctx.createOscillator(), g = ctx.createGain();
            o.connect(g); g.connect(ctx.destination);
            o.frequency.value = good ? 880 : 220;
            o.start(); g.gain.setValueAtTime(0.2, ctx.currentTime);
            g.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 0.18);
            o.stop(ctx.currentTime + 0.2);
        } catch (e) {}
    }

    async function postData(url, fields) {
        const fd = new FormData();
        for (const k in fields) fd.append(k, fields[k]);
        const res = await fetch(url, {
            method: 'POST', body: fd,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'X-CSRF-TOKEN': CSRF
            }
        });
        return res.json();
    }

    // -- Mode Toggle --
    function setMode(m, selected = false) {
        mode = m;
        if (selected) modeSelected = true;
        if ($('btnIn')) $('btnIn').classList.toggle('active', m === 'in');
        if ($('btnOut')) $('btnOut').classList.toggle('active', m === 'out');
        if (currentGroup) window.renderCommissaryGroup(currentGroup, $('scanResult'), postData, STOCK_API, beep, {mode, branchId: branchVal()});
        else if (current) renderItem(current);
    }
    if ($('btnIn')) $('btnIn').addEventListener('click', () => setMode('in', true));
    if ($('btnOut')) $('btnOut').addEventListener('click', () => setMode('out', true));
    $('branchSelect')?.addEventListener('change', () => {
        if (currentGroup) lookup(currentGroup.qr_code);
    });

    // Open in the mode the entry point asked for — e.g. "Release / Scan Out"
    // links to ?mode=out so releasing never defaults to Stock In.
    try {
        const wantMode = new URLSearchParams(location.search).get('mode');
        if (wantMode === 'out' || wantMode === 'in') setMode(wantMode, true);
    } catch (e) {}

    // -- Lookup --
    async function lookup(code) {
        currentGroup = null;
        $('scanResult').innerHTML =
            '<div class="hv-scan-result text-center text-muted py-3">' +
            '<span class="spinner-border spinner-border-sm me-1"></span> Looking up...</div>';

        try {
            const r = await postData(QR_API, { action: 'lookup', query: code, branch_id: branchVal() });
            if (!r.ok) {
                beep(false);
                current = null;
                $('scanResult').innerHTML =
                    '<div class="alert alert-danger mt-3">' +
                    escapeHtml(r.error || 'Product not found.') + '</div>';
                return;
            }
            beep(true);
            if (r.production_group) {
                current = null;
                currentGroup = r.production_group;
                // Default source goods to release, while preserving an explicit
                // selection and the current mode when looking up received goods.
                setMode(!modeSelected && currentGroup.can_dispatch ? 'out' : mode);
                return;
            }
            if (r.production_unit) {
                current = null;
                mode = 'out';
                $('btnIn').classList.toggle('active', false);
                $('btnOut').classList.toggle('active', true);
                renderProductionUnit(r.production_unit);
                return;
            }
            current = r.product;
            current._matched_by = r.matched_by || 'qr';
            current._matched_batch = r.matched_batch || null;
            current._main_branch = r.main_branch || null;
            renderItem(current);
        } catch (e) {
            beep(false);
            $('scanResult').innerHTML =
                '<div class="alert alert-danger mt-3">' +
                '<i class="ph ph-wifi-slash me-1"></i> Network error. Check your connection.</div>';
        }
    }

    // -- Render Result --
    function renderProductionUnit(p) {
        let html = '<div class="hv-card p-3 mt-3"><h5>' + escapeHtml(p.item_name) + '</h5>';
        html += '<p><strong>1 ' + escapeHtml(p.unit) + '</strong> — label ' + p.unit_number + ' of ' + p.total_units + '</p>';
        html += '<p>Order: ' + escapeHtml(p.order_number) + '<br>Destination: ' + escapeHtml(p.destination) + '<br>Expiry: ' + escapeHtml(p.expiry_date || 'Non-perishable') + '</p>';
        if (p.dispatched) html += '<div class="alert alert-success">This piece is already dispatched.</div>';
        else if (p.can_dispatch) {
            html += '<label for="productionMovement" class="form-label">Movement</label><select id="productionMovement" class="form-select mb-3"><option value="delivery">Stock Out — For Delivery</option></select>';
            html += '<button type="button" class="btn btn-maroon" id="btnProductionDispatch">Scan Out 1 ' + escapeHtml(p.unit) + '</button>';
        } else html += '<div class="alert alert-info">' + escapeHtml(p.blocked_reason || 'This order is not ready for release.') + '</div>';
        html += '<div id="productionMessage" class="mt-2" role="status"></div></div>';
        $('scanResult').innerHTML = html;
        const button = $('btnProductionDispatch');
        if (button) button.onclick = async function () {
            button.disabled = true;
            try {
                const r = await postData(STOCK_API, {action: 'stock_out', qr: p.qr_code, quantity: 1});
                $('productionMessage').textContent = r.message || r.error || Object.values(r.errors || {}).flat().join(' ') || 'Could not record stock-out.';
                if (!r.ok) button.disabled = false;
                else {button.textContent = 'Scanned Out'; beep(true);}
            } catch (e) { $('productionMessage').textContent = 'Network error. Retry this same label safely.'; button.disabled = false; }
        };
    }

    function renderItem(p) {
        const bid     = branchVal();
        const myStock = (p.stock || []).find(s => s.branch_id == bid);
        const onHand  = myStock ? parseInt(myStock.total_qty) || 0 : 0;
        const total   = (p.stock || []).reduce((s, b) => s + (parseInt(b.total_qty) || 0), 0);
        const unit    = escapeHtml(p.unit || '');

        const actionColor = mode === 'in' ? 'btn-success' : 'btn-danger';
        const actionLabel = mode === 'in' ? 'Confirm Stock In' : 'Confirm Stock Out';

        let h = '<div class="hv-scan-result">';

        // Matched-by-name warning
        if (p._matched_by === 'name') {
            h += '<div class="alert alert-warning py-1 px-2 small mb-2">' +
                 '<i class="ph ph-warning me-1"></i>Matched by name — verify this is the correct product.</div>';
        }

        // Product header
        h += '<div class="d-flex justify-content-between align-items-start">';
        h += '<div>';
        h += '<div class="fw-bold fs-5" style="color:var(--maroon-dark)">' + escapeHtml(p.item_name) + '</div>';
        h += '<div class="text-muted small">' + escapeHtml(p.category_name || '') +
             (p.qr_code ? ' &middot; ' + escapeHtml(p.qr_code) : '') + '</div>';
        h += '</div>';
        h += '<span class="hv-badge hv-badge-info">' + escapeHtml(p.goods_type || 'Active') + '</span>';
        h += '</div>';

        // -- Identified quantity (what the scanned label actually represents) --
        const batch = p._matched_batch;
        if (batch) {
            // A specific batch/package QR was scanned: show THAT batch's own
            // remaining quantity, not the product's cross-batch/location total.
            const bExpired = !!batch.is_expired;
            const bDamaged = !!batch.is_damaged;
            h += '<div class="border rounded p-2 my-3" style="background:var(--cream)">';
            h += '<div class="d-flex justify-content-between align-items-center">';
            h += '<div class="text-muted small">Scanned batch ' + escapeHtml(batch.batch_number) + '</div>';
            if (bDamaged) {
                h += '<span class="badge bg-dark">Damaged</span>';
            } else if (bExpired) {
                h += '<span class="badge bg-danger">Expired</span>';
            } else if (batch.expiry_date) {
                h += '<span class="badge" style="background:var(--gold-soft);color:var(--maroon-dark)">Eligible</span>';
            }
            h += '</div>';
            h += '<div class="fs-3 fw-bold" style="color:var(--maroon-dark)">' + (parseInt(batch.quantity_left) || 0) +
                 ' <span class="fs-6 text-muted">' + unit + ' in this batch</span></div>';
            if (batch.expiry_date) {
                h += '<div class="small ' + (bExpired ? 'text-danger fw-semibold' : 'text-muted') + '">' +
                     '<i class="ph ph-calendar-x me-1"></i>Expiry: ' + escapeHtml(batch.expiry_date) + '</div>';
            }
            h += '</div>';
        } else {
            // Product- or name-level code: cannot prove a physical count.
            h += '<div class="alert alert-info py-2 small my-3">' +
                 '<i class="ph ph-barcode me-1"></i>This code identifies a <strong>product</strong>, not a specific package or batch. ' +
                 'Enter the counted quantity below.</div>';
        }

        // On-hand totals shown separately so they are never mistaken for the
        // scanned quantity.
        h += '<div class="row text-center mb-2 g-2">';
        h += '<div class="col-6"><div class="text-muted small">On hand at location</div>';
        h += '<div class="fs-5 fw-bold">' + onHand + ' <span class="fs-6">' + unit + '</span></div></div>';
        h += '<div class="col-6"><div class="text-muted small">System total (all locations)</div>';
        h += '<div class="fs-5 fw-bold">' + total + ' <span class="fs-6">' + unit + '</span></div></div>';
        h += '</div>';

        // Extra info
        if (p.pack_description) {
            h += '<div class="small text-muted mb-1"><i class="ph ph-package me-1"></i>' + escapeHtml(p.pack_description) + '</div>';
        }
        if (myStock && myStock.nearest_expiry) {
            h += '<div class="small text-muted mb-1"><i class="ph ph-calendar-x me-1"></i>Nearest expiry: ' + escapeHtml(myStock.nearest_expiry) + '</div>';
        }
        if (onHand <= (p.reorder_level || 0) && onHand > 0) {
            h += '<div class="small text-danger fw-semibold mb-1"><i class="ph ph-warning-circle me-1"></i>Low stock (reorder level: ' + (p.reorder_level || 0) + ')</div>';
        }

        // Batch health flags (authoritative values come from the server; these
        // only drive the UI — the server re-validates on submit).
        const scannedExpired = !!(batch && batch.is_expired);
        const scannedDamaged = !!(batch && batch.is_damaged);

        // ── STOCK IN of an expired/damaged scanned batch is blocked outright ──
        if (mode === 'in' && (scannedExpired || scannedDamaged)) {
            const why = scannedExpired ? 'expired' : 'damaged';
            h += '<div class="alert alert-danger py-2 small mb-2" id="blockMsg">' +
                 '<i class="ph ph-prohibit me-1"></i>This batch is <strong>' + why + '</strong> and cannot be received into available stock. ' +
                 'Route it through disposal instead.</div>';
            h += '<div class="d-flex gap-2"><button type="button" class="btn btn-outline-secondary py-2" id="btnClear">Clear</button></div>';
            h += '</div>';
            $('scanResult').innerHTML = h;
            $('btnClear').onclick = clearScan;
            return;
        }

        // Max releasable quantity. For a scanned batch the movement is bounded by
        // THAT batch only; otherwise by on-hand at the location (product FEFO).
        currentMax = (mode === 'out')
            ? (batch ? (parseInt(batch.quantity_left) || 0) : onHand)
            : null;   // stock in has no upper bound

        // Normal stock out of an expired/damaged batch is not allowed — only
        // disposal reasons remain selectable.
        const disposalOnly = (mode === 'out' && batch && (scannedExpired || scannedDamaged));

        if (mode === 'out' && currentMax <= 0 && !disposalOnly) {
            h += '<div class="alert alert-warning py-2 small mb-0">' +
                 '<i class="ph ph-warning me-1"></i>No eligible stock to release' +
                 (batch ? ' from this batch.' : ' at this location.') + '</div>';
            h += '<div class="d-flex gap-2 mt-2"><button type="button" class="btn btn-outline-secondary py-2" id="btnClear">Clear</button></div>';
            h += '</div>';
            $('scanResult').innerHTML = h;
            $('btnClear').onclick = clearScan;
            return;
        }

        // -- Quantity --
        const qMaxAttr = (mode === 'out' && currentMax > 0) ? ' max="' + currentMax + '"' : '';
        h += '<label class="form-label small text-muted mt-2">Quantity to ' + (mode === 'in' ? 'add' : 'release') +
             (mode === 'out' && batch ? ' <span class="text-muted">(max ' + currentMax + ' in batch ' + escapeHtml(batch.batch_number) + ')</span>' : '') + '</label>';
        h += '<div class="hv-qty-stepper mb-3">';
        h += '<button type="button" class="btn btn-outline-secondary" id="qMinus">&minus;</button>';
        h += '<input type="number" id="qty" class="form-control" min="1" value="1"' + qMaxAttr + '>';
        h += '<button type="button" class="btn btn-outline-secondary" id="qPlus">+</button>';
        h += '</div>';

        // -- Mode-specific fields --
        if (mode === 'in') {
            // Expiry date
            let defExpiry = '';
            if (p.shelf_life_days) {
                const d = new Date();
                d.setDate(d.getDate() + parseInt(p.shelf_life_days));
                defExpiry = d.toISOString().split('T')[0];
            }
            h += '<label class="form-label small text-muted">Expiry Date</label>';
            h += '<input type="date" class="form-control mb-2" id="fExpiry" value="' + defExpiry + '">';
            if (p.shelf_life_days) {
                h += '<div class="form-text mb-2" style="margin-top:-8px">Auto-set to ' + p.shelf_life_days + ' days from today.</div>';
            }
            // Receiving-inspection condition (damaged goods are rejected server-side)
            h += '<div class="form-check mb-3">' +
                 '<input class="form-check-input" type="checkbox" id="fDamaged">' +
                 '<label class="form-check-label small" for="fDamaged">Items are <strong>damaged</strong> / fail receiving inspection</label></div>';
        } else {
            // Reason dropdown for stock out
            h += '<label class="form-label small text-muted">Reason <span class="text-danger">*</span></label>';
            h += '<select class="form-select mb-3" id="fReason">';
            h += '<option value="">Select reason</option>';
            const normalReasons = ['Sold / Used'];
            const disposalReasons = ['Expired', 'Damaged', 'Lost / Missing', 'Other'];
            const reasons = disposalOnly ? disposalReasons : normalReasons.concat(disposalReasons);
            reasons.forEach(function(r) {
                h += '<option value="' + escapeHtml(r) + '">' + escapeHtml(r) + '</option>';
            });
            h += '</select>';

            if (disposalOnly) {
                h += '<div class="alert alert-danger py-2 small mb-3"><i class="ph ph-prohibit me-1"></i>' +
                     'This batch is ' + (scannedExpired ? 'expired' : 'damaged') + ' — it cannot be issued as usable stock. ' +
                     'Only disposal is allowed.</div>';
            } else if (batch) {
                h += '<div class="alert alert-info py-2 small mb-3"><i class="ph ph-package me-1"></i>' +
                     'Releasing from the scanned batch ' + escapeHtml(batch.batch_number) + ' only.</div>';
            } else {
                h += '<div class="alert alert-info py-2 small mb-3"><i class="ph ph-info me-1"></i>' +
                     '<strong>FEFO:</strong> stock is consumed from the earliest-expiring eligible batch first.</div>';
            }
        }

        // Notes
        h += '<input type="text" id="notes" class="form-control form-control-sm mb-3" placeholder="Remarks (optional)" maxlength="500">';

        // Confirm + Clear
        h += '<div class="d-flex gap-2">';
        h += '<button type="button" class="btn ' + actionColor + ' flex-grow-1 py-2" id="btnConfirm">' + actionLabel + '</button>';
        h += '<button type="button" class="btn btn-outline-secondary py-2" id="btnClear" title="Clear scanned item">Clear</button>';
        h += '</div>';
        h += '</div>';

        $('scanResult').innerHTML = h;

        // A fresh idempotency key for THIS prepared movement.
        pendingKey = uuid();

        // Bind events
        $('qMinus').onclick = () => stepQty(-1);
        $('qPlus').onclick  = () => stepQty(1);
        if ($('qty')) $('qty').addEventListener('input', clampQty);
        clampQty();
        $('btnConfirm').onclick = confirmTxn;
        $('btnClear').onclick = clearScan;
    }

    // Keep the typed/stepped quantity within [1, currentMax] and reflect the
    // bound on the +/- buttons so a quantity above the batch cannot be entered.
    function clampQty() {
        const el = $('qty');
        if (!el) return;
        let v = parseInt(el.value, 10);
        if (isNaN(v) || v < 1) v = 1;
        if (currentMax != null && currentMax > 0 && v > currentMax) v = currentMax;
        el.value = v;
        if ($('qPlus')) $('qPlus').disabled = (currentMax != null && currentMax > 0 && v >= currentMax);
        if ($('qMinus')) $('qMinus').disabled = (v <= 1);
    }

    // Discard the current scan/form and reset state so stale data can't be
    // submitted and the next scan starts clean.
    function clearScan() {
        current = null;
        pendingKey = null;
        currentMax = null;
        lastCode = '';
        lastAt = 0;
        $('scanResult').innerHTML = '';
        if ($('manualCode')) $('manualCode').value = '';
    }

    function stepQty(d) {
        const el = $('qty');
        if (!el) return;
        el.value = Math.max(1, (parseInt(el.value, 10) || 1) + d);
        clampQty();
    }

    // -- Confirm Transaction --
    async function confirmTxn() {
        if (!current) return;

        const qty     = parseInt($('qty').value, 10) || 0;
        const remarks = $('notes') ? $('notes').value : '';
        if (qty < 1) { alert('Enter a quantity of at least 1.'); return; }
        if (currentMax != null && currentMax > 0 && qty > currentMax) {
            alert('Quantity exceeds what is available (' + currentMax + ').');
            return;
        }

        const batch = current._matched_batch;
        const bid = branchVal();
        let fields = {
            item_id:         current.id,
            branch_id:       bid,
            quantity:        qty,
            remarks:         remarks,
            idempotency_key: pendingKey || uuid()
        };
        // Bind the movement to the exact scanned batch when one was scanned.
        if (batch && batch.id) fields.batch_id = batch.id;

        if (mode === 'in') {
            fields.action      = 'stock_in';
            fields.expiry_date = $('fExpiry') ? $('fExpiry').value : '';
            fields.reason      = 'Stock received (scan)';
            fields.condition   = ($('fDamaged') && $('fDamaged').checked) ? 'damaged' : 'good';
            delete fields.batch_id; // stock-in creates a NEW batch, never reuses one
        } else {
            const reason = $('fReason') ? $('fReason').value : '';
            if (!reason) { alert('Please select a reason.'); return; }

            // Map certain reasons to 'adjustment' action
            const adjReasons = ['expired', 'damaged', 'spoiled', 'lost'];
            const isAdj = adjReasons.some(r => reason.toLowerCase().includes(r));

            fields.action = isAdj ? 'adjustment' : 'stock_out';
            fields.reason = reason;
        }

        const btn = $('btnConfirm');
        btn.disabled = true; btn.textContent = 'Saving...';

        try {
            const r = await postData(STOCK_API, fields);
            if (!r.ok) {
                beep(false);
                btn.disabled = false;
                btn.textContent = mode === 'in' ? 'Confirm Stock In' : 'Confirm Stock Out';
                $('scanResult').insertAdjacentHTML('afterbegin',
                    '<div class="alert alert-danger py-2">' + escapeHtml(r.error || r.message || 'Error') + '</div>');
                return;
            }
            beep(true);
            const okDetail = {
                mode: mode,
                item: current.item_name,
                unit: current.unit,
                qty: qty,
                detail: (mode === 'in')
                    ? (($('fExpiry') && $('fExpiry').value) ? 'Expires ' + $('fExpiry').value : 'No expiry')
                    : (fields.reason || ''),
            };
            prependFeed(r.message || (qty + ' ' + escapeHtml(current.unit) + ' — ' + escapeHtml(current.item_name)));
            $('scanResult').innerHTML = '';
            current = null;
            pendingKey = null;   // key consumed — a retry would need a new movement
            showScanSuccess(okDetail);
        } catch (e) {
            beep(false);
            btn.disabled = false;
            btn.textContent = mode === 'in' ? 'Confirm Stock In' : 'Confirm Stock Out';
            $('scanResult').insertAdjacentHTML('afterbegin',
                '<div class="alert alert-danger py-2">Network error. Try again.</div>');
        }
    }

    // -- Scan success confirmation --
    let scanOkTimer = null;
    function branchLabel() {
        const sel = $('branchSelect');
        if (sel && sel.tagName === 'SELECT' && sel.selectedIndex >= 0) return sel.options[sel.selectedIndex].text.trim();
        const locked = document.querySelector('.hv-scan-wrap .form-control.bg-light strong, .hv-scan-wrap .alert strong');
        return locked ? locked.textContent.trim() : '';
    }
    function closeScanOk() {
        const ok = $('scanOk');
        if (!ok || ok.hidden) return;
        ok.classList.remove('is-open');
        clearTimeout(scanOkTimer);
        setTimeout(function () { ok.hidden = true; }, 200);
    }
    function showScanSuccess(d) {
        const ok = $('scanOk');
        if (!ok) return;
        const isIn = d.mode === 'in';
        $('scanOkTop').setAttribute('data-tone', isIn ? 'in' : 'out');
        $('scanOkKicker').textContent = isIn ? 'Stock received' : 'Stock released';
        $('scanOkTitle').textContent = isIn ? 'Stock In recorded' : 'Stock Out recorded';
        $('scanOkQty').textContent = d.qty;
        $('scanOkUnit').textContent = d.unit + (d.qty === 1 ? '' : 's');

        const rows = [['Product', d.item]];
        const loc = branchLabel();
        if (loc) rows.push([isIn ? 'Location' : 'Branch', loc]);
        if (d.detail) rows.push([isIn ? 'Shelf life' : 'Reason', d.detail]);
        rows.push(['Recorded', new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' })]);
        $('scanOkMeta').innerHTML = rows.map(function (r) {
            return '<div><dt>' + escapeHtml(r[0]) + '</dt><dd>' + escapeHtml(r[1]) + '</dd></div>';
        }).join('');

        ok.hidden = false;
        // reflow so the open transition + timer animation restart each time
        void ok.offsetWidth;
        ok.classList.remove('is-paused');
        ok.classList.add('is-open');
        $('scanOkNext').focus();
        clearTimeout(scanOkTimer);
        scanOkTimer = setTimeout(closeScanOk, 2600);
    }
    (function () {
        const ok = $('scanOk');
        if (!ok) return;
        $('scanOkNext').addEventListener('click', function () {
            closeScanOk();
            const code = $('manualCode'); if (code) code.focus();
        });
        // Clicking the dim backdrop (not the card) dismisses.
        ok.addEventListener('click', function (e) { if (e.target === ok) closeScanOk(); });
        // Pause the auto-dismiss while the user is reading/interacting.
        ok.addEventListener('mouseenter', function () { ok.classList.add('is-paused'); clearTimeout(scanOkTimer); });
        ok.addEventListener('mouseleave', function () { if (!ok.hidden) { ok.classList.remove('is-paused'); scanOkTimer = setTimeout(closeScanOk, 1400); } });
        document.addEventListener('keydown', function (e) { if (e.key === 'Escape' && !ok.hidden) closeScanOk(); });
    })();

    // -- Activity Feed --
    function prependFeed(msg) {
        const t = new Date().toLocaleTimeString();
        $('scanFeed').insertAdjacentHTML('afterbegin',
            '<div class="hv-activity-item">' +
            '<div class="hv-activity-icon" style="background:#E3EEE2;color:var(--success)">' +
            '<i class="ph ph-check"></i></div><div>' + escapeHtml(msg) +
            ' <span class="text-muted">&mdash; ' + t + '</span></div></div>');
    }

    // -- Camera (html5-qrcode) --
    function onScan(decodedText) {
        const now = Date.now();
        if (decodedText === lastCode && now - lastAt < 2500) return;
        lastCode = decodedText; lastAt = now;
        $('manualCode').value = decodedText;
        lookup(decodedText.trim());
    }

    $('btnStartCam').addEventListener('click', async () => {
        if (scanning) return;
        if (typeof Html5Qrcode === 'undefined') {
            $('scanResult').innerHTML =
                '<div class="alert alert-warning mt-2">Camera library did not load (no internet?). ' +
                'Use the manual code box instead.</div>';
            return;
        }
        scanner = new Html5Qrcode('hv-reader');
        try {
            await scanner.start(
                { facingMode: 'environment' },
                { fps: 10, qrbox: { width: 220, height: 220 } },
                onScan, () => {}
            );
            scanning = true;
            $('btnStartCam').disabled = true;
            $('btnStopCam').disabled  = false;
        } catch (e) {
            $('scanResult').innerHTML =
                '<div class="alert alert-warning mt-2">Could not open the camera: ' +
                escapeHtml(e.message || e) +
                '.<br>On a phone the page must be opened over <strong>https</strong> (or localhost). ' +
                'You can still type the code manually below.</div>';
        }
    });

    $('btnStopCam').addEventListener('click', async () => {
        if (scanner && scanning) {
            try { await scanner.stop(); } catch (e) {}
            scanning = false;
            $('btnStartCam').disabled = false;
            $('btnStopCam').disabled  = true;
        }
    });

    // -- Manual form --
    $('manualForm').addEventListener('submit', (e) => {
        e.preventDefault();
        const code = $('manualCode').value.trim();
        if (code) { lookup(code); $('manualCode').value = ''; }
    });

    // Release camera when page is hidden
    document.addEventListener('visibilitychange', () => {
        if (document.hidden && scanning && scanner) {
            scanner.stop().catch(() => {});
            scanning = false;
            $('btnStartCam').disabled = false;
            $('btnStopCam').disabled  = true;
        }
    });
})();
</script>
@endpush

