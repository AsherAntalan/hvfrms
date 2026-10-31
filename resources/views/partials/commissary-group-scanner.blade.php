@push('styles')
<style>
[data-commissary-group] [hidden] { display: none !important; }
</style>
@endpush
@push('scripts')
<script>
window.renderCommissaryGroup = function (p, container, postData, stockApi, beep, context = {}) {
    const escape = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const key = window.crypto?.randomUUID ? crypto.randomUUID() : Date.now().toString(36)+Math.random().toString(36).slice(2);
    const issueReasons = ['Missing Quantity','Damaged in Transit','Incorrect Quantity','Incorrect Item','Other'];
    let posting = false, recorded = false, pendingReceipt = null;
    let html = '<div class="hv-card p-3 mt-3" data-commissary-group><h5>'+escape(p.item_name)+'</h5>';
    html += '<p><strong>'+p.quantity+' '+escape(p.unit)+'</strong> prepared for this order. All copies use this same QR.</p>';
    html += '<p>Order: '+escape(p.order_number)+'<br>Destination: '+escape(p.destination)+'<br>Expiry: '+escape((p.expiry_dates || []).join(', ') || 'Non-perishable')+'</p>';
    html += '<p class="small">Remaining to release: <strong id="groupRemaining">'+p.remaining_quantity+'</strong> · Released, awaiting receipt: <strong id="groupReceivable">'+p.receivable_quantity+'</strong> · Accepted at branch: <strong id="groupAccepted">'+p.received_quantity+'</strong></p>';
    if (p.usage_quantity !== undefined) html += '<p>Usable stock from this delivery: <strong id="groupUsable">'+p.usage_quantity+' '+escape(p.unit)+'</strong></p>';
    const canDispatch = p.can_dispatch && context.mode !== 'in';
    const usageOnly = context.mode === 'out' && !canDispatch;
    const canReceive = p.can_receive && !usageOnly;
    const canUse = p.can_use && context.mode !== 'in';
    if (canDispatch || canReceive || canUse) {
        html += '<label for="productionMovement" class="form-label" id="productionMovementLabel">Movement</label><select id="productionMovement" class="form-select mb-3">';
        if (canDispatch) html += '<option value="stock_out">Stock Out — For Delivery</option>';
        if (canReceive) html += '<option value="stock_in">Stock In — Receive Delivery</option>';
        if (canUse) html += '<option value="usage">Stock Out — For Branch Use</option>';
        html += '</select><div id="productionMovementSummary" class="small text-muted mb-3" hidden></div>';
        html += '<div id="productionReceiptWrap" hidden><label for="productionReceiptStatus" class="form-label">Receipt result</label><select id="productionReceiptStatus" class="form-select mb-3"><option value="good">Complete and good / No issue</option><option value="partial">Partial delivery - remaining will follow</option>' + issueReasons.map(value => '<option>'+escape(value)+'</option>').join('') + '</select></div>';
        html += '<label for="productionQuantity" class="form-label" id="productionQuantityLabel">Quantity</label><input id="productionQuantity" type="number" required step="1" class="form-control mb-2">';
        html += '<div class="small text-muted mb-3" id="productionLimit"></div>';
        html += '<div id="productionReasonWrap" hidden><label for="productionReason" class="form-label">Usage reason</label><select id="productionReason" class="form-select mb-3"></select></div>';
        html += '<button class="btn btn-maroon" type="button" id="btnProductionGroup">Confirm</button>';
        html += '<section id="productionReceiptReview" class="border rounded p-3 mt-3" aria-label="Review receipt with issue" hidden><strong>Review receipt</strong><div id="productionReceiptSummary" class="small my-3" role="status"></div><div class="d-flex flex-wrap gap-2"><button type="button" class="btn btn-outline-secondary" id="productionReceiptBack">Back</button><button type="button" class="btn btn-maroon" id="productionReceiptConfirm">Confirm Receipt With Issue</button></div></section>';
    } else html += '<div class="alert alert-info">'+escape(context.mode === 'in' && p.can_dispatch
        ? 'These are prepared source goods. Select Stock Out to release them for delivery.'
        : (usageOnly ? p.usage_message : (p.remaining_quantity ? (p.blocked_reason || p.receive_message) : p.receive_message)))+'</div>';
    html += '<div id="productionMessage" class="mt-2" role="status"></div></div>';
    container.innerHTML = html;
    const movement = container.querySelector('#productionMovement'), quantity = container.querySelector('#productionQuantity'), button = container.querySelector('#btnProductionGroup');
    if (!button) return;
    if (context.mode === 'out' && canUse) movement.value = 'usage';
    else if (context.mode === 'in' && canReceive) movement.value = 'stock_in';
    const receiptStatus = container.querySelector('#productionReceiptStatus');
    const reason = container.querySelector('#productionReason');
    const review = container.querySelector('#productionReceiptReview');
    const reviewConfirm = container.querySelector('#productionReceiptConfirm');
    const reviewBack = container.querySelector('#productionReceiptBack');
    function lockControls() {
        const locked = posting || recorded || !review.hidden;
        movement.disabled = locked;
        quantity.disabled = locked;
        receiptStatus.disabled = locked;
        reason.disabled = locked || movement.value !== 'usage';
        button.disabled = posting || recorded;
        reviewConfirm.disabled = posting || recorded;
        reviewBack.disabled = posting;
    }
    function updateReceipt() {
        const receive = movement.value === 'stock_in', use = movement.value === 'usage';
        const issue = receive && issueReasons.includes(receiptStatus.value);
        container.querySelector('#productionReceiptWrap').hidden = !receive;
        receiptStatus.required = receive;
        container.querySelector('#productionReasonWrap').hidden = !use;
        reason.required = use;
        reason.disabled = !use;
        quantity.min = issue ? 0 : 1;
        container.querySelector('#productionQuantityLabel').textContent = receive ? 'Quantity accepted in good condition' : 'Quantity';
        container.querySelector('#productionLimit').textContent = 'Maximum: '+quantity.max+' '+p.unit+'. '+(receive
            ? (issue ? 'Enter only the good quantity accepted. Review the remaining units before confirming this issue.'
                : (receiptStatus.value === 'partial' ? 'The remaining quantity stays pending for a later delivery. No missing-item report is created.' : 'All released units will be accepted. No issue will be recorded unless the system detects expired or damaged goods.'))
            : (use ? 'Deducts only remaining usable stock from this delivery at '+p.destination+'.' : 'Releases these goods for delivery.'));
        button.textContent = receive ? (issue ? 'Review Receipt' : (receiptStatus.value === 'partial' ? 'Receive Partial Delivery' : 'Confirm Good Delivery')) : (use ? 'Confirm Stock Out for Branch Use' : 'Confirm Stock Out for Delivery');
    }
    function update() {
        const receive = movement.value === 'stock_in', use = movement.value === 'usage';
        const max = receive ? p.receivable_quantity : (use ? p.usage_quantity : p.remaining_quantity);
        quantity.max = max; quantity.value = use ? 1 : max;
        receiptStatus.value = 'good';
        reason.innerHTML = ['Consumed','Dispose'].map(value => '<option>'+escape(value)+'</option>').join('');
        const singleMovement = movement.options.length === 1;
        movement.hidden = singleMovement;
        container.querySelector('#productionMovementLabel').hidden = singleMovement;
        container.querySelector('#productionMovementSummary').hidden = !singleMovement;
        container.querySelector('#productionMovementSummary').textContent = movement.selectedOptions[0].textContent;
        updateReceipt();
    }
    receiptStatus.onchange = function () {
        if (receiptStatus.value === 'good') quantity.value = p.receivable_quantity;
        updateReceipt();
    };
    quantity.oninput = function () {
        if (movement.value === 'stock_in' && ['good','partial'].includes(receiptStatus.value)) {
            receiptStatus.value = Number(quantity.value) < Number(p.receivable_quantity) ? 'partial' : 'good';
        }
        updateReceipt();
    };
    movement.onchange = update; update();
    button.onclick = async function () {
        if (posting || recorded) return;
        if (!quantity.reportValidity()) return;
        const receive = movement.value === 'stock_in', use = movement.value === 'usage';
        const issue = receive && issueReasons.includes(receiptStatus.value);
        const message = container.querySelector('#productionMessage');
        message.textContent = '';
        if (receive && !receiptStatus.reportValidity()) return;
        if (receive && receiptStatus.value === 'partial' && Number(quantity.value) >= Number(p.receivable_quantity)) {
            message.textContent = 'Enter fewer accepted units for a partial delivery, or choose Complete and good.';
            return;
        }
        if (issue && Number(quantity.value) >= p.receivable_quantity) {
            message.textContent = 'For an issue, enter fewer accepted good units than the released quantity. If everything was accepted, choose No issue.';
            return;
        }
        if (use && !reason.reportValidity()) return;
        const fields = {action:use ? 'stock_out' : movement.value,movement:use ? 'usage' : 'delivery',qr:p.qr_code,quantity:Number(quantity.value),idempotency_key:key,
            branch_id:context.branchId ?? p.usage_branch_id ?? '',finalize:issue ? '1' : '0',reason:use ? reason.value : (issue ? receiptStatus.value : ''),...(receive ? {receipt_status:issue ? 'issue' : 'good'} : {})};
        if (issue) {
            pendingReceipt = fields;
            const rejected = Number(p.receivable_quantity) - fields.quantity;
            container.querySelector('#productionReceiptSummary').innerHTML = '<p>Released, awaiting receipt: <strong>'+p.receivable_quantity+' '+escape(p.unit)+'</strong><br>Accepted in good condition: <strong>'+fields.quantity+' '+escape(p.unit)+'</strong><br>Not accepted: <strong>'+rejected+' '+escape(p.unit)+'</strong><br>Issue: <strong>'+escape(fields.reason)+'</strong></p><p class="mb-0">The '+rejected+' unaccepted units will be recorded under this issue and will no longer be pending for this receipt.</p>';
            review.hidden = false; button.hidden = true;
            lockControls();
            review.scrollIntoView({block:'nearest'});
            reviewConfirm.focus();
            return;
        }
        await recordMovement(fields);
    };
    reviewBack.onclick = function () {
        if (posting) return;
        pendingReceipt = null; review.hidden = true; button.hidden = false;
        lockControls(); updateReceipt();
        receiptStatus.focus();
    };
    reviewConfirm.onclick = async function () {
        if (pendingReceipt && !posting && !recorded) await recordMovement(pendingReceipt);
    };
    async function recordMovement(fields) {
        posting = true;
        lockControls();
        try {
            const result = await postData(stockApi, fields);
            container.querySelector('#productionMessage').textContent = result.error || Object.values(result.errors || {}).flat().join(' ') || result.message || 'Could not record movement.';
            if (result.ok) {
                if (result.dispatched_quantity !== undefined) {
                    p.remaining_quantity = result.remaining_quantity;
                    p.receivable_quantity += result.dispatched_quantity;
                    p.can_dispatch = p.remaining_quantity > 0;
                    container.querySelector('#groupRemaining').textContent = p.remaining_quantity;
                    container.querySelector('#groupReceivable').textContent = p.receivable_quantity;
                }
                if (result.received_quantity !== undefined) {
                    p.receivable_quantity = result.remaining_to_receive;
                    p.received_quantity += result.received_quantity;
                    p.usage_quantity = (p.usage_quantity || 0) + result.received_quantity;
                    p.can_use = p.usage_quantity > 0 && Number(context.branchId ?? p.usage_branch_id) === Number(p.usage_branch_id);
                    p.can_receive = p.receivable_quantity > 0;
                    container.querySelector('#groupReceivable').textContent = p.receivable_quantity;
                    container.querySelector('#groupAccepted').textContent = p.received_quantity;
                    const usable = container.querySelector('#groupUsable');
                    if (usable) usable.textContent = p.usage_quantity+' '+p.unit;
                }
                if (result.used_quantity !== undefined) {
                    p.usage_quantity = result.remaining_usable_quantity;
                    p.can_use = p.usage_quantity > 0;
                    container.querySelector('#groupUsable').textContent = p.usage_quantity+' '+p.unit;
                }
                recorded = true; pendingReceipt = null;
                review.hidden = true; button.hidden = false;
                button.textContent = 'Recorded'; if (beep) beep(true);
            }
        } catch (error) {container.querySelector('#productionMessage').textContent = 'Connection error. Retry to safely confirm this same movement.';}
        finally {posting = false; lockControls();}
    }
};
</script>
@endpush
