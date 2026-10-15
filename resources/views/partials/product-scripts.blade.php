@push('scripts')
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
    return res.json();
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

@endif

@if($canManageAvailability)
async function toggleProduct(id, newStatus) {
    var action = newStatus ? 'make this product available for orders' : 'mark this product unavailable for orders';
    if (!confirm('Do you want to ' + action + '?')) return;

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

</script>
@endpush
