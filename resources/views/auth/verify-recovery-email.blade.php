@extends('layouts.guest')
@section('title', ($verified ?? false) ? 'Awaiting Recovery Approval' : 'Verify Recovery Email')
@section('content')
<main class="min-vh-100 d-flex align-items-center justify-content-center p-3" style="background:#7a1717;">
    <div class="card border-0 rounded-4 p-4 text-center" style="max-width:460px;width:100%;">
        @if($verified ?? false)
            <h1 class="h4 mb-3">Request sent for approval</h1>
            <p role="status">Your email is verified. Your recovery request is now in the authorized manager/admin's Reset Requests queue. You do not need to submit another request.</p>
            <p>Staff: contact your manager for identity verification, then wait for admin approval. Managers: contact admin for identity verification and approval. After approval, a one-time reset link will be emailed to you, valid for 12 hours.</p>
        @elseif($valid)
            <h1 class="h4 mb-3">Verify your recovery email</h1>
            <p>Confirm access to this inbox. Your manager or admin must still verify your identity and approve the recovery request.</p>
            <form method="POST" action="{{ route('password.code.verify-confirm', $token) }}">
                @csrf
                <button type="submit" class="btn btn-maroon w-100">Confirm my recovery email</button>
            </form>
        @else
            <h1 class="h4 mb-3">Verification link unavailable</h1>
            <p>This link is invalid, expired, or already used.</p>
            <a href="{{ route('password.code.request') }}" class="btn btn-maroon">Submit a new recovery request</a>
        @endif
        <a href="{{ route('login') }}" class="mt-3">Back to sign in</a>
    </div>
</main>
@endsection
