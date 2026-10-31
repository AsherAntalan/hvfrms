@extends('layouts.guest')

@section('title', 'Forgot Password')

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
.hv-recover-desc{ color:var(--hv-muted); font-size:14px; line-height:1.55; margin:0 auto 22px; max-width:320px; }
.hv-recover-field{ text-align:left; margin-bottom:18px; }
.hv-recover-field label{ display:block; font-size:12.5px; font-weight:700; color:var(--hv-ink); margin:0 0 6px; }
.hv-recover-input{ position:relative; }
.hv-recover-input .ph{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--hv-muted); font-size:18px; }
.hv-recover-input input{ width:100%; height:48px; border:1.5px solid #E3DED4; border-radius:12px;
  padding:0 14px 0 42px; font-size:15px; color:var(--hv-ink); background:#fff; transition:border-color .15s, box-shadow .15s; }
.hv-recover-input input::placeholder{ color:#B6ADA0; }
.hv-recover-input input:hover{ border-color:#D4C9B4; }
.hv-recover-input input:focus{ outline:none; border-color:var(--hv-red); box-shadow:0 0 0 3px rgba(122,23,23,.14); }
.hv-recover-input input:focus + .ph{ color:var(--hv-red); }
.hv-recover-error{ color:#C0392B; font-size:12.5px; margin:7px 2px 0; text-align:left; }
.hv-recover-btn{ width:100%; height:48px; border:none; border-radius:12px; background:var(--hv-red); color:#fff;
  font-weight:700; font-size:15px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px;
  transition:background .15s; }
.hv-recover-btn:hover{ background:var(--hv-red-dark); }
.hv-recover-btn:focus-visible{ outline:3px solid var(--hv-gold); outline-offset:2px; }
.hv-recover-back{ display:inline-block; margin-top:18px; color:var(--hv-red); font-weight:700; font-size:14px; text-decoration:none; }
.hv-recover-back:hover{ text-decoration:underline; }
.hv-recover-back:focus-visible{ outline:2px solid var(--hv-gold); outline-offset:3px; border-radius:4px; }
.hv-recover-help{ margin:18px 0 0; font-size:12.5px; color:var(--hv-muted); }
.hv-recover-foot{ margin-top:20px; color:rgba(255,255,255,.72); font-size:11.5px; letter-spacing:.04em; }
/* confirmation state */
.hv-recover-mail{ width:84px; height:84px; margin:0 auto 18px; position:relative; display:flex; align-items:center; justify-content:center;
  background:var(--hv-gold-soft); border-radius:50%; }
.hv-recover-mail .ph-envelope-simple{ font-size:38px; color:var(--hv-red); }
.hv-recover-mail .hv-recover-check{ position:absolute; right:-2px; bottom:-2px; width:30px; height:30px; border-radius:50%;
  background:#3E7C4A; color:#fff; display:flex; align-items:center; justify-content:center; font-size:16px; border:3px solid #fff; }
.hv-recover-notice{ background:var(--hv-gold-soft); border:1px solid #E7D7AE; border-radius:12px; padding:11px 14px;
  font-size:13px; color:var(--hv-red-dark); font-weight:600; margin:6px 0 16px; }
.hv-recover-spam{ color:var(--hv-muted); font-size:13px; margin:0 0 20px; }
@media (max-width:480px){ .hv-recover-card{ padding:28px 20px 24px; border-radius:18px; } .hv-recover h1{ font-size:20px; } }
</style>
@endpush

@section('content')
<main class="hv-recover">
    <div class="hv-recover-card" role="region" aria-label="Password recovery">
        <img src="{{ asset('assets/img/logo.jpg') }}" alt="Hidden Vietnam logo" class="hv-recover-logo">
        <div class="hv-recover-brand">HIDDEN VIETNAM</div>
        <p class="hv-recover-sub">Franchise Management System</p>
        <div class="hv-recover-rule"></div>

        @if(session('reset_requested'))
            {{-- ── Confirmation state ── --}}
            <div class="hv-recover-mail" aria-hidden="true">
                <i class="ph ph-envelope-simple"></i>
                <span class="hv-recover-check"><i class="ph-bold ph-check"></i></span>
            </div>
            <h1>Check your email</h1>
            <p class="hv-recover-desc">If this email is registered, you&rsquo;ll receive a password reset link shortly.</p>
            <div class="hv-recover-notice">The reset link expires in 1 hour.</div>
            <p class="hv-recover-spam">Can&rsquo;t find the email? Check your spam folder.</p>
            <a href="{{ route('password.request') }}" class="hv-recover-btn" style="text-decoration:none;">
                <i class="ph ph-arrow-counter-clockwise"></i> Try another email
            </a>
            <div><a href="{{ route('login') }}" class="hv-recover-back">Back to sign in</a></div>
        @else
            {{-- ── Request-link form state ── --}}
            <h1>Forgot your password?</h1>
            <p class="hv-recover-desc">Enter your registered email and we&rsquo;ll send you a reset link.</p>

            <form method="POST" action="{{ route('password.email') }}" novalidate>
                @csrf
                <div class="hv-recover-field">
                    <label for="recoverEmail">Email address</label>
                    <div class="hv-recover-input">
                        <input type="email" id="recoverEmail" name="email" value="{{ old('email') }}"
                               placeholder="you@example.com" autocomplete="email" required autofocus
                               aria-describedby="{{ $errors->has('email') ? 'recoverEmailError' : '' }}"
                               @error('email') aria-invalid="true" @enderror>
                        <i class="ph ph-envelope-simple" aria-hidden="true"></i>
                    </div>
                    @error('email')
                        <p class="hv-recover-error" id="recoverEmailError" role="alert">{{ $message }}</p>
                    @enderror
                </div>
                <button type="submit" class="hv-recover-btn">
                    <i class="ph ph-paper-plane-tilt"></i> Send reset link
                </button>
            </form>

            <a href="{{ route('login') }}" class="hv-recover-back">Back to sign in</a>
            <p class="hv-recover-help">Need help? Contact your administrator.</p>
        @endif
    </div>
    <p class="hv-recover-foot">HVFRMS &bull; Secure account recovery</p>
</main>
@endsection
