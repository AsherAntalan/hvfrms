@push('scripts')
<script>
window.renderCommissaryGroup = function (p, container, postData, stockApi, beep, context = {}) {
    const escape = value => String(value ?? '').replace(/[&<>"']/g,c=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
    const key = window.crypto?.randomUUID ? crypto.randomUUID() : Date.now().toString(36)+Math.random().toString(36).slice(2);
    let html = '<div class="hv-card p-3 mt-3"><h5>'+escape(p.item_name)+'</h5>';
    html += '<p><strong>'+p.quantity+' '+escape(p.unit)+'</strong> prepared for this order. All copies use this same QR.</p>';
    html += '<p>Order: '+escape(p.order_number)+'<br>Destination: '+escape(p.destination)+'<br>Expiry: '+escape((p.expiry_dates || []).join(', ') || 'Non-perishable')+'</p>';
    html += '<p class="small">Remaining to release: <strong id="groupRemaining">'+p.remaining_quantity+'</strong> · Released, awaiting receipt: <strong id="groupReceivable">'+p.receivable_quantity+'</strong> · Accepted at branch: <strong id="groupAccepted">'+p.received_quantity+'</strong></p>';
    if (p.usage_quantity !== undefined) html += '<p>Usable stock from this delivery: <strong id="groupUsable">'+p.usage_quantity+' '+escape(p.unit)+'</strong></p>';
    const usageOnly = context.mode === 'out' && !p.can_dispatch;
    const canReceive = p.can_receive && !usageOnly;
    const canUse = p.can_use && context.mode !== 'in';
    if (p.can_dispatch || canReceive || canUse) {
        html += '<label for="productionMovement" class="form-label">Movement</label><select id="productionMovement" class="form-select mb-3">';
        if (p.can_dispatch) html += '<option value="stock_out">Stock Out — For Delivery</option>';
        if (canReceive) html += '<option value="stock_in">Stock In — Receive Delivery</option>';
        if (canUse) html += '<option value="usage">Stock Out — For Branch Use</option>';
        html += '</select><label for="productionQuantity" class="form-label">Quantity</label><input id="productionQuantity" type="number" required step="1" class="form-control mb-2">';
        html += '<div class="small text-muted mb-3" id="productionLimit"></div>';
        html += '<div class="form-check mb-2" id="productionFinalizeWrap"><input class="form-check-input" type="checkbox" id="productionFinalize"><label class="form-check-label" for="productionFinalize">Complete receipt now; record any unreceived quantity as missing</label></div>';
        html += '<label for="productionReason" class="form-label" id="productionReasonLabel">Reason</label><select id="productionReason" class="form-select mb-3"></select>';
        html += '<button class="btn btn-maroon" type="button" id="btnProductionGroup">Confirm</button>';
    } else html += '<div class="alert alert-info">'+escape(usageOnly ? p.usage_message : (p.remaining_quantity ? (p.blocked_reason || p.receive_message) : p.receive_message))+'</div>';
    html += '<div id="productionMessage" class="mt-2" role="status"></div></div>';
    container.innerHTML = html;
    const movement = container.querySelector('#productionMovement'), quantity = container.querySelector('#productionQuantity'), button = container.querySelector('#btnProductionGroup');
    if (!button) return;
    if (context.mode === 'out' && canUse) movement.value = 'usage';
    else if (context.mode === 'in' && canReceive) movement.value = 'stock_in';
    function update() {
        const receive = movement.value === 'stock_in', use = movement.value === 'usage';
        const max = receive ? p.receivable_quantity : (use ? p.usage_quantity : p.remaining_quantity);
        quantity.min = receive ? 0 : 1; quantity.max = max; quantity.value = use ? 1 : max;
        container.querySelector('#productionLimit').textContent = 'Maximum: '+max+' '+p.unit+'. '+(receive ? 'A smaller receipt leaves the balance pending unless you select Complete receipt.' : (use ? 'Deducts only remaining usable stock from this delivery at '+p.destination+'.' : 'Releases these goods for delivery.'));
        container.querySelector('#productionFinalizeWrap').hidden = !receive;
        const reason = container.querySelector('#productionReason');
        reason.hidden = !receive && !use; container.querySelector('#productionReasonLabel').hidden = reason.hidden;
        container.querySelector('#productionReasonLabel').textContent = use ? 'Usage reason' : 'Receipt discrepancy reason';
        reason.innerHTML = (use ? ['Used in Production','Consumed','Other'] : ['Missing Quantity','Damaged in Transit','Incorrect Item','Other']).map(value => '<option>'+escape(value)+'</option>').join('');
        button.textContent = receive ? 'Confirm Delivery Receipt' : (use ? 'Confirm Stock Out for Branch Use' : 'Confirm Stock Out for Delivery');
    }
    movement.onchange = update; update();
    button.onclick = async function () {
        if (!quantity.reportValidity()) return;
        button.disabled = true;
        try {
            const use = movement.value === 'usage';
            const result = await postData(stockApi,{action:use ? 'stock_out' : movement.value,movement:use ? 'usage' : 'delivery',qr:p.qr_code,quantity:Number(quantity.value),idempotency_key:key,
                branch_id:context.branchId ?? p.usage_branch_id ?? '',finalize:container.querySelector('#productionFinalize').checked ? '1' : '0',reason:container.querySelector('#productionReason').value});
            container.querySelector('#productionMessage').textContent = result.error || Object.values(result.errors || {}).flat().join(' ') || result.message || 'Could not record movement.';
            if (!result.ok) button.disabled = false;
            else {
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
                button.textContent = 'Recorded'; if (beep) beep(true);
            }
        } catch (error) {container.querySelector('#productionMessage').textContent = 'Connection error. Retry to safely confirm this same movement.'; button.disabled = false;}
    };
};
</script>
@endpush
