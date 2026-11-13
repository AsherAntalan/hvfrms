@php
    $hvPaymentAccounts = \App\Models\PaymentAccount::where('is_active', true)->orderBy('sort_order')->orderBy('provider')->get();
    $hvHasBank = $hvPaymentAccounts->where('type', 'bank')->isNotEmpty();
    $hvHasEwallet = $hvPaymentAccounts->where('type', 'ewallet')->isNotEmpty();
    $hvAccountsJson = $hvPaymentAccounts->map(function ($a) {
        return ['id' => $a->id, 'type' => $a->type, 'provider' => $a->provider, 'account_name' => $a->account_name, 'account_number' => $a->account_number, 'instructions' => $a->instructions];
    })->values();
@endphp
<section class="border rounded p-3 my-3" aria-labelledby="{{ $paymentPrefix }}PaymentHeading" data-payment-prefix="{{ $paymentPrefix }}">
    <h6 id="{{ $paymentPrefix }}PaymentHeading" class="fw-semibold">Mode of payment</h6>
    <p class="small text-muted">Choose how you paid, then attach your payment screenshot and reference number. The Commissary will review your proof.</p>

    @if($hvPaymentAccounts->isEmpty())
        <div class="alert alert-warning small py-2">No payment methods have been configured. Ask the Owner or Admin to set them up before paying.</div>
    @else
    <div class="row g-2">
        <div class="col-sm-6">
            <label for="{{ $paymentPrefix }}PaymentType" class="form-label small fw-semibold">Payment type <span class="text-danger">*</span></label>
            <select id="{{ $paymentPrefix }}PaymentType" class="form-select hv-pay-type" required>
                <option value="">Select type…</option>
                @if($hvHasBank)<option value="bank">Bank</option>@endif
                @if($hvHasEwallet)<option value="ewallet">E-Wallet</option>@endif
            </select>
        </div>
        <div class="col-sm-6">
            <label for="{{ $paymentPrefix }}PaymentProvider" class="form-label small fw-semibold">Provider <span class="text-danger">*</span></label>
            <select id="{{ $paymentPrefix }}PaymentProvider" class="form-select hv-pay-provider" required disabled>
                <option value="">Select type first…</option>
            </select>
        </div>
    </div>
    <input type="hidden" name="payment_account_id" id="{{ $paymentPrefix }}PaymentAccountId">
    <div id="{{ $paymentPrefix }}PaymentDetails" class="border rounded bg-light p-2 mt-2 small" hidden></div>
    <script type="application/json" id="{{ $paymentPrefix }}PaymentAccountsData">@json($hvAccountsJson)</script>
    @endif

    <label for="{{ $paymentPrefix }}PaymentReference" class="form-label small fw-semibold mt-3">Payment reference number <span class="text-danger">*</span></label>
    <input id="{{ $paymentPrefix }}PaymentReference" name="payment_reference" type="text" class="form-control mb-2" required maxlength="60" autocomplete="off" aria-describedby="{{ $paymentPrefix }}PaymentHelp" placeholder="Reference shown on your payment receipt">
    <label for="{{ $paymentPrefix }}PaymentScreenshot" class="form-label small fw-semibold">Payment screenshot <span class="text-danger">*</span></label>
    <input id="{{ $paymentPrefix }}PaymentScreenshot" name="payment_screenshot" type="file" class="form-control" required accept="image/jpeg,image/png,image/webp" aria-describedby="{{ $paymentPrefix }}PaymentHelp">
    <p id="{{ $paymentPrefix }}PaymentHelp" class="small text-muted mt-2 mb-0">JPG, PNG, or WebP, up to 5 MB. Include the amount and reference number. A reference can only be used for one order.</p>
    <img id="{{ $paymentPrefix }}PaymentPreview" alt="Selected payment screenshot preview" hidden class="mt-2 rounded" style="max-width:100%;max-height:160px;object-fit:contain;">
    <p id="{{ $paymentPrefix }}PaymentError" class="text-danger small mt-2 mb-0" role="alert" hidden></p>
</section>

@once
@push('scripts')
<script>
function showOrderPaymentError(prefix, message) {
    const error = document.getElementById(prefix + 'PaymentError');
    error.textContent = message;
    error.hidden = !message;
    if (message) error.scrollIntoView({block: 'nearest'});
}
function orderPaymentError(json) {
    if (json.errors) return Object.values(json.errors).flat().join(' ');
    return json.error || json.message || 'Could not submit the order. Please try again.';
}
function appendOrderPayment(prefix, formData) {
    const reference = document.getElementById(prefix + 'PaymentReference');
    const screenshot = document.getElementById(prefix + 'PaymentScreenshot');
    const accountId = document.getElementById(prefix + 'PaymentAccountId');
    const value = reference.value.trim().replace(/[\s-]+/g, '').toUpperCase();
    let error = '';
    if (accountId && !accountId.value) error = 'Select your payment type and provider.';
    else if (!/^[A-Z0-9]{6,40}$/.test(value)) error = 'Enter the reference number shown on your payment receipt.';
    else if (!screenshot.files.length) error = 'Upload your payment screenshot before placing the order.';
    else if (!['image/jpeg','image/png','image/webp'].includes(screenshot.files[0].type)) error = 'Use a JPG, PNG, or WebP screenshot.';
    else if (screenshot.files[0].size > 5 * 1024 * 1024) error = 'The screenshot must be 5 MB or smaller.';
    showOrderPaymentError(prefix, error);
    if (error) { return false; }
    formData.append('payment_reference', reference.value.trim());
    formData.append('payment_screenshot', screenshot.files[0]);
    if (accountId) formData.append('payment_account_id', accountId.value);
    return true;
}
document.addEventListener('DOMContentLoaded', function () {
    // Payment type → provider → details wiring.
    document.querySelectorAll('[data-payment-prefix]').forEach(function (section) {
        const prefix = section.getAttribute('data-payment-prefix');
        const dataEl = document.getElementById(prefix + 'PaymentAccountsData');
        if (!dataEl) return;
        let accounts = [];
        try { accounts = JSON.parse(dataEl.textContent); } catch (e) { accounts = []; }
        const typeSel = document.getElementById(prefix + 'PaymentType');
        const providerSel = document.getElementById(prefix + 'PaymentProvider');
        const accountId = document.getElementById(prefix + 'PaymentAccountId');
        const details = document.getElementById(prefix + 'PaymentDetails');
        function esc(s){ return (s==null?'':String(s)).replace(/[&<>"]/g,function(c){return ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;'})[c];}); }
        typeSel.addEventListener('change', function () {
            providerSel.innerHTML = '<option value="">Select provider…</option>';
            accounts.filter(a => a.type === typeSel.value).forEach(function (a) {
                const o = document.createElement('option'); o.value = a.id; o.textContent = a.provider; providerSel.append(o);
            });
            providerSel.disabled = !typeSel.value;
            accountId.value = ''; details.hidden = true; details.innerHTML = '';
        });
        providerSel.addEventListener('change', function () {
            const a = accounts.find(x => String(x.id) === providerSel.value);
            accountId.value = a ? a.id : '';
            if (a) {
                details.innerHTML = '<strong>' + esc(a.provider) + '</strong>'
                    + (a.account_name ? '<br>Account name: ' + esc(a.account_name) : '')
                    + (a.account_number ? '<br>Account number: ' + esc(a.account_number) : '')
                    + (a.instructions ? '<br><span class="text-muted">' + esc(a.instructions) + '</span>' : '')
                    + ((!a.account_name && !a.account_number) ? '<br><span class="text-danger">Account details not set — ask the Admin.</span>' : '');
                details.hidden = false;
            } else { details.hidden = true; details.innerHTML = ''; }
        });
    });
    document.querySelectorAll('input[name="payment_screenshot"]').forEach(function(input) {
        const prefix = input.id.replace(/PaymentScreenshot$/, '');
        const preview = document.getElementById(prefix + 'PaymentPreview');
        let objectUrl;
        function clearPreview() {
            if (objectUrl) URL.revokeObjectURL(objectUrl);
            objectUrl = null;
            preview.removeAttribute('src');
            preview.hidden = true;
            showOrderPaymentError(prefix, '');
        }
        input.addEventListener('change', function() {
            clearPreview();
            const file = input.files[0];
            if (file && ['image/jpeg','image/png','image/webp'].includes(file.type) && file.size <= 5*1024*1024) {
                objectUrl = URL.createObjectURL(file);
                preview.src = objectUrl;
                preview.hidden = false;
            }
        });
        if (input.form) input.form.addEventListener('reset', clearPreview);
    });
});
</script>
@endpush
@endonce
