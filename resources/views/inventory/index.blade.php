@extends('layouts.app')

@section('title', $isMgr ? 'Branch Inventory' : 'Products')

@section('content')

{{-- ═══ PAGE HEADER ═══════════════════════════════════════ --}}
<div class="hv-page-header">
    <div></div>
    <div class="d-flex align-items-center gap-2">
        <button class="btn btn-outline-secondary btn-sm d-none" id="btnPrintSelected" onclick="printSelectedQR()">
            <i class="bi bi-printer me-1"></i> Print Selected QR
        </button>
        <button class="btn btn-maroon btn-sm" onclick="openModal()">
            <i class="bi bi-plus-lg me-1"></i> Add Product
        </button>
    </div>
</div>

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
                    <th class="text-center" style="width:36px;">
                        <input type="checkbox" class="form-check-input" id="selectAll" onchange="toggleSelectAll()" title="Select all for QR print">
                    </th>
                    <th class="ps-3">Product Name</th>
                    <th>Category</th>
                    <th>Unit</th>
                    <th class="text-end">Price</th>
                    <th class="text-center">{{ $isMgr ? 'Your Stock' : 'Total Stock' }}</th>
                    <th class="text-center">Reorder Lvl</th>
                    <th class="text-center">Shelf Life</th>
                    <th class="text-center">QR</th>
                    <th class="text-center">Status</th>
                    <th class="text-center">Actions</th>
                </tr>
            </thead>
            <tbody>
            @if($items->isEmpty())
                <tr id="emptyRow">
                    <td colspan="11" class="text-center text-muted py-4">
                        No products found. Click "Add Product" to create one.
                    </td>
                </tr>
            @else
                @foreach($items as $item)
                    @php
                        if ($isMgr) {
                            $stockVal = (int)$item->my_stock;
                            $exp = $item->nearest_expiry ?? null;
                            $warnStr = date('Y-m-d', strtotime('+7 days'));
                            $todayStr = date('Y-m-d');
                            $expWarning = ($exp && $exp <= $warnStr);
                            $expExpired = ($exp && $exp <= $todayStr);
                        } else {
                            $stockVal = (int)$item->total_stock;
                            $expWarning = false;
                            $expExpired = false;
                        }
                        $rl = (int)$item->reorder_level;
                    @endphp
                    <tr class="product-row {{ $item->is_active ? '' : 'table-secondary' }}"
                        data-name="{{ e(strtolower($item->item_name)) }}"
                        data-category="{{ e($item->category_name ?? '') }}"
                        data-goods="{{ e($item->goods_type ?? '') }}">
                        <td class="text-center">
                            @if(!empty($item->qr_code))
                            <input type="checkbox" class="form-check-input qr-check" onchange="updatePrintBtn()"
                                   data-qr="{{ e($item->qr_code) }}"
                                   data-name="{{ e($item->item_name) }}"
                                   data-unit="{{ e($item->unit) }}">
                            @endif
                        </td>
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
                            @if($isMgr)
                                @if($stockVal > 0 && $stockVal <= $rl)
                                    <div class="text-warning small"><i class="bi bi-exclamation-triangle-fill"></i> Low</div>
                                @endif
                                @if($expExpired)
                                    <div class="text-danger small"><i class="bi bi-x-circle-fill"></i> Expired {{ date('M j', strtotime($exp)) }}</div>
                                @elseif($expWarning)
                                    <div class="text-warning small"><i class="bi bi-clock-fill"></i> Exp {{ date('M j', strtotime($exp)) }}</div>
                                @endif
                            @endif
                        </td>
                        <td class="text-center">{{ (int)$item->reorder_level }}</td>
                        <td class="text-center">
                            {{ $item->shelf_life_days ? $item->shelf_life_days . 'd' : '—' }}
                        </td>
                        <td class="text-center">
                            @if(!empty($item->qr_code))
                                <button class="btn btn-sm btn-outline-secondary py-0 px-1" title="Print QR label"
                                        onclick="printSingleQR(this)"
                                        data-qr="{{ e($item->qr_code) }}"
                                        data-name="{{ e($item->item_name) }}"
                                        data-unit="{{ e($item->unit) }}">
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
                                <a href="{{ route('stock.history', $item->id) }}" class="btn btn-sm btn-outline-primary" title="Movement History">
                                    <i class="bi bi-clock-history"></i>
                                </a>
                                <button class="btn btn-sm btn-outline-secondary" title="Edit"
                                        onclick="openModal(@js($item))">
                                    <i class="bi bi-pencil"></i>
                                </button>
                                @if($item->is_active)
                                    <button class="btn btn-sm btn-outline-danger" title="Deactivate"
                                            onclick="toggleItem({{ $item->id }}, 0)">
                                        <i class="bi bi-x-circle"></i>
                                    </button>
                                @else
                                    <button class="btn btn-sm btn-outline-success" title="Activate"
                                            onclick="toggleItem({{ $item->id }}, 1)">
                                        <i class="bi bi-check-circle"></i>
                                    </button>
                                @endif
                            </div>
                        </td>
                    </tr>
                @endforeach
            @endif
            </tbody>
        </table>
    </div>
</div>

{{-- ═══ ADD / EDIT PRODUCT MODAL ══════════════════════════ --}}
<div class="modal fade" id="itemModal" tabindex="-1">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <form id="itemForm" onsubmit="saveItem(event)" enctype="multipart/form-data">
                @csrf
                <input type="hidden" name="id" id="f_id">

                <div class="modal-header" style="background:var(--gold-soft);">
                    <h5 class="modal-title" id="modalTitle" style="color:var(--maroon);font-weight:700;">
                        <i class="bi bi-box-seam me-1"></i> Add Product
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
                        <i class="bi bi-check-lg me-1"></i> Save
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

@endsection

@push('scripts')
<script>
const API  = '{{ route("inventory.api") }}';
const CSRF = document.querySelector('meta[name="csrf-token"]').content;

let itemModal;

document.addEventListener('DOMContentLoaded', function () {
    itemModal = new bootstrap.Modal(document.getElementById('itemModal'));
});

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

function openModal(data) {
    var form  = document.getElementById('itemForm');
    var title = document.getElementById('modalTitle');

    form.reset();
    document.getElementById('f_reorder_level').value = 10;

    if (data) {
        title.innerHTML = '<i class="bi bi-pencil me-1"></i> Edit Product';
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
        title.innerHTML = '<i class="bi bi-box-seam me-1"></i> Add Product';
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
        btn.innerHTML = '<i class="bi bi-check-lg me-1"></i> Save';
    }
}

async function toggleItem(id, newStatus) {
    var action = newStatus ? 'activate' : 'deactivate';
    if (!confirm('Are you sure you want to ' + action + ' this product?')) return;

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

function toggleSelectAll() {
    var checked = document.getElementById('selectAll').checked;
    document.querySelectorAll('.qr-check').forEach(function (cb) {
        var row = cb.closest('.product-row');
        if (row && row.style.display !== 'none') cb.checked = checked;
    });
    updatePrintBtn();
}

function updatePrintBtn() {
    var count = document.querySelectorAll('.qr-check:checked').length;
    var btn   = document.getElementById('btnPrintSelected');
    if (!btn) return;
    if (count > 0) {
        btn.classList.remove('d-none');
        btn.innerHTML = '<i class="bi bi-printer me-1"></i> Print ' + count + ' QR Label' + (count !== 1 ? 's' : '');
    } else {
        btn.classList.add('d-none');
    }
}

function printSingleQR(el) {
    printQRLabels([{ qr: el.dataset.qr, name: el.dataset.name, unit: el.dataset.unit }]);
}

function printSelectedQR() {
    var checked = document.querySelectorAll('.qr-check:checked');
    if (!checked.length) { alert('Select products first.'); return; }
    var labels = [];
    checked.forEach(function (cb) {
        labels.push({ qr: cb.dataset.qr, name: cb.dataset.name, unit: cb.dataset.unit });
    });
    printQRLabels(labels);
}

function printQRLabels(labels) {
    var w = window.open('', 'qr_print', 'width=820,height=600');
    if (!w) { alert('Please allow pop-ups to print QR labels.'); return; }
    function esc(s) { return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
    var d = w.document;
    d.open();
    d.write('<!DOCTYPE html><html><head><meta charset="utf-8"><title>QR Labels</title>');
    d.write('<style>*{margin:0;padding:0;box-sizing:border-box}body{font-family:Arial,sans-serif;padding:10mm;color:#222}.bar{text-align:center;margin-bottom:8mm}.bar button{padding:8px 28px;font-size:14px;cursor:pointer;background:#7A1717;color:#fff;border:none;border-radius:6px;font-weight:600}.bar button:hover{background:#5C1010}.bar .info{margin-left:10px;color:#888;font-size:13px}.grid{display:flex;flex-wrap:wrap;gap:5mm;justify-content:center}.lbl{width:58mm;border:1px dashed #bbb;border-radius:2mm;display:flex;flex-direction:column;align-items:center;justify-content:center;padding:4mm 3mm;page-break-inside:avoid}.lbl svg{width:26mm;height:26mm}.lbl .n{font-weight:700;font-size:9pt;margin-top:2mm;text-align:center;line-height:1.3}.lbl .c{font-size:7.5pt;color:#555;margin-top:1mm;font-family:monospace;letter-spacing:.5px}.lbl .u{font-size:7pt;color:#999;margin-top:.5mm}@media print{.bar{display:none}body{padding:5mm}}</style></head><body>');
    d.write('<div class="bar"><button onclick="window.print()"><b>Print Labels</b></button><span class="info">' + labels.length + ' label' + (labels.length > 1 ? 's' : '') + '</span></div>');
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
