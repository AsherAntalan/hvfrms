{{-- Shared auth loading modal (sign-in / sign-out). Centered cream card over a
     blurred backdrop. Rendered hidden; window.hvAuthLoader.show()/hide() drive it.
     The default title/message cover the sign-in transition and the JS-less path. --}}
<div id="hvAuthLoading" class="hv-auth-loading" role="dialog" aria-modal="true"
     aria-labelledby="hvAuthTitle" aria-describedby="hvAuthMsg" aria-hidden="true" hidden>
    <div class="hv-auth-loading-card" tabindex="-1">
        <img src="{{ asset('assets/img/logo.jpg') }}" alt="" class="hv-auth-loading-logo">
        <div class="hv-auth-spinner" aria-hidden="true"></div>
        <h2 class="hv-auth-loading-title" id="hvAuthTitle">Signing you in</h2>
        <p class="hv-auth-loading-msg" id="hvAuthMsg">Please wait while we prepare your workspace.</p>
        <button type="button" id="hvAuthRecover" class="hv-auth-loading-recover" hidden>
            Taking longer than expected — reload
        </button>
        <p class="hv-auth-loading-brand">HIDDEN VIETNAM</p>
        <span class="visually-hidden" role="status" aria-live="assertive" id="hvAuthStatus"></span>
    </div>
</div>
<script>
/* One controller shared by the login page (sign-in) and the app shell
   (destination transition + sign-out). Never cancels a form submit, so the
   normal POST still works with JavaScript disabled. */
(function () {
    var el = document.getElementById('hvAuthLoading');
    if (!el || window.hvAuthLoader) { return; }
    var card = el.querySelector('.hv-auth-loading-card');
    var titleEl = document.getElementById('hvAuthTitle');
    var msgEl = document.getElementById('hvAuthMsg');
    var statusEl = document.getElementById('hvAuthStatus');
    var recover = document.getElementById('hvAuthRecover');
    var lastFocus = null, slowTimer = null, trapHandler = null, open = false;

    function trap(e) { if (e.key === 'Tab' && open) { e.preventDefault(); if (card) { card.focus(); } } }

    window.hvAuthLoader = {
        show: function (title, message) {
            if (title && titleEl) { titleEl.textContent = title; }
            if (message && msgEl) { msgEl.textContent = message; }
            if (statusEl) { statusEl.textContent = (title || 'Loading') + '. Please wait.'; }
            if (!open) { lastFocus = document.activeElement; }
            open = true;
            el.hidden = false;
            el.setAttribute('aria-hidden', 'false');
            document.documentElement.style.overflow = 'hidden';
            // Let the browser paint the overlay before any navigation starts.
            requestAnimationFrame(function () { if (card) { card.focus(); } });
            trapHandler = trap;
            document.addEventListener('keydown', trapHandler, true);
            if (recover) {
                recover.hidden = true;
                clearTimeout(slowTimer);
                slowTimer = setTimeout(function () { recover.hidden = false; }, 15000);
            }
        },
        hide: function () {
            open = false;
            el.hidden = true;
            el.classList.remove('is-fading');
            el.setAttribute('aria-hidden', 'true');
            document.documentElement.style.overflow = '';
            if (trapHandler) { document.removeEventListener('keydown', trapHandler, true); trapHandler = null; }
            clearTimeout(slowTimer);
            if (recover) { recover.hidden = true; }
            if (statusEl) { statusEl.textContent = ''; }
            if (lastFocus && lastFocus.focus) { try { lastFocus.focus(); } catch (e) {} }
            lastFocus = null;
        },
    };

    if (recover) { recover.addEventListener('click', function () { window.location.reload(); }); }
})();
</script>
