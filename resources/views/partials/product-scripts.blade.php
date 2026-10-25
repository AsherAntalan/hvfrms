@push('styles')
<style>
.hv-wh-toasts{ position:fixed; right:16px; bottom:16px; z-index:1090; display:flex; flex-direction:column;
  gap:8px; max-width:360px; pointer-events:none; }
.hv-wh-toast{ pointer-events:auto; display:flex; align-items:flex-start; gap:.5rem; background:#fff;
  border:1px solid var(--border); border-left:4px solid var(--maroon); border-radius:10px; padding:.6rem .8rem;
  font-size:13px; color:var(--ink); box-shadow:0 10px 26px -14px rgba(60,8,8,.45); }
.hv-wh-toast.is-success{ border-left-color:var(--success); }
.hv-wh-toast.is-error{ border-left-color:var(--danger); }
.hv-wh-toast i[class*="ph-"]{ font-size:16px; }
.hv-wh-toast.is-success i{ color:var(--success); }
.hv-wh-toast.is-error i{ color:var(--danger); }
.hv-wh-toast.is-info i{ color:var(--maroon); }
</style>
@endpush

@push('scripts')
<div class="hv-wh-toasts" id="catalogToasts" aria-live="polite" aria-atomic="true"></div>
<script>
const API = '{{ route("warehouse.api") }}';
const CSRF = document.querySelector('meta[name="csrf-token"]').content;
@if($canManageProducts)
let productModal;
document.addEventListener('DOMContentLoaded', function () {
    productModal = new bootstrap.Modal(document.getElementById('productModal'));
});
@endif

async function postAPI(fields) {
    var fd = new FormData();
    for (var k in fields) fd.append(k, fields[k]);
    var res = await fetch(API, {
        method: 'POST', body: fd,
        headers: { 'X-Requested-With': 'XMLHttpRequest', 'X-CSRF-TOKEN': CSRF }
    });
    if (res.status === 419) { throw { handled: true, message: 'Your session expired. Please refresh the page and sign in again.' }; }
    if (res.status === 401 || res.status === 403) {
        var b = await res.json().catch(function () { return {}; });
        throw { handled: true, message: b.error || 'You are not authorized to perform this action.' };
    }
    return res.json();
}

function catalogToast(type, msg) {
    var box = document.getElementById('catalogToasts');
    if (!box) { if (type === 'error') { alert(msg); } return; }
    var icon = type === 'success' ? 'ph-check-circle' : (type === 'error' ? 'ph-warning-circle' : 'ph-info');
    var el = document.createElement('div');
    el.className = 'hv-wh-toast is-' + type;
    el.innerHTML = '<i class="ph ' + icon + '"></i><div class="flex-fill">' + String(msg).replace(/</g, '&lt;') + '</div>'
        + '<button type="button" class="btn-close btn-close-sm ms-1" aria-label="Dismiss"></button>';
    el.querySelector('.btn-close').addEventListener('click', function () { el.remove(); });
    box.appendChild(el);
    if (type !== 'error') { setTimeout(function () { el.remove(); }, 4000); }
}

// Re-fetch the catalog table from the server and swap in place (no page reload),
// preserving the search box, filters, and scroll position.
async function refreshCatalog() {
    var scrollY = window.scrollY;
    var res = await fetch(window.location.href, { headers: { 'X-Requested-With': 'XMLHttpRequest' } });
    if (!res.ok) { throw new Error('http'); }
    var html = await res.text();
    var doc = new DOMParser().parseFromString(html, 'text/html');
    var fresh = doc.getElementById('productCatalogBody');
    var target = document.getElementById('productCatalogBody');
    if (!fresh || !target) { throw new Error('missing'); }
    target.innerHTML = fresh.innerHTML;
    if (typeof filterProducts === 'function') { filterProducts(); }
    window.scrollTo(0, scrollY);
}

// -- Products --
@if($canManageProducts)
function openProductModal(data) {
    var form  = document.getElementById('productForm');
    var title = document.getElementById('productModalTitle');
    form.reset();
    document.getElementById('p_reorder_level').value = 10;

    if (data) {
        title.innerHTML = '<i class="ph ph-pencil-simple me-1"></i> Edit Product';
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
        title.innerHTML = '<i class="ph ph-package me-1"></i> Add Product';
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
        if (res.status === 419) { catalogToast('error', 'Your session expired. Please refresh the page.'); return; }
        var json = await res.json();
        if (json.ok) {
            productModal.hide();
            catalogToast('success', json.message || 'Product saved.');
            try { await refreshCatalog(); }
            catch (e2) { catalogToast('error', 'Saved, but the list could not refresh. Please reload the page.'); }
        } else { catalogToast('error', json.error || 'Something went wrong.'); }
    } catch (err) {
        catalogToast('error', 'Network error. Please try again.');
    } finally {
        btn.disabled = false;
        btn.innerHTML = '<i class="ph ph-check me-1"></i> Save';
    }
}

@endif

@if($canManageAvailability)
async function toggleProduct(id, newStatus) {
    var action = newStatus ? 'make this product available for orders' : 'mark this product unavailable for orders';
    if (!confirm('Do you want to ' + action + '?')) return;

    try {
        var r = await postAPI({ action: 'toggle_product', id: id, is_active: newStatus });
        if (r.ok) {
            catalogToast('success', r.message || (newStatus ? 'Product marked available.' : 'Product marked unavailable.'));
            try { await refreshCatalog(); }
            catch (e2) { catalogToast('error', 'Saved, but the list could not refresh. Please reload the page.'); }
        } else { catalogToast('error', r.error || 'Something went wrong.'); }
    } catch (err) { catalogToast('error', err.handled ? err.message : 'Network error. Please try again.'); }
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

</script>
@endpush
