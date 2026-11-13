@extends('layouts.app')

@section('title', 'Payment Methods')

@section('content')
<p class="small text-muted mb-3">Bank and e-wallet accounts branches pay to when placing an order. Branches pick a type and provider, then see these details.</p>

@if(session('success'))<div class="alert alert-success py-2 small">{{ session('success') }}</div>@endif
@if($errors->any())<div class="alert alert-danger py-2 small">{{ $errors->first() }}</div>@endif

{{-- Add a new method --}}
<div class="hv-card p-3 mb-3">
    <h6 class="fw-semibold mb-2">Add a payment method</h6>
    <form method="POST" action="{{ route('payment-accounts.store') }}" class="row g-2 align-items-end">
        @csrf
        <div class="col-md-2"><label class="form-label small">Type</label>
            <select name="type" class="form-select form-select-sm" required>
                <option value="bank">Bank</option>
                <option value="ewallet">E-Wallet</option>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small">Provider</label><input name="provider" class="form-control form-control-sm" required maxlength="60" placeholder="e.g. BDO"></div>
        <div class="col-md-3"><label class="form-label small">Account name</label><input name="account_name" class="form-control form-control-sm" maxlength="150"></div>
        <div class="col-md-2"><label class="form-label small">Account number</label><input name="account_number" class="form-control form-control-sm" maxlength="100"></div>
        <div class="col-md-2"><label class="form-label small">Sort</label><input name="sort_order" type="number" min="0" max="999" value="0" class="form-control form-control-sm"></div>
        <div class="col-md-1"><button class="btn btn-maroon btn-sm w-100">Add</button></div>
        <div class="col-12"><input name="instructions" class="form-control form-control-sm" maxlength="255" placeholder="Instructions (optional)"></div>
    </form>
</div>

<div class="hv-card p-3">
    @forelse($accounts as $acc)
    <form method="POST" action="{{ route('payment-accounts.update', $acc) }}" class="row g-2 align-items-end border-bottom pb-2 mb-2">
        @csrf
        <div class="col-md-2"><label class="form-label small">Type</label>
            <select name="type" class="form-select form-select-sm">
                <option value="bank" @selected($acc->type === 'bank')>Bank</option>
                <option value="ewallet" @selected($acc->type === 'ewallet')>E-Wallet</option>
            </select>
        </div>
        <div class="col-md-2"><label class="form-label small">Provider</label><input name="provider" class="form-control form-control-sm" value="{{ $acc->provider }}" required maxlength="60"></div>
        <div class="col-md-3"><label class="form-label small">Account name</label><input name="account_name" class="form-control form-control-sm" value="{{ $acc->account_name }}" maxlength="150"></div>
        <div class="col-md-2"><label class="form-label small">Account number</label><input name="account_number" class="form-control form-control-sm" value="{{ $acc->account_number }}" maxlength="100"></div>
        <div class="col-md-2"><label class="form-label small">Sort</label><input name="sort_order" type="number" min="0" max="999" value="{{ $acc->sort_order }}" class="form-control form-control-sm"></div>
        <div class="col-md-1"><button class="btn btn-sm btn-maroon w-100">Save</button></div>
        <div class="col-12"><input name="instructions" class="form-control form-control-sm" value="{{ $acc->instructions }}" maxlength="255" placeholder="Instructions (optional)"></div>
        <div class="col-12 d-flex gap-2 align-items-center">
            <span class="hv-badge {{ $acc->is_active ? 'hv-badge-success' : 'hv-badge-warning' }}">{{ $acc->is_active ? 'Active' : 'Disabled' }}</span>
            <button class="btn btn-sm btn-outline-secondary" name="op" value="toggle">{{ $acc->is_active ? 'Disable' : 'Enable' }}</button>
            <button class="btn btn-sm btn-outline-danger" name="op" value="delete" onclick="return confirm('Remove {{ $acc->provider }}?')">Delete</button>
        </div>
    </form>
    @empty
    <p class="small text-muted mb-0">No payment methods yet.</p>
    @endforelse

    @if($accounts->total() > 0)
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mt-2 px-1">
        <div class="text-muted small">Showing {{ $accounts->firstItem() }}–{{ $accounts->lastItem() }} of {{ $accounts->total() }}</div>
        @if($accounts->hasPages())
        <nav class="hv-inv-pager" aria-label="Payment methods pages">
            <a class="hv-page-btn {{ $accounts->onFirstPage() ? 'disabled' : '' }}" href="{{ $accounts->previousPageUrl() ?: '#' }}"><i class="ph ph-caret-left"></i> Prev</a>
            @for($p = 1; $p <= $accounts->lastPage(); $p++)
                <a class="hv-page-btn {{ $p === $accounts->currentPage() ? 'active' : '' }}" href="{{ $accounts->url($p) }}">{{ $p }}</a>
            @endfor
            <a class="hv-page-btn {{ $accounts->hasMorePages() ? '' : 'disabled' }}" href="{{ $accounts->nextPageUrl() ?: '#' }}">Next <i class="ph ph-caret-right"></i></a>
        </nav>
        @endif
    </div>
    @endif
</div>
@endsection
