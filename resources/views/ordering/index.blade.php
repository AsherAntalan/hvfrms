@extends('layouts.app')
@section('title', 'Order Supplies')

@section('content')

@include('partials.orders-navigation')

@if(!$isFranchise)
<div class="alert alert-warning">
    <i class="ph ph-info me-1"></i>
    Ordering is available for franchise branches only.
</div>
@else

<!-- ═══ STORE BANNER ═════════════════════════════════════ -->
<div class="hv-card p-4 mb-3" style="background:linear-gradient(135deg, var(--maroon), #5c1010); color:#fff; border:none;">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-3">
        <div>
            <h4 class="mb-1 fw-bold"><i class="ph ph-storefront me-2"></i> Main Branch Commissary</h4>
            <div class="opacity-75">Products are prepared after you place an order.</div>
        </div>
        <div style="width:320px;">
            <div class="input-group">
                <span class="input-group-text bg-white border-0"><i class="ph ph-magnifying-glass text-muted"></i></span>
                <input type="text" class="form-control border-0" id="searchBox"
                       placeholder="Search products..." oninput="filterProducts()">
            </div>
        </div>
    </div>
</div>

<!-- ═══ PENDING ORDERS NOTICE ═════════════════════════════ -->
@if($pendingOrders > 0)
<div class="alert alert-info py-2 small mb-3">
    <i class="ph ph-info me-1"></i>
    You have <strong>{{ $pendingOrders }}</strong> pending order{{ $pendingOrders > 1 ? 's' : '' }} awaiting approval.
    <a href="{{ route('orders') }}" class="alert-link">View Orders &rarr;</a>
</div>
@endif

<!-- ═══ CATEGORY FILTERS ═════════════════════════════════ -->
@php
    $catCounts = [];
    foreach ($items as $it) {
        $cn = $it->category_name ?? 'Uncategorized';
        $catCounts[$cn] = ($catCounts[$cn] ?? 0) + 1;
    }
@endphp
<div class="d-flex align-items-center gap-2 flex-wrap mb-2">
    <button class="btn btn-sm rounded-pill cat-pill active" data-cat="" onclick="filterByCategory(this)">
        All <span class="badge bg-white text-dark ms-1">{{ count($items) }}</span>
    </button>
    @foreach($catCounts as $catName => $catCount)
        <button class="btn btn-sm rounded-pill cat-pill" data-cat="{{ $catName }}" onclick="filterByCategory(this)">
            {{ $catName }} <span class="badge bg-white text-dark ms-1">{{ $catCount }}</span>
        </button>
    @endforeach
</div>

<div class="d-flex justify-content-between align-items-center mb-3">
    <span class="text-muted small" id="productCount">Showing {{ count($items) }} products</span>
    <select class="form-select form-select-sm" id="sortSelect" onchange="sortProducts()" style="width:160px;">
        <option value="name-asc">Sort: Name A-Z</option>
        <option value="name-desc">Sort: Name Z-A</option>
        <option value="price-asc">Sort: Price Low</option>
        <option value="price-desc">Sort: Price High</option>
    </select>
</div>

<!-- ═══ PRODUCT CARDS GRID ═══════════════════════════════ -->
<div class="row g-3 mb-4" id="productGrid">
    @foreach($items as $item)
    @php
        $myStock = (int)$item->my_stock;
        $isAvailable = (bool)$item->is_active;
        $reorder = (int)$item->reorder_level;
        $lowStock = $myStock > 0 && $myStock <= $reorder;
    @endphp
    <div class="col-6 col-md-4 col-lg-3 col-xl product-card"
         data-name="{{ strtolower($item->item_name) }}"
         data-category="{{ $item->category_name ?? '' }}"
         data-price="{{ $item->price }}"
         data-id="{{ $item->id }}"
         data-item-name="{{ $item->item_name }}"
         data-unit="{{ $item->unit }}"
         data-image="{{ $item->image_path ? asset($item->image_path) : '' }}"
         data-suggested="{{ (int)($item->suggested_qty ?? 0) }}"
         data-available="{{ $isAvailable ? '1' : '0' }}">
        <div class="hv-card hv-prod h-100 p-0 d-flex flex-column {{ $isAvailable ? '' : 'is-off' }}">
            <!-- Product Image -->
            <div class="hv-prod-img">
                @if($item->image_path)
                    <img src="{{ asset($item->image_path) }}" alt="{{ $item->item_name }}">
                @else
                    <span class="hv-prod-ph" aria-hidden="true"><i class="ph ph-bag"></i></span>
                @endif
                @if($item->category_name)
                    <span class="hv-prod-cat">{{ $item->category_name }}</span>
                @endif
                @if($lowStock)<span class="hv-prod-flag">Low stock</span>@endif
            </div>
            <!-- Product Info -->
            <div class="hv-prod-body d-flex flex-column flex-grow-1">
                <h3 class="hv-prod-name">{{ $item->item_name }}</h3>
                <div class="hv-prod-sub">{{ $item->pack_description ?: 'Per '.$item->unit }}</div>
                <div class="hv-prod-price">&#8369;{{ number_format($item->price, 2) }}<span>/{{ $item->unit }}</span></div>

                <div class="hv-prod-meta">
                    @if($isAvailable)
                        <span class="hv-prod-chip ok"><i class="ph-fill ph-check-circle"></i> Made to order</span>
                    @else
                        <span class="hv-prod-chip off"><i class="ph-fill ph-x-circle"></i> Unavailable</span>
                    @endif
                    <span class="hv-prod-stock {{ $lowStock ? 'is-low' : '' }}">Your stock: <b>{{ $myStock }}</b></span>
                    @if((int)($item->suggested_qty ?? 0) > 0)
                        <span class="hv-prod-sugg" title="Forecast-suggested quantity"><i class="ph ph-trend-up"></i> Suggested: {{ (int)$item->suggested_qty }}</span>
                    @endif
                </div>

                <div class="mt-auto pt-2">
                    @if($isAvailable)
                        <button class="btn btn-maroon btn-sm w-100 btn-add-cart"
                                id="btnCart{{ $item->id }}"
                                onclick="openAddModal(this)">
                            <i class="ph ph-shopping-cart me-1"></i> Add to Cart
                        </button>
                    @else
                        <button class="btn btn-outline-secondary btn-sm w-100" disabled
                                title="Marked unavailable by the Owner or Main Branch Manager">
                            <i class="ph ph-shopping-cart me-1"></i> Unavailable
                        </button>
                    @endif
                </div>
            </div>
        </div>
    </div>
    @endforeach
</div>

<!-- ═══ RECENT ORDERS ═════════════════════════════════════ -->
@if($recentOrders->count() > 0)
<div class="hv-card p-3">
    <h6 class="mb-3" style="color:var(--maroon);">
        <i class="ph ph-clock-counter-clockwise me-1"></i> Recent Orders
    </h6>
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Order #</th>
                    <th>Items</th>
                    <th class="text-end">Total</th>
                    <th class="text-center">Status</th>
                    <th>Date</th>
                </tr>
            </thead>
            <tbody>
            @foreach($recentOrders as $ord)
            @php
                $statusBadge = match($ord->status) {
                    'pending'    => 'hv-badge-warning',
                    'approved'   => 'hv-badge-info',
                    'delivering' => 'hv-badge-info',
                    'delivered'  => 'hv-badge-success',
                    'settled'    => 'hv-badge-success',
                    'cancelled'  => 'hv-badge-danger',
                    default      => '',
                };
            @endphp
                <tr>
                    <td class="fw-semibold">{{ $ord->order_number }}<br>
                        @if($ord->isProformaAvailable())
                        <a class="small" href="{{ route('orders.proforma', $ord) }}" target="_blank" rel="noopener">Proforma invoice</a>
                        @elseif($ord->status === 'pending')
                        <span class="small fw-normal text-muted">Proforma available after approval</span>
                        @endif
                    </td>
                    <td>{{ (int)$ord->item_count }} item{{ (int)$ord->item_count !== 1 ? 's' : '' }}</td>
                    <td class="text-end fw-semibold">&#8369;{{ number_format($ord->total_amount, 2) }}</td>
                    <td class="text-center">
                        <span class="hv-badge {{ $statusBadge }}">{{ $ord->status === 'approved' ? 'Awaiting Confirmation' : ucfirst($ord->status) }}</span>
                    </td>
                    <td class="small text-muted">{{ \Carbon\Carbon::parse($ord->created_at)->format('M j, Y g:i A') }}</td>
                </tr>
            @endforeach
            </tbody>
        </table>
    </div>
    <div class="text-end mt-2">
        <a href="{{ route('orders') }}" class="small text-decoration-none" style="color:var(--maroon);">
            View all orders <i class="ph ph-arrow-right"></i>
        </a>
    </div>
</div>
@endif

<!-- ═══ ADD TO CART MODAL ═════════════════════════════════ -->
<div class="modal fade" id="addModal" tabindex="-1">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header py-2" style="background:var(--gold-soft);">
                <h6 class="modal-title" style="color:var(--maroon);">
                    <i class="ph ph-shopping-cart me-1"></i> Add to Cart
                </h6>
                <button type="button" class="btn-close btn-close-sm" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <div id="addModalImg" class="mb-2" style="height:100px; display:flex; align-items:center; justify-content:center;">
                </div>
                <p class="fw-bold mb-1" id="addModalName"></p>
                <p class="mb-1" style="color:var(--maroon); font-weight:600;" id="addModalPrice"></p>
                <p class="text-muted small mb-3" id="addModalLimit"></p>
                <label for="addModalQty" class="form-label small">Quantity</label>
                <div class="d-flex align-items-center justify-content-center gap-2 mb-2">
                    <button class="btn btn-outline-secondary btn-sm px-3" id="btnModalMinus" aria-label="Decrease quantity" onclick="adjustModalQty(-1)"><i class="ph ph-minus"></i></button>
                    <input type="number" class="form-control text-center" id="addModalQty" min="1" step="1" value="1" oninput="updateModalQtyControls()" style="width:70px;">
                    <button class="btn btn-outline-secondary btn-sm px-3" id="btnModalPlus" aria-label="Increase quantity" onclick="adjustModalQty(1)"><i class="ph ph-plus"></i></button>
                </div>
                <input type="hidden" id="addModalItemId">
                <input type="hidden" id="addModalUnitPrice">
                <input type="hidden" id="addModalUnit">
            </div>
            <div class="modal-footer py-2 justify-content-center">
                <button type="button" class="btn btn-light btn-sm px-4" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-maroon btn-sm px-4" onclick="confirmAddToCart()">
                    <i class="ph ph-shopping-cart me-1"></i> Add to Cart
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ═══ SHOPPING CART SIDEBAR ═════════════════════════════ -->
<div class="offcanvas offcanvas-end" tabindex="-1" id="cartSidebar" style="width:400px; max-width:90vw;">
    <div class="offcanvas-header py-3" style="background:var(--maroon); color:#fff;">
        <h6 class="offcanvas-title mb-0">
            <i class="ph ph-shopping-cart me-2"></i> Shopping Cart
            <span class="badge bg-success ms-2" id="cartSidebarCount">0</span>
        </h6>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="offcanvas"></button>
    </div>
    <div class="offcanvas-body p-0 d-flex flex-column">
        <div class="flex-grow-1 overflow-auto">
        <div class="p-3" id="cartSidebarBody">
            <div class="text-center text-muted py-5" id="cartEmpty">
                <i class="ph ph-shopping-cart-simple" style="font-size:2rem;"></i>
                <div class="mt-2">Your cart is empty</div>
            </div>
        </div>
        <div class="px-3">@include('partials.order-payment-fields', ['paymentPrefix' => 'cart'])</div>
        </div>
        <div class="border-top p-3" id="cartSidebarFooter" style="display:none;">
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold" id="cartTotalLabel">Total (0 items)</span>
                <span class="fw-bold" style="color:var(--maroon); font-size:1.1rem;" id="cartTotalAmount">&#8369;0.00</span>
            </div>
            <textarea class="form-control form-control-sm mb-3" id="orderRemarks"
                      rows="2" placeholder="Add remarks for this order (optional)..." maxlength="500"></textarea>
            <button class="btn btn-maroon w-100 mb-2" id="btnPlaceOrder" onclick="placeOrder()">
                <i class="ph ph-paper-plane-tilt me-1"></i> Place Order
            </button>
            <button class="btn btn-outline-secondary w-100 btn-sm" onclick="clearCart()">
                <i class="ph ph-trash me-1"></i> Clear Cart
            </button>
        </div>
    </div>
</div>

<!-- ═══ FLOATING CART BUTTON ══════════════════════════════ -->
<button class="btn btn-maroon rounded-circle shadow-lg position-fixed d-flex align-items-center justify-content-center"
        id="cartFab" onclick="openCart()" style="display:none; bottom:24px; right:24px; width:56px; height:56px; z-index:1040; font-size:1.3rem;">
    <i class="ph ph-shopping-cart"></i>
    <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger" id="cartFabBadge" style="font-size:0.7rem;">0</span>
</button>

@endif
@endsection

@push('styles')
<style>
.cat-pill {
    background: #fff;
    border: 1px solid #ddd;
    color: #555;
    font-size: 0.8rem;
    transition: all 0.2s;
}
.cat-pill:hover {
    border-color: var(--maroon);
    color: var(--maroon);
}
.cat-pill.active {
    background: var(--maroon);
    border-color: var(--maroon);
    color: #fff;
}
.cat-pill.active .badge {
    background: rgba(255,255,255,0.3) !important;
    color: #fff !important;
}
/* ── Product cards ─────────────────────────────────────── */
.hv-prod {
    overflow: hidden; border-radius: 16px; border: 1px solid var(--border);
    transition: transform 0.18s cubic-bezier(.2,.7,.2,1), box-shadow 0.18s, border-color 0.18s;
}
.product-card .hv-prod:hover {
    transform: translateY(-3px); border-color: var(--gold);
    box-shadow: 0 1px 2px rgba(60,8,8,.05), 0 16px 30px -18px rgba(60,8,8,.35);
}
.hv-prod.is-off { opacity: .72; }
.hv-prod-img {
    position: relative; height: 128px; overflow: hidden;
    background: linear-gradient(135deg, var(--gold-soft), #FBF3E0);
    display: flex; align-items: center; justify-content: center;
}
.hv-prod-img img { width: 100%; height: 100%; object-fit: cover; }
.hv-prod-ph {
    width: 54px; height: 54px; border-radius: 14px; display: flex; align-items: center; justify-content: center;
    background: #fff; border: 1px solid rgba(122,23,23,.12); box-shadow: 0 6px 16px -10px rgba(60,8,8,.4);
}
.hv-prod-ph i[class*="ph-"] { font-size: 1.5rem; color: var(--maroon); opacity: .55; }
.hv-prod-cat {
    position: absolute; top: 8px; left: 8px; font-size: 10px; font-weight: 700; letter-spacing: .03em;
    color: var(--maroon); background: rgba(255,255,255,.9); border-radius: 999px; padding: .12rem .5rem;
    backdrop-filter: blur(2px);
}
.hv-prod-flag {
    position: absolute; top: 8px; right: 8px; font-size: 10px; font-weight: 700;
    color: #8A6D00; background: rgba(255,255,255,.92); border-radius: 999px; padding: .12rem .5rem;
}
.hv-prod-body { padding: .85rem .9rem 1rem; }
.hv-prod-name { font-size: .98rem; font-weight: 700; letter-spacing: -.01em; color: var(--ink); margin: 0; line-height: 1.25; }
.hv-prod-sub { font-size: 11.5px; color: var(--muted); margin-top: 1px; }
.hv-prod-price { font-size: 1.2rem; font-weight: 800; color: var(--maroon); letter-spacing: -.02em; margin-top: .5rem; }
.hv-prod-price span { font-size: 11px; font-weight: 500; color: var(--muted); letter-spacing: 0; }
.hv-prod-meta { display: flex; flex-wrap: wrap; gap: .3rem .5rem; align-items: center; margin-top: .5rem; font-size: 11.5px; }
.hv-prod-chip { display: inline-flex; align-items: center; gap: .25rem; font-weight: 600; border-radius: 999px; padding: .1rem .45rem; }
.hv-prod-chip.ok { color: var(--success); background: #E6F0E7; }
.hv-prod-chip.off { color: var(--muted); background: #EFEBE3; }
.hv-prod-chip i[class*="ph-"] { font-size: .78em; }
.hv-prod-stock { color: var(--muted); }
.hv-prod-stock.is-low { color: #8A6D00; }
.hv-prod-stock b { color: var(--ink); }
.hv-prod-sugg { display: inline-flex; align-items: center; gap: .25rem; color: var(--maroon); font-weight: 600; }
.hv-prod-sugg i[class*="ph-"] { font-size: .82em; }
.btn-maroon {
    background: var(--maroon);
    border-color: var(--maroon);
    color: #fff;
}
.btn-maroon:hover {
    background: #5c1010;
    border-color: #5c1010;
    color: #fff;
}
.btn-outline-maroon {
    border-color: var(--maroon);
    color: var(--maroon);
}
.btn-outline-maroon:hover {
    background: var(--maroon);
    color: #fff;
}
.btn-in-cart {
    background: #198754 !important;
    border-color: #198754 !important;
    color: #fff !important;
}
.cart-item {
    display: flex;
    align-items: center;
    gap: 12px;
    padding: 12px 0;
    border-bottom: 1px solid #eee;
}
.cart-item:last-child { border-bottom: none; }
.cart-item-img {
    width: 56px;
    height: 56px;
    border-radius: 8px;
    object-fit: cover;
    flex-shrink: 0;
}
.cart-item-placeholder {
    width: 56px;
    height: 56px;
    border-radius: 8px;
    background: var(--gold-soft);
    display: flex;
    align-items: center;
    justify-content: center;
    flex-shrink: 0;
}
.cart-qty-btn {
    width: 28px;
    height: 28px;
    padding: 0;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 0.8rem;
}
@media (min-width: 1200px) {
    .col-xl { flex: 0 0 20%; max-width: 20%; }
}
</style>
@endpush

@push('scripts')
<script>
const API  = '{{ route("ordering.api") }}';
const CSRF = '{{ csrf_token() }}';
const MAX_ORDER_QUANTITY = @json($maxOrderQuantity);

let cart = [];
let addModal, cartOffcanvas;

document.addEventListener('DOMContentLoaded', function () {
    const el = document.getElementById('addModal');
    if (el) addModal = new bootstrap.Modal(el);
    const cs = document.getElementById('cartSidebar');
    if (cs) cartOffcanvas = new bootstrap.Offcanvas(cs);
});

// ── Category Filter ──────────────────────────────────────
let activeCategory = '';

function filterByCategory(btn) {
    document.querySelectorAll('.cat-pill').forEach(b => b.classList.remove('active'));
    btn.classList.add('active');
    activeCategory = btn.dataset.cat;
    filterProducts();
}

function filterProducts() {
    const search = document.getElementById('searchBox').value.toLowerCase();
    const cards  = document.querySelectorAll('.product-card');
    let visible  = 0;

    cards.forEach(function (card) {
        let show = true;
        if (search && !card.dataset.name.includes(search)) show = false;
        if (activeCategory && card.querySelector('[data-category]') === null) {
            if (card.dataset.category !== activeCategory) show = false;
        } else if (activeCategory && card.dataset.category !== activeCategory) {
            show = false;
        }
        card.style.display = show ? '' : 'none';
        if (show) visible++;
    });

    document.getElementById('productCount').textContent = 'Showing ' + visible + ' products';
}

function sortProducts() {
    const grid = document.getElementById('productGrid');
    const cards = Array.from(grid.querySelectorAll('.product-card'));
    const sort  = document.getElementById('sortSelect').value;

    cards.sort(function (a, b) {
        if (sort === 'name-asc') return a.dataset.name.localeCompare(b.dataset.name);
        if (sort === 'name-desc') return b.dataset.name.localeCompare(a.dataset.name);
        if (sort === 'price-asc') return parseFloat(a.dataset.price) - parseFloat(b.dataset.price);
        if (sort === 'price-desc') return parseFloat(b.dataset.price) - parseFloat(a.dataset.price);
        return 0;
    });

    cards.forEach(c => grid.appendChild(c));
}

// ── Add to Cart Modal ────────────────────────────────────
function openAddModal(button) {
    var card = button.closest('.product-card');
    if (!card || card.dataset.available !== '1') return;

    var id = Number(card.dataset.id);
    var name = card.dataset.itemName;
    var price = Number(card.dataset.price);
    var unit = card.dataset.unit;
    var imgUrl = card.dataset.image;
    var input = document.getElementById('addModalQty');

    document.getElementById('addModalItemId').value = id;
    document.getElementById('addModalName').textContent = name;
    document.getElementById('addModalPrice').textContent = '₱' + price.toFixed(2) + ' / ' + unit;
    var suggested = Number(card.dataset.suggested) || 0;
    document.getElementById('addModalLimit').textContent = suggested > 0
        ? 'Forecast suggests ' + suggested + ' ' + unit + '. Adjust as needed.'
        : (MAX_ORDER_QUANTITY > 0
            ? 'Maximum ' + MAX_ORDER_QUANTITY + ' ' + unit + ' per product in each order.'
            : 'Prepared after your order is confirmed.');
    document.getElementById('addModalUnitPrice').value = price;
    document.getElementById('addModalUnit').value = unit;
    if (MAX_ORDER_QUANTITY > 0) {
        input.max = MAX_ORDER_QUANTITY;
    } else {
        input.removeAttribute('max');
    }

    var imgDiv = document.getElementById('addModalImg');
    imgDiv.replaceChildren();
    if (imgUrl) {
        var image = document.createElement('img');
        image.src = imgUrl;
        image.alt = name;
        image.style.cssText = 'max-height:100px;max-width:100%;border-radius:8px;object-fit:cover;';
        imgDiv.appendChild(image);
    } else {
        imgDiv.innerHTML = '<i class="ph ph-package" style="font-size:3rem;color:var(--maroon);opacity:0.3;"></i>';
    }

    var existing = cart.find(c => c.item_id === id);
    var defaultQty = suggested > 0 ? suggested : 1;
    if (MAX_ORDER_QUANTITY > 0) defaultQty = Math.min(defaultQty, MAX_ORDER_QUANTITY);
    input.value = existing ? existing.quantity : defaultQty;
    updateModalQtyControls();
    addModal.show();
}

function updateModalQtyControls() {
    var qty = Number(document.getElementById('addModalQty').value);
    document.getElementById('btnModalMinus').disabled = qty <= 1;
    document.getElementById('btnModalPlus').disabled = MAX_ORDER_QUANTITY > 0 && qty >= MAX_ORDER_QUANTITY;
}

function adjustModalQty(delta) {
    var input = document.getElementById('addModalQty');
    var qty = Number(input.value);
    if (!Number.isSafeInteger(qty)) qty = 1;
    qty = Math.max(1, qty + delta);
    if (MAX_ORDER_QUANTITY > 0) qty = Math.min(MAX_ORDER_QUANTITY, qty);
    input.value = qty;
    updateModalQtyControls();
}

function confirmAddToCart() {
    var itemId = Number(document.getElementById('addModalItemId').value);
    var name = document.getElementById('addModalName').textContent;
    var price = Number(document.getElementById('addModalUnitPrice').value);
    var qty = Number(document.getElementById('addModalQty').value);
    var unit = document.getElementById('addModalUnit').value;
    var imgEl = document.getElementById('addModalImg').querySelector('img');
    var imgUrl = imgEl ? imgEl.src : '';

    if (!Number.isSafeInteger(qty) || qty < 1) { alert('Please enter a positive whole-number quantity.'); return; }
    if (MAX_ORDER_QUANTITY > 0 && qty > MAX_ORDER_QUANTITY) {
        alert('Maximum ' + MAX_ORDER_QUANTITY + ' ' + unit + ' per product in each order.');
        return;
    }

    var existing = cart.find(c => c.item_id === itemId);
    if (existing) {
        existing.quantity = qty;
    } else {
        cart.push({ item_id: itemId, item_name: name, unit_price: price, quantity: qty, unit: unit, image: imgUrl });
    }

    addModal.hide();
    updateCartUI();
}

function updateCartUI() {
    var fab   = document.getElementById('cartFab');
    var badge = document.getElementById('cartFabBadge');
    var totalItems = cart.reduce((s, c) => s + c.quantity, 0);

    if (cart.length > 0) {
        fab.style.display = 'flex';
        badge.textContent = cart.length;
    } else {
        fab.style.display = 'none';
    }

    // Update card buttons
    document.querySelectorAll('.product-card').forEach(function (card) {
        var id  = parseInt(card.dataset.id);
        var btn = card.querySelector('.btn-add-cart');
        if (!btn) return;
        var inCart = cart.find(c => c.item_id === id);
        if (inCart) {
            btn.classList.add('btn-in-cart');
            btn.innerHTML = '<i class="ph ph-shopping-cart me-1"></i> In Cart (' + inCart.quantity + ')';
        } else {
            btn.classList.remove('btn-in-cart');
            btn.innerHTML = '<i class="ph ph-shopping-cart me-1"></i> Add to Cart';
        }
    });

    renderCartSidebar();
}

function renderCartSidebar() {
    var body    = document.getElementById('cartSidebarBody');
    var footer  = document.getElementById('cartSidebarFooter');
    var empty   = document.getElementById('cartEmpty');
    var countEl = document.getElementById('cartSidebarCount');
    var totalEl = document.getElementById('cartTotalAmount');
    var labelEl = document.getElementById('cartTotalLabel');

    if (cart.length === 0) {
        body.innerHTML = '<div class="text-center text-muted py-5"><i class="ph ph-shopping-cart-simple" style="font-size:2rem;"></i><div class="mt-2">Your cart is empty</div></div>';
        footer.style.display = 'none';
        countEl.textContent  = '0';
        return;
    }

    footer.style.display = '';
    countEl.textContent  = cart.length;

    var html = '';
    var sum  = 0;
    var totalQty = 0;

    cart.forEach(function (c) {
        var sub = c.unit_price * c.quantity;
        sum += sub;
        totalQty += c.quantity;

        html += '<div class="cart-item">';
        if (c.image) {
            html += '<img src="' + escHtml(c.image) + '" class="cart-item-img">';
        } else {
            html += '<div class="cart-item-placeholder"><i class="ph ph-package" style="color:var(--maroon);"></i></div>';
        }
        html += '<div class="flex-grow-1">';
        html += '<div class="fw-semibold small">' + escHtml(c.item_name) + '</div>';
        html += '<div style="color:var(--maroon); font-weight:600; font-size:0.85rem;">&#8369;' + c.unit_price.toFixed(2) + '</div>';
        html += '<div class="text-muted small">Subtotal: &#8369;' + sub.toFixed(2) + '</div>';
        html += '</div>';
        html += '<div class="d-flex align-items-center gap-1">';
        html += '<button class="btn btn-outline-secondary cart-qty-btn" onclick="changeCartQty(' + c.item_id + ',-1)"><i class="ph ph-minus"></i></button>';
        html += '<span class="fw-bold" style="min-width:24px; text-align:center;">' + c.quantity + '</span>';
        var atLimit = MAX_ORDER_QUANTITY > 0 && c.quantity >= MAX_ORDER_QUANTITY;
        html += '<button class="btn btn-outline-secondary cart-qty-btn" aria-label="Increase quantity" ' + (atLimit ? 'disabled ' : '') + 'onclick="changeCartQty(' + c.item_id + ',1)"><i class="ph ph-plus"></i></button>';
        html += '</div>';
        html += '<button class="btn btn-outline-danger btn-sm px-1 py-0" onclick="removeFromCart(' + c.item_id + ')" title="Remove"><i class="ph ph-trash"></i></button>';
        html += '</div>';
    });

    body.innerHTML = html;
    totalEl.innerHTML = '&#8369;' + sum.toFixed(2);
    labelEl.textContent = 'Total (' + totalQty + ' items)';
}

function openCart() {
    renderCartSidebar();
    cartOffcanvas.show();
}

function changeCartQty(itemId, delta) {
    var item = cart.find(c => c.item_id === itemId);
    if (!item) return;

    var qty = item.quantity + delta;
    if (!Number.isSafeInteger(qty)) return;
    if (qty < 1) {
        removeFromCart(itemId);
        return;
    }
    item.quantity = MAX_ORDER_QUANTITY > 0 ? Math.min(MAX_ORDER_QUANTITY, qty) : qty;
    updateCartUI();
}

function removeFromCart(itemId) {
    cart = cart.filter(c => c.item_id !== itemId);
    updateCartUI();
}

function clearCart() {
    if (cart.length === 0) return;
    if (!confirm('Clear all items from the cart?')) return;
    cart = [];
    updateCartUI();
}

// ── Place Order ──────────────────────────────────────────
async function placeOrder() {
    if (cart.length === 0) { alert('Cart is empty.'); return; }
    var paymentData = new FormData();
    if (!appendOrderPayment('cart', paymentData)) return;
    if (!confirm('Submit this order and payment proof to the Commissary for review?')) return;

    var btn = document.getElementById('btnPlaceOrder');
    btn.disabled  = true;
    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-1"></span> Placing...';

    var remarks = document.getElementById('orderRemarks').value.trim();

    var data = new FormData();
    data.append('action', 'place_order');
    data.append('_token', CSRF);
    data.append('cart', JSON.stringify(cart));
    paymentData.forEach(function(value, key) { data.append(key, value); });
    if (remarks) data.append('remarks', remarks);

    try {
        var res  = await fetch(API, { method: 'POST', body: data,
                        headers: { 'X-Requested-With': 'XMLHttpRequest' } });
        var json = await res.json();

        if (json.ok) {
            alert(json.message || 'Order placed successfully!');
            cart = [];
            updateCartUI();
            document.getElementById('orderRemarks').value = '';
            cartOffcanvas.hide();
            location.reload();
        } else {
            showOrderPaymentError('cart', orderPaymentError(json));
        }
    } catch (err) {
        alert('Network error. Please check your connection.');
    } finally {
        btn.disabled  = false;
        btn.innerHTML = '<i class="ph ph-paper-plane-tilt me-1"></i> Place Order';
    }
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s;
    return d.innerHTML;
}
</script>
@endpush
