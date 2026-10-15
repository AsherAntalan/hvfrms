<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\Order;
use App\Models\Setting;
use App\Services\OrderPaymentService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class OrderPaymentController extends Controller
{
    private function authorizeOrder(Request $request, Order $order): void
    {
        $user = $request->user();
        abort_unless($user->isOwner() || $user->isCommissaryManager()
            || ($user->isBranchManager() && (int) $user->branch_id === (int) $order->branch_id), 403);
    }

    public function proforma(Request $request, Order $order, OrderPaymentService $payments): Response
    {
        $this->authorizeOrder($request, $order);
        abort_unless($order->isProformaAvailable(), 403, 'Proforma printing is available only after order confirmation by the Owner, Admin, or Commissary Manager.');
        $order->loadMissing('approvedByUser');

        return response()->view('orders.proforma', [
            'order' => $order,
            'invoice' => $order->proforma_snapshot ?? $payments->snapshot($order),
        ])->header('Cache-Control', 'private, no-store');
    }

    public function screenshot(Request $request, Order $order): StreamedResponse
    {
        $this->authorizeOrder($request, $order);
        abort_unless($order->payment_screenshot_path && Storage::disk('local')->exists($order->payment_screenshot_path), 404);

        return Storage::disk('local')->response($order->payment_screenshot_path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function receivingQr(): StreamedResponse
    {
        $path = Setting::get('gcash_receiving_qr_path');
        abort_unless($path && Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->response($path, null, [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    public function updateReceivingQr(Request $request): RedirectResponse
    {
        abort_unless($request->user()->isOwner(), 403);
        $data = $request->validate([
            'gcash_receiving_qr' => ['required', 'file', 'image', 'mimes:jpg,jpeg,png,webp', 'max:5120'],
            'gcash_account_name' => ['required', 'string', 'max:100'],
            'gcash_account_number' => ['nullable', 'regex:/\A09[0-9]{9}\z/'],
            'confirm_receiving_qr' => ['accepted'],
        ]);
        $oldPath = Setting::get('gcash_receiving_qr_path');
        $path = $data['gcash_receiving_qr']->store('payment-settings', 'local');
        abort_unless($path, 500, 'Could not save the receiving QR.');
        try {
            DB::transaction(function () use ($path, $data, $request) {
                Setting::set('gcash_receiving_qr_path', $path);
                Setting::set('gcash_account_name', trim($data['gcash_account_name']));
                Setting::set('gcash_account_number', $data['gcash_account_number'] ?? '');
                AuditLog::create(['user_id' => $request->user()->id, 'action' => 'GCash receiving QR updated',
                    'table_name' => 'settings', 'record_id' => 0, 'details' => 'Receiving QR and recipient details updated.', 'ip_address' => $request->ip()]);
            });
        } catch (Throwable $exception) {
            Storage::disk('local')->delete($path);
            throw $exception;
        }
        if ($oldPath && $oldPath !== $path) {
            Storage::disk('local')->delete($oldPath);
        }

        return redirect()->route('settings')->with('success', 'GCash receiving QR saved. Check the recipient in GCash before paying.');
    }
}
