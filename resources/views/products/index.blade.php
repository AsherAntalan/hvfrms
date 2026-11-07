@extends('layouts.app')

@section('title', 'Commissary Products')

@section('content')
<div class="hv-page-header">
    <div>
        <div class="small text-muted">Manage Commissary product details, prices, reorder levels, shelf life, and catalog availability.</div>
    </div>
    <div class="d-flex gap-2">
    @if(auth()->user()->isOwner())
    <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-toggle="modal" data-bs-target="#archivedProductsModal">Archived Products</button>
    @endif
    @if($canManageProducts)
    <button type="button" class="btn btn-outline-maroon btn-sm" onclick="openProductModal()">
        <i class="ph ph-plus me-1"></i> Add Product
    </button>
    @endif
    </div>
</div>

@include('partials.product-catalog')
@include('partials.product-form')
@if(auth()->user()->isOwner())
<div class="modal fade" id="archivedProductsModal" tabindex="-1" aria-labelledby="archivedProductsTitle" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-centered modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header">
            <h5 class="modal-title" id="archivedProductsTitle">Archived Products</h5>
            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
        </div>
        <div class="modal-body" id="archivedProductsBody">
            <p class="small text-muted">Restore returns a product to the catalog as unavailable. Products with linked records cannot be permanently deleted.</p>
            <div class="table-responsive"><table class="table align-middle">
                <thead><tr><th>Product</th><th>Category</th><th>Archived</th><th class="text-end">Actions</th></tr></thead>
                <tbody>
                @forelse($archivedProducts as $product)
                    <tr>
                        <td>{{ $product->item_name }}</td><td>{{ $product->category?->name ?? '—' }}</td>
                        <td>{{ $product->archived_at->format('M j, Y') }}</td>
                        <td><div class="d-flex justify-content-end gap-2">
                            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="restoreProduct({{ $product->id }}, {{ Illuminate\Support\Js::from($product->item_name) }})">Restore</button>
                            <button type="button" class="btn btn-sm btn-outline-danger" onclick="deleteProduct({{ $product->id }}, {{ Illuminate\Support\Js::from($product->item_name) }})">Delete</button>
                        </div></td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No archived products.</td></tr>
                @endforelse
                </tbody>
            </table></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button></div>
    </div></div>
</div>
@endif
@endsection

@include('partials.product-scripts')
