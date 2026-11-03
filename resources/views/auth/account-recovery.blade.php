@extends('layouts.guest')

@section('title', 'Account Recovery')

@push('styles')
<style>
:root{ --hv-red:#7A1717; --hv-red-dark:#5C1010; --hv-gold:#D4A94A; --hv-gold-soft:#F3E7CC; --hv-cream:#FBF7F0; --hv-ink:#2B2B2B; --hv-muted:#6E635C; }
.hv-recover{ min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center;
  background:var(--hv-red); padding:28px 16px; }
.hv-recover-card{ width:100%; max-width:430px; background:#fff; border-radius:22px; padding:34px 30px 28px;
  box-shadow:0 22px 60px -20px rgba(0,0,0,.45), 0 4px 14px -8px rgba(0,0,0,.25); text-align:center; }
.hv-recover-logo{ width:92px; height:92px; border-radius:50%; object-fit:cover; display:block; margin:0 auto 14px;
  border:3px solid var(--hv-gold); box-shadow:0 8px 22px rgba(92,16,16,.22); }
.hv-recover-brand{ color:var(--hv-red); font-weight:800; font-size:14px; letter-spacing:.16em; }
.hv-recover-sub{ color:var(--hv-muted); font-size:12.5px; margin:0 0 18px; }
.hv-recover-rule{ height:1px; background:linear-gradient(90deg,transparent,var(--hv-gold-soft),transparent); margin:0 0 20px; }
.hv-recover h1{ color:var(--hv-ink); font-size:22px; font-weight:800; letter-spacing:-.02em; margin:0 0 8px; }
.hv-recover-desc{ color:var(--hv-muted); font-size:14px; line-height:1.55; margin:0 auto 22px; max-width:340px; }
.hv-recover-field{ text-align:left; margin-bottom:16px; }
.hv-recover-field label{ display:block; font-size:12.5px; font-weight:700; color:var(--hv-ink); margin:0 0 6px; }
.hv-recover-input{ position:relative; }
.hv-recover-input > .ph{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--hv-muted); font-size:18px; }
.hv-recover-input input{ width:100%; height:48px; border:1.5px solid #E3DED4; border-radius:12px;
  padding:0 46px 0 42px; font-size:15px; color:var(--hv-ink); background:#fff; transition:border-color .15s, box-shadow .15s; }
.hv-recover-input input:hover{ border-color:#D4C9B4; }
.hv-recover-input input:focus{ outline:none; border-color:var(--hv-red); box-shadow:0 0 0 3px rgba(122,23,23,.14); }
.hv-recover-input input:focus ~ .ph{ color:var(--hv-red); }
.hv-recover-input input.hv-recover-code{ letter-spacing:.18em; font-weight:700; text-transform:uppercase; }
.hv-recover-toggle{ position:absolute; right:8px; top:50%; transform:translateY(-50%); width:34px; height:34px;
  border:none; background:transparent; color:var(--hv-muted); cursor:pointer; border-radius:8px; font-size:18px;
  display:flex; align-items:center; justify-content:center; }
.hv-recover-toggle:hover{ color:var(--hv-red); background:var(--hv-gold-soft); }
.hv-recover-toggle:focus-visible{ outline:2px solid var(--hv-gold); outline-offset:1px; }
.hv-recover-hint{ font-size:12px; color:var(--hv-muted); margin:6px 2px 0; }
.hv-recover-alert{ background:#F6DED9; color:#8A2A1E; border-radius:12px; padding:11px 14px; font-size:12.5px;
  text-align:left; margin:0 0 18px; }
.hv-recover-btn{ width:100%; height:48px; border:none; border-radius:12px; background:var(--hv-red); color:#fff;
  font-weight:700; font-size:15px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px;
  transition:background .15s; margin-top:4px; text-decoration:none; }
.hv-recover-btn:hover{ background:var(--hv-red-dark); }
.hv-recover-btn:focus-visible{ outline:3px solid var(--hv-gold); outline-offset:2px; }
.hv-recover-back{ display:inline-block; margin-top:18px; color:var(--hv-red); font-weight:700; font-size:14px; text-decoration:none; }
.hv-recover-back:hover{ text-decoration:underline; }
.hv-recover-back:focus-visible{ outline:2px solid var(--hv-gold); outline-offset:3px; border-radius:4px; }
.hv-recover-foot{ margin-top:20px; color:rgba(255,255,255,.72); font-size:11.5px; letter-spacing:.04em; }
@media (max-width:480px){ .hv-recover-card{ padding:28px 20px 24px; border-radius:18px; } .hv-recover h1{ font-size:20px; } }
</style>
@endpush

@section('content')
<main class="hv-recover">
    <div class="hv-recover-card" role="region" aria-label="Account recovery">
        <img src="{{ asset('assets/img/logo.jpg') }}" alt="Hidden Vietnam logo" class="hv-recover-logo">
        <div class="hv-recover-brand">HIDDEN VIETNAM</div>
        <p class="hv-recover-sub">Franchise Management System</p>
        <div class="hv-recover-rule"></div>

        <h1>Request a password reset</h1>
        <p class="hv-recover-desc">Enter your username, recovery code, and an email you can access to begin your reset request.</p>
        <p class="hv-recover-hint mb-3">Staff: manager verifies identity, then admin approves. Manager: admin. Admin/Owner: super admin.</p>

        @if(session('recovery_requested'))
            <noscript><div class="alert alert-info small" role="status">Check your email. If your username and recovery code are valid, a verification email will be sent. Check your inbox and spam folder. If nothing arrives, retry later or contact your manager/admin.</div></noscript>
        @endif
        @if(session('email_verified'))
            <div class="alert alert-success small" role="status">Email verified. Staff must have their identity verified by their manager before admin approval. Managers contact admin for identity verification and approval. The reset link will be sent after approval.</div>
        @endif
        @if(session('error'))
            <div class="hv-recover-alert" role="alert">{{ session('error') }}</div>
        @endif
        @if($errors->any())
            <div class="hv-recover-alert" role="alert">{{ $errors->first() }}</div>
        @endif

        <form method="POST" action="{{ route('password.code.update') }}" novalidate>
            @csrf

            <div class="hv-recover-field">
                <label for="username">Username</label>
                <div class="hv-recover-input">
                    <input type="text" id="username" name="username" value="{{ old('username') }}" required
                           autocomplete="username" spellcheck="false" autocapitalize="none" maxlength="50" autofocus>
                    <i class="ph ph-user" aria-hidden="true"></i>
                </div>
            </div>

            <div class="hv-recover-field">
                <label for="code">Reset code</label>
                <div class="hv-recover-input">
                    <input type="text" id="code" name="code" class="hv-recover-code" required
                           autocomplete="one-time-code" spellcheck="false" autocapitalize="characters"
                           maxlength="20" placeholder="XXXX-XXXX-XXXX">
                    <i class="ph ph-key" aria-hidden="true"></i>
                </div>
                <p class="hv-recover-hint">12 characters. Case and dashes do not matter.</p>
            </div>

            <div class="hv-recover-field">
                <label for="email">Personal email for this request</label>
                <div class="hv-recover-input">
                    <input type="email" id="email" name="email" value="{{ old('email') }}" required maxlength="254" autocomplete="email">
                    <i class="ph ph-envelope" aria-hidden="true"></i>
                </div>
                <p class="hv-recover-hint">Used only for this recovery request. Your registered account email will not change.</p>
            </div>
            <div class="hv-recover-field">
                <label for="contact_number">Contact number for this request (optional)</label>
                <div class="hv-recover-input">
                    <input type="tel" id="contact_number" name="contact_number" value="{{ old('contact_number') }}" maxlength="30" autocomplete="tel" placeholder="09XX XXX XXXX">
                    <i class="ph ph-phone" aria-hidden="true"></i>
                </div>
                <p class="hv-recover-hint">So your manager/admin can contact you. This number is not verified and is not proof of identity.</p>
            </div>
            <button type="submit" class="hv-recover-btn"><i class="ph ph-paper-plane-tilt"></i> Submit reset request</button>
        </form>
        <a href="{{ route('login') }}" class="hv-recover-back">Back to sign in</a>
    </div>
    <p class="hv-recover-foot">HVFRMS &bull; Secure account recovery</p>
</main>
@endsection

@if(session('recovery_requested'))
    @push('scripts')
        @include('partials.confirm-modal')
        <script>
            window.hvModal.success({
                title: 'Check your email',
                message: 'If your username and recovery code are valid, a verification email will be sent. Check your Inbox and Spam folder, then open the link to verify your email. If nothing arrives, retry later or contact your manager/admin.',
                doneText: 'Got it'
            });
        </script>
    @endpush
@endif

