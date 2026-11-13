@extends('layouts.guest')

@section('title', 'Reset Password')

@push('styles')
<style>
:root{ --hv-red:#7A1717; --hv-red-dark:#5C1010; --hv-gold:#D4A94A; --hv-gold-soft:#F3E7CC; --hv-cream:#FBF7F0; --hv-ink:#2B2B2B; --hv-muted:#6E635C; }
.hv-recover{ min-height:100vh; display:flex; flex-direction:column; align-items:center; justify-content:center; background:var(--hv-red); padding:28px 16px; }
.hv-recover-card{ width:100%; max-width:430px; background:#fff; border-radius:22px; padding:34px 30px 28px; box-shadow:0 22px 60px -20px rgba(0,0,0,.45), 0 4px 14px -8px rgba(0,0,0,.25); text-align:center; }
.hv-recover-logo{ width:92px; height:92px; border-radius:50%; object-fit:cover; display:block; margin:0 auto 14px; border:3px solid var(--hv-gold); box-shadow:0 8px 22px rgba(92,16,16,.22); }
.hv-recover-brand{ color:var(--hv-red); font-weight:800; font-size:14px; letter-spacing:.16em; }
.hv-recover-sub{ color:var(--hv-muted); font-size:12.5px; margin:0 0 18px; }
.hv-recover-rule{ height:1px; background:linear-gradient(90deg,transparent,var(--hv-gold-soft),transparent); margin:0 0 20px; }
.hv-recover h1{ color:var(--hv-ink); font-size:22px; font-weight:800; letter-spacing:-.02em; margin:0 0 8px; }
.hv-recover-desc{ color:var(--hv-muted); font-size:14px; line-height:1.55; margin:0 auto 22px; max-width:330px; }
.hv-recover-desc strong{ color:var(--hv-ink); }
.hv-recover-field{ text-align:left; margin-bottom:16px; }
.hv-recover-field label{ display:block; font-size:12.5px; font-weight:700; color:var(--hv-ink); margin:0 0 6px; }
.hv-recover-input{ position:relative; }
.hv-recover-input .ph{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--hv-muted); font-size:18px; }
.hv-recover-input input{ width:100%; height:48px; border:1.5px solid #E3DED4; border-radius:12px; padding:0 14px 0 42px; font-size:15px; color:var(--hv-ink); background:#fff; transition:border-color .15s, box-shadow .15s; }
.hv-recover-input input::placeholder{ color:#B6ADA0; }
.hv-recover-input input:focus{ outline:none; border-color:var(--hv-red); box-shadow:0 0 0 3px rgba(122,23,23,.14); }
.hv-recover-input input:focus + .ph{ color:var(--hv-red); }
.hv-otp-input input{ text-align:center; letter-spacing:.5em; font-size:22px; font-weight:700; padding-left:14px; }
.hv-recover-error{ color:#C0392B; font-size:12.5px; margin:7px 2px 0; text-align:left; }
.hv-recover-btn{ width:100%; height:48px; border:none; border-radius:12px; background:var(--hv-red); color:#fff; font-weight:700; font-size:15px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px; transition:background .15s; }
.hv-recover-btn:hover{ background:var(--hv-red-dark); }
.hv-recover-btn:focus-visible{ outline:3px solid var(--hv-gold); outline-offset:2px; }
.hv-recover-back{ display:inline-block; margin-top:18px; color:var(--hv-red); font-weight:700; font-size:14px; text-decoration:none; }
.hv-recover-back:hover{ text-decoration:underline; }
.hv-recover-notice{ background:var(--hv-gold-soft); border:1px solid #E7D7AE; border-radius:12px; padding:10px 14px; font-size:13px; color:var(--hv-red-dark); font-weight:600; margin:0 0 16px; }
.hv-recover-resend{ margin:14px 0 0; font-size:13px; color:var(--hv-muted); }
.hv-recover-resend button{ border:none; background:none; color:var(--hv-red); font-weight:700; cursor:pointer; padding:0; font-size:13px; }
.hv-recover-foot{ margin-top:20px; color:rgba(255,255,255,.72); font-size:11.5px; letter-spacing:.04em; }
.hv-recover-hint{ font-size:11.5px; color:var(--hv-muted); margin:6px 2px 0; text-align:left; }
@media (max-width:480px){ .hv-recover-card{ padding:28px 20px 24px; border-radius:18px; } .hv-recover h1{ font-size:20px; } }
</style>
@endpush

@section('content')
<main class="hv-recover">
    <div class="hv-recover-card" role="region" aria-label="Reset password with code">
        <img src="{{ asset('assets/img/logo.jpg') }}" alt="Hidden Vietnam logo" class="hv-recover-logo">
        <div class="hv-recover-brand">HIDDEN VIETNAM</div>
        <p class="hv-recover-sub">Franchise Management System</p>
        <div class="hv-recover-rule"></div>

        <h1>Enter your code</h1>
        <p class="hv-recover-desc">We sent a 6-digit verification code to <strong>{{ $email }}</strong>. Enter it below and choose a new password.</p>

        @if(session('otp_sent'))
            <div class="hv-recover-notice">If the account is eligible, a code was sent. It expires in 10 minutes.</div>
        @endif

        <form method="POST" action="{{ route('password.otp.update') }}" novalidate>
            @csrf
            <div class="hv-recover-field">
                <label for="otpCode">Verification code</label>
                <div class="hv-recover-input hv-otp-input">
                    <input type="text" id="otpCode" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6"
                           autocomplete="one-time-code" placeholder="000000" required autofocus
                           @error('code') aria-invalid="true" @enderror>
                </div>
                @error('code')<p class="hv-recover-error" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="hv-recover-field">
                <label for="otpPassword">New password</label>
                <div class="hv-recover-input">
                    <input type="password" id="otpPassword" name="password" placeholder="New password"
                           autocomplete="new-password" required @error('password') aria-invalid="true" @enderror>
                    <i class="ph ph-lock-key" aria-hidden="true"></i>
                </div>
                <p class="hv-recover-hint">At least 8 characters with upper &amp; lower case, a number, and a symbol.</p>
                @error('password')<p class="hv-recover-error" role="alert">{{ $message }}</p>@enderror
            </div>

            <div class="hv-recover-field">
                <label for="otpConfirm">Confirm new password</label>
                <div class="hv-recover-input">
                    <input type="password" id="otpConfirm" name="confirm_password" placeholder="Re-enter new password"
                           autocomplete="new-password" required>
                    <i class="ph ph-lock-key" aria-hidden="true"></i>
                </div>
                @error('confirm_password')<p class="hv-recover-error" role="alert">{{ $message }}</p>@enderror
            </div>

            <button type="submit" class="hv-recover-btn">
                <i class="ph ph-check-circle"></i> Reset password
            </button>
        </form>

        <p class="hv-recover-resend">Didn&rsquo;t get a code?
            <form method="POST" action="{{ route('password.otp.resend') }}" style="display:inline;">@csrf<button type="submit">Resend code</button></form>
        </p>
        <a href="{{ route('login') }}" class="hv-recover-back">Back to sign in</a>
    </div>
    <p class="hv-recover-foot">HVFRMS &bull; Secure account recovery</p>
</main>
@endsection
