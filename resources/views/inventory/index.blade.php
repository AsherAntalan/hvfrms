@extends('layouts.app')

@section('title', $isMgr ? 'Inventory' : 'Products')

@section('content')

@include('partials.production-shipments')

{{-- ═══ PAGE HEADER ═══════════════════════════════════════ --}}
<div class="hv-page-header">
    <div>{{ $scopeLabel }}</div>
    <div class="d-flex align-items-center gap-2">
        @if(auth()->user()->isBranchManager() && auth()->user()->branch?->branch_type !== 'main')
        <a class="btn btn-maroon btn-sm" href="{{ route('ordering') }}">Create Commissary Order</a>
        @endif
        @if($canManageProducts)
        <button class="btn btn-maroon btn-sm" onclick="openModal()">
            <i class="ph ph-plus me-1"></i> Add Product
        </button>
        @endif
    </div>
</div>

{{-- ═══ TABS (Branch Manager only) ════════════════════════ --}}
@if($showMovements)
<div class="d-flex flex-wrap gap-2 mb-3" role="tablist" aria-label="Inventory views">
    <a class="btn btn-sm hv-tab-btn {{ $tab === 'inventory' ? 'active' : '' }}"
       href="{{ route('inventory') }}" aria-current="{{ $tab === 'inventory' ? 'page' : 'false' }}">Inventory</a>
    <a class="btn btn-sm hv-tab-btn {{ $tab === 'movements' ? 'active' : '' }}"
       href="{{ route('inventory', ['tab' => 'movements']) }}" aria-current="{{ $tab === 'movements' ? 'page' : 'false' }}">Movement History</a>
</div>
@endif

@if($tab === 'inventory')
{{-- ═══ FILTER BAR ════════════════════════════════════════ --}}
<div class="hv-card p-2 mb-3">
    <div class="row g-2 align-items-center">
        <div class="col-md-4">
            <input type="text" class="form-control form-control-sm" id="searchBox"
                   placeholder="Search products..." oninput="filterTable()">
        </div>
        <div class="col-md-3">
            <select class="form-select form-select-sm" id="filterCategory" onchange="filterTable()">
                <option value="">All Categories</option>
                @foreach($categories as $cat)
                    <option value="{{ e($cat->name) }}">{{ e($cat->name) }} ({{ $cat->goods_type }})</option>
                @endforeach
            </select>
        </div>
        <div class="col-md-2">
            <select class="form-select form-select-sm" id="filterGoods" onchange="filterTable()">
                <option value="">All Types</option>
                <option value="dry">Dry Goods</option>
                <option value="wet">Wet Goods</option>
            </select>
        </div>
        <div class="col-md-3 text-end">
            <span class="text-muted small" id="itemCount">
                Showing {{ count($items) }} item{{ count($items) !== 1 ? 's' : '' }}
            </span>
        </div>
    </div>
</div>

{{-- ═══ PRODUCT / INVENTORY TABLE ═════════════════════════ --}}
<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0" id="productTable">
            <thead>
                <tr>
                    <th class="ps-3">Product Name</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th class="text-center" title="All remaining units at {{ $scopeLabel }}, including expired/damaged">Physical Stock</th>
                    <th class="text-center" title="Good, unexpired remaining units; some may be reserved for a commissary order">Usable Stock</th>
                    <th class="text-center">Reorder Lvl</th>
                    <th class="text-center">Shelf Life</th>
                    <th class="text-center">Catalog Status</th>
                </tr>
            </thead>
            <tbody>
            @if($items->isEmpty())
                <tr id="emptyRow">
                    <td colspan="8" class="text-center text-muted py-4">
                        No products found.@if($canManageProducts) Click "Add Product" to create one.@endif
                    </td>
                </tr>
            @else
                @foreach($items as $item)
                    @php
                        $physical = (int)($item->physical_stock ?? 0);
                        $usable   = (int)($item->usable_stock ?? 0);
                        $expiredQty = (int)($item->expired_stock ?? 0);
                        $damagedQty = (int)($item->damaged_stock ?? 0);
                        $exp = $item->nearest_expiry ?? null;
                        $warnStr = date('Y-m-d', strtotime('+7 days'));
                        $expWarning = ($exp && $exp <= $warnStr);
                        $rl = (int)$item->reorder_level;
                    @endphp
                    <tr class="product-row {{ $item->is_active ? '' : 'table-secondary' }}"
                        style="cursor:pointer" title="Click to view details"
                        data-name="{{ e(strtolower($item->item_name)) }}"
                        data-category="{{ e($item->category_name ?? '') }}"
                        data-goods="{{ e($item->goods_type ?? '') }}"
                        onclick="openHistory({{ $item->id }}, @js($item->item_name))">
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
                        {{-- Physical stock (includes expired/damaged) --}}
                        <td class="text-center">
                            <span class="fw-semibold">{{ $physical }}</span>
                            @if($expiredQty > 0)
                                <div class="text-danger small"><i class="ph-fill ph-x-circle"></i> {{ $expiredQty }} expired</div>
                            @endif
                            @if($damagedQty > 0)
                                <div class="text-muted small"><i class="ph ph-prohibit"></i> {{ $damagedQty }} damaged</div>
                            @endif
                        </td>
                        {{-- Usable stock (eligible only) --}}
                        <td class="text-center">
                            <span class="fw-bold {{ $usable === 0 ? 'text-danger' : ($usable <= $rl ? 'text-warning' : 'text-success') }}">
                                {{ $usable }}
                            </span>
                            @if($usable === 0 && $physical > 0)
                                <div class="text-danger small" title="Enabled product, but no issuable stock">None usable</div>
                            @elseif($usable > 0 && $usable <= $rl)
                                <div class="text-warning small"><i class="ph-fill ph-warning"></i> Low</div>
                            @endif
                            @if($usable > 0 && $expWarning)
                                <div class="text-warning small"><i class="ph-fill ph-clock"></i> Exp {{ date('M j', strtotime($exp)) }}</div>
                            @endif
                        </td>
                        <td class="text-center">{{ (int)$item->reorder_level }}</td>
                        <td class="text-center">
                            {{ $item->shelf_life_days ? $item->shelf_life_days . 'd' : '—' }}
                        </td>
                        <td class="text-center">
                            @if($item->is_active)
                                <span class="hv-badge hv-badge-success" title="Available for commissary orders; finished stock is not required">Available</span>
                            @else
                                <span class="hv-badge hv-badge-danger">Unavailable</span>
                            @endif
                        </td>
                    </tr>
                @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>
@endif

{{-- ═══ MOVEMENT HISTORY TAB ══════════════════════════════ --}}
@if($tab === 'movements')
@php
    $mvTypes = ['in' => 'IN', 'out' => 'OUT', 'adjustment' => 'Adjustment', 'expired' => 'Expired', 'damaged' => 'Damaged'];
    $mvHasFilters = ($movementFilters['search'] ?? '') !== '' || ($movementFilters['from'] ?? '') !== ''
        || ($movementFilters['to'] ?? '') !== '' || ($movementFilters['type'] ?? '') !== '' || ($movementFilters['category'] ?? '') !== '';
@endphp
<div class="hv-card p-2 mb-3">
    <form method="GET" action="{{ route('inventory') }}" class="row g-2 align-items-end">
        <input type="hidden" name="tab" value="movements">
        <div class="col-12 col-md-4">
            <label class="form-label small mb-1" for="mvSearch">Search</label>
            <input type="text" class="form-control form-control-sm" id="mvSearch" name="search"
                   maxlength="100" value="{{ $movementFilters['search'] }}"
                   placeholder="Product, batch #, QR, or order…">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="mvFrom">Date from</label>
            <input type="date" class="form-control form-control-sm" id="mvFrom" name="from" value="{{ $movementFilters['from'] }}">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="mvTo">Date to</label>
            <input type="date" class="form-control form-control-sm" id="mvTo" name="to" value="{{ $movementFilters['to'] }}">
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="mvType">Movement type</label>
            <select class="form-select form-select-sm" id="mvType" name="type">
                <option value="">All types</option>
                @foreach($mvTypes as $value => $label)
                    <option value="{{ $value }}" @selected($movementFilters['type'] === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-6 col-md-2">
            <label class="form-label small mb-1" for="mvCategory">Category</label>
            <select class="form-select form-select-sm" id="mvCategory" name="category">
                <option value="">All categories</option>
                @foreach($categories as $cat)
                    <option value="{{ $cat->id }}" @selected((string) $movementFilters['category'] === (string) $cat->id)>{{ e($cat->name) }}</option>
                @endforeach
            </select>
        </div>
        <div class="col-12 d-flex gap-2">
            <button type="submit" class="btn btn-sm btn-maroon"><i class="ph ph-funnel me-1"></i> Apply filters</button>
            <a class="btn btn-sm btn-outline-secondary" href="{{ route('inventory', ['tab' => 'movements']) }}">Clear</a>
        </div>
    </form>
</div>

<div class="hv-card p-0">
    <div class="table-responsive">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th class="ps-3">Date/Time</th>
                    <th>Product</th>
                    <th>Type</th>
                    <th class="text-end">Quantity</th>
                    <th>Batch</th>
                    <th>Order</th>
                    <th>Reason</th>
                    <th>By</th>
                </tr>
            </thead>
            <tbody>
            @forelse($movements as $tx)
                @php
                    $mvColors = ['in' => 'success', 'out' => 'danger', 'adjustment' => 'warning', 'expired' => 'dark', 'damaged' => 'secondary'];
                    // Quantity is stored signed; show the stored sign, never add a second one.
                    $qty = (int) $tx->quantity;
                    $qtySign = $qty > 0 ? '+' : '';
                    $qtyClass = $qty > 0 ? 'text-success' : ($qty < 0 ? 'text-danger' : 'text-muted');
                    $ref = $movementOrderRefs[$tx->id] ?? null;
                @endphp
                <tr>
                    <td class="ps-3 small text-nowrap">{{ $tx->created_at?->timezone(config('app.timezone'))->format('M j, Y g:i A') }}</td>
                    <td>
                        {{ e($tx->item->item_name ?? '—') }}
                        @if($tx->item && ! $tx->item->is_active)
                            <span class="hv-badge hv-badge-danger ms-1">Inactive</span>
                        @endif
                    </td>
                    <td><span class="badge bg-{{ $mvColors[$tx->type] ?? 'secondary' }}">{{ strtoupper($tx->type) }}</span></td>
                    <td class="text-end fw-bold {{ $qtyClass }} text-nowrap">
                        {{ $qtySign }}{{ $qty }} <span class="text-muted fw-normal small">{{ e($tx->item->unit ?? '') }}</span>
                    </td>
                    <td>
                        @if($tx->batch_id)
                            <a href="{{ route('stock.batch', $tx->batch_id) }}">#{{ $tx->batch_id }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td>
                        @if($ref)
                            <a href="{{ route('orders', ['order' => $ref['id']]) }}">{{ e($ref['number']) }}</a>
                        @else
                            —
                        @endif
                    </td>
                    <td class="small">{{ e($tx->reason) ?: '—' }}</td>
                    <td class="small text-nowrap">{{ e($tx->performer->full_name ?? '—') }}</td>
                </tr>
            @empty
                <tr>
                    <td colspan="8" class="text-center text-muted py-4">
                        {{ $mvHasFilters ? 'No movements match your filters.' : 'No inventory movements recorded yet.' }}
                    </td>
                </tr>
            @endforelse
            </tbody>
        </table>
    </div>
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 p-2 border-top">
        <span class="text-muted small">
            @if($movements->total() > 0)
                Showing {{ $movements->firstItem() }}–{{ $movements->lastItem() }} of {{ $movements->total() }} record{{ $movements->total() !== 1 ? 's' : '' }}
            @else
                0 records
            @endif
        </span>
        <div>{{ $movements->onEachSide(1)->links() }}</div>
    </div>
</div>
@endif

{{-- ═══ ADD / EDIT PRODUCT MODAL ══════════════════════════ --}}
@if($canManageProducts)
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="itemForm" onsubmit="saveItem(event)" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="id" id="f_id">

                <div class="modal-header" style="background:var(--gold-soft);">
                    <h5 class="modal-title" id="modalTitle" style="color:var(--maroon);font-weight:700;">
                        <i class="ph ph-package me-1"></i> Add Product
                    </h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>

                <div class="modal-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Product Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="item_name" id="f_item_name"
                                       required maxlength="150" placeholder="e.g. Pho Sauce">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Category <span class="text-danger">*</span></label>
                                <select class="form-select" name="category_id" id="f_category_id" required>
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
                                <input type="text" class="form-control" name="unit" id="f_unit"
                                       required maxlength="50" placeholder="e.g. pack, liter, pcs, grams">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Pack Description</label>
                                <input type="text" class="form-control" name="pack_description" id="f_pack_description"
                                       maxlength="100" placeholder="e.g. 5pcs/pack, 900 grams, 1 Liter">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Price (PHP) <span class="text-danger">*</span></label>
                                <input type="number" class="form-control" name="price" id="f_price"
                                       required step="0.01" min="0" placeholder="0.00">
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Reorder Level</label>
                                <input type="number" class="form-control" name="reorder_level" id="f_reorder_level"
                                       min="0" value="10" placeholder="10">
                                <div class="form-text">Alert when stock falls below this number.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Shelf Life (days)</label>
                                <input type="number" class="form-control" name="shelf_life_days" id="f_shelf_life_days"
                                       min="1" placeholder="e.g. 30">
                                <div class="form-text">Used for FIFO expiry tracking.</div>
                            </div>
                            <div class="mb-3">
                                <label class="form-label fw-semibold">Product Image</label>
                                <input type="file" class="form-control" name="image" id="f_image"
                                       accept="image/jpeg,image/png,image/webp">
                                <div class="form-text">JPG, PNG, or WebP. Max 2MB.</div>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Remarks</label>
                        <textarea class="form-control" name="remarks" id="f_remarks"
                                  rows="2" maxlength="500" placeholder="Optional notes about this product"></textarea>
                    </div>
                </div>

                <div class="modal-footer">
                    <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" class="btn btn-maroon" id="btnSave">
                        <i class="ph ph-check me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endif

{{-- ═══ MOVEMENT HISTORY MODAL (opened by clicking a row) ══ --}}
<div class="modal fade" id="historyModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header" style="background:var(--gold-soft);">
                <h5 class="modal-title" id="hTitle" style="color:var(--maroon);font-weight:700;">
                    <i class="ph ph-clock-counter-clockwise me-1"></i> Movement History
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="hBody">
                <div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

@endsection

@push('styles')
<style>
.hv-tab-btn { border:1px solid var(--line, #e7e0d4); background:#fff; color:var(--maroon); font-weight:600; text-decoration:none; }
.hv-tab-btn.active { background:var(--maroon); color:#fff; border-color:var(--maroon); }
</style>
@endpush

@push('scripts')
<script>
const API  = '{{ route("inventory.api") }}';
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

let historyModal;
const HISTORY_BASE = '{{ url('stock/history') }}';
const BATCH_BASE   = '{{ url('stock/batch') }}';
var _histItem = { id: null, name: '' };
@if($canManageProducts) let itemModal; @endif

document.addEventListener('DOMContentLoaded', function () {
    historyModal = new bootstrap.Modal(document.getElementById('historyModal'));
    @if($canManageProducts) itemModal = new bootstrap.Modal(document.getElementById('itemModal')); @endif

    // Batch links inside the history modal open the batch detail in the same
    // modal instead of navigating away.
    document.getElementById('hBody').addEventListener('click', function (e) {
        var a = e.target.closest('a[href*="/stock/batch/"]');
        if (!a) { return; }
        e.preventDefault();
        var m = a.getAttribute('href').match(/\/stock\/batch\/(\d+)/);
        if (m) { openBatch(m[1]); }
    });
});

function dEsc(s) { var d = document.createElement('div'); d.textContent = (s == null ? '' : s); return d.innerHTML; }

async function loadIntoHistory(url, title) {
    document.getElementById('hTitle').innerHTML = title;
    var body = document.getElementById('hBody');
    body.innerHTML = '<div class="text-center text-muted py-5"><span class="spinner-border spinner-border-sm me-2"></span>Loading…</div>';
    try {
        var res = await fetch(url, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        if (!res.ok) { throw new Error('http'); }
        return await res.text();
    } catch (e) {
        body.innerHTML = '<div class="text-center text-danger py-4">Could not load. Please try again.</div>';
        return null;
    }
}

// Row click → open this product's Movement History in a modal (AJAX partial).
async function openHistory(id, name) {
    _histItem = { id: id, name: name };
    historyModal.show();
    var html = await loadIntoHistory(HISTORY_BASE + '/' + id,
        '<i class="ph ph-clock-counter-clockwise me-1"></i> ' + dEsc(name) + ' — Movement History');
    if (html != null) { document.getElementById('hBody').innerHTML = html; }
}

// Batch "view" (eye) → batch detail in the same modal, with a Back button.
async function openBatch(batchId) {
    var html = await loadIntoHistory(BATCH_BASE + '/' + batchId,
        '<i class="ph ph-package me-1"></i> Batch #' + dEsc(batchId));
    if (html == null) { return; }
    var back = '<div class="mb-3"><button type="button" class="btn btn-sm btn-outline-secondary" onclick="openHistory(_histItem.id, _histItem.name)">'
        + '<i class="ph ph-arrow-left me-1"></i> Back to History</button></div>';
    document.getElementById('hBody').innerHTML = back + html;
}

function filterTable() {
    var search   = document.getElementById('searchBox').value.toLowerCase();
    var category = document.getElementById('filterCategory').value;
    var goods    = document.getElementById('filterGoods').value;
    var rows     = document.querySelectorAll('.product-row');
    var visible  = 0;

    rows.forEach(function (row) {
        var show = true;
        if (search   && !row.dataset.name.includes(search))  show = false;
        if (category && row.dataset.category !== category)    show = false;
        if (goods    && row.dataset.goods !== goods)          show = false;
        row.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('itemCount').textContent =
        'Showing ' + visible + ' item' + (visible !== 1 ? 's' : '');
}

@if($canManageProducts)
function openModal(data) {
    var form  = document.getElementById('itemForm');
    var title = document.getElementById('modalTitle');

    form.reset();
    document.getElementById('f_reorder_level').value = 10;

    if (data) {
        title.innerHTML = '<i class="ph ph-pencil-simple me-1"></i> Edit Product';
        document.getElementById('f_id').value              = data.id;
        document.getElementById('f_item_name').value       = data.item_name;
        document.getElementById('f_category_id').value     = data.category_id || '';
        document.getElementById('f_unit').value            = data.unit;
        document.getElementById('f_pack_description').value = data.pack_description || '';
        document.getElementById('f_price').value           = data.price;
        document.getElementById('f_reorder_level').value   = data.reorder_level;
        document.getElementById('f_shelf_life_days').value = (data.shelf_life_days != null && data.shelf_life_days !== '') ? data.shelf_life_days : '';
        document.getElementById('f_remarks').value         = data.remarks || '';
    } else {
        title.innerHTML = '<i class="ph ph-package me-1"></i> Add Product';
        document.getElementById('f_id').value = '';
    }

    itemModal.show();
}

async function saveItem(e) {
    e.preventDefault();
    var form = document.getElementById('itemForm');
    var data = new FormData(form);
    data.append('action', 'save');
    data.set('shelf_life_days', document.getElementById('f_shelf_life_days').value);

    var btn = document.getElementById('btnSave');
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Saving...';

    try {
        var res  = await fetch(API, {
            method: 'POST', body: data,
            headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
        });
        var json = await res.json();
        if (json.ok) { itemModal.hide(); location.reload(); }
        else { alert(json.error || 'Something went wrong.'); }
    } catch (err) {
        alert('Network error.');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="ph ph-check me-1"></i> Save';
    }
}

@endif

async function toggleItem(id, newStatus) {
    var action = newStatus ? 'make this product available for orders' : 'mark this product unavailable for orders';
    if (!confirm('Do you want to ' + action + '?')) return;

    var data = new FormData();
    data.append('action', 'toggle');
    data.append('id', id);
    data.append('is_active', newStatus);

    try {
        var res  = await fetch(API, {
            method: 'POST', body: data,
            headers: { 'X-CSRF-TOKEN': CSRF, 'X-Requested-With': 'XMLHttpRequest' }
        });
        var json = await res.json();
        if (json.ok) { location.reload(); }
        else { alert(json.error || 'Something went wrong.'); }
    } catch (err) { alert('Network error.'); }
}

</script>
@endpush
