<?php

namespace App\Services;

use App\Models\Order;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use RuntimeException;
use Throwable;

class OrderPaymentService
{
    /** @return array{payment_reference: string, payment_screenshot: UploadedFile} */
    public function validateSubmission(Request $request): array
    {
        $raw = $request->input('payment_reference');
        if (is_string($raw)) {
            $request->merge(['payment_reference' => strtoupper(preg_replace('/[\s-]+/', '', trim($raw)))]);
        }

        return $request->validate([
            'payment_reference' => ['required', 'string', 'regex:/\A[A-Z0-9]{6,40}\z/', Rule::unique('orders', 'payment_reference')],
            'payment_screenshot' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
        ], [
            'payment_reference.required' => 'Enter the GCash payment reference number before placing your order.',
            'payment_reference.regex' => 'Enter the reference shown on your GCash receipt (6 to 40 letters or digits).',
            'payment_reference.unique' => 'This payment reference has already been used for an order. Contact the Commissary if you need a correction.',
            'payment_screenshot.required' => 'Upload your GCash payment screenshot before placing your order.',
            'payment_screenshot.image' => 'The payment screenshot must be a valid image.',
            'payment_screenshot.mimes' => 'Use a JPG, PNG, or WebP payment screenshot.',
            'payment_screenshot.max' => 'The payment screenshot must be 5 MB or smaller.',
        ]);
    }

    /** @param array{payment_reference: string, payment_screenshot: UploadedFile} $payment */
    public function attach(Order $order, array $payment): string
    {
        $path = $payment['payment_screenshot']->store('order-payments', 'local');
        if (! $path) {
            throw new RuntimeException('Could not save payment screenshot.');
        }
        try {
            $order->load('branch', 'orderedByUser', 'orderItems.item');
            $order->update([
                'payment_reference' => $payment['payment_reference'],
                'payment_screenshot_path' => $path,
                'payment_submitted_at' => now(),
                'proforma_snapshot' => $this->snapshot($order),
            ]);
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }

        return $path;
    }

    /** @return array<string, mixed> */
    public function snapshot(Order $order): array
    {
        $order->loadMissing('branch', 'orderedByUser', 'orderItems.item');

        return [
            'issuer' => Setting::get('app_name', 'Hidden Vietnam'),
            'branch' => $order->branch?->branch_name,
            'requested_by' => $order->orderedByUser?->full_name,
            'date' => ($order->created_at ?? now())->timezone('Asia/Manila')->format('M j, Y g:i A'),
            'total' => (string) $order->total_amount,
            'lines' => $order->orderItems->map(fn ($line) => [
                'name' => $line->item?->item_name ?? 'Product unavailable',
                'unit' => $line->item?->unit ?? '',
                'quantity' => (int) $line->quantity,
                'unit_price' => (string) $line->unit_price,
                'subtotal' => number_format((float) $line->unit_price * (int) $line->quantity, 2, '.', ''),
            ])->all(),
        ];
    }
}
