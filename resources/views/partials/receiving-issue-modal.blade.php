@if(auth()->user()->isAdmin() || auth()->user()->isBranchManager())
@push('styles')
<style>
.receiving-issue-open .modal-backdrop { background: rgba(45, 15, 15, .35); opacity: 1; backdrop-filter: blur(5px); }
#receivingIssueModal .modal-content { border-radius: 18px; }
</style>
@endpush
<div class="modal fade" id="receivingIssueModal" tabindex="-1" aria-labelledby="receivingIssueTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <form id="receivingIssueForm">
            <div class="modal-header">
                <h5 class="modal-title" id="receivingIssueTitle">Receiving assistance</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="receivingIssueError" class="alert alert-danger" role="alert" hidden></div>
                <p id="receivingIssueSummary" class="small"></p>
                <div class="table-responsive"><table class="table table-sm">
                    <thead><tr><th>Product</th><th>Awaiting receipt</th><th>Actual accepted</th><th>Discrepancy reason</th></tr></thead>
                    <tbody id="receivingIssueLines"></tbody>
                </table></div>
                <label class="form-label" for="receivingIssueReason">Reason / technical error and evidence reference</label>
                <textarea id="receivingIssueReason" class="form-control" minlength="10" maxlength="2000" rows="3" required></textarea>
                <div class="form-check mt-3">
                    <input id="receivingIssueConfirmed" class="form-check-input" type="checkbox" required>
                    <label id="receivingIssueConfirmationLabel" class="form-check-label small" for="receivingIssueConfirmed"></label>
                </div>
                <p class="small text-muted mt-2 mb-0">Only remaining released goods can be processed. Short or rejected goods are recorded as discrepancies. Existing receipts are never added again.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-maroon" id="receivingIssueSubmit">Submit</button>
            </div>
        </form>
    </div></div>
</div>
@push('scripts')
<script>
window.HVReceivingIssue = (function () {
    const el = document.getElementById('receivingIssueModal');
    const form = document.getElementById('receivingIssueForm');
    const error = document.getElementById('receivingIssueError');
    const reason = document.getElementById('receivingIssueReason');
    const confirmed = document.getElementById('receivingIssueConfirmed');
    const submit = document.getElementById('receivingIssueSubmit');
    const modal = new bootstrap.Modal(el);
    const isAdmin = @json(auth()->user()->isAdmin());
    const reasons = ['Missing Quantity', 'Damaged in Transit', 'Incorrect Quantity', 'Incorrect Item', 'Other'];
    let order = null;
    let busy = false;
    async function post(payload) {
        const res = await fetch(@json(route('orders.api')), {
            method: 'POST', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': document.querySelector('meta[name="csrf-token"]').content},
            body: JSON.stringify(payload)
        });
        const data = await res.json();
        if (!res.ok || !data.ok) { throw new Error(data.error || Object.values(data.errors || {}).flat().join(' ') || data.message || 'Could not process this request.'); }
        return data;
    }
    function showError(message) { error.textContent = message; error.hidden = false; }
    function setBusy(value) {
        busy = value;
        form.querySelectorAll('button, textarea, input, select').forEach(control => control.disabled = value);
    }
    function render(current) {
        order = current;
        document.getElementById('receivingIssueTitle').textContent = isAdmin ? 'Resolve Receiving Issue' : 'Report Receiving Issue';
        const issue = current.receiving_issue;
        document.getElementById('receivingIssueSummary').textContent = current.order_number + ' — ' + current.branch_name + (isAdmin && issue ? '\nConfirmed by ' + issue.reported_by + ' on ' + issue.reported_at + '. Branch report: ' + issue.reason : '. Enter only goods physically received and accepted. This report does not change stock.');
        const tbody = document.getElementById('receivingIssueLines');
        tbody.replaceChildren();
        current.items.forEach(item => {
            const row = document.createElement('tr');
            const expected = item.receivable_quantity ?? (item.released_quantity || item.fulfilled_quantity || item.quantity);
            const stored = issue?.receipt.find(line => Number(line.order_item_id) === Number(item.id));
            const name = row.insertCell(); name.textContent = item.item_name + ' (' + item.unit + ')';
            row.insertCell().textContent = expected;
            const quantityCell = row.insertCell();
            const reasonCell = row.insertCell();
            if (isAdmin) {
                quantityCell.textContent = stored?.received_quantity ?? 'Not confirmed';
                reasonCell.textContent = stored?.reason || 'None';
            } else {
                const input = document.createElement('input');
                input.type = 'number'; input.min = 0; input.max = expected; input.step = 1; input.required = true;
                input.className = 'form-control form-control-sm'; input.dataset.lineId = item.id;
                input.setAttribute('aria-label', 'Actual accepted quantity for ' + item.item_name);
                quantityCell.append(input);
                const select = document.createElement('select'); select.className = 'form-select form-select-sm';
                select.setAttribute('aria-label', 'Discrepancy reason for ' + item.item_name);
                select.add(new Option('No discrepancy', ''));
                reasons.forEach(value => select.add(new Option(value, value)));
                input.addEventListener('input', () => { select.required = input.value !== '' && Number(input.value) < expected; });
                reasonCell.append(select);
            }
            tbody.append(row);
        });
        document.getElementById('receivingIssueConfirmationLabel').textContent = isAdmin
            ? 'I reviewed the branch-confirmed quantities and supporting evidence. Reprocess this stored receipt without changing its quantities.'
            : 'I physically verified these accepted quantities at my branch and request admin assistance for the receiving error.';
        submit.textContent = isAdmin ? 'Reprocess confirmed receipt' : 'Submit branch confirmation';
    }
    async function open(id) {
        form.reset(); error.hidden = true; order = null; setBusy(true);
        document.getElementById('receivingIssueLines').replaceChildren();
        document.getElementById('receivingIssueSummary').textContent = 'Loading branch receipt details…';
        document.body.classList.add('receiving-issue-open'); modal.show();
        try {
            const data = await post({action: 'view', id: id});
            if (!(isAdmin ? data.order.actions.can_resolve_receiving_issue : data.order.actions.can_report_receiving_issue)) { throw new Error('This order is no longer eligible for receiving assistance.'); }
            render(data.order); setBusy(false);
        } catch (e) { setBusy(false); submit.disabled = true; showError(e.message); }
    }
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || !order || !form.reportValidity()) { return; }
        const payload = {action: isAdmin ? 'resolve_receiving_issue' : 'report_receiving_issue', reason: reason.value};
        if (isAdmin) { payload.issue_id = order.receiving_issue.id; payload.review_confirmed = confirmed.checked; }
        else {
            payload.id = order.id; payload.branch_confirmed = confirmed.checked;
            payload.receipt = [...document.querySelectorAll('#receivingIssueLines input')].map(input => ({order_item_id: Number(input.dataset.lineId), received_quantity: Number(input.value), reason: input.closest('tr').querySelector('select').value || null}));
        }
        setBusy(true); error.hidden = true;
        try {
            const data = await post(payload);
            setBusy(false);
            el.addEventListener('hidden.bs.modal', async function () {
                if (window.hvModal) { await window.hvModal.success({title: isAdmin ? 'Receiving issue resolved' : 'Report submitted', message: data.message}); }
                window.location.reload();
            }, {once: true});
            modal.hide();
        } catch (e) { setBusy(false); showError(e.message); }
    });
    el.addEventListener('hide.bs.modal', event => { if (busy) { event.preventDefault(); } });
    el.addEventListener('hidden.bs.modal', () => document.body.classList.remove('receiving-issue-open'));
    return {
        addAction: function (footer, current) {
            if (!(isAdmin ? current?.actions?.can_resolve_receiving_issue : current?.actions?.can_report_receiving_issue)) { return; }
            const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline-warning';
            button.textContent = isAdmin ? 'Resolve Receiving Issue' : 'Report Receiving Issue';
            button.onclick = function () {
                const parent = footer.closest('.modal');
                if (parent) {
                    parent.addEventListener('hidden.bs.modal', () => open(current.id), {once: true});
                    bootstrap.Modal.getInstance(parent).hide();
                } else { open(current.id); }
            };
            footer.append(button);
        }
    };
})();
</script>
@endpush
@else
@push('scripts')
<script>window.HVReceivingIssue = {addAction: function () {}};</script>
@endpush
@endif
