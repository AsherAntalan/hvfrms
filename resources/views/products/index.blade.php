@extends('layouts.app')

@section('title', 'Overall Products')

@section('content')
<div class="hv-page-header">
    <div>
        <strong>Overall Products</strong>
        <div class="small text-muted">Product catalog with combined remaining stock from all branches and the Commissary.</div>
        <div class="small text-muted mt-1">Physical stock includes expired and damaged batches. Usable stock counts only good-condition, unexpired quantities.</div>
    </div>
    @if($canManageProducts)
    <button type="button" class="btn btn-outline-maroon btn-sm" onclick="openProductModal()">
        <i class="bi bi-plus-lg me-1"></i> Add Product
    </button>
    @endif
</div>

@include('partials.product-catalog')
@include('partials.product-form')
@endsection

@include('partials.product-scripts')
