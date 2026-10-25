@extends('layouts.app')

@section('title', 'Commissary Products')

@section('content')
<div class="hv-page-header">
    <div>
        <div class="small text-muted">Manage Commissary product details, prices, reorder levels, shelf life, and catalog availability.</div>
    </div>
    @if($canManageProducts)
    <button type="button" class="btn btn-outline-maroon btn-sm" onclick="openProductModal()">
        <i class="ph ph-plus me-1"></i> Add Product
    </button>
    @endif
</div>

@include('partials.product-catalog')
@include('partials.product-form')
@endsection

@include('partials.product-scripts')
