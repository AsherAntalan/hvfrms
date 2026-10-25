@extends('layouts.app')
@section('title', 'Movement History — ' . $item->item_name)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center mb-3">
        <a href="{{ route('inventory') }}" class="btn btn-sm btn-outline-secondary me-3">
            <i class="ph ph-arrow-left"></i> Back
        </a>
        <h4 class="mb-0" style="color:var(--maroon);">
            <i class="ph ph-clock-counter-clockwise me-2"></i>{{ $item->item_name }} — Movement History
        </h4>
    </div>

    @include('partials.stock-history-content')
</div>
@endsection
