@extends('layouts.guest')

@section('title', 'Account Recovery')

@push('styles')
<style>
:root{ --hv-maroon:#7A1717; --hv-maroon-dark:#5C1010; --hv-gold:#D4A94A; --hv-gold-soft:#F3E7CC;
  --hv-cream:#FBF7F0; --hv-ink:#2B2B2B; --hv-muted:#6E635C; --hv-border:#E3DED4; }

.hv-auth-wrap{ position:relative; display:grid; grid-template-columns:minmax(0,1fr) minmax(0,1fr);
  min-height:100svh; margin:0; background:var(--hv-cream); }

/* ── Left: image slider (mirrors the sign-in page) ── */
.hv-auth-left{ position:relative; min-width:0; overflow:hidden; background:var(--hv-maroon-dark); }
.hv-slide{ position:absolute; inset:0; background-size:cover; background-position:center; opacity:0; transition:opacity .8s ease; z-index:1; }
.hv-slide.active{ opacity:1; z-index:2; }
.hv-slide::after{ content:''; position:absolute; inset:0;
  background:linear-gradient(110deg, rgba(20,4,4,.62), rgba(14,3,3,.52) 55%, rgba(0,0,0,.66)); }
.hv-slide-content{ position:absolute; inset:0; z-index:1; display:flex; flex-direction:column;
  align-items:flex-start; justify-content:center; padding:48px 48px 96px 56px; text-align:left; color:#fff; }
.hv-mv-brand{ font-family:Georgia,'Times New Roman',serif; font-style:italic; font-size:clamp(34px,4vw,54px);
  line-height:.96; color:#fff; text-shadow:0 2px 22px rgba(0,0,0,.55); margin:0 0 20px; }
.hv-mv-label{ font-family:Georgia,'Times New Roman',serif; font-style:italic; font-weight:700;
  font-size:clamp(22px,2.4vw,30px); color:var(--hv-gold); margin:0 0 14px; text-shadow:0 2px 14px rgba(0,0,0,.5); }
.hv-mv-plate{ border-radius:16px; padding:clamp(20px,2.2vw,28px); max-width:540px;
  background:linear-gradient(180deg, rgba(26,6,6,.22), rgba(16,4,4,.30));
  -webkit-backdrop-filter:blur(3px) saturate(1.05); backdrop-filter:blur(3px) saturate(1.05);
  border:1px solid rgba(212,169,74,.28); box-shadow:0 30px 60px -32px rgba(0,0,0,.65); }
.hv-mv-copy{ font-size:clamp(13.5px,1vw,15px); font-weight:600; line-height:1.72; color:#FDF4E6; margin:0;
  text-align:justify; text-shadow:0 1px 8px rgba(0,0,0,.78); }
.hv-dots{ position:absolute; bottom:30px; left:0; right:0; z-index:10; display:flex; justify-content:center; gap:10px; }
.hv-dot{ width:10px; height:10px; border-radius:50%; border:1px solid rgba(255,255,255,.9); background:transparent; padding:0; cursor:pointer; transition:background .2s; }
.hv-dot.active{ background:#fff; }
.hv-dot:focus-visible{ outline:2px solid var(--hv-gold); outline-offset:4px; }

/* ── Right: floating cream card ── */
.hv-auth-right{ min-width:0; display:flex; align-items:center; justify-content:center; position:relative; z-index:3;
  margin:0 0 0 clamp(-64px,-2.6vw,-36px); padding:clamp(30px,3vw,56px) clamp(26px,3vw,60px);
  background:var(--hv-cream); border-radius:30px 0 0 30px; box-shadow:-20px 0 54px -30px rgba(60,8,8,.5); }
.hv-rec-content{ width:100%; max-width:520px; text-align:center; }
.hv-rec-logo{ width:96px; height:96px; border-radius:50%; object-fit:cover; border:3px solid var(--hv-gold);
  box-shadow:0 8px 26px rgba(92,16,16,.18); margin:0 auto 16px; display:block; }
.hv-rec-content h1{ color:var(--hv-maroon); font-size:clamp(24px,2.4vw,32px); font-weight:800; letter-spacing:-.03em; margin:0 0 4px; }
.hv-rec-sub{ color:var(--hv-ink); font-size:14px; margin:0 0 22px; }
.hv-rec-card{ background:#fff; border:1px solid var(--hv-border); border-radius:18px; padding:26px; text-align:left;
  box-shadow:0 1px 2px rgba(60,8,8,.04), 0 18px 44px -28px rgba(60,8,8,.3); }
.hv-rec-grid{ display:grid; grid-template-columns:1fr 1fr; gap:16px 18px; }
.hv-rec-field label{ display:block; font-size:13px; font-weight:600; color:var(--hv-ink); margin-bottom:7px; }
.hv-rec-ipt{ position:relative; }
.hv-rec-ipt > i{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--hv-muted); font-size:18px; pointer-events:none; }
.hv-rec-ipt input{ width:100%; height:48px; border:1px solid var(--hv-border); border-radius:11px;
  padding:10px 14px 10px 42px; font-size:15px; color:var(--hv-ink); background:#fff; transition:border-color .15s, box-shadow .15s; }
.hv-rec-ipt input:focus{ outline:none; border-color:var(--hv-maroon); box-shadow:0 0 0 3px rgba(122,23,23,.12); }
.hv-rec-ipt input:focus ~ i{ color:var(--hv-maroon); }
.hv-rec-ipt input.hv-rec-code{ letter-spacing:.16em; font-weight:700; text-transform:uppercase; }
.hv-rec-hint{ font-size:12px; color:var(--hv-muted); margin:14px 2px 0; }
.hv-rec-btn{ width:100%; min-height:48px; margin-top:18px; border:none; border-radius:11px; background:var(--hv-maroon);
  color:#fff; font-weight:700; font-size:14px; letter-spacing:.05em; cursor:pointer; display:inline-flex;
  align-items:center; justify-content:center; gap:8px; transition:background .15s; }
.hv-rec-btn:hover{ background:var(--hv-maroon-dark); }
.hv-rec-btn:focus-visible{ outline:3px solid var(--hv-gold); outline-offset:2px; }
.hv-rec-alert{ text-align:left; border-radius:11px; padding:11px 14px; font-size:13px; margin:0 0 16px; }
.hv-rec-alert.err{ background:#F6DED9; color:#8A2A1E; }
.hv-rec-alert.ok{ background:#E7F4EB; color:#1F6B37; }
.hv-rec-back{ display:inline-block; margin-top:18px; color:var(--hv-maroon); font-weight:700; font-size:14px; text-decoration:none; }
.hv-rec-back:hover{ text-decoration:underline; }

@media (max-width:991.98px){
  .hv-auth-wrap{ display:flex; flex-direction:column; }
  .hv-auth-right{ order:1; margin:0; padding:34px 24px; border-radius:0; box-shadow:none; }
  .hv-auth-left{ order:2; min-height:360px; width:100%; }
  .hv-slide-content{ padding:34px 26px 72px; }
}
@media (max-width:575.98px){
  .hv-rec-grid{ grid-template-columns:1fr; }
  .hv-rec-card{ padding:20px; }
  .hv-auth-left{ min-height:220px; }
}
@media (prefers-reduced-motion: reduce){ .hv-slide{ transition:none; } }
</style>
@endpush

@section('content')
<main class="hv-auth-wrap" aria-label="Hidden Vietnam account recovery">

    <section class="hv-auth-left" aria-label="About Hidden Vietnam" aria-roledescription="carousel">
        <div class="hv-slide active" style="background-image:url('{{ asset('assets/img/carousel/slide-storefront.png') }}')"></div>
        <div class="hv-slide" style="background-image:url('{{ asset('assets/img/carousel/slide-performance.png') }}')">
            <div class="hv-slide-content">
                <div class="hv-mv-brand">Hidden<br>Vietnam</div>
                <div class="hv-mv-label">Mission</div>
                <div class="hv-mv-plate"><p class="hv-mv-copy">To bring the authentic taste and warmth of Vietnam to every Filipino table through affordable, high-quality Vietnamese street food in a welcoming, culturally immersive setting.</p></div>
            </div>
        </div>
        <div class="hv-slide" style="background-image:url('{{ asset('assets/img/carousel/slide-interior.png') }}')">
            <div class="hv-slide-content">
                <div class="hv-mv-brand">Hidden<br>Vietnam</div>
                <div class="hv-mv-label">Vision</div>
                <div class="hv-mv-plate"><p class="hv-mv-copy">To become the leading Vietnamese street food franchise in the Philippines, recognized for authentic flavors, exceptional service, and sustainable practices.</p></div>
            </div>
        </div>
        <div class="hv-dots">
            <button type="button" class="hv-dot active" data-slide="0" aria-label="Slide 1"></button>
            <button type="button" class="hv-dot" data-slide="1" aria-label="Slide 2"></button>
            <button type="button" class="hv-dot" data-slide="2" aria-label="Slide 3"></button>
        </div>
    </section>

    <section class="hv-auth-right" aria-labelledby="recTitle">
        <div class="hv-rec-content">
            <img src="{{ asset('assets/img/logo.jpg') }}" class="hv-rec-logo" alt="Hidden Vietnam logo"
                 onerror="this.src='https://placehold.co/120x120/5C1010/D4A94A?text=HV'">
            <h1 id="recTitle">Account Recovery</h1>
            <p class="hv-rec-sub">Request a password reset</p>

            @if(session('email_verified'))
                <div class="hv-rec-alert ok" role="status">Email verified. Your request now awaits manager/admin approval. The reset link is sent once approved.</div>
            @endif
            @if(session('error'))
                <div class="hv-rec-alert err" role="alert">{{ session('error') }}</div>
            @endif
            @if($errors->any())
                <div class="hv-rec-alert err" role="alert">{{ $errors->first() }}</div>
            @endif

            <div class="hv-rec-card">
                <form method="POST" action="{{ route('password.code.update') }}" novalidate>
                    @csrf
                    <div class="hv-rec-grid">
                        <div class="hv-rec-field">
                            <label for="username">Username</label>
                            <div class="hv-rec-ipt">
                                <input type="text" id="username" name="username" value="{{ old('username', request('u')) }}" required
                                       autocomplete="username" spellcheck="false" autocapitalize="none" maxlength="50" autofocus>
                                <i class="ph ph-user" aria-hidden="true"></i>
                            </div>
                        </div>
                        <div class="hv-rec-field">
                            <label for="email">Personal email</label>
                            <div class="hv-rec-ipt">
                                <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email">
                                <i class="ph ph-envelope" aria-hidden="true"></i>
                            </div>
                        </div>
                        <div class="hv-rec-field" id="codeField" @if($presetAdmin ?? false) hidden @endif>
                            <label for="code">Recovery code</label>
                            <div class="hv-rec-ipt">
                                <input type="text" id="code" name="code" class="hv-rec-code"
                                       autocomplete="one-time-code" spellcheck="false" autocapitalize="characters"
                                       maxlength="20" placeholder="XXXX-XXXX-XXXX">
                                <i class="ph ph-key" aria-hidden="true"></i>
                            </div>
                        </div>
                        <div class="hv-rec-field" id="contactField" @if($presetAdmin ?? false) hidden @endif>
                            <label for="contact_number">Contact number</label>
                            <div class="hv-rec-ipt">
                                <input type="tel" id="contact_number" name="contact_number" value="{{ old('contact_number') }}"
                                       maxlength="30" autocomplete="tel" placeholder="09XX XXX XXXX">
                                <i class="ph ph-phone" aria-hidden="true"></i>
                            </div>
                        </div>
                    </div>
                    <p class="hv-rec-hint">@if($presetAdmin ?? false)Admin: enter your username and registered email — a reset link is emailed to the Hidden Vietnam account.@else Admin: username + email are enough — a reset link is emailed. Manager/Staff: add your recovery code and contact number for approval.@endif</p>
                    <button type="submit" class="hv-rec-btn"><i class="ph ph-paper-plane-tilt"></i> Submit request</button>
                </form>
            </div>

            <a href="{{ route('login') }}" class="hv-rec-back"><i class="ph ph-arrow-left"></i> Back to sign in</a>
        </div>
    </section>
</main>
@include('partials.auth-loading')
@endsection

@push('scripts')
<script>
(function () {
    var slides = [].slice.call(document.querySelectorAll('.hv-slide'));
    var dots = [].slice.call(document.querySelectorAll('.hv-dot'));
    if (!slides.length) { return; }
    var motion = window.matchMedia('(prefers-reduced-motion: reduce)');
    var current = 0, timer = null;
    function goTo(i) {
        current = (i + slides.length) % slides.length;
        slides.forEach(function (s, n) { s.classList.toggle('active', n === current); });
        dots.forEach(function (d, n) { d.classList.toggle('active', n === current); });
    }
    function stop() { if (timer) { clearInterval(timer); timer = null; } }
    function start() { stop(); if (!motion.matches && !document.hidden) { timer = setInterval(function () { goTo(current + 1); }, 4000); } }
    dots.forEach(function (d) { d.addEventListener('click', function () { goTo(Number(d.dataset.slide)); start(); }); });
    document.addEventListener('visibilitychange', start);
    goTo(0); start();
})();

/* Admin usernames only need username + email: hide the recovery code and
   contact number fields when the typed username is an active admin. */
(function () {
    var username = document.getElementById('username');
    var codeField = document.getElementById('codeField');
    var contactField = document.getElementById('contactField');
    var code = document.getElementById('code');
    var hint = document.querySelector('.hv-rec-hint');
    var form = username ? username.closest('form') : null;
    if (!username || !form || !codeField || !contactField) { return; }
    var tokenEl = form.querySelector('input[name=_token]');
    var url = @json(route('password.code.mode'));
    var timer = null, last = '';

    function setAdminMode(isAdmin) {
        codeField.hidden = isAdmin;
        contactField.hidden = isAdmin;
        if (isAdmin && code) { code.value = ''; }
        if (hint) {
            hint.textContent = isAdmin
                ? 'Admin: enter your username and registered email — a reset link is emailed to the Hidden Vietnam account.'
                : 'Manager/Staff: add your recovery code and contact number. Your request goes to your manager/admin for approval.';
        }
    }

    function check(onDone) {
        var name = username.value.trim();
        if (!name) { setAdminMode(false); last = ''; if (onDone) { onDone(); } return; }
        if (name === last) { if (onDone) { onDone(); } return; }
        last = name;
        fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': tokenEl ? tokenEl.value : '' },
            body: JSON.stringify({ username: name })
        }).then(function (r) { return r.ok ? r.json() : { admin: false }; })
          .then(function (d) { if (username.value.trim() === name) { setAdminMode(!!d.admin); } })
          .catch(function () {})
          .then(function () { if (onDone) { onDone(); } });
    }
    username.addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { check(); }, 350); });
    username.addEventListener('blur', function () { check(); });
    // On load with a carried username (from the login page), keep a loading
    // overlay up until the admin check resolves, so the admin/non-admin field
    // layout never visibly switches in front of the user.
    if (username.value.trim()) {
        if (window.hvAuthLoader) { window.hvAuthLoader.show('Preparing your request', 'Please wait a moment.'); }
        check(function () { if (window.hvAuthLoader) { window.hvAuthLoader.hide(); } });
    }
})();
</script>
@endpush

@if(session('recovery_requested'))
    @push('scripts')
        @include('partials.confirm-modal')
        <script>
            Promise.resolve(window.hvModal.success({
                title: 'Request submitted',
                message: 'If you are an Admin, a password-reset link was emailed to the registered Hidden Vietnam account — check Inbox and Spam. If you are a Manager or Staff, please wait for the approval of your request; the reset link is sent after your manager/admin confirms your identity.',
                doneText: 'Back to sign in'
            })).then(function () { window.location.href = @json(route('login')); });
        </script>
    @endpush
@endif
