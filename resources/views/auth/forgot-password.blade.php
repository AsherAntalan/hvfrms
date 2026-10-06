<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Forgot Password — {{ config('app.name') }}</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
    <link href="{{ asset('assets/css/theme.css') }}" rel="stylesheet">
    <style>
    .hv-auth-box {
        min-height: 100vh;
        display: flex;
        align-items: center;
        justify-content: center;
        background: linear-gradient(135deg, var(--maroon-dark), var(--maroon));
        padding: 2rem 1rem;
    }
    .hv-reset-card {
        background: #fff;
        border-radius: 20px;
        padding: 2.5rem 2rem;
        width: 100%;
        max-width: 440px;
        box-shadow: 0 12px 40px rgba(0,0,0,0.15);
    }
    .hv-reset-card h3 {
        color: var(--maroon);
        font-weight: 800;
    }
    .hv-reset-link {
        background: var(--gold-soft);
        border: 1px solid var(--gold);
        border-radius: 10px;
        padding: 12px 14px;
        font-size: 13px;
        word-break: break-all;
        margin-top: 1rem;
    }
    .hv-reset-link a { color: var(--maroon); font-weight: 600; }
    .hv-reset-link .copy-btn {
        font-size: 12px;
        margin-top: 8px;
    }
    </style>
</head>
<body>
<div class="hv-auth-box">
    <div class="hv-reset-card">
        <div class="text-center mb-4">
            <img src="{{ asset('assets/img/logo.jpg') }}" style="width:70px;height:70px;border-radius:50%;border:3px solid var(--gold);object-fit:cover;"
                 alt="Hidden Vietnam logo"
                 onerror="this.src='https://placehold.co/70x70/5C1010/D4A94A?text=HV'">
            <h3 class="mt-3 mb-1">Forgot Password</h3>
            <p class="text-muted small mb-0">Enter your email to receive a reset link</p>
        </div>

        @if($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        @if(session('success'))
            <div class="alert alert-success py-2 small">{{ session('success') }}</div>

            @if(session('reset_link') && config('app.debug'))
            <div class="hv-reset-link">
                <div class="text-muted" style="font-size:11px;margin-bottom:4px;">
                    <i class="bi bi-info-circle"></i> <strong>Dev only:</strong> Copy this link and open it in your browser:
                </div>
                <a href="{{ session('reset_link') }}" id="resetLink">{{ session('reset_link') }}</a>
                <div class="copy-btn">
                    <button type="button" class="btn btn-sm btn-outline-maroon" onclick="copyLink()">
                        <i class="bi bi-clipboard"></i> Copy Link
                    </button>
                </div>
            </div>
            <div class="alert alert-warning py-2 small mt-3">
                <i class="bi bi-clock"></i> This link expires in <strong>1 hour</strong>.
            </div>
            @endif
        @endif

        @if(!session('reset_link'))
        <form method="POST" action="{{ route('password.email') }}">
            @csrf
            <div class="hv-input-icon mb-3">
                <i class="bi bi-envelope"></i>
                <input type="email" name="email" class="form-control py-2" placeholder="Email address" required autofocus>
            </div>
            <button type="submit" class="btn btn-maroon w-100 py-2 fw-bold">
                <i class="bi bi-send me-1"></i> Send Reset Link
            </button>
        </form>
        @endif

        <div class="text-center mt-3">
            <a href="{{ route('login') }}" class="text-muted small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>
</div>

<script>
function copyLink() {
    const link = document.getElementById('resetLink').textContent;
    navigator.clipboard.writeText(link).then(function() {
        const btn = document.querySelector('.copy-btn button');
        btn.innerHTML = '<i class="bi bi-check2"></i> Copied!';
        setTimeout(function() {
            btn.innerHTML = '<i class="bi bi-clipboard"></i> Copy Link';
        }, 2000);
    });
}
</script>
</body>
</html>
