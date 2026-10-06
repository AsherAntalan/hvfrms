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
</style>
@endpush

@section('content')

<div class="hv-scan-wrap">
    <div class="hv-card p-3 mb-3">
        {{-- ═══ MODE TOGGLE ═══════════════════════════════════ --}}
        <div class="d-flex hv-mode-toggle gap-2 mb-3">
            <button type="button" class="btn btn-outline-success active" data-mode="in" id="btnIn">
                <i class="bi bi-box-arrow-in-down"></i> Stock In
            </button>
            <button type="button" class="btn btn-outline-danger" data-mode="out" id="btnOut">
                <i class="bi bi-box-arrow-up"></i> Stock Out
            </button>
        </div>

        {{-- ═══ BRANCH ════════════════════════════════════════ --}}
        @if(!auth()->user()->isBranchScoped())
            <label class="form-label small text-muted mb-1">Branch</label>
            <select id="branchSelect" class="form-select mb-3">
                @foreach($branches as $b)
                    <option value="{{ (int)$b->id }}">{{ $b->branch_name }}</option>
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
                <i class="bi bi-camera-video"></i> Start camera
            </button>
            <button type="button" class="btn btn-outline-secondary" id="btnStopCam" disabled>
                <i class="bi bi-stop-circle"></i> Stop
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

@endsection

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    "use strict";

    const QR_API    = '{{ route("qr.api") }}';
    const STOCK_API = '{{ route("stock.api") }}';
    const CSRF      = document.querySelector('meta[name="csrf-token"]').content;

    let mode      = 'in';
    let current   = null;     // last looked-up product
    let scanner   = null;
    let scanning  = false;
    let lastCode  = '';
    let lastAt    = 0;

    const $ = (id) => document.getElementById(id);
    const branchVal = () => ($('branchSelect') ? $('branchSelect').value : '');

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
    function setMode(m) {
        mode = m;
        $('btnIn').classList.toggle('active', m === 'in');
        $('btnOut').classList.toggle('active', m === 'out');
        if (current) renderItem(current);
    }
    $('btnIn').addEventListener('click', () => setMode('in'));
    $('btnOut').addEventListener('click', () => setMode('out'));

    // -- Lookup --
    async function lookup(code) {
        $('scanResult').innerHTML =
            '<div class="hv-scan-result text-center text-muted py-3">' +
            '<span class="spinner-border spinner-border-sm me-1"></span> Looking up...</div>';

        try {
            const r = await postData(QR_API, { action: 'lookup', query: code });
            if (!r.ok) {
                beep(false);
                current = null;
                $('scanResult').innerHTML =
                    '<div class="alert alert-danger mt-3">' +
                    escapeHtml(r.error || 'Product not found.') + '</div>';
                return;
            }
            beep(true);
            current = r.product;
            current._matched_by = r.matched_by || 'qr';
            renderItem(current);
        } catch (e) {
            beep(false);
            $('scanResult').innerHTML =
                '<div class="alert alert-danger mt-3">' +
                '<i class="bi bi-wifi-off me-1"></i> Network error. Check your connection.</div>';
        }
    }

    // -- Render Result --
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
                 '<i class="bi bi-exclamation-triangle me-1"></i>Matched by name — verify this is the correct product.</div>';
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

        // Two-column stock display
        h += '<div class="row text-center my-3 g-2">';
        h += '<div class="col-6"><div class="text-muted small">At this branch</div>';
        h += '<div class="fs-4 fw-bold">' + onHand + ' <span class="fs-6">' + unit + '</span></div></div>';
        h += '<div class="col-6"><div class="text-muted small">System total</div>';
        h += '<div class="fs-4 fw-bold">' + total + ' <span class="fs-6">' + unit + '</span></div></div>';
        h += '</div>';

        // Extra info
        if (p.pack_description) {
            h += '<div class="small text-muted mb-1"><i class="bi bi-box me-1"></i>' + escapeHtml(p.pack_description) + '</div>';
        }
        if (myStock && myStock.nearest_expiry) {
            h += '<div class="small text-muted mb-1"><i class="bi bi-calendar-x me-1"></i>Nearest expiry: ' + escapeHtml(myStock.nearest_expiry) + '</div>';
        }
        if (onHand <= (p.reorder_level || 0) && onHand > 0) {
            h += '<div class="small text-danger fw-semibold mb-1"><i class="bi bi-exclamation-circle me-1"></i>Low stock (reorder level: ' + (p.reorder_level || 0) + ')</div>';
        }

        // -- Quantity --
        h += '<label class="form-label small text-muted mt-2">Quantity to ' + (mode === 'in' ? 'add' : 'release') + '</label>';
        h += '<div class="hv-qty-stepper mb-3">';
        h += '<button type="button" class="btn btn-outline-secondary" id="qMinus">&minus;</button>';
        h += '<input type="number" id="qty" class="form-control" min="1" value="1"' +
             (mode === 'out' && onHand > 0 ? ' max="' + onHand + '"' : '') + '>';
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
            h += '<input type="date" class="form-control mb-3" id="fExpiry" value="' + defExpiry + '">';
            if (p.shelf_life_days) {
                h += '<div class="form-text mb-2" style="margin-top:-8px">Auto-set to ' + p.shelf_life_days + ' days from today.</div>';
            }
        } else {
            // Reason dropdown for stock out
            if (onHand <= 0) {
                h += '<div class="alert alert-warning py-2 small mb-0">' +
                     '<i class="bi bi-exclamation-triangle me-1"></i>No stock to deduct at this branch.</div></div>';
                $('scanResult').innerHTML = h;
                return;
            }
            h += '<label class="form-label small text-muted">Reason <span class="text-danger">*</span></label>';
            h += '<select class="form-select mb-3" id="fReason">';
            h += '<option value="">Select reason</option>';
            ['Sold / Used', 'Transferred to another branch', 'Given away / Promo',
             'Expired', 'Damaged', 'Spoiled', 'Lost / Missing', 'Other'].forEach(function(r) {
                h += '<option value="' + escapeHtml(r) + '">' + escapeHtml(r) + '</option>';
            });
            h += '</select>';

            h += '<div class="alert alert-info py-2 small mb-3">' +
                 '<i class="bi bi-info-circle me-1"></i><strong>FIFO:</strong> Stock will be consumed from the oldest batch first.</div>';
        }

        // Notes
        h += '<input type="text" id="notes" class="form-control form-control-sm mb-3" placeholder="Remarks (optional)" maxlength="500">';

        // Confirm button
        h += '<button type="button" class="btn ' + actionColor + ' w-100 py-2" id="btnConfirm">' + actionLabel + '</button>';
        h += '</div>';

        $('scanResult').innerHTML = h;

        // Bind events
        $('qMinus').onclick = () => stepQty(-1);
        $('qPlus').onclick  = () => stepQty(1);
        $('btnConfirm').onclick = confirmTxn;
    }

    function stepQty(d) {
        const el = $('qty');
        el.value = Math.max(1, (parseInt(el.value, 10) || 1) + d);
    }

    // -- Confirm Transaction --
    async function confirmTxn() {
        if (!current) return;

        const qty     = parseInt($('qty').value, 10) || 0;
        const remarks = $('notes') ? $('notes').value : '';
        if (qty < 1) { alert('Enter a quantity of at least 1.'); return; }

        const bid = branchVal();
        let fields = {
            item_id:   current.id,
            branch_id: bid,
            quantity:  qty,
            remarks:   remarks
        };

        if (mode === 'in') {
            fields.action      = 'stock_in';
            fields.expiry_date = $('fExpiry') ? $('fExpiry').value : '';
            fields.reason      = 'Stock received (scan)';
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
            prependFeed(r.message || (qty + ' ' + escapeHtml(current.unit) + ' — ' + escapeHtml(current.item_name)));
            $('scanResult').innerHTML =
                '<div class="alert alert-success mt-3">' + escapeHtml(r.message || 'Saved!') +
                ' Scan the next item.</div>';
            current = null;
        } catch (e) {
            beep(false);
            btn.disabled = false;
            btn.textContent = mode === 'in' ? 'Confirm Stock In' : 'Confirm Stock Out';
            $('scanResult').insertAdjacentHTML('afterbegin',
                '<div class="alert alert-danger py-2">Network error. Try again.</div>');
        }
    }

    // -- Activity Feed --
    function prependFeed(msg) {
        const t = new Date().toLocaleTimeString();
        $('scanFeed').insertAdjacentHTML('afterbegin',
            '<div class="hv-activity-item">' +
            '<div class="hv-activity-icon" style="background:#E3EEE2;color:var(--success)">' +
            '<i class="bi bi-check2"></i></div><div>' + escapeHtml(msg) +
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

