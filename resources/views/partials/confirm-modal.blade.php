{{-- Shared, centered confirmation + success/error modal. One element, reconfigured
     per call. Full-viewport blurred backdrop over the sidebar, matching the
     auth-loading treatment. Drive it with window.hvModal.confirm/success/error. --}}
<div id="hvModal" class="hv-modal" role="dialog" aria-modal="true"
     aria-labelledby="hvModalTitle" aria-describedby="hvModalMsg" aria-hidden="true" hidden>
    <div class="hv-modal-card" tabindex="-1">
        <button type="button" class="hv-modal-close" id="hvModalClose" aria-label="Close">
            <i class="ph ph-x" aria-hidden="true"></i>
        </button>
        <div class="hv-modal-icon" id="hvModalIcon" aria-hidden="true"><i class="ph"></i></div>
        <h2 class="hv-modal-title" id="hvModalTitle"></h2>
        <p class="hv-modal-msg" id="hvModalMsg"></p>
        <div id="hvModalSelectField" class="text-start mb-3" hidden>
            <label for="hvModalSelect" id="hvModalSelectLabel" class="form-label"></label>
            <select id="hvModalSelect" class="form-select"></select>
        </div>
        <p class="hv-modal-error" id="hvModalError" role="alert" hidden></p>
        <div class="hv-modal-actions" id="hvModalActions">
            <button type="button" class="hv-modal-btn hv-modal-cancel" id="hvModalCancel">Cancel</button>
            <button type="button" class="hv-modal-btn hv-modal-confirm" id="hvModalConfirm">
                <span class="hv-modal-btn-label">Confirm</span>
                <span class="hv-modal-spinner" aria-hidden="true" hidden></span>
            </button>
        </div>
        <span class="visually-hidden" role="status" aria-live="assertive" id="hvModalStatus"></span>
    </div>
</div>

<style>
.hv-modal{
    position:fixed; inset:0; z-index:4000; display:flex; align-items:center; justify-content:center;
    padding:24px 16px; background:color-mix(in srgb, #5C1010 44%, transparent); backdrop-filter:blur(5px);
    -webkit-backdrop-filter:blur(5px); animation:hvModalFade .16s ease-out;
}
.hv-modal[hidden]{ display:none; }
.hv-modal-card{
    position:relative; width:100%; max-width:420px; background:var(--cream, #FBF7F0);
    border:1px solid color-mix(in srgb, var(--gold, #D4A94A) 55%, transparent); border-radius:20px;
    padding:30px 26px 24px; text-align:center;
    box-shadow:0 24px 64px -20px rgba(0,0,0,.5), 0 6px 18px -10px rgba(0,0,0,.3);
    animation:hvModalPop .18s cubic-bezier(.2,.8,.3,1);
}
.hv-modal-close{
    position:absolute; top:12px; right:12px; width:34px; height:34px; border:none; background:transparent;
    color:#6E635C; cursor:pointer; border-radius:9px; font-size:18px; display:flex; align-items:center; justify-content:center;
}
.hv-modal-close:hover{ background:var(--gold-soft, #F3E7CC); color:var(--maroon, #7A1717); }
.hv-modal-close:focus-visible{ outline:2px solid var(--gold, #D4A94A); outline-offset:1px; }
.hv-modal-icon{ width:56px; height:56px; margin:4px auto 14px; border-radius:50%; display:flex; align-items:center; justify-content:center; font-size:26px; }
.hv-modal-icon.is-danger{ background:#F8DAD4; color:#C0392B; }
.hv-modal-icon.is-success{ background:#DCF1E3; color:#1E7E46; }
.hv-modal-icon.is-question{ background:var(--gold-soft, #F3E7CC); color:var(--maroon, #7A1717); }
.hv-modal-title{ font-size:20px; font-weight:800; color:#1F1A17; margin:0 0 8px; letter-spacing:-.01em; }
.hv-modal-msg{ font-size:13.5px; line-height:1.55; color:#5B534D; margin:0 auto 20px; max-width:320px; }
.hv-modal-error{ font-size:12.5px; color:#C0392B; margin:-8px auto 16px; max-width:320px; }
.hv-modal-actions{ display:flex; gap:12px; justify-content:center; }
.hv-modal-actions.is-single{ }
.hv-modal-btn{
    flex:1 1 0; min-height:46px; border-radius:12px; font-weight:700; font-size:14px; cursor:pointer;
    display:inline-flex; align-items:center; justify-content:center; gap:8px; border:1.5px solid transparent;
    transition:background .14s, border-color .14s, color .14s;
}
.hv-modal-cancel{ background:#fff; border-color:#E3DED4; color:#2B2B2B; }
.hv-modal-cancel:hover{ border-color:#CFC6B5; }
.hv-modal-confirm{ background:var(--maroon, #7A1717); color:#fff; }
.hv-modal-confirm:hover{ background:var(--maroon-dark, #5C1010); }
.hv-modal.is-danger .hv-modal-confirm{ background:#C0392B; }
.hv-modal.is-danger .hv-modal-confirm:hover{ background:#A5302350; background:#9E2B20; }
.hv-modal.is-success .hv-modal-confirm{ background:#1E7E46; }
.hv-modal.is-success .hv-modal-confirm:hover{ background:#19683A; }
.hv-modal-btn:focus-visible{ outline:3px solid var(--gold, #D4A94A); outline-offset:2px; }
.hv-modal-btn:disabled{ opacity:.6; cursor:not-allowed; }
.hv-modal-spinner{ width:16px; height:16px; border-radius:50%; border:2px solid rgba(255,255,255,.45);
    border-top-color:#fff; animation:hvModalSpin .7s linear infinite; }
@keyframes hvModalSpin{ to{ transform:rotate(360deg); } }
@keyframes hvModalFade{ from{ opacity:0; } to{ opacity:1; } }
@keyframes hvModalPop{ from{ opacity:0; transform:translateY(8px) scale(.98); } to{ opacity:1; transform:none; } }
@media (max-width:480px){
    .hv-modal-card{ padding:26px 20px 20px; border-radius:16px; }
    .hv-modal-actions{ flex-direction:column-reverse; }
}
@media (prefers-reduced-motion:reduce){
    .hv-modal, .hv-modal-card{ animation:none; }
    .hv-modal-spinner{ animation-duration:1.4s; }
}
</style>

<script>
(function () {
    var el = document.getElementById('hvModal');
    if (!el || (window.hvModal && typeof window.hvModal.confirm === 'function')) { return; }
    var card = el.querySelector('.hv-modal-card');
    var iconWrap = document.getElementById('hvModalIcon');
    var iconEl = iconWrap.querySelector('i');
    var titleEl = document.getElementById('hvModalTitle');
    var msgEl = document.getElementById('hvModalMsg');
    var selectField = document.getElementById('hvModalSelectField');
    var selectEl = document.getElementById('hvModalSelect');
    var selectLabel = document.getElementById('hvModalSelectLabel');
    var errEl = document.getElementById('hvModalError');
    var actionsEl = document.getElementById('hvModalActions');
    var cancelBtn = document.getElementById('hvModalCancel');
    var confirmBtn = document.getElementById('hvModalConfirm');
    var confirmLabel = confirmBtn.querySelector('.hv-modal-btn-label');
    var confirmSpin = confirmBtn.querySelector('.hv-modal-spinner');
    var closeBtn = document.getElementById('hvModalClose');
    var statusEl = document.getElementById('hvModalStatus');

    var ICONS = {
        danger: 'ph-warning',
        success: 'ph-check',
        question: 'ph-question',
    };

    var lastFocus = null, processing = false, open = false;
    var state = { cfg: null, settle: null };

    function focusable() {
        return Array.prototype.slice.call(
            card.querySelectorAll('button:not([disabled]):not([hidden]), select:not([disabled])')
        ).filter(function (item) { return item.getClientRects().length > 0; });
    }

    function trap(e) {
        if (e.key === 'Escape' && open) { e.preventDefault(); dismiss(); return; }
        if (e.key !== 'Tab' || !open) { return; }
        var items = focusable();
        if (!items.length) { return; }
        var first = items[0], last = items[items.length - 1];
        if (e.shiftKey && document.activeElement === first) { e.preventDefault(); last.focus(); }
        else if (!e.shiftKey && document.activeElement === last) { e.preventDefault(); first.focus(); }
    }

    function setProcessing(on) {
        processing = !!on;
        confirmBtn.disabled = processing;
        cancelBtn.disabled = processing;
        closeBtn.disabled = processing;
        selectEl.disabled = processing;
        confirmSpin.hidden = !processing;
        card.setAttribute('aria-busy', processing ? 'true' : 'false');
    }

    function showError(message) {
        errEl.textContent = message || 'Something went wrong. Please try again.';
        errEl.hidden = false;
        statusEl.textContent = errEl.textContent;
    }

    function hideEl() {
        open = false;
        el.hidden = true;
        el.setAttribute('aria-hidden', 'true');
        document.documentElement.style.overflow = '';
        document.removeEventListener('keydown', trap, true);
        statusEl.textContent = '';
    }

    function showEl(cfg) {
        errEl.hidden = true; errEl.textContent = '';
        iconWrap.className = 'hv-modal-icon is-' + (cfg.icon || 'question');
        iconEl.className = 'ph ' + (ICONS[cfg.icon] || ICONS.question);
        el.className = 'hv-modal is-' + (cfg.variant || 'primary');
        titleEl.textContent = cfg.title || '';
        msgEl.textContent = cfg.message || '';
        selectField.hidden = !cfg.select;
        selectEl.required = !!cfg.select;
        selectEl.replaceChildren();
        if (cfg.select) {
            selectLabel.textContent = cfg.select.label || 'Reason';
            var placeholder = document.createElement('option');
            placeholder.value = '';
            placeholder.textContent = 'Select a reason...';
            selectEl.appendChild(placeholder);
            Object.entries(cfg.select.options).forEach(function (entry) {
                var option = document.createElement('option');
                option.value = entry[0]; option.textContent = entry[1];
                selectEl.appendChild(option);
            });
            selectEl.value = '';
        }
        confirmLabel.textContent = cfg.confirmText || 'Confirm';
        cancelBtn.textContent = cfg.cancelText || 'Cancel';
        cancelBtn.hidden = !cfg.showCancel;
        actionsEl.classList.toggle('is-single', !cfg.showCancel);
        setProcessing(false);

        if (!open) { lastFocus = document.activeElement; }
        open = true;
        el.hidden = false;
        el.setAttribute('aria-hidden', 'false');
        document.documentElement.style.overflow = 'hidden';
        document.addEventListener('keydown', trap, true);
        statusEl.textContent = (cfg.title || 'Dialog') + '. ' + (cfg.message || '');
        requestAnimationFrame(function () {
            var target = cfg.focus === 'confirm' || !cfg.showCancel ? confirmBtn : cancelBtn;
            try { target.focus(); } catch (e) {}
        });
    }

    function restoreFocus() {
        var target = lastFocus && document.contains(lastFocus) ? lastFocus : document.body;
        try { target.focus(); } catch (e) {}
        lastFocus = null;
    }

    function dismiss() {
        if (processing || !state.cfg) { return; }
        var cfg = state.cfg, settle = state.settle;
        state = { cfg: null, settle: null };
        hideEl(); restoreFocus();
        if (settle) { settle(cfg.dismissValue); }
    }

    // Present one dialog and resolve when it closes. Handlers read the live state,
    // so no listeners are added per call (prevents duplicate listeners).
    function present(cfg) {
        return new Promise(function (resolve) {
            var settled = false;
            state = {
                cfg: cfg,
                settle: function (v) { if (!settled) { settled = true; resolve(v); } },
            };
            showEl(cfg);
        });
    }

    cancelBtn.addEventListener('click', dismiss);
    closeBtn.addEventListener('click', dismiss);
    el.addEventListener('mousedown', function (e) { if (e.target === el) { dismiss(); } });
    confirmBtn.addEventListener('click', function () {
        var cfg = state.cfg;
        if (!cfg || typeof cfg.onConfirm !== 'function') { return; }
        if (processing || (cfg.select && !selectEl.reportValidity())) { return; }
        cfg.onConfirm();
    });

    window.hvModal = {
        /**
         * Confirmation dialog. With opts.action (async), the dialog stays open and
         * shows a spinner while it runs; on success it closes and (if opts.success)
         * shows a success dialog, resolving with the action result. Cancel/close/
         * Escape/backdrop resolve false and run nothing.
         */
        confirm: function (opts) {
            opts = opts || {};
            var variant = opts.variant || 'danger';
            return present({
                variant: variant,
                icon: opts.icon || (variant === 'danger' ? 'danger' : 'question'),
                title: opts.title || 'Are you sure?',
                message: opts.message || '',
                confirmText: opts.confirmText || 'Delete',
                cancelText: opts.cancelText || 'Cancel',
                showCancel: true,
                focus: 'cancel',
                dismissValue: false,
                select: opts.select,
                onConfirm: function () {
                    var settle = state.settle;
                    if (typeof opts.action !== 'function') { hideEl(); restoreFocus(); settle(true); return; }
                    setProcessing(true);
                    var selection = selectEl.value;
                    Promise.resolve().then(function () { return opts.action(selection); }).then(function (result) {
                        if (result && result.ok === false) { showError(result.error); setProcessing(false); return; }
                        hideEl();
                        var after = opts.success ? window.hvModal.success(opts.success) : Promise.resolve();
                        after.then(function () { restoreFocus(); settle(result || true); });
                    }).catch(function (e) {
                        showError((e && e.message) || 'Network error. Please try again.');
                        setProcessing(false);
                    });
                },
            });
        },
        /** Success dialog with a single Done button. Resolves when dismissed. */
        success: function (opts) {
            opts = opts || {};
            return present({
                variant: 'success', icon: 'success',
                title: opts.title || 'Done', message: opts.message || '',
                confirmText: opts.doneText || 'Done', showCancel: false, focus: 'confirm',
                dismissValue: undefined,
                onConfirm: function () { var s = state.settle; hideEl(); restoreFocus(); s(); },
            });
        },
        /** Error dialog with a single Close button. Resolves when dismissed. */
        error: function (opts) {
            opts = opts || {};
            return present({
                variant: 'danger', icon: 'danger',
                title: opts.title || 'Something went wrong', message: opts.message || '',
                confirmText: opts.doneText || 'Close', showCancel: false, focus: 'confirm',
                dismissValue: undefined,
                onConfirm: function () { var s = state.settle; hideEl(); restoreFocus(); s(); },
            });
        },
    };
})();
</script>
