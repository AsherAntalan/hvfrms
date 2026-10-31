@extends('layouts.app')

@section('title', 'Scan — Stock In / Out')

@push('styles')
<style>
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
.hv-batch-info {
    background: #E0F2FE; border: 1px solid #BAE6FD; border-radius: 10px;
    padding: 10px 14px; margin-bottom: 12px;
}
.hv-batch-info .batch-label {
    font-size: 13px; font-weight: 600; color: var(--maroon);
}
.hv-batch-info .batch-detail {
    font-size: 12px; color: #555;
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
                <i class="ph ph-tray-arrow-down"></i> Stock In
            </button>
            <button type="button" class="btn btn-outline-danger" data-mode="out" id="btnOut">
                <i class="ph ph-export"></i> Stock Out
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

@endsection

@include('partials.commissary-group-scanner')

@push('scripts')
<script src="https://cdn.jsdelivr.net/npm/html5-qrcode@2.3.8/html5-qrcode.min.js"></script>
<script>
(function () {
    "use strict";

    const QR_API    = '{{ route("qr.api") }}';
    const CSRF      = document.querySelector('meta[name="csrf-token"]').content;

    let mode      = 'in';
    let modeSelected = false;
    let current   = null;
    let currentGroup = null;
    let scanner   = null;
    let scanning  = false;
    let lastCode  = '';
    let lastAt    = 0;

    const $ = (id) => document.getElementById(id);
    const branchVal = () => ($('branchSelect') ? parseInt($('branchSelect').value) || 0 : 0);

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
        $('btnIn').classList.toggle('active', m === 'in');
        $('btnOut').classList.toggle('active', m === 'out');
        if (currentGroup) window.renderCommissaryGroup(currentGroup, $('scanResult'), postData, QR_API, beep, {mode, branchId: branchVal()});
        else if (current) renderItem(current);
    }
    $('btnIn').addEventListener('click', () => setMode('in', true));
    $('btnOut').addEventListener('click', () => setMode('out', true));
    $('branchSelect')?.addEventListener('change', () => {
        if (currentGroup) lookup(currentGroup.qr_code);
    });

    const requestedMode = new URLSearchParams(location.search).get('mode');
    if (requestedMode === 'in' || requestedMode === 'out') setMode(requestedMode, true);

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
            current._movement_key = window.crypto?.randomUUID ? crypto.randomUUID() : Date.now().toString(36) + Math.random().toString(36).slice(2);
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
                const r = await postData(QR_API, {action: 'stock_out', qr: p.qr_code, quantity: 1});
                $('productionMessage').textContent = r.message || r.error || Object.values(r.errors || {}).flat().join(' ') || 'Could not record stock-out.';
                if (!r.ok) button.disabled = false;
                else {button.textContent = 'Scanned Out'; beep(true);}
            } catch (e) { $('productionMessage').textContent = 'Network error. Retry this same label safely.'; button.disabled = false; }
        };
    }

    function availableQuantity(p) {
        if (p._matched_batch) {
            return Number(p._matched_batch.branch_id) === branchVal() ? Number(p._matched_batch.quantity_left) || 0 : 0;
        }
        const stock = (p.stock || []).find(s => Number(s.branch_id) === branchVal());
        return Number(stock?.total_qty) || 0;
    }

    function renderItem(p) {
        const bid     = branchVal();
        const onHand  = availableQuantity(p);
        const unit    = escapeHtml(p.unit || '');

        const myBatches = (p.batches || []).filter(b => b.branch_id == bid && (!p._matched_batch || b.id === p._matched_batch.id));

        const actionColor = mode === 'in' ? 'btn-success' : 'btn-danger';
        const actionLabel = mode === 'in' ? 'Confirm Stock In' : 'Confirm Stock Out';


        let h = '<div class="hv-scan-result">';

        if (p._matched_by === 'name') {
            h += '<div class="alert alert-warning py-1 px-2 small mb-2">' +
                 '<i class="ph ph-warning me-1"></i>Matched by name — verify this is the correct product.</div>';
        }

        // Batch info (show for the selected branch)
        if (myBatches.length > 0) {
            myBatches.forEach(function(batch) {
                h += '<div class="hv-batch-info">';
                h += '<div class="batch-label"><i class="ph ph-qr-code me-1"></i>Batch ' + escapeHtml(batch.batch_number) + ' &middot; Total qty: <strong>' + batch.quantity_left + ' ' + unit + '</strong></div>';
                if (batch.expiry_date) {
                    h += '<div class="batch-detail"><i class="ph ph-calendar-x me-1"></i>Expires: <strong>' + escapeHtml(batch.expiry_date) + '</strong></div>';
                }
                h += '</div>';
            });
        }

        // Product name
        h += '<h5 class="fw-bold mb-1" style="color:var(--maroon-dark)">' + escapeHtml(p.item_name) + '</h5>';

        // Quantity input with label showing max
        h += '<label class="form-label small text-muted mt-2">Quantity to ' + (mode === 'in' ? 'add' : 'release');
        if (mode === 'out' && onHand > 0) {
            h += ' (max ' + onHand + ')';
        }
        h += '</label>';
        h += '<div class="hv-qty-stepper mb-3">';
        h += '<button type="button" class="btn btn-outline-secondary" id="qMinus">&minus;</button>';
        h += '<input type="number" id="qty" class="form-control" min="1" value="1"' +
             (mode === 'out' && onHand > 0 ? ' max="' + onHand + '"' : '') + '>';
        h += '<button type="button" class="btn btn-outline-secondary" id="qPlus">+</button>';
        h += '</div>';

        // Mode-specific fields
        if (mode === 'in') {
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
            if (onHand <= 0) {
                h += '<div class="alert alert-warning py-2 small mb-0">' +
                     '<i class="ph ph-warning me-1"></i>No stock to deduct at this branch.</div></div>';
                $('scanResult').innerHTML = h;
                return;
            }
            h += '<label class="form-label small text-muted">Reason <span class="text-danger">*</span></label>';
            h += '<select class="form-select mb-3" id="fReason">';
            h += '<option value="">Select reason</option>';
            ['Sold / Used', 'Expired', 'Damaged', 'Lost / Missing', 'Other'].forEach(function(r) {
                h += '<option value="' + escapeHtml(r) + '">' + escapeHtml(r) + '</option>';
            });
            h += '</select>';

            h += '<div class="alert alert-info py-2 small mb-3">' +
                 '<i class="ph ph-info me-1"></i>' + (p._matched_batch ? 'Stock will be deducted only from the scanned batch.' : '<strong>FEFO:</strong> Stock with the earliest expiry will be used first.') + '</div>';
        }

        // Remarks
        h += '<input type="text" id="notes" class="form-control form-control-sm mb-3" placeholder="Remarks (optional)" maxlength="500">';

        // Confirm button
        h += '<button type="button" class="btn ' + actionColor + ' w-100 py-2" id="btnConfirm">' + actionLabel + '</button>';
        h += '</div>';

        $('scanResult').innerHTML = h;

        // Bind qty stepper events
        $('qMinus').onclick = () => {
            const el = $('qty');
            el.value = Math.max(1, (parseInt(el.value, 10) || 1) - 1);
        };
        $('qPlus').onclick = () => {
            const el = $('qty');
            const max = parseInt(el.getAttribute('max')) || 9999;
            const next = (parseInt(el.value, 10) || 1) + 1;
            el.value = Math.min(next, max);
        };

        // Enforce max on manual input
        const qtyEl = $('qty');
        qtyEl.addEventListener('change', function() {
            const max = parseInt(this.getAttribute('max')) || 9999;
            let val = parseInt(this.value) || 1;
            if (val < 1) val = 1;
            if (val > max) val = max;
            this.value = val;
        });

        $('btnConfirm').onclick = confirmTxn;
    }

    // -- Confirm Transaction --
    async function confirmTxn() {
        if (!current) return;

        if (!$('qty').reportValidity()) return;
        const qty     = Number($('qty').value);
        const remarks = $('notes') ? $('notes').value : '';
        if (qty < 1) { alert('Enter a quantity of at least 1.'); return; }

        // Enforce max for stock out
        if (mode === 'out') {
            const onHand = availableQuantity(current);
            if (qty > onHand) {
                alert('Quantity exceeds available stock (' + onHand + '). Please enter a valid amount.');
                return;
            }
        }

        const bid = branchVal();
        let fields = {
            item_id:   current.id,
            batch_id:  current._matched_batch?.id || '',
            idempotency_key: current._movement_key,
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

            const adjReasons = ['expired', 'damaged', 'spoiled', 'lost'];
            const isAdj = adjReasons.some(r => reason.toLowerCase().includes(r));

            fields.action = isAdj ? 'adjustment' : 'stock_out';
            fields.reason = reason;
        }

        const btn = $('btnConfirm');
        btn.disabled = true; btn.textContent = 'Saving...';

        try {
            const r = await postData(QR_API, fields);
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
