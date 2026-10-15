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
.hv-slide-mv { width: 100%; max-width: 580px; }
.hv-slide-mv .hv-mv-label { font-weight: 700; letter-spacing: .12em; text-transform: uppercase; font-size: 13px; color: var(--gold-soft); margin-bottom: 10px; }
.hv-slide-mv .hv-mv-box { background: rgba(255,255,255,.1); border: 1px solid rgba(255,255,255,.22); border-radius: 14px; padding: 16px; font-size: 13px; line-height: 1.65; color: #fff; text-align: left; }
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
.hv-login-extras a { color: var(--maroon); font-weight: 600; text-decoration: none; }
.hv-login-extras a:hover { text-decoration: underline; }
.hv-login-extras label { display: flex; align-items: center; gap: 8px; color: var(--ink); }
.hv-login-extras .form-check-input { margin: 0; }
.hv-login-card .btn-maroon { min-height: 48px; border-radius: 11px; font-size: 14px; font-weight: 700; letter-spacing: .05em; }
.hv-login-help { color: var(--muted); font-size: 12.5px; line-height: 1.6; margin: 22px 4px 0; }
.hv-login-help strong { font-weight: 600; color: var(--maroon); }
.hv-login-alert { text-align: left; margin-bottom: 16px; }

@media (max-width: 991.98px) {
    .hv-auth-wrap { display: flex; flex-direction: column; }
    .hv-auth-right { order: 1; margin: 0; padding: 34px 24px; border-radius: 0; box-shadow: none; }
    .hv-auth-left { order: 2; min-height: 320px; width: 100%; }
    .hv-slide-content { padding: 34px 26px 72px; }
    .hv-slide-mv { max-width: 660px; }
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

        {{-- Slide 1: storefront --}}
        <div class="hv-slide active" style="background-image:url('{{ asset('assets/img/carousel/slide-storefront.png') }}')">
            <div class="hv-slide-content">
                <div class="hv-slide-brand">HIDDEN VIETNAM</div>
                <div class="hv-slide-script">Affordable Vietnamese<br>Street Food</div>
            </div>
        </div>

        {{-- Slide 2: Mission & Vision --}}
        <div class="hv-slide" style="background-image:url('{{ asset('assets/img/carousel/slide-interior.png') }}')">
            <div class="hv-slide-content">
                <div class="hv-slide-brand mb-3">HIDDEN VIETNAM</div>
                <div class="hv-slide-mv">
                    <div class="row g-3">
                        <div class="col-6">
                            <div class="hv-mv-label">Mission</div>
                            <div class="hv-mv-box">
                                To bring the authentic taste and warmth of Vietnam to every Filipino table
                                by offering affordable, high-quality Vietnamese street food in a welcoming
                                and culturally immersive dining environment. We are committed to preserving
                                the rich culinary traditions of Vietnam while adapting to the Filipino palate,
                                creating a unique fusion experience that celebrates both cultures.
                            </div>
                        </div>
                        <div class="col-6">
                            <div class="hv-mv-label">Vision</div>
                            <div class="hv-mv-box">
                                To become the leading Vietnamese street food franchise in the Philippines,
                                recognized for our commitment to authentic flavors, exceptional customer
                                service, and sustainable business practices. We envision a network of
                                thriving franchise locations across the country, each serving as a cultural
                                bridge between Vietnam and the Philippines through food.
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        {{-- Slide 3: logo lockup --}}
        <div class="hv-slide" style="background:linear-gradient(135deg,var(--maroon-dark),var(--maroon))">
            <div class="hv-slide-content">
                <img src="{{ asset('assets/img/logo.jpg') }}" style="width:120px;height:120px;border-radius:50%;border:3px solid var(--gold);object-fit:cover;margin-bottom:1.25rem;"
                     alt="Hidden Vietnam logo" onerror="this.src='https://placehold.co/120x120/5C1010/D4A94A?text=HV'">
                <div class="hv-slide-brand">HIDDEN VIETNAM</div>
                <div class="hv-slide-script">Affordable Vietnamese<br>Street Food</div>
            </div>
        </div>

        {{-- Dot indicators --}}
        <div class="hv-dots">
            <button type="button" class="hv-dot active" data-slide="0" aria-label="Show Hidden Vietnam storefront" aria-current="true"></button>
            <button type="button" class="hv-dot" data-slide="1" aria-label="Show mission and vision" aria-current="false"></button>
            <button type="button" class="hv-dot" data-slide="2" aria-label="Show Hidden Vietnam brand" aria-current="false"></button>
        </div>
    </section>

    {{-- ═══ RIGHT: floating sign-in card ════════════════ --}}
    <section class="hv-auth-right" aria-labelledby="loginTitle">
        <div class="hv-login-content">
            <img src="{{ asset('assets/img/logo.jpg') }}" class="hv-login-logo" alt="Hidden Vietnam logo"
                 onerror="this.src='https://placehold.co/120x120/5C1010/D4A94A?text=HV'">
            <h1 id="loginTitle">WELCOME BACK!</h1>
            <p class="hv-login-sub">Login to your account</p>

            @if(session('success'))
                <div class="alert alert-success py-2 small hv-login-alert" role="status">{{ session('success') }}</div>
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

            <div class="hv-login-card">
                <form method="POST" action="{{ route('login.submit') }}" id="loginForm">
                    @csrf
                    <div class="mb-3">
                        <label for="loginEmail" class="form-label">Email address</label>
                        <div class="hv-input-icon">
                            <i class="bi bi-person" aria-hidden="true"></i>
                            <input id="loginEmail" type="email" name="email" class="form-control"
                                   placeholder="Enter your registered email" value="{{ old('email') }}" autocomplete="username" spellcheck="false" required>
                        </div>
                    </div>
                    <div>
                        <label for="loginPassword" class="form-label">Password</label>
                        <div class="hv-input-icon hv-password-field">
                            <i class="bi bi-lock" aria-hidden="true"></i>
                            <input id="loginPassword" type="password" name="password" class="form-control"
                                   placeholder="Enter your password" autocomplete="current-password" required>
                            <button type="button" class="hv-password-toggle" id="toggleLoginPassword" aria-controls="loginPassword" aria-label="Show password" aria-pressed="false">Show</button>
                        </div>
                    </div>
                    <div class="hv-login-extras">
                        <label for="loginRemember"><input id="loginRemember" type="checkbox" name="remember" class="form-check-input"> Remember me</label>
                        <a href="{{ route('password.request') }}">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn btn-maroon w-100">LOGIN</button>
                </form>
            </div>

            <p class="hv-login-help">
                <strong>Forgot your password or need access?</strong><br>
                Contact your Owner or Admin to reset your password or check your account and branch assignment.
            </p>
        </div>
    </section>
</main>
@endsection

@push('scripts')
<script>
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
        if (!motion.matches && !document.hidden) timer = setInterval(() => goTo(current + 1), 7000);
    }
    dots.forEach(dot => dot.addEventListener('click', () => { goTo(Number(dot.dataset.slide)); startAuto(); }));
    panel.addEventListener('mouseenter', stopAuto);
    panel.addEventListener('mouseleave', startAuto);
    panel.addEventListener('focusin', stopAuto);
    panel.addEventListener('focusout', event => { if (!panel.contains(event.relatedTarget)) startAuto(); });
    document.addEventListener('visibilitychange', startAuto);
    goTo(0); startAuto();
})();
</script>
@endpush
