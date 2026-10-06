@extends('layouts.guest')

@section('title', 'Login')

@push('styles')
<style>
/* ── Carousel (left panel) ─────────────────────────── */
.hv-auth-left {
    position: relative;
    overflow: hidden;
    padding: 0;
    display: flex;
    flex-direction: column;
    justify-content: flex-end;
}

/* Slide backgrounds */
.hv-slide {
    position: absolute;
    inset: 0;
    background-size: cover;
    background-position: center;
    opacity: 0;
    transition: opacity 1s ease-in-out;
    z-index: 1;
}
.hv-slide.active { opacity: 1; z-index: 2; }

/* Dark overlay for readability */
.hv-slide::after {
    content: '';
    position: absolute;
    inset: 0;
    background: linear-gradient(
        to bottom,
        rgba(92,16,16,0.35) 0%,
        rgba(92,16,16,0.25) 40%,
        rgba(0,0,0,0.55) 100%
    );
}

/* Overlay content on each slide */
.hv-slide-content {
    position: absolute;
    inset: 0;
    z-index: 1;
    display: flex;
    flex-direction: column;
    justify-content: center;
    align-items: center;
    padding: 2.5rem 2rem 4rem;
    text-align: center;
    color: #fff;
}

/* Branding overlay */
.hv-slide-brand { letter-spacing: 0.25em; font-weight: 800; font-size: 1.15rem; color: #fff; }
.hv-slide-script {
    font-family: Georgia, 'Times New Roman', serif;
    font-style: italic;
    font-size: 1.85rem;
    line-height: 1.25;
    color: var(--gold-soft);
    margin-top: 0.75rem;
}

/* Mission & Vision overlay */
.hv-slide-mv { width: 100%; max-width: 520px; }
.hv-slide-mv .hv-mv-label {
    font-weight: 800;
    letter-spacing: 0.15em;
    text-transform: uppercase;
    font-size: 0.95rem;
    color: #fff;
    margin-bottom: 0.5rem;
}
.hv-slide-mv .hv-mv-box {
    background: rgba(255,255,255,0.1);
    backdrop-filter: blur(4px);
    border: 1px solid rgba(255,255,255,0.25);
    border-radius: 12px;
    padding: 14px 16px;
    font-size: 13px;
    line-height: 1.65;
    color: rgba(255,255,255,0.93);
    text-align: justify;
}

/* Dot navigation */
.hv-dots {
    position: absolute;
    bottom: 20px;
    left: 50%;
    transform: translateX(-50%);
    z-index: 10;
    display: flex;
    gap: 10px;
}
.hv-dot {
    width: 12px;
    height: 12px;
    border-radius: 50%;
    border: 2px solid #fff;
    background: transparent;
    cursor: pointer;
    transition: background 0.3s, transform 0.3s;
    padding: 0;
}
.hv-dot:hover { background: rgba(255,255,255,0.5); transform: scale(1.15); }
.hv-dot.active { background: #fff; }

/* ── Right panel adjustments ───────────────────────── */
.hv-auth-right {
    flex-direction: column;
    padding: 2.5rem 3rem;
    border-radius: 40px 0 0 40px;
    overflow: hidden;
    position: relative;
    margin-left: -30px;
    box-shadow: -6px 0 24px rgba(0,0,0,0.10);
    background: var(--cream);
}
.hv-login-logo {
    width: 90px;
    height: 90px;
    border-radius: 50%;
    border: 3px solid var(--gold);
    object-fit: cover;
    margin-bottom: 1.25rem;
}
.hv-login-extras {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
    margin-bottom: 1rem;
}
.hv-login-extras a { color: var(--maroon); text-decoration: none; font-weight: 600; }
.hv-login-extras a:hover { text-decoration: underline; }
.hv-login-extras label { color: var(--muted); cursor: pointer; display: flex; align-items: center; gap: 6px; }

/* Glass card around form */
.hv-glass {
    background: rgba(255,255,255,0.75);
    backdrop-filter: blur(14px);
    -webkit-backdrop-filter: blur(14px);
    border: 1.5px solid rgba(122,23,23,0.15);
    border-radius: 20px;
    padding: 2.25rem 2rem;
    box-shadow: 0 8px 40px rgba(0,0,0,0.12), 0 1px 3px rgba(0,0,0,0.06);
}
.hv-glass .form-control {
    font-size: 16px;
    padding: 0.85rem 0.85rem 0.85rem 44px;
    border-radius: 12px;
    border: 1.5px solid var(--border);
    height: auto;
}
.hv-glass .form-control:focus {
    border-color: var(--maroon);
    box-shadow: 0 0 0 3px rgba(122,23,23,0.1);
}
.hv-glass .btn-maroon {
    font-size: 17px;
    padding: 0.85rem;
    border-radius: 12px;
    letter-spacing: 0.08em;
}

/* Mobile: stack vertically */
/* Tablet */
@media (max-width: 991.98px) {
    .hv-auth-wrap { flex-direction: column !important; }
    .hv-auth-left,
    .hv-auth-right {
        flex: 0 0 100% !important;
        max-width: 100% !important;
        margin-left: 0 !important;
        border-radius: 0 !important;
    }
    .hv-auth-left { min-height: 40vh; }
    .hv-auth-right {
        padding: 2rem 1.5rem;
        box-shadow: none !important;
    }
    .hv-slide-script { font-size: 1.4rem; }
    .hv-slide-mv .hv-mv-box { font-size: 12px; padding: 10px 12px; }
    .hv-slide-brand { font-size: 1rem; }
}

/* Phone */
@media (max-width: 575.98px) {
    .hv-auth-left { min-height: 35vh; }
    .hv-auth-right { padding: 1.5rem 1rem; }
    .hv-glass {
        padding: 1.5rem 1.25rem !important;
        border-radius: 16px !important;
    }
    .hv-glass .form-control {
        font-size: 15px !important;
        padding: 0.7rem 0.7rem 0.7rem 40px !important;
    }
    .hv-glass .btn-maroon {
        font-size: 15px !important;
        padding: 0.7rem !important;
    }
    .hv-login-logo {
        width: 70px !important;
        height: 70px !important;
    }
    h3 { font-size: 1.4rem !important; }
    .hv-slide-content { padding: 1.5rem 1rem 3rem; }
    .hv-slide-brand { font-size: 0.85rem; letter-spacing: 0.15em; }
    .hv-slide-script { font-size: 1.1rem; }
    .hv-slide-mv { display: none; }
    .hv-dots { bottom: 12px; }
    .hv-dot { width: 10px; height: 10px; }
}
</style>
@endpush

@section('content')
<div class="hv-auth-wrap row g-0">

    {{-- ═══ LEFT PANEL: Image Carousel ═══════════════════ --}}
    <div class="col-lg-5 hv-auth-left" style="flex:0 0 50%;max-width:50%">

        {{-- Slide 1: Restaurant storefront — branding --}}
        <div class="hv-slide active" style="background-image:url('{{ asset('assets/img/carousel/slide-storefront.png') }}')">
            <div class="hv-slide-content">
                <div class="hv-slide-brand">H I D D E N &nbsp; V I E T N A M</div>
                <div class="hv-slide-script">Affordable Vietnamese<br>Street Food</div>
            </div>
        </div>

        {{-- Slide 2: Restaurant interior — Mission & Vision --}}
        <div class="hv-slide" style="background-image:url('{{ asset('assets/img/carousel/slide-interior.png') }}')">
            <div class="hv-slide-content">
                <div class="hv-slide-brand mb-3">H I D D E N &nbsp; V I E T N A M</div>
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

        {{-- Slide 3: Logo only --}}
        <div class="hv-slide" style="background:linear-gradient(135deg,var(--maroon-dark),var(--maroon))">
            <div class="hv-slide-content">
                <img src="{{ asset('assets/img/logo.jpg') }}" style="width:120px;height:120px;border-radius:50%;border:3px solid var(--gold);object-fit:cover;margin-bottom:1.25rem;"
                     alt="Hidden Vietnam logo" onerror="this.src='https://placehold.co/120x120/5C1010/D4A94A?text=HV'">
                <div class="hv-slide-brand">H I D D E N &nbsp; V I E T N A M</div>
                <div class="hv-slide-script">Affordable Vietnamese<br>Street Food</div>
            </div>
        </div>

        {{-- Dot indicators --}}
        <div class="hv-dots">
            <button class="hv-dot active" data-slide="0" aria-label="Slide 1"></button>
            <button class="hv-dot" data-slide="1" aria-label="Slide 2"></button>
            <button class="hv-dot" data-slide="2" aria-label="Slide 3"></button>
        </div>
    </div>

    {{-- ═══ RIGHT PANEL: Login Form ═════════════════════ --}}
    <div class="col-lg-7 hv-auth-right" style="flex:0 0 calc(50% + 30px);max-width:calc(50% + 30px);border-radius:40px 0 0 40px;z-index:5">
        <div style="width:100%; max-width:440px;">
            <div class="text-center mb-4">
                <img src="{{ asset('assets/img/logo.jpg') }}" class="hv-login-logo" alt="Hidden Vietnam logo"
                     onerror="this.src='https://placehold.co/90x90/5C1010/D4A94A?text=HV'">
                <h3 class="mb-1" style="color:var(--maroon); font-weight:800;">WELCOME BACK!</h3>
                <p class="text-muted small mb-0">Login to your account</p>
            </div>

            {{-- Flash messages --}}
            @if(session('success'))
                <div class="alert alert-success py-2 small">{{ session('success') }}</div>
            @endif

            @if(session('warning'))
                <div class="alert alert-warning py-2 small">{{ session('warning') }}</div>
            @endif

            @if(session('error'))
                <div class="alert alert-danger py-2 small">{{ session('error') }}</div>
            @endif

            @if($errors->any())
                <div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>
            @endif

            <div class="hv-glass">
                <form method="POST" action="{{ route('login.submit') }}">
                    @csrf
                    <div class="hv-input-icon mb-3">
                        <i class="bi bi-person"></i>
                        <input type="email" name="email" class="form-control py-2"
                               placeholder="Email" value="{{ old('email') }}" required autofocus>
                    </div>
                    <div class="hv-input-icon mb-3">
                        <i class="bi bi-lock"></i>
                        <input type="password" name="password" class="form-control py-2"
                               placeholder="Password" required>
                    </div>
                    <div class="hv-login-extras">
                        <label><input type="checkbox" name="remember" class="form-check-input" style="margin-top:0"> Remember me</label>
                        <a href="{{ url('modules/auth/forgot_password.php') }}">Forgot password?</a>
                    </div>
                    <button type="submit" class="btn btn-maroon w-100 py-2 fw-bold">LOGIN</button>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection

@push('scripts')
{{-- ═══ CAROUSEL JS ═══════════════════════════════════════ --}}
<script>
(function () {
    const slides = document.querySelectorAll('.hv-slide');
    const dots   = document.querySelectorAll('.hv-dot');
    let current  = 0;
    let timer    = null;
    const INTERVAL = 7000;

    function goTo(index) {
        slides[current].classList.remove('active');
        dots[current].classList.remove('active');
        current = (index + slides.length) % slides.length;
        slides[current].classList.add('active');
        dots[current].classList.add('active');
    }

    function next() { goTo(current + 1); }

    function startAuto() {
        stopAuto();
        timer = setInterval(next, INTERVAL);
    }

    function stopAuto() {
        if (timer) { clearInterval(timer); timer = null; }
    }

    // Dot clicks
    dots.forEach(function (dot) {
        dot.addEventListener('click', function () {
            goTo(parseInt(this.dataset.slide, 10));
            startAuto();
        });
    });

    // Pause auto-slide when user hovers the left panel
    var panel = document.querySelector('.hv-auth-left');
    panel.addEventListener('mouseenter', stopAuto);
    panel.addEventListener('mouseleave', startAuto);

    // Start
    startAuto();
})();
</script>
@endpush
