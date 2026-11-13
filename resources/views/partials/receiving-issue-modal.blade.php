@if(auth()->user()->isAdmin() || auth()->user()->isCommissaryManager() || auth()->user()->isBranchManager())
@push('styles')
<style>
.receiving-issue-open .modal-backdrop { background: rgba(45, 15, 15, .35); opacity: 1; backdrop-filter: blur(5px); }
#receivingIssueModal .modal-content { border-radius: 18px; }
#receivingIssuePhotoView img { max-width: 100%; max-height: 260px; object-fit: contain; border-radius: 10px; border: 1px solid #e9ecef; }
</style>
@endpush
<div class="modal fade" id="receivingIssueModal" tabindex="-1" aria-labelledby="receivingIssueTitle" aria-hidden="true" data-bs-backdrop="static" data-bs-keyboard="false">
    <div class="modal-dialog modal-dialog-centered modal-lg"><div class="modal-content">
        <form id="receivingIssueForm" enctype="multipart/form-data">
            <div class="modal-header">
                <h5 class="modal-title" id="receivingIssueTitle">Request Refund</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div id="receivingIssueError" class="alert alert-danger" role="alert" hidden></div>
                <p id="receivingIssueSummary" class="small"></p>

                {{-- Branch manager: mark damaged quantities + describe + photo. --}}
                <div id="refundFields">
                    <label class="form-label">Damaged items (enter the damaged quantity)</label>
                    <div class="table-responsive"><table class="table table-sm align-middle">
                        <thead><tr><th>Product</th><th class="text-center">Delivered</th><th style="width:140px;">Damaged qty</th></tr></thead>
                        <tbody id="refundLines"></tbody>
                    </table></div>
                    <label class="form-label mt-2" for="receivingIssueReason">Describe the problem</label>
                    <textarea id="receivingIssueReason" class="form-control" minlength="10" maxlength="2000" rows="3" placeholder="e.g. 3 bottles arrived cracked and leaking." required></textarea>
                    <label class="form-label mt-3" for="refundPhoto">Attach a photo as evidence</label>
                    <input id="refundPhoto" class="form-control" type="file" accept="image/*" capture="environment" required>
                    <p class="small text-muted mt-2 mb-0">On approval, the damaged quantities are removed from your branch inventory.</p>
                </div>

                {{-- Reviewer (admin / commissary manager): review + confirm. --}}
                <div id="adminReview" hidden>
                    <div class="table-responsive"><table class="table table-sm align-middle">
                        <thead><tr><th>Product</th><th class="text-center">Damaged qty to remove</th></tr></thead>
                        <tbody id="reviewLines"></tbody>
                    </table></div>
                    <div id="receivingIssuePhotoView" class="mb-3"></div>
                    <label class="form-label" for="resolveReason">Resolution note</label>
                    <textarea id="resolveReason" class="form-control" minlength="10" maxlength="2000" rows="3" placeholder="How was this refund handled?"></textarea>
                    <div class="form-check mt-3">
                        <input id="receivingIssueConfirmed" class="form-check-input" type="checkbox">
                        <label class="form-check-label small" for="receivingIssueConfirmed">I reviewed the reason and photo. Confirming removes the damaged quantities from the branch's inventory.</label>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-light" data-bs-dismiss="modal">Cancel</button>
                <button type="submit" class="btn btn-maroon" id="receivingIssueSubmit">Submit Request Refund</button>
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
    const photo = document.getElementById('refundPhoto');
    const resolveReason = document.getElementById('resolveReason');
    const confirmed = document.getElementById('receivingIssueConfirmed');
    const submit = document.getElementById('receivingIssueSubmit');
    const refundFields = document.getElementById('refundFields');
    const adminReview = document.getElementById('adminReview');
    const photoView = document.getElementById('receivingIssuePhotoView');
    const modal = new bootstrap.Modal(el);
    const isReviewer = @json(auth()->user()->isAdmin() || auth()->user()->isCommissaryManager());
    const API = @json(route('orders.api'));
    const CSRF = document.querySelector('meta[name="csrf-token"]').content;
    let order = null;
    let busy = false;

    function showError(message) { error.textContent = message; error.hidden = false; }
    function setBusy(value) {
        busy = value;
        form.querySelectorAll('button, textarea, input').forEach(c => c.disabled = value);
    }
    async function postJson(payload) {
        const res = await fetch(API, { method: 'POST', headers: { 'Content-Type': 'application/json', 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: JSON.stringify(payload) });
        const data = await res.json();
        if (!res.ok || !data.ok) { throw new Error(data.error || Object.values(data.errors || {}).flat().join(' ') || data.message || 'Could not process this request.'); }
        return data;
    }
    async function postForm(fd) {
        fd.append('_token', CSRF);
        const res = await fetch(API, { method: 'POST', headers: { 'Accept': 'application/json', 'X-CSRF-TOKEN': CSRF }, body: fd });
        const data = await res.json();
        if (!res.ok || !data.ok) { throw new Error(data.error || Object.values(data.errors || {}).flat().join(' ') || data.message || 'Could not submit the request.'); }
        return data;
    }
    function render(current) {
        order = current;
        const issue = current.receiving_issue;
        document.getElementById('receivingIssueTitle').textContent = isReviewer ? 'Review Refund Request' : 'Request Refund';
        if (isReviewer) {
            refundFields.hidden = true;
            adminReview.hidden = false;
            submit.textContent = 'Confirm refund — remove from inventory';
            document.getElementById('receivingIssueSummary').textContent = current.order_number + ' — ' + current.branch_name
                + (issue ? '  ·  Reported by ' + issue.reported_by + ' on ' + issue.reported_at : '');
            const names = {};
            current.items.forEach(i => { names[i.id] = i.item_name + ' (' + i.unit + ')'; });
            const tbody = document.getElementById('reviewLines');
            tbody.replaceChildren();
            (issue && issue.receipt ? issue.receipt : []).forEach(line => {
                const row = document.createElement('tr');
                row.insertCell().textContent = names[line.order_item_id] || ('Item #' + line.item_id);
                const q = row.insertCell(); q.className = 'text-center fw-semibold'; q.textContent = line.quantity;
                tbody.append(row);
            });
            photoView.replaceChildren();
            if (issue && issue.reason) {
                const p = document.createElement('p'); p.className = 'mb-2'; p.innerHTML = '<strong>Reason:</strong> ' + issue.reason;
                photoView.append(p);
            }
            if (issue && issue.photo_url) {
                const a = document.createElement('a'); a.href = issue.photo_url; a.target = '_blank'; a.rel = 'noopener';
                const img = document.createElement('img'); img.src = issue.photo_url; img.alt = 'Refund evidence photo';
                a.append(img); photoView.append(a);
            } else {
                const n = document.createElement('p'); n.className = 'small text-muted'; n.textContent = 'No photo attached.';
                photoView.append(n);
            }
        } else {
            refundFields.hidden = false;
            adminReview.hidden = true;
            submit.textContent = 'Submit Request Refund';
            document.getElementById('receivingIssueSummary').textContent = current.order_number + ' — ' + current.branch_name + '. Mark the damaged quantities, describe the problem, and attach a photo.';
            const tbody = document.getElementById('refundLines');
            tbody.replaceChildren();
            current.items.forEach(item => {
                const delivered = item.fulfilled_quantity ?? item.quantity;
                const row = document.createElement('tr');
                row.insertCell().textContent = item.item_name + ' (' + item.unit + ')';
                const d = row.insertCell(); d.className = 'text-center'; d.textContent = delivered;
                const cell = row.insertCell();
                const input = document.createElement('input');
                input.type = 'number'; input.min = 0; input.max = delivered; input.step = 1; input.value = 0;
                input.className = 'form-control form-control-sm'; input.dataset.lineId = item.id;
                input.setAttribute('aria-label', 'Damaged quantity for ' + item.item_name);
                cell.append(input);
                tbody.append(row);
            });
        }
    }
    async function open(id) {
        form.reset(); error.hidden = true; order = null; setBusy(true);
        document.getElementById('receivingIssueSummary').textContent = 'Loading order details…';
        document.body.classList.add('receiving-issue-open'); modal.show();
        try {
            const data = await postJson({ action: 'view', id: id });
            if (!(isReviewer ? data.order.actions.can_resolve_receiving_issue : data.order.actions.can_request_refund)) { throw new Error('This order is no longer eligible.'); }
            render(data.order); setBusy(false);
        } catch (e) { setBusy(false); submit.disabled = true; showError(e.message); }
    }
    form.addEventListener('submit', async function (event) {
        event.preventDefault();
        if (busy || !order) { return; }
        setBusy(true); error.hidden = true;
        try {
            let data;
            if (isReviewer) {
                if (!resolveReason.value || resolveReason.value.trim().length < 10) { throw new Error('Enter a resolution note (at least 10 characters).'); }
                if (!confirmed.checked) { throw new Error('Please confirm you reviewed the request.'); }
                data = await postJson({ action: 'resolve_receiving_issue', issue_id: order.receiving_issue.id, reason: resolveReason.value, review_confirmed: true });
            } else {
                if (!reason.value || reason.value.trim().length < 10) { throw new Error('Describe the problem (at least 10 characters).'); }
                if (!photo.files || !photo.files[0]) { throw new Error('Attach a photo as evidence.'); }
                const lines = [...document.querySelectorAll('#refundLines input')]
                    .map(i => ({ order_item_id: Number(i.dataset.lineId), quantity: Number(i.value) }))
                    .filter(l => l.quantity > 0);
                if (!lines.length) { throw new Error('Enter the damaged quantity for at least one item.'); }
                const fd = new FormData();
                fd.append('action', 'request_refund');
                fd.append('id', order.id);
                fd.append('reason', reason.value);
                fd.append('items', JSON.stringify(lines));
                fd.append('photo', photo.files[0]);
                data = await postForm(fd);
            }
            setBusy(false);
            el.addEventListener('hidden.bs.modal', async function () {
                if (window.hvModal) { await window.hvModal.success({ title: isReviewer ? 'Refund confirmed' : 'Refund request submitted', message: data.message }); }
                window.location.reload();
            }, { once: true });
            modal.hide();
        } catch (e) { setBusy(false); showError(e.message); }
    });
    el.addEventListener('hide.bs.modal', event => { if (busy) { event.preventDefault(); } });
    el.addEventListener('hidden.bs.modal', () => document.body.classList.remove('receiving-issue-open'));
    return {
        addAction: function (footer, current) {
            if (!(isReviewer ? current?.actions?.can_resolve_receiving_issue : current?.actions?.can_request_refund)) { return; }
            const button = document.createElement('button'); button.type = 'button'; button.className = 'btn btn-outline-warning';
            button.textContent = isReviewer ? 'Review Refund Request' : 'Report Receiving Issue';
            button.onclick = function () {
                const parent = footer.closest('.modal');
                if (parent) {
                    parent.addEventListener('hidden.bs.modal', () => open(current.id), { once: true });
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
