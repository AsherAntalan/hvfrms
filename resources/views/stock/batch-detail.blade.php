@extends('layouts.app')
@section('title', 'Batch #' . $batch->id . ' — ' . $batch->item->item_name)

@section('content')
<div class="container-fluid py-4">
    <div class="d-flex align-items-center mb-3">
        <a href="{{ route('stock.history', $batch->item_id) }}" class="btn btn-sm btn-outline-secondary me-3">
            <i class="ph ph-arrow-left"></i> Back to History
        </a>
        <h4 class="mb-0" style="color:var(--maroon);">
            <i class="ph ph-package me-2"></i>Batch #{{ $batch->id }}
        </h4>
    </div>

    @include('partials.stock-batch-content')
</div>
@endsection
