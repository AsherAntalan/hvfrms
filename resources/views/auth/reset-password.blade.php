@extends('layouts.guest')

@section('title', 'Reset Password')

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
.hv-recover-desc{ color:var(--hv-muted); font-size:14px; line-height:1.55; margin:0 auto 22px; max-width:330px; }
.hv-recover-field{ text-align:left; margin-bottom:16px; }
.hv-recover-field label{ display:block; font-size:12.5px; font-weight:700; color:var(--hv-ink); margin:0 0 6px; }
.hv-recover-input{ position:relative; }
.hv-recover-input > .ph-lock{ position:absolute; left:14px; top:50%; transform:translateY(-50%); color:var(--hv-muted); font-size:18px; }
.hv-recover-input input{ width:100%; height:48px; border:1.5px solid #E3DED4; border-radius:12px;
  padding:0 46px 0 42px; font-size:15px; color:var(--hv-ink); background:#fff; transition:border-color .15s, box-shadow .15s; }
.hv-recover-input input:hover{ border-color:#D4C9B4; }
.hv-recover-input input:focus{ outline:none; border-color:var(--hv-red); box-shadow:0 0 0 3px rgba(122,23,23,.14); }
.hv-recover-input input:focus ~ .ph-lock{ color:var(--hv-red); }
.hv-recover-toggle{ position:absolute; right:8px; top:50%; transform:translateY(-50%); width:34px; height:34px;
  border:none; background:transparent; color:var(--hv-muted); cursor:pointer; border-radius:8px; font-size:18px;
  display:flex; align-items:center; justify-content:center; }
.hv-recover-toggle:hover{ color:var(--hv-red); background:var(--hv-gold-soft); }
.hv-recover-toggle:focus-visible{ outline:2px solid var(--hv-gold); outline-offset:1px; }
.hv-recover-hint{ font-size:12px; color:var(--hv-muted); margin:6px 2px 0; }
.hv-recover-error{ color:#C0392B; font-size:12.5px; margin:7px 2px 0; text-align:left; }
.hv-recover-btn{ width:100%; height:48px; border:none; border-radius:12px; background:var(--hv-red); color:#fff;
  font-weight:700; font-size:15px; cursor:pointer; display:inline-flex; align-items:center; justify-content:center; gap:8px;
  transition:background .15s; margin-top:4px; text-decoration:none; }
.hv-recover-btn:hover{ background:var(--hv-red-dark); }
.hv-recover-btn:focus-visible{ outline:3px solid var(--hv-gold); outline-offset:2px; }
.hv-recover-back{ display:inline-block; margin-top:18px; color:var(--hv-red); font-weight:700; font-size:14px; text-decoration:none; }
.hv-recover-back:hover{ text-decoration:underline; }
.hv-recover-back:focus-visible{ outline:2px solid var(--hv-gold); outline-offset:3px; border-radius:4px; }
.hv-recover-foot{ margin-top:20px; color:rgba(255,255,255,.72); font-size:11.5px; letter-spacing:.04em; }
.hv-recover-warn{ width:84px; height:84px; margin:0 auto 18px; display:flex; align-items:center; justify-content:center;
  background:#F6DED9; border-radius:50%; }
.hv-recover-warn .ph{ font-size:38px; color:#C0392B; }
@media (max-width:480px){ .hv-recover-card{ padding:28px 20px 24px; border-radius:18px; } .hv-recover h1{ font-size:20px; } }
</style>
@endpush

@section('content')
<main class="hv-recover">
    <div class="hv-recover-card" role="region" aria-label="Reset password">
        <img src="{{ asset('assets/img/logo.jpg') }}" alt="Hidden Vietnam logo" class="hv-recover-logo">
        <div class="hv-recover-brand">HIDDEN VIETNAM</div>
        <p class="hv-recover-sub">Franchise Management System</p>
        <div class="hv-recover-rule"></div>

        @if($valid)
            <h1>Set a new password</h1>
            <p class="hv-recover-desc">Choose a new password for your HVFRMS account.</p>

            <form method="POST" action="{{ route('password.update') }}" novalidate>
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">

                <div class="hv-recover-field">
                    <label for="newPassword">New password</label>
                    <div class="hv-recover-input">
                        <input type="password" id="newPassword" name="password" minlength="8" required
                               autocomplete="new-password" autofocus aria-describedby="newPasswordHint"
                               @error('password') aria-invalid="true" @enderror>
                        <i class="ph ph-lock" aria-hidden="true"></i>
                        <button type="button" class="hv-recover-toggle" data-toggle="newPassword"
                                aria-label="Show password" aria-pressed="false">
                            <i class="ph ph-eye"></i>
                        </button>
                    </div>
                    <p class="hv-recover-hint" id="newPasswordHint">At least 8 characters.</p>
                    @error('password')<p class="hv-recover-error" role="alert">{{ $message }}</p>@enderror
                </div>

                <div class="hv-recover-field">
                    <label for="confirmPassword">Confirm new password</label>
                    <div class="hv-recover-input">
                        <input type="password" id="confirmPassword" name="confirm_password" minlength="8" required
                               autocomplete="new-password"
                               @error('confirm_password') aria-invalid="true" @enderror>
                        <i class="ph ph-lock" aria-hidden="true"></i>
                        <button type="button" class="hv-recover-toggle" data-toggle="confirmPassword"
                                aria-label="Show password" aria-pressed="false">
                            <i class="ph ph-eye"></i>
                        </button>
                    </div>
                    @error('confirm_password')<p class="hv-recover-error" role="alert">{{ $message }}</p>@enderror
                </div>

                @error('token')<p class="hv-recover-error" role="alert">{{ $message }}</p>@enderror

                <button type="submit" class="hv-recover-btn"><i class="ph ph-check-circle"></i> Reset password</button>
            </form>
            <a href="{{ route('login') }}" class="hv-recover-back">Back to sign in</a>
        @else
            <div class="hv-recover-warn" aria-hidden="true"><i class="ph ph-warning-circle"></i></div>
            <h1>Link no longer valid</h1>
            <p class="hv-recover-desc">{{ $error ?? 'This reset link is invalid or has expired.' }}</p>
            <a href="{{ route('password.request') }}" class="hv-recover-btn"><i class="ph ph-paper-plane-tilt"></i> Request a new link</a>
            <a href="{{ route('login') }}" class="hv-recover-back">Back to sign in</a>
        @endif
    </div>
    <p class="hv-recover-foot">HVFRMS &bull; Secure account recovery</p>
</main>
@endsection

@push('scripts')
<script>
document.querySelectorAll('.hv-recover-toggle').forEach(function(btn){
    btn.addEventListener('click', function(){
        var input = document.getElementById(btn.dataset.toggle);
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.setAttribute('aria-pressed', show ? 'true' : 'false');
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
        btn.querySelector('i').className = show ? 'ph ph-eye-slash' : 'ph ph-eye';
    });
});
</script>
@endpush
