<div class="hv-card p-0">
    <div class="p-3 border-bottom">
        <div class="row g-2 align-items-center">
            <div class="col-md-4">
                <input type="text" class="form-control form-control-sm" id="searchProducts"
                       placeholder="Search products..." oninput="filterProducts()">
            </div>
            <div class="col-md-3">
                <select class="form-select form-select-sm" id="filterCategory" onchange="filterProducts()">
                    <option value="">All Categories</option>
                    @foreach($categories as $cat)
                        <option value="{{ e($cat->name) }}">{{ e($cat->name) }} ({{ $cat->goods_type }})</option>
                    @endforeach
                </select>
            </div>
            <div class="col-md-2">
                <select class="form-select form-select-sm" id="filterGoods" onchange="filterProducts()">
                    <option value="">All Types</option>
                    <option value="dry">Dry Goods</option>
                    <option value="wet">Wet Goods</option>
                </select>
            </div>
            <div class="col-md-3 text-end">
                <span class="text-muted small" id="productItemCount">
                    Showing {{ count($allItems) }} item{{ count($allItems) !== 1 ? 's' : '' }}
                </span>
            </div>
        </div>
    </div>
    <div class="table-responsive" id="productCatalogBody">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Product Name</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th class="text-end">Price</th>
                    <th class="text-center">Reorder Lvl</th>
                    <th class="text-center">Shelf Life</th>
                    <th class="text-center">Catalog Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @forelse($allItems as $item)
                <tr class="product-row {{ $item->is_active ? '' : 'table-secondary' }}"
                    data-pname="{{ e(strtolower($item->item_name)) }}"
                    data-pcategory="{{ e($item->category_name ?? '') }}"
                    data-pgoods="{{ e($item->goods_type ?? '') }}">
                    <td class="ps-3">
                        <div class="d-flex align-items-center gap-2">
                            @if($item->image_path)
                                <img src="{{ asset($item->image_path) }}" alt=""
                                     style="width:36px;height:36px;object-fit:cover;border-radius:6px;">
                            @else
                                <div style="width:36px;height:36px;border-radius:6px;background:var(--gold-soft);display:flex;align-items:center;justify-content:center;">
                                    <i class="ph ph-package" style="color:var(--maroon);"></i>
                                </div>
                            @endif
                            <div>
                                <div class="fw-semibold">{{ e($item->item_name) }}</div>
                                @if($item->pack_description)
                                    <div class="text-muted small">{{ e($item->pack_description) }}</div>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td>
                        @if($item->category_name)
                            <span class="hv-badge {{ $item->goods_type === 'wet' ? 'hv-badge-info' : 'hv-badge-warning' }}">
                                {{ e($item->category_name) }}
                            </span>
                        @else
                            <span class="text-muted">&mdash;</span>
                        @endif
                    </td>
                    <td class="small">{{ e($item->unit) }}</td>
                    <td class="text-end fw-semibold">&#8369;{{ number_format($item->price, 2) }}</td>
                    <td class="text-center">{{ (int)$item->reorder_level }}</td>
                    <td class="text-center">{{ $item->shelf_life_days ? $item->shelf_life_days . 'd' : '—' }}</td>

                    <td class="text-center">
                        @if($item->is_active)
                            <span class="hv-badge hv-badge-success" title="Available for commissary orders; finished stock is not required">Available</span>
                        @else
                            <span class="hv-badge hv-badge-danger">Unavailable</span>
                        @endif
                    </td>
                    <td class="text-center">
                        <div class="d-flex gap-1 justify-content-center">
                            @if($canManageProducts)
                            <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                    onclick="openProductModal(@js($item))">
                                <i class="ph ph-pencil-simple"></i>
                            </button>
                            @endif
                            @if($canManageAvailability)
                            @if($item->is_active)
                                <button class="btn btn-sm btn-outline-danger" title="Mark unavailable" aria-label="Mark {{ $item->item_name }} unavailable for orders"
                                        onclick="toggleProduct({{ $item->id }}, 0)">
                                    <i class="ph ph-x-circle" aria-hidden="true"></i> Mark unavailable
                                </button>
                            @else
                                <button class="btn btn-sm btn-outline-success" title="Make available" aria-label="Make {{ $item->item_name }} available for orders"
                                        onclick="toggleProduct({{ $item->id }}, 1)">
                                    <i class="ph ph-check-circle" aria-hidden="true"></i> Make available
                                </button>
                            @endif
                            @endif
                        </div>
                    </td>
                </tr>
            @empty
                <tr><td colspan="8" class="text-center text-muted py-4">No products found.@if($canManageProducts) Click "Add Product" to create one.@endif</td></tr>
            @endforelse
            </tbody>
        </table>
    </div>
</div>
