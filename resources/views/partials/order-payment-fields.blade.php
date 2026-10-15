<section class="border rounded p-3 my-3" aria-labelledby="{{ $paymentPrefix }}PaymentHeading">
    <h6 id="{{ $paymentPrefix }}PaymentHeading" class="fw-semibold">GCash payment</h6>
    <p class="small text-muted">Pay the order total, then attach your payment screenshot and reference number. The Commissary will review your proof.</p>
    @if(\App\Models\Setting::get('gcash_receiving_qr_path'))
        <a href="{{ route('payments.gcash-qr') }}" target="_blank" rel="noopener" class="d-inline-block mb-2">
            <img src="{{ route('payments.gcash-qr') }}" alt="GCash receiving QR — verify the recipient before paying" style="width:180px;max-width:100%;height:auto;">
        </a>
        <p class="small mb-2"><strong>{{ \App\Models\Setting::get('gcash_account_name') }}</strong><br>{{ \App\Models\Setting::get('gcash_account_number') }}</p>
        <p class="small text-muted">Open the QR to save it. In GCash, scan or upload it, and check the recipient and amount before confirming.</p>
    @else
        <div class="alert alert-warning small py-2">Receiving QR has not been configured. Ask the Owner or Admin for the confirmed payment details before paying.</div>
    @endif
    <label for="{{ $paymentPrefix }}PaymentReference" class="form-label small fw-semibold">GCash reference number <span class="text-danger">*</span></label>
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
    const value = reference.value.trim().replace(/[\s-]+/g, '').toUpperCase();
    let error = '';
    if (!/^[A-Z0-9]{6,40}$/.test(value)) error = 'Enter the reference number shown on your GCash receipt.';
    else if (!screenshot.files.length) error = 'Upload your GCash payment screenshot before placing the order.';
    else if (!['image/jpeg','image/png','image/webp'].includes(screenshot.files[0].type)) error = 'Use a JPG, PNG, or WebP screenshot.';
    else if (screenshot.files[0].size > 5 * 1024 * 1024) error = 'The screenshot must be 5 MB or smaller.';
    showOrderPaymentError(prefix, error);
    if (error) { (value ? screenshot : reference).focus(); return false; }
    formData.append('payment_reference', reference.value.trim());
    formData.append('payment_screenshot', screenshot.files[0]);
    return true;
}
document.addEventListener('DOMContentLoaded', function () {
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
