<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Reset Password — {{ config('app.name') }}</title>
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
    .hv-pw-toggle {
        position: absolute;
        right: 12px;
        top: 50%;
        transform: translateY(-50%);
        border: none;
        background: none;
        color: var(--muted);
        cursor: pointer;
        padding: 0;
        font-size: 16px;
    }
    .hv-pw-toggle:hover { color: var(--maroon); }
    </style>
</head>
<body>
<div class="hv-auth-box">
    <div class="hv-reset-card">
        <div class="text-center mb-4">
            <img src="{{ asset('assets/img/logo.jpg') }}" style="width:70px;height:70px;border-radius:50%;border:3px solid var(--gold);object-fit:cover;"
                 alt="Hidden Vietnam logo"
                 onerror="this.src='https://placehold.co/70x70/5C1010/D4A94A?text=HV'">
            <h3 class="mt-3 mb-1">Reset Password</h3>

            @if($valid)
                <p class="text-muted small mb-0">
                    Set a new password for <strong>{{ $user->full_name }}</strong>
                </p>
            @endif
        </div>

        @if($errors->any())
            <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
        @endif

        @if($error)
            <div class="alert alert-danger py-2 small">{{ $error }}</div>
        @endif

        @if(session('success'))
            <div class="alert alert-success py-2 small">
                <i class="bi bi-check-circle me-1"></i>
                {{ session('success') }}
            </div>
            <a href="{{ route('login') }}" class="btn btn-maroon w-100 py-2 fw-bold">
                <i class="bi bi-box-arrow-in-right me-1"></i> Go to Login
            </a>

        @elseif($valid)
            <form method="POST" action="{{ route('password.update') }}">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="hv-input-icon mb-3" style="position:relative;">
                    <i class="bi bi-lock"></i>
                    <input type="password" name="password" id="pw1" class="form-control py-2"
                           placeholder="New password (min 6 chars)" required minlength="6"
                           style="padding-right:40px;">
                    <button type="button" class="hv-pw-toggle" onclick="togglePw('pw1', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <div class="hv-input-icon mb-3" style="position:relative;">
                    <i class="bi bi-lock-fill"></i>
                    <input type="password" name="confirm_password" id="pw2" class="form-control py-2"
                           placeholder="Confirm new password" required minlength="6"
                           style="padding-right:40px;">
                    <button type="button" class="hv-pw-toggle" onclick="togglePw('pw2', this)">
                        <i class="bi bi-eye"></i>
                    </button>
                </div>
                <button type="submit" class="btn btn-maroon w-100 py-2 fw-bold">
                    <i class="bi bi-check-lg me-1"></i> Reset Password
                </button>
            </form>

        @else
            <!-- Invalid / expired token -->
            <a href="{{ route('password.request') }}" class="btn btn-outline-maroon w-100 py-2">
                <i class="bi bi-arrow-counterclockwise me-1"></i> Request New Reset Link
            </a>
        @endif

        <div class="text-center mt-3">
            <a href="{{ route('login') }}" class="text-muted small text-decoration-none">
                <i class="bi bi-arrow-left"></i> Back to Login
            </a>
        </div>
    </div>
</div>

<script>
function togglePw(id, btn) {
    const input = document.getElementById(id);
    const icon  = btn.querySelector('i');
    if (input.type === 'password') {
        input.type = 'text';
        icon.classList.replace('bi-eye', 'bi-eye-slash');
    } else {
        input.type = 'password';
        icon.classList.replace('bi-eye-slash', 'bi-eye');
    }
}
</script>
</body>
</html>
