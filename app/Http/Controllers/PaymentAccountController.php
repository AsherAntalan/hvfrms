<?php

namespace App\Http\Controllers;

use App\Models\AuditLog;
use App\Models\PaymentAccount;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

class PaymentAccountController extends Controller
{
    private function authorizeManager(Request $request): void
    {
        $user = $request->user();
        abort_unless($user->status === 'active' && ($user->isOwner() || $user->isAdmin()), 403);
    }

    public function index(Request $request): Response
    {
        $this->authorizeManager($request);
        $accounts = PaymentAccount::orderBy('type')->orderBy('sort_order')->orderBy('provider')->paginate(10)->withQueryString();

        return response()->view('settings.payment-accounts', compact('accounts'))->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request): RedirectResponse
    {
        $this->authorizeManager($request);
        $data = $this->validated($request);
        $account = PaymentAccount::create($data + ['is_active' => true]);
        $this->audit($request, 'payment_account_created', $account->id, $account->provider);

        return back()->with('success', $account->provider.' payment method added.');
    }

    public function update(Request $request, PaymentAccount $paymentAccount): RedirectResponse
    {
        $this->authorizeManager($request);
        $action = $request->input('op');
        if ($action === 'toggle') {
            $paymentAccount->update(['is_active' => ! $paymentAccount->is_active]);
            $this->audit($request, 'payment_account_toggled', $paymentAccount->id, $paymentAccount->provider.' '.($paymentAccount->is_active ? 'enabled' : 'disabled'));

            return back()->with('success', $paymentAccount->provider.' '.($paymentAccount->is_active ? 'enabled' : 'disabled').'.');
        }
        if ($action === 'delete') {
            $provider = $paymentAccount->provider;
            $paymentAccount->delete();
            $this->audit($request, 'payment_account_deleted', 0, $provider);

            return back()->with('success', $provider.' payment method removed.');
        }
        $paymentAccount->update($this->validated($request));
        $this->audit($request, 'payment_account_updated', $paymentAccount->id, $paymentAccount->provider);

        return back()->with('success', $paymentAccount->provider.' updated.');
    }

    /** @return array<string, mixed> */
    private function validated(Request $request): array
    {
        return $request->validate([
            'type' => ['required', 'in:bank,ewallet'],
            'provider' => ['required', 'string', 'max:60'],
            'account_name' => ['nullable', 'string', 'max:150'],
            'account_number' => ['nullable', 'string', 'max:100'],
            'instructions' => ['nullable', 'string', 'max:255'],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:999'],
        ]);
    }

    private function audit(Request $request, string $action, int $id, string $details): void
    {
        AuditLog::create([
            'user_id' => $request->user()->id, 'action' => $action, 'table_name' => 'payment_accounts',
            'record_id' => $id, 'details' => $details, 'ip_address' => $request->ip(),
        ]);
    }
}
