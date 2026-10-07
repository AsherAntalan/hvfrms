@extends('layouts.app')

@section('title', 'Warehouse Management')

@section('content')

<div class="hv-page-header">
    <div></div>
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-sm d-none" id="btnPrintSelected" onclick="printSelectedQR()">
            <i class="bi bi-printer me-1"></i> Print Selected QR
        </button>
        <button class="btn btn-maroon btn-sm" onclick="openCreateModal()">
            <i class="bi bi-plus-lg me-1"></i> Receive Stock
        </button>
        @if($canManageProducts)
        <button class="btn btn-outline-maroon btn-sm" onclick="openProductModal()">
            <i class="bi bi-plus-lg me-1"></i> Add Product
        </button>
        @endif
    </div>
</div>

<!-- Statistics Cards -->
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3">
        <div class="hv-card p-3 text-center">
            <div class="text-muted small mb-1">Active Stock</div>
            <div class="display-6 fw-bold text-maroon">{{ number_format($totalStock) }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="hv-card p-3 text-center">
            <div class="text-muted small mb-1">Products</div>
            <div class="display-6 fw-bold text-maroon">{{ $productCount }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="hv-card p-3 text-center">
            <div class="text-muted small mb-1">Low Stock</div>
            <div class="display-6 fw-bold text-warning">{{ $lowStockCount }}</div>
        </div>
    </div>
    <div class="col-6 col-md-3">
        <div class="hv-card p-3 text-center">
            <div class="text-muted small mb-1">Expired</div>
            <div class="display-6 fw-bold text-danger">{{ count($expiredBatches) }}</div>
        </div>
    </div>
</div>

<!-- Tabs -->
<ul class="nav nav-tabs mb-0" role="tablist">
    <li class="nav-item">
        <button class="nav-link active" data-bs-toggle="tab" data-bs-target="#tabActive" type="button">
            <i class="bi bi-check-circle me-1"></i> Active Stock
            <span class="badge bg-success ms-1">{{ count($activeBatches) }}</span>
        </button>
    </li>
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabExpired" type="button">
            <i class="bi bi-archive me-1"></i> Expired Stock
            <span class="badge bg-danger ms-1">{{ count($expiredBatches) }}</span>
        </button>
    </li>
    @unless($isWarehouseStaff)
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabOrders" type="button">
            <i class="bi bi-cart me-1"></i> Pending Orders
            <span class="badge bg-warning text-dark ms-1">{{ count($pendingOrders) }}</span>
        </button>
    </li>
    @endunless
    @if($canManageProducts)
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabProducts" type="button">
            <i class="bi bi-box-seam me-1"></i> Products
            <span class="badge bg-secondary ms-1">{{ count($allItems) }}</span>
        </button>
    </li>
    @endif
    <li class="nav-item">
        <button class="nav-link" data-bs-toggle="tab" data-bs-target="#tabDisposal" type="button">
            <i class="bi bi-recycle me-1"></i> Disposal Records
            <span class="badge bg-secondary ms-1">{{ count($disposalRecords) }}</span>
        </button>
    </li>
</ul>

<div class="tab-content">

    {{-- ═══ ACTIVE STOCK ═══════════════════════════════════ --}}
    <div class="tab-pane fade show active" id="tabActive">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
            <div class="p-3 border-bottom">
                <input type="text" id="searchActive" class="form-control form-control-sm"
                       placeholder="Search active stock..." style="max-width:300px;" oninput="filterRows('active')">
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="text-center" style="width:36px;">
                                <input type="checkbox" class="form-check-input" onchange="toggleAll(this)" title="Select all">
                            </th>
                            <th class="ps-3">Product</th>
                            <th>Category</th>
                            <th>Batch QR</th>
                            <th class="text-center">Qty Left</th>
                            <th class="text-center">Qty In</th>
                            <th>Expiry Date</th>
                            <th>Received</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($activeBatches as $b)
                        @php
                            $warnDate = date('Y-m-d', strtotime('+7 days'));
                            $expSoon = $b->expiry_date && $b->expiry_date <= $warnDate;
                        @endphp
                        <tr class="row-active" data-name="{{ e(strtolower($b->item_name)) }}">
                            <td class="text-center">
                                <input type="checkbox" class="form-check-input qr-cb" onchange="updatePrintBtn()"
                                       data-qr="{{ e($b->qr_code) }}"
                                       data-name="{{ e($b->item_name) }}"
                                       data-unit="{{ e($b->unit) }}">
                            </td>
                            <td class="ps-3 fw-semibold">{{ e($b->item_name) }}</td>
                            <td>
                                @if($b->category_name)
                                    <span class="hv-badge {{ ($b->goods_type ?? '') === 'wet' ? 'hv-badge-info' : 'hv-badge-warning' }}">{{ e($b->category_name) }}</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td><code class="small" style="color:var(--maroon);">{{ $b->qr_code ?? '—' }}</code></td>
                            <td class="text-center fw-bold">{{ $b->quantity_left }} <span class="text-muted small fw-normal">{{ $b->unit }}</span></td>
                            <td class="text-center text-muted">{{ $b->quantity_in }}</td>
                            <td>
                                @if($b->expiry_date)
                                    <span class="{{ $expSoon ? 'text-warning fw-semibold' : 'text-success' }}">
                                        {{ date('M j, Y', strtotime($b->expiry_date)) }}
                                    </span>
                                    @if($expSoon)
                                        <div class="small text-warning"><i class="bi bi-clock-fill"></i> Soon</div>
                                    @endif
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="small text-muted">
                                {{ $b->received_at ? date('M j, Y', strtotime($b->received_at)) : '—' }}
                                @if($b->full_name)
                                    <div>by {{ $b->full_name }}</div>
                                @endif
                            </td>
                            <td class="text-center"><span class="hv-badge hv-badge-success">Active</span></td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center">
                                    @if($canEditBatches)
                                    <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                            onclick="openEditModal({{ $b->id }}, '{{ e($b->item_name) }}', {{ $b->quantity_left }}, '{{ $b->expiry_date ?? '' }}')">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    @endif
                                    <button class="btn btn-sm btn-outline-secondary" title="Print QR"
                                            onclick="printSingleQR('{{ e($b->qr_code) }}', '{{ e($b->item_name) }}', '{{ e($b->unit) }}')">
                                        <i class="bi bi-printer"></i>
                                    </button>
                                    @if($canManageProducts)
                                    <button class="btn btn-sm btn-outline-danger" title="Delete"
                                            onclick="deleteBatch({{ $b->id }}, '{{ e($b->item_name) }}')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">No active stock. Click "Receive Stock" to add.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ═══ EXPIRED STOCK ═══════════════════════════════════ --}}
    <div class="tab-pane fade" id="tabExpired">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
            <div class="p-3 border-bottom">
                <input type="text" id="searchExpired" class="form-control form-control-sm"
                       placeholder="Search expired stock..." style="max-width:300px;" oninput="filterRows('expired')">
            </div>
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Product</th>
                            <th>Category</th>
                            <th>Batch QR</th>
                            <th class="text-center">Qty Left</th>
                            <th>Expired On</th>
                            <th>Received</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($expiredBatches as $b)
                        @php $daysAgo = max(0, (int)round((time() - strtotime($b->expiry_date)) / 86400)); @endphp
                        <tr class="row-expired table-secondary" data-name="{{ e(strtolower($b->item_name)) }}">
                            <td class="ps-3 fw-semibold">{{ e($b->item_name) }}</td>
                            <td>
                                @if($b->category_name)
                                    <span class="hv-badge {{ ($b->goods_type ?? '') === 'wet' ? 'hv-badge-info' : 'hv-badge-warning' }}">{{ e($b->category_name) }}</span>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td><code class="small">{{ $b->qr_code ?? '—' }}</code></td>
                            <td class="text-center fw-bold text-danger">{{ $b->quantity_left }} <span class="text-muted small fw-normal">{{ $b->unit }}</span></td>
                            <td>
                                <span class="text-danger fw-semibold">{{ date('M j, Y', strtotime($b->expiry_date)) }}</span>
                                <div class="small text-danger"><i class="bi bi-x-circle-fill"></i> {{ $daysAgo }} day{{ $daysAgo !== 1 ? 's' : '' }} ago</div>
                            </td>
                            <td class="small text-muted">{{ $b->received_at ? date('M j, Y', strtotime($b->received_at)) : '—' }}</td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center">
                                    <button class="btn btn-sm btn-warning" title="Dispose expired batch"
                                            onclick="disposeBatch({{ $b->id }}, '{{ e($b->item_name) }}', {{ $b->quantity_left }})">
                                        <i class="bi bi-recycle me-1"></i> Dispose
                                    </button>
                                    @if($canManageProducts)
                                    <button class="btn btn-sm btn-outline-danger" title="Delete expired batch"
                                            onclick="deleteBatch({{ $b->id }}, '{{ e($b->item_name) }}')">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="text-center text-muted py-4">No expired stock.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    {{-- ═══ PENDING ORDERS ════════════════════════════════ --}}
    @unless($isWarehouseStaff)
    <div class="tab-pane fade" id="tabOrders">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Order #</th>
                            <th>Branch</th>
                            <th>Ordered By</th>
                            <th>Items</th>
                            <th class="text-end">Total</th>
                            <th>Date</th>
                            <th class="text-center">Status</th>
                            @if($canFulfillOrders)
                            <th class="text-center">Actions</th>
                            @endif
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($pendingOrders as $o)
                        <tr>
                            <td class="ps-3 fw-bold" style="color:var(--maroon);">{{ $o->order_number }}</td>
                            <td>{{ $o->branch_name }}</td>
                            <td class="small">{{ $o->ordered_by_name ?? '—' }}</td>
                            <td class="small">
                                @if(isset($orderItems[$o->id]))
                                    @foreach($orderItems[$o->id] as $oi)
                                        <div>{{ $oi->quantity }}x {{ $oi->item_name }} <span class="text-muted">({{ $oi->unit }})</span></div>
                                    @endforeach
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-end fw-semibold">&#8369;{{ number_format($o->total_amount, 2) }}</td>
                            <td class="small text-muted">{{ \Carbon\Carbon::parse($o->created_at)->format('M j, g:i A') }}</td>
                            <td class="text-center">
                                @php
                                    $statusBadge = match($o->status) {
                                        'pending' => 'hv-badge-warning',
                                        'approved' => 'hv-badge-info',
                                        'delivering' => 'hv-badge-info',
                                        default => 'hv-badge-secondary',
                                    };
                                @endphp
                                <span class="hv-badge {{ $statusBadge }}">{{ ucfirst($o->status) }}</span>
                            </td>
                            @if($canFulfillOrders)
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center flex-wrap">
                                    @if($o->status === 'pending')
                                        <button class="btn btn-sm btn-success" onclick="fulfillOrder({{ $o->id }}, 'approved')" title="Approve">
                                            <i class="bi bi-check-lg"></i> Approve
                                        </button>
                                        <button class="btn btn-sm btn-outline-danger" onclick="fulfillOrder({{ $o->id }}, 'cancelled')" title="Cancel">
                                            <i class="bi bi-x-lg"></i>
                                        </button>
                                    @elseif($o->status === 'approved')
                                        <button class="btn btn-sm btn-info text-white" onclick="fulfillOrder({{ $o->id }}, 'delivering')" title="Mark Delivering">
                                            <i class="bi bi-truck"></i> Send
                                        </button>
                                    @elseif($o->status === 'delivering')
                                        <button class="btn btn-sm btn-success" onclick="fulfillOrder({{ $o->id }}, 'delivered')" title="Mark Delivered">
                                            <i class="bi bi-check-all"></i> Delivered
                                        </button>
                                    @endif
                                </div>
                            </td>
                            @endif
                        </tr>
                    @empty
                        <tr><td colspan="{{ $canFulfillOrders ? 8 : 7 }}" class="text-center text-muted py-4">No pending orders from branches.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endunless

    {{-- ═══ PRODUCTS ═════════════════════════════════════════ --}}
    @if($canManageProducts)
    <div class="tab-pane fade" id="tabProducts">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
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
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Product Name</th>
                            <th>Category</th>
                            <th>Unit</th>
                            <th class="text-end">Price</th>
                            <th class="text-center">Total Stock</th>
                            <th class="text-center">Reorder Lvl</th>
                            <th class="text-center">Shelf Life</th>
                            <th class="text-center">QR</th>
                            <th class="text-center">Status</th>
                            <th class="text-center">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($allItems as $item)
                        @php
                            $stockVal = (int)$item->total_stock;
                            $rl = (int)$item->reorder_level;
                        @endphp
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
                                            <i class="bi bi-box" style="color:var(--maroon);"></i>
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
                            <td class="text-center">
                                <span class="fw-bold {{ $stockVal === 0 ? 'text-danger' : ($stockVal <= $rl ? 'text-warning' : '') }}">
                                    {{ $stockVal }}
                                </span>
                            </td>
                            <td class="text-center">{{ (int)$item->reorder_level }}</td>
                            <td class="text-center">{{ $item->shelf_life_days ? $item->shelf_life_days . 'd' : '—' }}</td>
                            <td class="text-center">
                                @if(!empty($item->qr_code))
                                    <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Print QR label"
                                            onclick="printSingleQR('{{ e($item->qr_code) }}', '{{ e($item->item_name) }}', '{{ e($item->unit) }}')">
                                        <i class="bi bi-qr-code"></i>
                                    </button>
                                @else
                                    <span class="text-muted">&mdash;</span>
                                @endif
                            </td>
                            <td class="text-center">
                                @if($item->is_active)
                                    <span class="hv-badge hv-badge-success">Active</span>
                                @else
                                    <span class="hv-badge hv-badge-danger">Inactive</span>
                                @endif
                            </td>
                            <td class="text-center">
                                <div class="d-flex gap-1 justify-content-center">
                                    <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                            onclick="openProductModal(@js($item))">
                                        <i class="bi bi-pencil"></i>
                                    </button>
                                    @if($item->is_active)
                                        <button class="btn btn-sm btn-outline-danger" title="Deactivate"
                                                onclick="toggleProduct({{ $item->id }}, 0)">
                                            <i class="bi bi-x-circle"></i>
                                        </button>
                                    @else
                                        <button class="btn btn-sm btn-outline-success" title="Activate"
                                                onclick="toggleProduct({{ $item->id }}, 1)">
                                            <i class="bi bi-check-circle"></i>
                                        </button>
                                    @endif
                                </div>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="10" class="text-center text-muted py-4">No products found. Click "Add Product" to create one.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    @endif

    {{-- ═══ DISPOSAL RECORDS ═══════════════════════════════ --}}
    <div class="tab-pane fade" id="tabDisposal">
        <div class="hv-card p-0" style="border-top-left-radius:0;">
            <div class="table-responsive">
                <table class="table table-hover align-middle mb-0">
                    <thead>
                        <tr>
                            <th class="ps-3">Product</th>
                            <th>Type</th>
                            <th class="text-center">Qty</th>
                            <th>Reason</th>
                            <th>Remarks</th>
                            <th>Batch QR</th>
                            <th>Batch Expiry</th>
                            <th>Performed By</th>
                            <th>Date</th>
                        </tr>
                    </thead>
                    <tbody>
                    @forelse($disposalRecords as $d)
                        <tr>
                            <td class="ps-3 fw-semibold">{{ e($d->item_name) }}</td>
                            <td>
                                @php
                                    $typeBadge = match($d->type) {
                                        'expired' => 'hv-badge-danger',
                                        'damaged' => 'hv-badge-warning',
                                        default => 'hv-badge-secondary',
                                    };
                                @endphp
                                <span class="hv-badge {{ $typeBadge }}">{{ ucfirst($d->type) }}</span>
                            </td>
                            <td class="text-center fw-bold text-danger">{{ abs($d->quantity) }} <span class="text-muted small fw-normal">{{ $d->unit }}</span></td>
                            <td class="small">{{ e($d->reason) }}</td>
                            <td class="small text-muted">{{ $d->remarks ? e($d->remarks) : '—' }}</td>
                            <td><code class="small">{{ $d->batch_qr ?? '—' }}</code></td>
                            <td class="small">
                                @if($d->batch_expiry)
                                    {{ date('M j, Y', strtotime($d->batch_expiry)) }}
                                @else
                                    —
                                @endif
                            </td>
                            <td class="small">{{ $d->performed_by_name ?? '—' }}</td>
                            <td class="small text-muted">{{ \Carbon\Carbon::parse($d->created_at)->format('M j, g:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="9" class="text-center text-muted py-4">No disposal records yet.</td></tr>
                    @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    </div>

</div>

{{-- ═══ CREATE STOCK MODAL ════════════════════════════════ --}}
<div class="modal fade" id="createModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                    <i class="bi bi-plus-circle me-1"></i> Receive Stock
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form id="createForm">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Product <span class="text-danger">*</span></label>
                        <select class="form-select" id="cItem" required>
                            <option value="">Select a product...</option>
                            @foreach($products as $p)
                                <option value="{{ $p->id }}" data-shelf="{{ $p->shelf_life_days ?: '' }}">
                                    {{ $p->item_name }} ({{ $p->unit }})
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="cQty" required min="1" placeholder="Enter quantity">
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Expiry Date</label>
                        <div class="input-group">
                            <input type="date" class="form-control" id="cExpiry">
                            <button type="button" class="btn btn-outline-secondary d-none" id="cAutoBtn" onclick="autoCalcExpiry()">
                                Auto
                            </button>
                        </div>
                        <small class="text-muted" id="cShelfHint"></small>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" id="cRemarks" rows="2" placeholder="Optional notes..." maxlength="500"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-maroon" id="btnCreate" onclick="submitCreate()">
                        <i class="bi bi-check-lg me-1"></i> Receive Stock
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

{{-- ═══ EDIT BATCH MODAL ══════════════════════════════════ --}}
@if($canEditBatches)
<div class="modal fade" id="editModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" style="color:var(--maroon);font-weight:700;">
                    <i class="bi bi-pencil me-1"></i> Edit Batch
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="eBatchId">
                <div class="mb-2 fw-semibold" id="eItemName" style="color:var(--maroon);"></div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Quantity Left</label>
                    <input type="number" class="form-control" id="eQty" min="0">
                </div>
                <div class="mb-3">
                    <label class="form-label fw-semibold">Expiry Date</label>
                    <input type="date" class="form-control" id="eExpiry">
                    <small class="text-muted">Changing expiry will regenerate the batch QR code.</small>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-maroon" id="btnEdit" onclick="submitEdit()">
                    <i class="bi bi-check-lg me-1"></i> Save Changes
                </button>
            </div>
        </div>
    </div>
</div>
@endif

{{-- ═══ ADD / EDIT PRODUCT MODAL ══════════════════════════ --}}
@if($canManageProducts)
<div class="modal fade" id="productModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="productForm" onsubmit="saveProduct(event)" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="id" id="p_id">

                <div class="modal-header" style="background:var(--gold-soft);">
                    <h5 class="modal-title" id="productModalTitle" style="color:var(--maroon);font-weight:700;">
                        <i class="bi bi-box-seam me-1"></i> Add Product
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="item_name" id="p_item_name"
                                       required maxlength="150" placeholder="e.g. Pho Sauce">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                                <select class="form-select" name="category_id" id="p_category_id" required>
                                    <option value="">Select category</option>
                                    @foreach($categories as $cat)
                                        <option value="{{ $cat->id }}">
                                            {{ e($cat->name) }} ({{ $cat->goods_type }})
                                        </option>
                                    @endforeach
                                </select>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Unit <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="unit" id="p_unit"
                                       required maxlength="50" placeholder="e.g. pack, liter, pcs, grams">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Pack Description</label>
                                <input type="text" class="form-control" name="pack_description" id="p_pack_description"
                                       maxlength="100" placeholder="e.g. 5pcs/pack, 900 grams, 1 Liter">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Price (PHP) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="price" id="p_price"
                                       required step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Reorder Level</label>
                                <input type="number" class="form-control" name="reorder_level" id="p_reorder_level"
                                       min="0" value="10" placeholder="10">
                                <div class="form-text">Alert when stock falls below this number.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Shelf Life (days)</label>
                                <input type="number" class="form-control" name="shelf_life_days" id="p_shelf_life_days"
                                       min="1" placeholder="e.g. 30">
                                <div class="form-text">Used for FIFO expiry tracking.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Product Image</label>
                                <input type="file" class="form-control" name="image" id="p_image"
                                       accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">JPG, PNG, or WebP. Max 2MB.</div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea class="form-control" name="remarks" id="p_remarks"
                                  rows="2" maxlength="500" placeholder="Optional notes about this product"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon" id="btnSaveProduct">
                        <i class="bi bi-check-lg me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>
@endif

@endsection

@push('scripts')
<script>
const API  = '{{ route("warehouse.api") }}';
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

let createModal;
@if($canEditBatches) let editModal; @endif
@if($canManageProducts) let productModal; @endif

document.addEventListener('DOMContentLoaded', function () {
    createModal  = new bootstrap.Modal(document.getElementById('createModal'));
    @if($canEditBatches) editModal = new bootstrap.Modal(document.getElementById('editModal')); @endif
    @if($canManageProducts) productModal = new bootstrap.Modal(document.getElementById('productModal')); @endif

    document.getElementById('cItem').addEventListener('change', function () {
        var opt = this.options[this.selectedIndex];
        var shelf = opt.dataset.shelf;
        var hint = document.getElementById('cShelfHint');
        var btn = document.getElementById('cAutoBtn');
        if (shelf) {
            hint.textContent = 'Shelf life: ' + shelf + ' days';
            btn.classList.remove('d-none');
        } else {
            hint.textContent = '';
            btn.classList.add('d-none');
        }
    });
});

// -- Helpers --
async function postAPI(fields) {
    var fd = new FormData();
    for (var k in fields) fd.append(k, fields[k]);
    var res = await fetch(API, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF }
    });
    return res.json();
}

// -- Filter --
function filterRows(type) {
    var q = document.getElementById('search' + (type === 'active' ? 'Active' : 'Expired')).value.toLowerCase();
    document.querySelectorAll('.row-' + type).forEach(function (row) {
        row.style.display = row.dataset.name.includes(q) ? '' : 'none';
    });
}

// -- Create Stock --
function openCreateModal() {
    document.getElementById('createForm').reset();
    document.getElementById('cShelfHint').textContent = '';
    document.getElementById('cAutoBtn').classList.add('d-none');
    createModal.show();
}

function autoCalcExpiry() {
    var opt = document.getElementById('cItem').options[document.getElementById('cItem').selectedIndex];
    var shelf = parseInt(opt.dataset.shelf) || 0;
    if (shelf > 0) {
        var d = new Date();
        d.setDate(d.getDate() + shelf);
        document.getElementById('cExpiry').valueAsDate = d;
    }
}

async function submitCreate() {
    var itemId = document.getElementById('cItem').value;
    var qty = document.getElementById('cQty').value;
    if (!itemId || !qty) { alert('Please fill in product and quantity.'); return; }

    var btn = document.getElementById('btnCreate');
    btn.disabled = true; btn.textContent = 'Saving...';

    try {
        var r = await postAPI({
            action: 'create_stock',
            item_id: itemId,
            quantity: qty,
            expiry_date: document.getElementById('cExpiry').value,
            remarks: document.getElementById('cRemarks').value
        });
        if (r.ok) { createModal.hide(); location.reload(); }
        else { alert(r.error || 'Error creating stock.'); }
    } catch (e) { alert('Network error.'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Receive Stock'; }
}

// -- Edit Batch --
@if($canEditBatches)
function openEditModal(batchId, itemName, qty, expiry) {
    document.getElementById('eBatchId').value = batchId;
    document.getElementById('eItemName').textContent = itemName;
    document.getElementById('eQty').value = qty;
    document.getElementById('eExpiry').value = expiry || '';
    editModal.show();
}

async function submitEdit() {
    var btn = document.getElementById('btnEdit');
    btn.disabled = true; btn.textContent = 'Saving...';

    try {
        var r = await postAPI({
            action: 'edit_batch',
            batch_id: document.getElementById('eBatchId').value,
            quantity_left: document.getElementById('eQty').value,
            expiry_date: document.getElementById('eExpiry').value
        });
        if (r.ok) { editModal.hide(); location.reload(); }
        else { alert(r.error || 'Error updating batch.'); }
    } catch (e) { alert('Network error.'); }
    finally { btn.disabled = false; btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Save Changes'; }
}
@endif

// -- Delete Batch --
async function deleteBatch(batchId, itemName) {
    if (!confirm('Delete batch for "' + itemName + '"? This cannot be undone.')) return;

    try {
        var r = await postAPI({ action: 'delete_batch', batch_id: batchId });
        if (r.ok) { location.reload(); }
        else { alert(r.error || 'Error deleting batch.'); }
    } catch (e) { alert('Network error.'); }
}

// -- Dispose Batch --
async function disposeBatch(batchId, itemName, qty) {
    var remarks = prompt('Dispose ' + qty + ' unit(s) of "' + itemName + '" as expired?\n\nOptional remarks:');
    if (remarks === null) return; // cancelled

    try {
        var r = await postAPI({ action: 'dispose_batch', batch_id: batchId, remarks: remarks });
        if (r.ok) { alert(r.message); location.reload(); }
        else { alert(r.error || 'Error disposing batch.'); }
    } catch (e) { alert('Network error.'); }
}

// -- Fulfill Order --
@unless($isWarehouseStaff)
async function fulfillOrder(orderId, status) {
    var labels = { approved: 'approve', delivering: 'send out', delivered: 'mark as delivered', cancelled: 'cancel' };
    if (!confirm('Are you sure you want to ' + labels[status] + ' this order?')) return;

    try {
        var r = await postAPI({ action: 'fulfill_order', order_id: orderId, status: status });
        if (r.ok) { alert(r.message); location.reload(); }
        else { alert(r.error || 'Error updating order.'); }
    } catch (e) { alert('Network error.'); }
}
@endunless

// -- QR Printing --
function toggleAll(el) {
    var checked = el.checked;
    document.querySelectorAll('.qr-cb').forEach(function (cb) {
        var row = cb.closest('.row-active');
        if (row && row.style.display !== 'none') cb.checked = checked;
    });
    updatePrintBtn();
}

function updatePrintBtn() {
    var count = document.querySelectorAll('.qr-cb:checked').length;
    var btn = document.getElementById('btnPrintSelected');
    if (count > 0) {
        btn.classList.remove('d-none');
        btn.innerHTML = '<i class="bi bi-printer me-1"></i> Print ' + count + ' QR' + (count !== 1 ? 's' : '');
    } else {
        btn.classList.add('d-none');
    }
}

function printSingleQR(qr, name, unit) {
    printQRLabels([{ qr: qr, name: name, unit: unit }]);
}

function printSelectedQR() {
    var checked = document.querySelectorAll('.qr-cb:checked');
    if (!checked.length) { alert('Select batches first.'); return; }
    var labels = [];
    checked.forEach(function (cb) {
        labels.push({ qr: cb.dataset.qr, name: cb.dataset.name, unit: cb.dataset.unit });
    });
    printQRLabels(labels);
}

// -- Products --
@if($canManageProducts)
function openProductModal(data) {
    var form  = document.getElementById('productForm');
    var title = document.getElementById('productModalTitle');
    form.reset();
    document.getElementById('p_reorder_level').value = 10;

    if (data) {
        title.innerHTML = '<i class="bi bi-pencil me-1"></i> Edit Product';
        document.getElementById('p_id').value              = data.id;
        document.getElementById('p_item_name').value       = data.item_name;
        document.getElementById('p_category_id').value     = data.category_id || '';
        document.getElementById('p_unit').value            = data.unit;
        document.getElementById('p_pack_description').value = data.pack_description || '';
        document.getElementById('p_price').value           = data.price;
        document.getElementById('p_reorder_level').value   = data.reorder_level;
        document.getElementById('p_shelf_life_days').value = (data.shelf_life_days != null && data.shelf_life_days !== '') ? data.shelf_life_days : '';
        document.getElementById('p_remarks').value         = data.remarks || '';
    } else {
        title.innerHTML = '<i class="bi bi-box-seam me-1"></i> Add Product';
        document.getElementById('p_id').value = '';
    }
    productModal.show();
}

async function saveProduct(e) {
    e.preventDefault();
    var form = document.getElementById('productForm');
    var data = new FormData(form);
    data.append('action', 'save_product');
    data.set('shelf_life_days', document.getElementById('p_shelf_life_days').value);

    var btn = document.getElementById('btnSaveProduct');
    btn.disabled = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    try {
        var res  = await fetch(API, {
            method: 'POST', body: data,
            headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
        });
        var json = await res.json();
        if (json.ok) { productModal.hide(); location.reload(); }
        else { alert(json.error || 'Something went wrong.'); }
    } catch (err) {
        alert('Network error.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Save';
    }
}

async function toggleProduct(id, newStatus) {
    var action = newStatus ? 'activate' : 'deactivate';
    if (!confirm('Are you sure you want to ' + action + ' this product?')) return;

    try {
        var r = await postAPI({ action: 'toggle_product', id: id, is_active: newStatus });
        if (r.ok) { location.reload(); }
        else { alert(r.error || 'Something went wrong.'); }
    } catch (err) { alert('Network error.'); }
}
@endif

function filterProducts() {
    var search   = document.getElementById('searchProducts').value.toLowerCase();
    var category = document.getElementById('filterCategory').value;
    var goods    = document.getElementById('filterGoods').value;
    var rows     = document.querySelectorAll('.product-row');
    var visible  = 0;

    rows.forEach(function (row) {
        var show = true;
        if (search   && !row.dataset.pname.includes(search))      show = false;
        if (category && row.dataset.pcategory !== category)        show = false;
        if (goods    && row.dataset.pgoods !== goods)              show = false;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    var countEl = document.getElementById('productItemCount');
    if (countEl) countEl.textContent = 'Showing ' + visible + ' item' + (visible !== 1 ? 's' : '');
}

function printQRLabels(labels) {
    var w = window.open('', 'qr_print', 'width=820,height=600');
    if (!w) { alert('Please allow pop-ups.'); return; }
    function esc(s) { return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    var d = w.document;
    d.open();
    d.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>QR Labels</title>');
    d.write('<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;padding:10mm;color:#222}.bar{text-align:center;margin-bottom:8mm}.bar button{padding:8px 28px;font-size:14px;cursor:pointer;background:#7A1717;color:#fff;border:none;border-radius:6px;font-weight:600}.grid{display:flex;flex-wrap:wrap;gap:5mm;justify-content:center}.lbl{width:58mm;border:1px dashed #bbb;border-radius:2mm;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4mm 3mm;page-break-inside:avoid}.lbl svg{width:26mm;height:26mm}.lbl .n{font-weight:700;font-size:9pt;margin-top:2mm;text-align:center}.lbl .c{font-size:7.5pt;color:#555;margin-top:1mm;font-family:monospace}.lbl .u{font-size:7pt;color:#999;margin-top:.5mm}@media print{.bar{display:none}body{padding:5mm}}</style></head><body>');
    d.write('<div class="bar"><button onclick="window.print()"><b>Print Labels</b></button> <span style="color:#888;margin-left:10px;">' + labels.length + ' label(s)</span></div>');
    d.write('<div class="grid" id="g"></div></body></html>');
    d.close();
    var scr = d.createElement('script');
    scr.src = 'https://cdnjs.cloudflare.com/ajax/libs/qrcode-generator/1.4.4/qrcode.min.js';
    scr.onload = function () {
        var grid = d.getElementById('g');
        labels.forEach(function (l) {
            var q = w.qrcode(0, 'M'); q.addData(l.qr); q.make();
            var el = d.createElement('div'); el.className = 'lbl';
            el.innerHTML = q.createSvgTag(3,0) + '<div class="n">' + esc(l.name) + '</div><div class="c">' + esc(l.qr) + '</div><div class="u">' + esc(l.unit) + '</div>';
            grid.appendChild(el);
        });
    };
    d.head.appendChild(scr);
}
</script>
@endpush
