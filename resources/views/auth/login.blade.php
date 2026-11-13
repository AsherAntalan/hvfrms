@extends('layouts.guest')

@section('title', 'Login')

@push('styles')
<style>
.hv-auth-wrap { position: relative; display: grid; grid-template-columns: minmax(0, 1fr) minmax(0, 1fr);
  min-height: 100svh; margin: 0; background: var(--cream); }

/* ── Left: full-bleed image slider ────────────────────── */
.hv-auth-left { position: relative; min-width: 0; overflow: hidden; background: var(--maroon-dark); }
.hv-slide { position: absolute; inset: 0; background-size: cover; background-position: center;
  opacity: 0; transition: opacity .8s ease; z-index: 1; }
.hv-slide.active { opacity: 1; z-index: 2; }
.hv-slide::after { content: ''; position: absolute; inset: 0;
  background: linear-gradient(180deg, rgba(92,16,16,.28), rgba(92,16,16,.34) 45%, rgba(0,0,0,.6)); }
.hv-slide-content { position: absolute; inset: 0; z-index: 1; display: flex; flex-direction: column;
  align-items: center; justify-content: center; padding: 48px 36px 96px; text-align: center; color: #fff; }
.hv-slide-brand { letter-spacing: .3em; text-indent: .3em; font-weight: 800; font-size: clamp(16px, 1.5vw, 22px); color: #fff; }
.hv-slide-script { font-family: Georgia, 'Times New Roman', serif; font-style: italic;
  font-size: clamp(26px, 2.8vw, 40px); line-height: 1.25; color: var(--gold-soft);
  text-shadow: 0 2px 18px rgba(0,0,0,.35); margin-top: 18px; }
/* Mission / Vision slides: brand script + gold label + transparent text panel, left-aligned */
.hv-slide-content--left { align-items: flex-start; justify-content: center; text-align: left;
  padding: 48px 48px 96px 56px; }
.hv-slide--mv::after { background:
  linear-gradient(110deg, rgba(20,4,4,.62), rgba(14,3,3,.52) 55%, rgba(0,0,0,.66)); }
.hv-slide-mv { width: 100%; max-width: 540px; }
.hv-mv-brand { font-family: Georgia, 'Times New Roman', serif; font-style: italic; font-weight: 400;
  font-size: clamp(34px, 4vw, 54px); line-height: .96; color: #fff;
  text-shadow: 0 2px 22px rgba(0,0,0,.55); margin: 0 0 20px; }
.hv-mv-label { font-family: Georgia, 'Times New Roman', serif; font-style: italic; font-weight: 700;
  font-size: clamp(22px, 2.4vw, 30px); color: var(--gold); margin: 0 0 14px;
  text-shadow: 0 2px 14px rgba(0,0,0,.5); }
/* Transparent panel — the dark cafe photo shows through; a light frost + text-shadow keep it legible */
.hv-mv-plate { border-radius: 16px; padding: clamp(20px, 2.2vw, 28px);
  background: linear-gradient(180deg, rgba(26,6,6,.22), rgba(16,4,4,.30));
  -webkit-backdrop-filter: blur(3px) saturate(1.05); backdrop-filter: blur(3px) saturate(1.05);
  border: 1px solid rgba(212,169,74,.28);
  box-shadow: 0 30px 60px -32px rgba(0,0,0,.65), inset 0 1px 0 rgba(255,255,255,.08); }
.hv-mv-copy { font-size: clamp(13.5px, 1vw, 15px); font-weight: 600; line-height: 1.72;
  color: #FDF4E6; margin: 0; text-align: justify; text-shadow: 0 1px 8px rgba(0,0,0,.78); }
.hv-dots { position: absolute; bottom: 30px; left: 0; right: 0; z-index: 10;
  display: flex; justify-content: center; align-items: center; gap: 10px; }
.hv-dot { width: 10px; height: 10px; border-radius: 50%; border: 1px solid rgba(255,255,255,.9);
  background: transparent; padding: 0; cursor: pointer; transition: background .2s, width .2s, border-radius .2s; }
.hv-dot.active { background: #fff; }
.hv-dot:focus-visible { outline: 2px solid var(--gold); outline-offset: 4px; }

/* ── Right: floating cream sign-in card (overlaps slider) ─ */
.hv-auth-right { min-width: 0; display: flex; align-items: center; justify-content: center;
  position: relative; z-index: 3; margin: 0 0 0 clamp(-64px, -2.6vw, -36px);
  padding: clamp(30px, 3vw, 56px) clamp(26px, 3vw, 60px); background: var(--cream);
  border-radius: 30px 0 0 30px; box-shadow: -20px 0 54px -30px rgba(60,8,8,.5); }
.hv-login-content { width: 100%; max-width: 420px; text-align: center; }
.hv-login-logo { width: 104px; height: 104px; border-radius: 50%; object-fit: cover;
  border: 3px solid var(--gold); box-shadow: 0 8px 26px rgba(92,16,16,.18); margin: 0 auto 20px; display: block; }
.hv-login-content h1 { color: var(--maroon); font-size: clamp(28px, 2.6vw, 38px); font-weight: 800;
  letter-spacing: -.035em; margin: 0 0 4px; }
.hv-login-content .hv-login-sub { color: var(--ink); font-size: 15px; margin: 0 0 22px; }
.hv-login-card { background: #fff; border: 1px solid var(--border); border-radius: 18px; padding: 26px;
  text-align: left; box-shadow: 0 1px 2px rgba(60,8,8,.04), 0 18px 44px -28px rgba(60,8,8,.3); }
.hv-login-card .form-label { font-size: 13px; font-weight: 600; color: var(--ink); margin-bottom: 7px; }
.hv-login-card .form-control { height: 48px; padding: 10px 14px 10px 42px; border: 1px solid var(--border);
  border-radius: 11px; font-size: 16px; }
.hv-login-card .form-control:focus { border-color: var(--maroon); box-shadow: 0 0 0 3px rgba(122,23,23,.1); }
.hv-login-card .hv-input-icon > i { left: 14px; pointer-events: none; }
.hv-password-field .form-control { padding-right: 74px; }
.hv-password-toggle { position: absolute; right: 8px; top: 50%; transform: translateY(-50%); border: 0;
  border-radius: 7px; background: transparent; color: var(--maroon); font-size: 12px; font-weight: 600; padding: 7px 10px; }
.hv-password-toggle:hover { background: var(--gold-soft); }
.hv-password-toggle:focus-visible { outline: 2px solid var(--gold); outline-offset: 1px; }
.hv-login-extras { display: flex; flex-wrap: wrap; justify-content: space-between; align-items: center;
  gap: 12px; font-size: 13px; margin: 20px 0; }
.hv-login-extras label { display: flex; align-items: center; gap: 8px; color: var(--ink); }
.hv-login-extras .form-check-input { margin: 0; }
.hv-login-card .btn-maroon { min-height: 48px; border-radius: 11px; font-size: 14px; font-weight: 700; letter-spacing: .05em; }
.hv-login-help { color: var(--ink); font-size: 13px; line-height: 1.9; margin: 22px 4px 0; }
.hv-login-forgot { color: var(--maroon); font-weight: 800; font-size: 15px; text-decoration: none; }
.hv-login-forgot:hover { text-decoration: underline; }
.hv-login-forgot:focus-visible { outline: 2px solid var(--gold); outline-offset: 2px; border-radius: 4px; }
.hv-login-alert { text-align: left; margin-bottom: 16px; }
/* Inline reset panels (forgot / OTP) share the login card */
.hv-panel-title { color: var(--maroon); font-size: 22px; font-weight: 800; margin: 0 0 6px; text-align: left; }
.hv-panel-sub { color: var(--muted, #6E635C); font-size: 13.5px; line-height: 1.5; margin: 0 0 18px; text-align: left; }
.hv-panel-sub strong { color: var(--ink, #2B2B2B); }
.hv-panel-hint { font-size: 11.5px; color: var(--muted, #6E635C); margin: 6px 2px 0; text-align: left; }
.hv-login-back { display: block; width: 100%; margin-top: 14px; border: none; background: none; color: var(--maroon); font-weight: 700; font-size: 14px; cursor: pointer; }
.hv-login-back:hover { text-decoration: underline; }
.hv-link-btn { border: none; background: none; color: var(--maroon); font-weight: 700; font-size: 14px; cursor: pointer; padding: 0; }
.hv-link-btn:hover { text-decoration: underline; }
.hv-otp-code { text-align: center; letter-spacing: .5em; font-size: 22px; font-weight: 700; }
.hv-otp-success-mark { display: grid; place-items: center; width: 76px; height: 76px; margin: 4px auto 18px;
  border-radius: 50%; background: #eaf5ed; color: #28743a; font-size: 44px; animation: hvPop .3s ease; }
@keyframes hvPop { from { transform: scale(.6); opacity: 0; } to { transform: scale(1); opacity: 1; } }
.hv-reset-success .modal-content { border: 1px solid var(--gold); border-radius: 22px;
  padding: 32px 24px; text-align: center; box-shadow: 0 24px 70px rgba(60,8,8,.25); }
.hv-reset-success-mark { display: grid; place-items: center; width: 76px; height: 76px;
  margin: 0 auto 20px; border-radius: 50%; background: #eaf5ed; color: #28743a; font-size: 40px; }
.hv-reset-success h2 { color: var(--maroon); font-size: 25px; font-weight: 800; margin-bottom: 12px; }
.hv-reset-success p { color: var(--ink); font-size: 15px; line-height: 1.65; }
.hv-reset-success .btn { min-height: 48px; border-radius: 11px; font-weight: 700; }

@media (max-width: 991.98px) {
    .hv-auth-wrap { display: flex; flex-direction: column; }
    .hv-auth-right { order: 1; margin: 0; padding: 34px 24px; border-radius: 0; box-shadow: none; }
    .hv-auth-left { order: 2; min-height: 540px; width: 100%; }
    .hv-slide-content { padding: 34px 26px 72px; }
    .hv-slide-mv { max-width: 560px; }
}
@media (max-width: 575.98px) {
    .hv-auth-right { margin: 0; padding: 26px 20px; }
    .hv-login-logo { width: 84px; height: 84px; }
    .hv-login-card { padding: 21px; border-radius: 15px; }
    .hv-auth-left { min-height: 240px; }
    .hv-slide-script { font-size: 25px; }
    .hv-slide-mv { display: none; }
}
@media (prefers-reduced-motion: reduce) { .hv-slide { transition: none; } }
</style>
@endpush

@section('content')
<main class="hv-auth-wrap" aria-label="Hidden Vietnam sign in">

    {{-- ═══ LEFT: image slider ══════════════════════════ --}}
    <section class="hv-auth-left" aria-label="About Hidden Vietnam" aria-roledescription="carousel">

        {{-- Slide 1: brand + tagline --}}
        <div class="hv-slide active" style="background-image:url('{{ asset('assets/img/carousel/slide-storefront.png') }}')">
            <div class="hv-slide-content">
                <div class="hv-slide-brand">HIDDEN VIETNAM</div>
                <div class="hv-slide-script">Affordable Vietnamese<br>Street Food</div>
            </div>
        </div>

        {{-- Slide 2: Mission --}}
        <div class="hv-slide hv-slide--mv" style="background-image:url('{{ asset('assets/img/carousel/slide-performance.png') }}')">
            <div class="hv-slide-content hv-slide-content--left">
                <div class="hv-slide-mv">
                    <div class="hv-mv-brand">Hidden<br>Vietnam</div>
                    <div class="hv-mv-label">Mission</div>
                    <div class="hv-mv-plate">
                        <p class="hv-mv-copy">
                            To bring the authentic taste and warmth of Vietnam to every Filipino table by offering
                            affordable, high-quality Vietnamese street food in a welcoming and culturally immersive
                            dining environment. We are committed to preserving the rich culinary traditions of
                            Vietnam while adapting to the Filipino palate, creating a unique fusion experience that
                            celebrates both cultures.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Slide 3: Vision --}}
        <div class="hv-slide hv-slide--mv" style="background-image:url('{{ asset('assets/img/carousel/slide-interior.png') }}')">
            <div class="hv-slide-content hv-slide-content--left">
                <div class="hv-slide-mv">
                    <div class="hv-mv-brand">Hidden<br>Vietnam</div>
                    <div class="hv-mv-label">Vision</div>
                    <div class="hv-mv-plate">
                        <p class="hv-mv-copy">
                            To become the leading Vietnamese street food franchise in the Philippines, recognized
                            for our commitment to authentic flavors, exceptional customer service, and sustainable
                            business practices. We envision a network of thriving franchise locations across the
                            country, each serving as a cultural bridge between Vietnam and the Philippines through
                            food.
                        </p>
                    </div>
                </div>
            </div>
        </div>

        {{-- Dot indicators --}}
        <div class="hv-dots">
            <button type="button" class="hv-dot active" data-slide="0" aria-label="Show Hidden Vietnam tagline" aria-current="true"></button>
            <button type="button" class="hv-dot" data-slide="1" aria-label="Show Hidden Vietnam mission" aria-current="false"></button>
            <button type="button" class="hv-dot" data-slide="2" aria-label="Show Hidden Vietnam vision" aria-current="false"></button>
        </div>
    </section>

    {{-- ═══ RIGHT: floating sign-in card ════════════════ --}}
    <section class="hv-auth-right" aria-labelledby="loginTitle">
        <div class="hv-login-content">
            <img src="{{ asset('assets/img/logo.jpg') }}" class="hv-login-logo" alt="Hidden Vietnam logo"
                 onerror="this.src='https://placehold.co/120x120/5C1010/D4A94A?text=HV'">
            <h1 id="loginTitle">WELCOME</h1>
            <p class="hv-login-sub">Login to your account</p>
            <p class="small text-muted mb-3"><strong>Note:</strong> Too many failed attempts will temporarily lock access.</p>

            @if(session('success'))
                <div id="resetSuccessNotice" class="alert alert-success py-2 small hv-login-alert" role="status">{{ session('success') }}</div>
            @endif
            @if(session('warning'))
                <div class="alert alert-warning py-2 small hv-login-alert" role="alert">{{ session('warning') }}</div>
            @endif
            @if(session('error'))
                <div class="alert alert-danger py-2 small hv-login-alert" role="alert">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="alert alert-danger py-2 small hv-login-alert" role="alert">{{ $errors->first() }}</div>
            @endif

            <div class="hv-login-card" id="loginCard"
                 data-url-email="{{ route('password.email') }}"
                 data-url-check="{{ route('password.otp.check') }}"
                 data-url-otp="{{ route('password.otp.update') }}"
                 data-url-resend="{{ route('password.otp.resend') }}">

                {{-- ── Panel: sign in ── --}}
                <div id="panelLogin">
                    <form method="POST" action="{{ route('login.submit') }}" id="loginForm">
                        @csrf
                        <div class="mb-3">
                            <label for="loginUsername" class="form-label">Username or email</label>
                            <div class="hv-input-icon">
                                <i class="ph ph-user" aria-hidden="true"></i>
                                <input id="loginUsername" type="text" name="login" class="form-control"
                                       placeholder="Enter your username or email" value="{{ old('login') }}" autocomplete="username" spellcheck="false" autocapitalize="none" required>
                            </div>
                        </div>
                        <div>
                            <label for="loginPassword" class="form-label">Password</label>
                            <div class="hv-input-icon hv-password-field">
                                <i class="ph ph-lock" aria-hidden="true"></i>
                                <input id="loginPassword" type="password" name="password" class="form-control"
                                       placeholder="Enter your password" autocomplete="current-password" required>
                                <button type="button" class="hv-password-toggle" id="toggleLoginPassword" aria-controls="loginPassword" aria-label="Show password" aria-pressed="false">Show</button>
                            </div>
                        </div>
                        <div class="hv-login-extras">
                            <label for="loginRemember"><input id="loginRemember" type="checkbox" name="remember" class="form-check-input"> Remember me</label>
                            @if(session('show_recovery'))
                            <a href="#" class="hv-login-forgot" id="forgotToggle">Forgot password?</a>
                            @endif
                        </div>
                        <button type="submit" class="btn btn-maroon w-100">LOGIN</button>
                    </form>
                </div>

                {{-- ── Panel: forgot (email → code) ── --}}
                <div id="panelForgot" hidden>
                    <h2 class="hv-panel-title">Reset your password</h2>
                    <p class="hv-panel-sub">Enter your registered email and we&rsquo;ll send you a 6-digit verification code.</p>
                    <div id="forgotMsg" class="alert py-2 small hv-login-alert" role="alert" hidden></div>
                    <div class="mb-3">
                        <label for="forgotEmail" class="form-label">Email address</label>
                        <div class="hv-input-icon">
                            <i class="ph ph-envelope-simple" aria-hidden="true"></i>
                            <input id="forgotEmail" type="email" class="form-control" placeholder="you@example.com" autocomplete="email">
                        </div>
                    </div>
                    <button type="button" class="btn btn-maroon w-100" id="sendCodeBtn">Send verification code</button>
                    <button type="button" class="hv-login-back" id="forgotBack">&larr; Back to sign in</button>
                </div>

                {{-- ── Panel: code + new password ── --}}
                <div id="panelOtp" hidden>
                    <h2 class="hv-panel-title">Enter your code</h2>
                    <p class="hv-panel-sub">We sent a 6-digit code to <strong id="otpEmailLabel"></strong>. It expires in 10 minutes.</p>
                    <div id="otpMsg" class="alert py-2 small hv-login-alert" role="alert" hidden></div>
                    <div class="mb-3">
                        <label for="otpCode" class="form-label">Verification code</label>
                        <input id="otpCode" type="text" inputmode="numeric" maxlength="6" class="form-control hv-otp-code" placeholder="000000" autocomplete="one-time-code">
                    </div>
                    {{-- Step 1: verify the code. New-password fields stay hidden until the code is valid. --}}
                    <button type="button" class="btn btn-maroon w-100" id="verifyCodeBtn">Verify code</button>
                    {{-- Step 2: only revealed after a valid code. --}}
                    <div id="otpPwStep" hidden>
                        <div class="mb-3 mt-3">
                            <label for="otpPassword" class="form-label">New password</label>
                            <div class="hv-input-icon hv-password-field">
                                <i class="ph ph-lock" aria-hidden="true"></i>
                                <input id="otpPassword" type="password" class="form-control" placeholder="New password" autocomplete="new-password">
                                <button type="button" class="hv-password-toggle" data-target="otpPassword" aria-label="Show password" aria-pressed="false">Show</button>
                            </div>
                            <p class="hv-panel-hint">8+ chars with upper &amp; lower case, a number, and a symbol.</p>
                        </div>
                        <div class="mb-3">
                            <label for="otpConfirm" class="form-label">Confirm new password</label>
                            <div class="hv-input-icon">
                                <i class="ph ph-lock" aria-hidden="true"></i>
                                <input id="otpConfirm" type="password" class="form-control" placeholder="Re-enter new password" autocomplete="new-password">
                            </div>
                        </div>
                        <button type="button" class="btn btn-maroon w-100" id="resetBtn">Reset password</button>
                    </div>
                    <div class="hv-login-extras" style="justify-content:space-between;">
                        <button type="button" class="hv-link-btn" id="resendBtn">Resend code</button>
                        <button type="button" class="hv-link-btn" id="otpBack">Back to sign in</button>
                    </div>
                </div>

                {{-- ── Panel: success ── --}}
                <div id="panelSuccess" hidden style="text-align:center;">
                    <div class="hv-otp-success-mark" aria-hidden="true"><i class="ph ph-check-circle"></i></div>
                    <h2 class="hv-panel-title" style="text-align:center;">Password reset!</h2>
                    <p class="hv-panel-sub" id="successMsg" style="text-align:center;">You can now sign in with your new password.</p>
                    <button type="button" class="btn btn-maroon w-100" id="successBack">Go to sign in</button>
                </div>
            </div>

            <p class="hv-login-help">
                Login with your Hidden Vietnam credentials. Contact your manager or admin if you need access or an account.
            </p>
        </div>
    </section>
</main>

@if(session('success'))
<div class="modal fade hv-reset-success" id="resetSuccessModal" tabindex="-1"
     aria-labelledby="resetSuccessTitle" aria-describedby="resetSuccessDescription resetSuccessHint" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="hv-reset-success-mark" aria-hidden="true"><i class="ph ph-check-circle"></i></div>
            <h2 id="resetSuccessTitle">Password reset successful!</h2>
            <p id="resetSuccessDescription">{{ session('success') }}</p>
            <p id="resetSuccessHint" class="small mb-4">Your reset link has been used. Sign in below with your new password.</p>
            <button type="button" class="btn btn-maroon w-100" data-bs-dismiss="modal">Go to sign in</button>
        </div>
    </div>
</div>
@endif

{{-- Auth loading modal (sign-in) --}}
@include('partials.auth-loading')
@endsection

@push('scripts')
<script>
(function () {
    var modalElement = document.getElementById('resetSuccessModal');
    if (!modalElement || !window.bootstrap || !window.bootstrap.Modal) { return; }
    var successModal = new bootstrap.Modal(modalElement);
    modalElement.addEventListener('shown.bs.modal', function () {
        modalElement.querySelector('[data-bs-dismiss="modal"]').focus();
    });
    modalElement.addEventListener('hidden.bs.modal', function () {
        document.getElementById('loginUsername').focus();
    });
    successModal.show();
    document.getElementById('resetSuccessNotice').hidden = true;
})();

(function () {
    const password = document.getElementById('loginPassword');
    const toggle = document.getElementById('toggleLoginPassword');
    toggle.addEventListener('click', function () {
        const visible = password.type === 'password';
        password.type = visible ? 'text' : 'password';
        toggle.textContent = visible ? 'Hide' : 'Show';
        toggle.setAttribute('aria-label', visible ? 'Hide password' : 'Show password');
        toggle.setAttribute('aria-pressed', visible ? 'true' : 'false');
    });

    const slides = [...document.querySelectorAll('.hv-slide')];
    const dots = [...document.querySelectorAll('.hv-dot')];
    const panel = document.querySelector('.hv-auth-left');
    const motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    let current = 0, timer = null;

    function goTo(index) {
        current = (index + slides.length) % slides.length;
        slides.forEach((slide, number) => {
            slide.classList.toggle('active', number === current);
            slide.setAttribute('aria-hidden', number === current ? 'false' : 'true');
            dots[number].classList.toggle('active', number === current);
            dots[number].setAttribute('aria-current', number === current ? 'true' : 'false');
        });
    }
    function stopAuto() { if (timer) clearInterval(timer); timer = null; }
    function startAuto() {
        stopAuto();
        if (!motion.matches && !document.hidden) timer = setInterval(() => goTo(current + 1), 4000);
    }
    dots.forEach(dot => dot.addEventListener('click', () => { goTo(Number(dot.dataset.slide)); startAuto(); }));
    panel.addEventListener('mouseenter', stopAuto);
    panel.addEventListener('mouseleave', startAuto);
    panel.addEventListener('focusin', stopAuto);
    panel.addEventListener('focusout', event => { if (!panel.contains(event.relatedTarget)) startAuto(); });
    document.addEventListener('visibilitychange', startAuto);
    goTo(0); startAuto();
})();

/* ── Login loading transition ───────────────────────────────────
   On submit (after native validation passes) show the loading modal, block
   duplicate submits, and flag a one-time transition so the destination page
   keeps it visible until ready. The normal POST is never cancelled, so
   JS-disabled login still works. */
(function () {
    var form = document.getElementById('loginForm');
    if (!form) { return; }
    var submitting = false;

    // Arriving on the login page clears any stale transition state (failed
    // login reload, normal visit, or bfcache restore) so controls never stick.
    function reset() {
        submitting = false;
        try { sessionStorage.removeItem('hv-signing-in'); } catch (e) {}
        if (window.hvAuthLoader) { window.hvAuthLoader.hide(); }
        form.removeAttribute('aria-busy');
        var b = form.querySelector('[type=submit]');
        if (b) { b.disabled = false; }
    }
    reset();
    window.addEventListener('pageshow', function (e) { if (e.persisted) { reset(); } });

    form.addEventListener('submit', function () {
        if (submitting) { return; }              // prevent duplicate submissions
        submitting = true;
        try { sessionStorage.setItem('hv-signing-in', '1'); } catch (e) {}
        if (window.hvAuthLoader) {
            window.hvAuthLoader.show('Signing you in', 'Please wait while we prepare your workspace.');
        }
        form.setAttribute('aria-busy', 'true');
        var btn = form.querySelector('[type=submit]');
        if (btn) { btn.disabled = true; }
        // No preventDefault: the normal POST proceeds with all fields intact.
    });
})();

/* ── Inline password reset: forgot → OTP → new password, all on this page.
   No navigation, no new tab — panels swap and submit over fetch. ── */
(function () {
    var card = document.getElementById('loginCard');
    if (!card) { return; }
    var tokenEl = document.querySelector('#loginForm input[name="_token"]');
    var CSRF = tokenEl ? tokenEl.value : '';
    var panels = { login: document.getElementById('panelLogin'), forgot: document.getElementById('panelForgot'), otp: document.getElementById('panelOtp'), success: document.getElementById('panelSuccess') };
    function show(name) { Object.keys(panels).forEach(function (k) { panels[k].hidden = (k !== name); }); }
    function msg(el, text, type) {
        if (!text) { el.hidden = true; return; }
        el.textContent = text;
        el.className = 'alert py-2 small hv-login-alert alert-' + (type || 'info');
        el.hidden = false;
    }
    function errText(j) {
        if (j && j.errors) { return Object.keys(j.errors).map(function (k) { return j.errors[k][0]; }).join(' '); }
        return (j && (j.error || j.message)) || 'Something went wrong. Please try again.';
    }
    function post(url, data) {
        var body = new FormData();
        Object.keys(data).forEach(function (k) { body.append(k, data[k]); });
        body.append('_token', CSRF);
        return fetch(url, { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: body })
            .then(function (r) {
                // Parse via text so a non-JSON response (419 session expiry, 429
                // throttle, 500) shows a clear message instead of "Network error".
                return r.text().then(function (t) {
                    var j;
                    try { j = JSON.parse(t); } catch (e) {
                        j = { ok: false, error: r.status === 419 ? 'Your session expired. Reload the page and try again.'
                            : r.status === 429 ? 'Too many attempts. Please wait a moment and try again.'
                            : 'Unexpected server response (' + r.status + '). Please try again.' };
                    }
                    return { status: r.status, json: j };
                });
            });
    }

    var forgotToggle = document.getElementById('forgotToggle');
    if (forgotToggle) {
        forgotToggle.addEventListener('click', function (e) {
            e.preventDefault();
            msg(document.getElementById('forgotMsg'), '', '');
            show('forgot');
            document.getElementById('forgotEmail').focus();
        });
    }
    document.getElementById('forgotBack').addEventListener('click', function () { show('login'); });
    document.getElementById('otpBack').addEventListener('click', function () { show('login'); });
    document.getElementById('successBack').addEventListener('click', function () {
        show('login');
        var lu = document.getElementById('loginUsername'); if (lu) { lu.focus(); }
    });

    card.querySelectorAll('.hv-password-toggle[data-target]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var inp = document.getElementById(btn.getAttribute('data-target'));
            var vis = inp.type === 'password';
            inp.type = vis ? 'text' : 'password';
            btn.textContent = vis ? 'Hide' : 'Show';
            btn.setAttribute('aria-pressed', vis ? 'true' : 'false');
        });
    });

    var sendBtn = document.getElementById('sendCodeBtn');
    sendBtn.addEventListener('click', function () {
        var email = document.getElementById('forgotEmail').value.trim();
        var fm = document.getElementById('forgotMsg');
        if (!email) { msg(fm, 'Enter your email address.', 'danger'); return; }
        var orig = sendBtn.textContent; sendBtn.disabled = true; sendBtn.textContent = 'Sending...';
        post(card.dataset.urlEmail, { email: email }).then(function (r) {
            sendBtn.disabled = false; sendBtn.textContent = orig;
            if (r.json && r.json.ok) {
                if (r.json.mode === 'link') {
                    msg(fm, 'If this is an admin account, a reset link was emailed. Check your inbox.', 'success');
                } else {
                    document.getElementById('otpEmailLabel').textContent = r.json.email || email;
                    msg(document.getElementById('otpMsg'), '', '');
                    document.getElementById('otpCode').value = '';
                    resetOtpSteps();
                    show('otp');
                    document.getElementById('otpCode').focus();
                }
            } else { msg(fm, errText(r.json), 'danger'); }
        }).catch(function () { sendBtn.disabled = false; sendBtn.textContent = orig; msg(fm, 'Network error. Please try again.', 'danger'); });
    });

    var pwStep = document.getElementById('otpPwStep');
    var verifyBtn = document.getElementById('verifyCodeBtn');
    // Return the OTP panel to step 1 (code only); new-password fields stay hidden.
    function resetOtpSteps() {
        pwStep.hidden = true;
        verifyBtn.hidden = false;
        document.getElementById('otpPassword').value = '';
        document.getElementById('otpConfirm').value = '';
    }

    // Step 1: verify the code. New-password fields appear ONLY if the code is valid.
    verifyBtn.addEventListener('click', function () {
        var om = document.getElementById('otpMsg');
        var code = document.getElementById('otpCode').value.trim();
        if (!/^\d{6}$/.test(code)) { msg(om, 'Enter the 6-digit code.', 'danger'); return; }
        var orig = verifyBtn.textContent; verifyBtn.disabled = true; verifyBtn.textContent = 'Verifying...';
        post(card.dataset.urlCheck, { code: code }).then(function (r) {
            verifyBtn.disabled = false; verifyBtn.textContent = orig;
            if (r.json && r.json.ok) {
                msg(om, '', '');
                verifyBtn.hidden = true;
                pwStep.hidden = false;
                document.getElementById('otpPassword').focus();
            } else {
                pwStep.hidden = true;           // keep new-password hidden on a bad code
                msg(om, errText(r.json), 'danger');
            }
        }).catch(function () { verifyBtn.disabled = false; verifyBtn.textContent = orig; msg(om, 'Network error. Please try again.', 'danger'); });
    });

    var resetBtn = document.getElementById('resetBtn');
    resetBtn.addEventListener('click', function () {
        var om = document.getElementById('otpMsg');
        var code = document.getElementById('otpCode').value.trim();
        var pw = document.getElementById('otpPassword').value;
        var cf = document.getElementById('otpConfirm').value;
        if (!/^\d{6}$/.test(code)) { msg(om, 'Enter the 6-digit code.', 'danger'); return; }
        if (pw !== cf) { msg(om, 'Passwords do not match.', 'danger'); return; }
        var orig = resetBtn.textContent; resetBtn.disabled = true; resetBtn.textContent = 'Resetting...';
        post(card.dataset.urlOtp, { code: code, password: pw, confirm_password: cf }).then(function (r) {
            resetBtn.disabled = false; resetBtn.textContent = orig;
            if (r.json && r.json.ok) {
                document.getElementById('successMsg').textContent = r.json.message || 'You can now sign in with your new password.';
                show('success');
                document.getElementById('successBack').focus();
            } else { msg(om, errText(r.json), 'danger'); }
        }).catch(function () { resetBtn.disabled = false; resetBtn.textContent = orig; msg(om, 'Network error. Please try again.', 'danger'); });
    });

    var resendBtn = document.getElementById('resendBtn');
    resendBtn.addEventListener('click', function () {
        var om = document.getElementById('otpMsg');
        resendBtn.disabled = true;
        post(card.dataset.urlResend, {}).then(function (r) {
            resendBtn.disabled = false;
            var ok = r.json && r.json.ok;
            if (ok) { document.getElementById('otpCode').value = ''; resetOtpSteps(); }
            msg(om, ok ? 'A new code was sent. Enter it to continue.' : errText(r.json), ok ? 'success' : 'danger');
        }).catch(function () { resendBtn.disabled = false; msg(om, 'Network error. Please try again.', 'danger'); });
    });
})();
</script>
@endpush
