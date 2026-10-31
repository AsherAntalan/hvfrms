<div class="modal fade" id="piecePrepareModal" tabindex="-1" aria-labelledby="piecePrepareTitle" aria-hidden="true">
    <div class="modal-dialog modal-xl modal-dialog-scrollable"><div class="modal-content">
        <div class="modal-header"><h5 class="modal-title" id="piecePrepareTitle">Prepare / Pick</h5><button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button></div>
        <div class="modal-body">
            <div id="pieceOrderToolbar" class="d-flex flex-wrap gap-2 mb-3" hidden>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="pieceOrderDetailsButton" aria-pressed="false">View Details</button>
                <button type="button" class="btn btn-sm btn-outline-secondary" id="pieceOrderPreparationButton" hidden>Preparation</button>
                @if(!Auth::user()->isCommissaryStaff())
                <a class="btn btn-sm btn-outline-secondary" id="pieceOrderProforma" target="_blank" rel="noopener" hidden>Print Proforma</a>
                @endif
                @if(Auth::user()->isOwner() || Auth::user()->isCommissaryManager())
                <button type="button" class="btn btn-sm btn-outline-secondary" id="pieceOrderLabels" hidden>Print Labels</button>
                @endif
            </div>
            <p class="text-muted small" id="pieceOrderDescription"></p>
            <div id="pieceOrderDetails" hidden></div>
            <div id="piecePrepareBody"></div>
            <div id="pieceOrderCancelPanel" class="border rounded p-3 mt-3" hidden>
                <label for="pieceOrderCancelReason" class="form-label fw-semibold">Reason for cancellation</label>
                <textarea id="pieceOrderCancelReason" class="form-control" rows="2" maxlength="500" required></textarea>
                <p class="small text-muted mt-2">Cancelling stops this order. This is allowed only before any goods are dispatched.</p>
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-secondary" id="pieceOrderKeep">Keep Order</button>
                    <button type="button" class="btn btn-danger" id="pieceOrderConfirmCancel">Confirm Cancellation</button>
                </div>
            </div>
            <div id="piecePrepareError" class="text-danger mt-2" role="alert"></div>
        </div>
        <div class="modal-footer flex-wrap gap-2">
            <button type="button" id="pieceOrderCancel" class="btn btn-outline-danger me-auto" hidden>Cancel Order</button>
            <button type="button" class="btn btn-light" data-bs-dismiss="modal">Close</button>
            <button type="button" id="pieceOrderManualRelease" class="btn btn-outline-maroon" hidden>Manual Release</button>
            <a id="pieceOrderScanOut" class="btn btn-maroon" hidden>Release / Scan Out</a>
            <button type="button" id="piecePrepareSave" class="btn btn-maroon" disabled>Save Preparation &amp; Open Labels</button>
        </div>
    </div></div>
</div>
@push('styles')
<style>
#piecePrepareModal [hidden] { display: none !important; }
</style>
@endpush
@push('scripts')
<script>
(function () {
    let orderId, modal, currentOrder, loadSequence = 0, busy = false, preparationAvailable = false;
    const element = id => document.getElementById(id);
    const workspace = element('piecePrepareModal');
    const escape = value => {const span = document.createElement('span'); span.textContent = value ?? ''; return span.innerHTML.replace(/"/g, '&quot;');};
    async function post(fields) {
        const body = new FormData(); body.append('_token', '{{ csrf_token() }}');
        Object.entries(fields).forEach(([key,value]) => body.append(key,value));
        const response = await fetch('{{ route("orders.api") }}', {method:'POST',body,headers:{'Accept':'application/json','X-Requested-With':'XMLHttpRequest'}});
        if (response.status === 401 || response.status === 419 || response.redirected) {
            throw new Error('Your session has expired. Sign in again before continuing.');
        }
        const result = await response.json();
        if (!response.ok || !result.ok) throw new Error(message(result));
        return result;
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
    async function refreshOrderLists() {
        if (typeof window.refreshOrdersTable === 'function') {
            await window.refreshOrdersTable();
            return;
        }
        const response = await fetch(window.location.href, {headers:{'Accept':'text/html','X-Requested-With':'XMLHttpRequest'}});
        if (!response.ok || response.redirected) throw new Error('The action was saved, but the list could not refresh. Reload the page to see the latest state.');
        const documentCopy = new DOMParser().parseFromString(await response.text(), 'text/html');
        ['tabActive', 'tabHistory', 'tabArchived', 'tabBtnActive', 'tabBtnHistory', 'tabBtnArchived'].forEach(id => {
            const current = element(id), updated = documentCopy.getElementById(id);
            if (current && updated) current.replaceWith(updated);
        });
    }
    function setBusy(value) {
        busy = value;
        workspace.querySelectorAll('button, input, textarea, select').forEach(control => { control.disabled = value; });
        element('piecePrepareSave').disabled = value || !preparationAvailable;
        workspace.setAttribute('aria-busy', value ? 'true' : 'false');
    }
    workspace.addEventListener('hide.bs.modal', event => {
        if (busy) { event.preventDefault(); return; }
        loadSequence++;
    });
    workspace.addEventListener('click', event => {
        if (busy && event.target.closest('a')) event.preventDefault();
    });
    function showDetails(show) {
        element('pieceOrderDetails').hidden = !show;
        element('piecePrepareBody').hidden = show || !preparationAvailable;
        element('pieceOrderDetailsButton').setAttribute('aria-pressed', show ? 'true' : 'false');
        element('pieceOrderPreparationButton').setAttribute('aria-pressed', show ? 'false' : 'true');
    }
    function renderDetails(order) {
        let html = '<dl class="row small"><dt class="col-sm-3">Order</dt><dd class="col-sm-9">' + escape(order.order_number) + '</dd>'
            + '<dt class="col-sm-3">Branch</dt><dd class="col-sm-9">' + escape(order.branch_name) + '</dd>'
            + '<dt class="col-sm-3">Ordered by</dt><dd class="col-sm-9">' + escape(order.ordered_by_name) + '</dd>'
            + '<dt class="col-sm-3">Date</dt><dd class="col-sm-9">' + escape(order.created_at) + '</dd>'
            + '<dt class="col-sm-3">Status</dt><dd class="col-sm-9">' + escape(order.workflow_stage || order.status) + '</dd></dl>';
        if (order.remarks) html += '<p class="small"><strong>Notes:</strong> ' + escape(order.remarks) + '</p>';
        html += '<div class="table-responsive"><table class="table table-sm align-middle"><thead><tr><th>Product</th><th>Requested</th><th>Prepared</th><th>Released</th><th class="text-end">Unit price</th></tr></thead><tbody>';
        order.items.forEach(item => {
            html += '<tr><td>' + escape(item.item_name) + '<div class="small text-muted">' + escape(item.unit) + '</div></td>'
                + '<td>' + item.quantity + '</td><td>' + (item.fulfilled_quantity ?? '&mdash;') + '</td><td>' + item.released_quantity + '</td>'
                + '<td class="text-end">&#8369;' + Number(item.unit_price).toFixed(2) + '</td></tr>';
            if (item.fulfillment_reason) html += '<tr><td colspan="5" class="small text-muted">Shortage: ' + escape(item.fulfillment_reason) + '</td></tr>';
        });
        html += '</tbody></table></div><p class="text-end fw-bold">Total: &#8369;' + Number(order.fulfilled_amount ?? order.total_amount).toFixed(2) + '</p>';
        if (order.payment_reference) {
            html += '<section class="border rounded p-3 small"><strong>Payment proof submitted</strong><p>Reference: ' + escape(order.payment_reference) + '</p><p class="text-muted">Submitted evidence does not mean payment has been verified.</p>';
            if (order.payment_screenshot_url) html += '<a href="' + escape(order.payment_screenshot_url) + '" target="_blank" rel="noopener">View payment proof</a>';
            html += '</section>';
        }
        element('pieceOrderDetails').innerHTML = html;
    }
    async function openWorkspace(button, mode) {
        if (busy) return;
        const sequence = ++loadSequence;
        orderId = Number(button.dataset.orderId);
        currentOrder = null;
        preparationAvailable = false;
        modal = bootstrap.Modal.getOrCreateInstance(workspace);
        element('piecePrepareTitle').textContent = (mode === 'prepare' ? 'Prepare / Pick - ' : 'Release - ') + button.dataset.orderNumber;
        ['pieceOrderToolbar', 'pieceOrderDetails', 'pieceOrderPreparationButton', 'pieceOrderCancelPanel', 'pieceOrderCancel', 'pieceOrderScanOut', 'pieceOrderManualRelease', 'pieceOrderProforma', 'pieceOrderLabels'].forEach(id => {
            if (element(id)) element(id).hidden = true;
        });
        element('pieceOrderCancelReason').value = '';
        element('pieceOrderDescription').textContent = '';
        element('piecePrepareBody').hidden = false;
        element('piecePrepareBody').textContent = 'Loading order...';
        element('piecePrepareError').textContent = '';
        const save = element('piecePrepareSave'); save.hidden = true; save.disabled = true; modal.show();
        try {
            const result = await post({action:'view',id:orderId}); if (!result.ok) throw new Error(message(result));
            if (sequence !== loadSequence) return;
            const order = result.order, actions = order.actions;
            currentOrder = order;
            renderDetails(order);
            element('piecePrepareTitle').textContent = (mode === 'prepare' && actions.can_prepare ? 'Prepare / Pick - ' : (actions.can_release ? 'Release - ' : 'Order Details - ')) + order.order_number;
            await refreshOrderLists();
            if (sequence !== loadSequence) return;
            element('pieceOrderToolbar').hidden = false;
            element('pieceOrderCancel').hidden = !actions.can_cancel;
            const proforma = element('pieceOrderProforma');
            if (proforma && order.proforma_url) { proforma.href = order.proforma_url; proforma.hidden = false; }
            const labels = element('pieceOrderLabels');
            if (labels && actions.labels_url) { labels.hidden = false; labels.onclick = () => window.hvPrintLabelsUrl(actions.labels_url); }
            if (mode === 'release' || !actions.can_prepare) {
                element('piecePrepareBody').textContent = '';
                showDetails(true);
                if (actions.can_release) {
                    element('pieceOrderDescription').textContent = 'Print labels if needed, then scan the prepared goods for dispatch. Opening this window or printing does not release stock.';
                    const scan = element('pieceOrderScanOut'); scan.href = actions.scan_out_url; scan.hidden = false;
                    element('pieceOrderManualRelease').hidden = !@json(Auth::user()->isOwner() || Auth::user()->isCommissaryManager()) || typeof window.openManualDeliver !== 'function';
                } else {
                    element('pieceOrderDescription').textContent = order.workflow_stage === 'Released / In Transit'
                        ? 'All prepared goods have been released. This shipment is awaiting destination receipt.'
                        : 'This order has moved to another stage. Review its latest details before continuing.';
                }
                return;
            }
            element('pieceOrderDescription').textContent = 'Record actual quantities and a reason for shortages. Saving preparation creates labels; goods are released only when dispatched.';
            element('pieceOrderPreparationButton').hidden = false;
            const options = await post({action:'preparation_options',id:orderId}); if (!options.ok) throw new Error(message(options));
            if (sequence !== loadSequence) return;
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
            preparationAvailable = true;
            save.hidden = false; save.disabled = false;
            showDetails(false);
        } catch(error) {
            if (sequence !== loadSequence) return;
            element('piecePrepareBody').textContent = '';
            element('piecePrepareError').textContent = error.message;
        }
    }
    window.openPiecePrepare = button => openWorkspace(button, 'prepare');
    window.openPieceRelease = button => openWorkspace(button, 'release');
    element('pieceOrderDetailsButton').onclick = () => showDetails(true);
    element('pieceOrderPreparationButton').onclick = () => showDetails(false);
    element('pieceOrderCancel').onclick = () => {
        element('pieceOrderCancelPanel').hidden = false;
        element('pieceOrderCancelReason').focus();
    };
    element('pieceOrderKeep').onclick = () => { element('pieceOrderCancelPanel').hidden = true; };
    element('pieceOrderConfirmCancel').onclick = async function () {
        const reason = element('pieceOrderCancelReason');
        reason.value = reason.value.trim();
        if (busy || !currentOrder?.actions.can_cancel || !reason.reportValidity()) return;
        setBusy(true);
        element('piecePrepareError').textContent = '';
        this.textContent = 'Cancelling...';
        try {
            await post({action:'cancel',id:orderId,reason:reason.value});
            setBusy(false);
            modal.hide();
            if (typeof window.hvOrderToast === 'function') window.hvOrderToast('Order cancelled. The reason was recorded.', 'success');
            await refreshOrderLists();
        } catch(error) { element('piecePrepareError').textContent = error.message; }
        finally { setBusy(false); this.textContent = 'Confirm Cancellation'; }
    };
    element('pieceOrderManualRelease').onclick = () => {
        if (busy || !currentOrder?.actions.can_release || typeof window.openManualDeliver !== 'function') return;
        const order = currentOrder;
        workspace.addEventListener('hidden.bs.modal', () => window.openManualDeliver(order.id, order.order_number, order.branch_name), {once:true});
        modal.hide();
    };
    document.getElementById('piecePrepareSave').onclick = async function () {
        if (busy || !preparationAvailable || !currentOrder?.actions.can_prepare) return;
        const button = this, expiry = {}, fulfillment = {};
        for (const row of document.querySelectorAll('#piecePrepareBody [data-line]')) {
            const quantity = row.querySelector('.piece-quantity'); if (!quantity.reportValidity()) return;
            const reason = row.querySelector('.piece-reason');
            reason.required = Number(quantity.value) < Number(quantity.max); if (!reason.reportValidity()) return;
            expiry[row.dataset.line] = row.querySelector('.piece-expiry').value;
            fulfillment[row.dataset.line] = {quantity:Number(quantity.value),source:'produced',reason:row.querySelector('.piece-reason').value.trim()};
        }
        setBusy(true); document.getElementById('piecePrepareError').textContent = '';
        const original = button.textContent; button.textContent = 'Saving preparation...';
        try {
            const result = await post({action:'prepare_order',id:orderId,expiry:JSON.stringify(expiry),fulfillment:JSON.stringify(fulfillment)});
            if (!result.ok) throw new Error(message(result));
            preparationAvailable = false;
            await refreshOrderLists();
            setBusy(false);
            await openWorkspace({dataset:{orderId:String(orderId),orderNumber:currentOrder.order_number}}, 'release');
            // Print the saved labels in-place (hidden iframe) — no new tab, page stays.
            if (window.hvPrintLabelsUrl) { window.hvPrintLabelsUrl(result.labels_url); } else { location.href = result.labels_url; }
        } catch(error) {document.getElementById('piecePrepareError').textContent = error.message;}
        finally {setBusy(false); button.textContent = original;}
    };
})();
</script>
@endpush
