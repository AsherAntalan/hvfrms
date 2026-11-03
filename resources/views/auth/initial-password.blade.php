@extends('layouts.guest')
@section('title', 'Set Your Password')
@section('content')
<main class="min-vh-100 d-flex align-items-center justify-content-center p-3" style="background:#7a1717;">
<div class="card rounded-4 border-0 p-4" style="max-width:460px;width:100%;">
    <h1 class="h4">Set your own password</h1>
    <p>Your temporary password must be replaced before you can use HVFRMS.</p>
    @if($errors->any())<div class="alert alert-danger" role="alert">{{ $errors->first() }}</div>@endif
    <form method="POST" action="{{ route('password.initial.update') }}">
        @csrf
        <label for="current_password" class="form-label">Temporary password</label>
        <input id="current_password" name="current_password" type="password" autocomplete="current-password" class="form-control mb-3" required>
        <label for="password" class="form-label">New password</label>
        <input id="password" name="password" type="password" autocomplete="new-password" minlength="8" class="form-control mb-3" required>
        <label for="password_confirmation" class="form-label">Confirm new password</label>
        <input id="password_confirmation" name="password_confirmation" type="password" autocomplete="new-password" minlength="8" class="form-control mb-3" required>
        <button class="btn btn-maroon w-100">Set password</button>
    </form>
    <form method="POST" action="{{ route('logout') }}" class="mt-3">@csrf<button class="btn btn-link p-0">Sign out</button></form>
</div></main>
@endsection
