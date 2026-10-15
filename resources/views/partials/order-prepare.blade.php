<div class="modal fade" id="piecePrepareModal" tabindex="-1" aria-labelledby="piecePrepareTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="piecePrepareTitle">Review &amp; Prepare</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <p class="text-muted small">Keep the original request. Prepare actual quantities or pick eligible Main Branch stock. Give a reason for shortages. Labels cover prepared quantities only; printing does not release goods.</p>
            <div id="piecePrepareBody"></div><div id="piecePrepareError" class="text-danger mt-2" role="alert"></div>
        </div>
        <div class="modal-footer"><button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button><button type="button" id="piecePrepareSave" class="btn btn-maroon">Save Preparation &amp; Open Labels</button></div>
    </div></div>
</div>
@push('scripts')
<script>
(function () {
    let orderId, modal;
    const escape = value => {const span = document.createElement('span'); span.textContent = value ?? ''; return span.innerHTML.replace(/"/g, '&quot;');};
    async function post(fields) {
        const body = new FormData(); body.append('_token', '{{ csrf_token() }}');
        Object.entries(fields).forEach(([key,value]) => body.append(key,value));
        return (await fetch('{{ route("orders.api") }}', {method:'POST',body,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}})).json();
    }
    function message(result) {return result.error || Object.values(result.errors || {}).flat().join(' ') || result.message || 'Could not complete this action.';}
    window.reviewCommissaryOrder = async function (button) {
        button.disabled = true;
        try {const result = await post({action:'review_order',id:button.dataset.orderId}); if (!result.ok) throw new Error(message(result)); location.reload();}
        catch(error) {alert(error.message);} finally {button.disabled = false;}
    };
    window.cancelPieceOrder = async function (button) {
        const reason = prompt('Reason for cancelling this order:'); if (!reason || !reason.trim()) return;
        button.disabled = true;
        try {const result = await post({action:'cancel',id:button.dataset.orderId,reason:reason.trim()}); if (!result.ok) throw new Error(message(result)); location.reload();}
        catch(error) {alert(error.message);} finally {button.disabled = false;}
    };
    window.openPiecePrepare = async function (button) {
        orderId = Number(button.dataset.orderId);
        modal = bootstrap.Modal.getOrCreateInstance(document.getElementById('piecePrepareModal'));
        document.getElementById('piecePrepareTitle').textContent = 'Prepare / Pick — ' + button.dataset.orderNumber;
        document.getElementById('piecePrepareBody').textContent = 'Loading…'; document.getElementById('piecePrepareError').textContent = '';
        const save = document.getElementById('piecePrepareSave'); save.disabled = true; modal.show();
        try {
            const result = await post({action:'view',id:orderId}); if (!result.ok) throw new Error(message(result));
            const options = await post({action:'preparation_options',id:orderId}); if (!options.ok) throw new Error(message(options));
            const available = new Map(options.lines.map(line => [line.order_item_id,line.available]));
            let html = '<p>Destination: <strong>' + escape(result.order.branch_name) + '</strong></p><div class="table-responsive"><table class="table align-middle"><thead><tr><th>Product</th><th>Requested</th><th>Prepare</th><th>Source</th><th>Expiry</th><th>Shortage reason</th></tr></thead><tbody>';
            result.order.items.forEach(item => {
                html += '<tr data-line="' + item.id + '"><td>' + escape(item.item_name) + '<div class="small text-muted">' + escape(item.unit) + ' · pickable: ' + (available.get(item.id) || 0) + '</div></td>'
                    + '<td>' + item.quantity + '</td><td><input type="number" aria-label="Prepared quantity for ' + escape(item.item_name) + '" min="0" max="' + item.quantity + '" step="1" value="' + (item.fulfilled_quantity ?? item.quantity) + '" class="form-control piece-quantity" style="min-width:85px"></td>'
                    + '<td><span class="text-muted">New production</span></td>'
                    + '<td><input type="date" aria-label="Expiry for ' + escape(item.item_name) + '" class="form-control piece-expiry"><small class="text-muted piece-expiry-note">Required for perishable production</small></td>'
                    + '<td><input type="text" aria-label="Shortage reason for ' + escape(item.item_name) + '" maxlength="500" class="form-control piece-reason" value="' + escape(item.fulfillment_reason || '') + '" placeholder="Required if reduced"></td></tr>';
            });
            document.getElementById('piecePrepareBody').innerHTML = html + '</tbody></table></div>';
            save.disabled = false;
        } catch(error) {document.getElementById('piecePrepareError').textContent = error.message;}
    };
    document.getElementById('piecePrepareSave').onclick = async function () {
        const button = this, expiry = {}, fulfillment = {};
        for (const row of document.querySelectorAll('#piecePrepareBody [data-line]')) {
            const quantity = row.querySelector('.piece-quantity'); if (!quantity.reportValidity()) return;
            const reason = row.querySelector('.piece-reason');
            reason.required = Number(quantity.value) < Number(quantity.max); if (!reason.reportValidity()) return;
            expiry[row.dataset.line] = row.querySelector('.piece-expiry').value;
            fulfillment[row.dataset.line] = {quantity:Number(quantity.value),source:'produced',reason:row.querySelector('.piece-reason').value.trim()};
        }
        button.disabled = true; document.getElementById('piecePrepareError').textContent = '';
        const printWindow = window.open('', '_blank'); if (printWindow) printWindow.document.body.textContent = 'Saving preparation…';
        try {
            const result = await post({action:'prepare_order',id:orderId,expiry:JSON.stringify(expiry),fulfillment:JSON.stringify(fulfillment)});
            if (!result.ok) throw new Error(message(result));
            if (printWindow) {printWindow.location.href = result.labels_url; modal.hide(); location.reload();} else location.href = result.labels_url;
        } catch(error) {if (printWindow) printWindow.close(); document.getElementById('piecePrepareError').textContent = error.message;}
        finally {button.disabled = false;}
    };
})();
</script>
@endpush
