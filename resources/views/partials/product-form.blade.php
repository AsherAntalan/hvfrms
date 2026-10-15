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
