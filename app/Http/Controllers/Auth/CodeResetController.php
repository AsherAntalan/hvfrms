<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\EmailRecoveryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\Rules\Password;
use Illuminate\Validation\ValidationException;

class CodeResetController extends Controller
{
    public function __construct(private EmailRecoveryService $recovery) {}

    public function showForm(): Response
    {
        if (session('email_verified')) {
            return response()->view('auth.verify-recovery-email', [
                'verified' => true, 'valid' => false,
            ])->header('Cache-Control', 'no-store');
        }

        $preset = trim((string) request('u', ''));
        $presetUser = $preset !== ''
            ? User::with('role')->where('username', $preset)->where('status', 'active')->first()
            : null;
        $presetAdmin = $presetUser && ! $presetUser->retired_at && $presetUser->isAdmin();

        return response()->view('auth.account-recovery', ['presetAdmin' => $presetAdmin])->header('Cache-Control', 'no-store');
    }

    public function reset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'username' => ['required', 'string', 'max:50'],
            'code' => ['nullable', 'string', 'max:40'],
            'email' => ['required', 'email', 'max:254'],
            'contact_number' => ['nullable', 'string', 'max:30', 'regex:/^[+]?[0-9][0-9 ()-]{5,28}[0-9]$/'],
        ]);
        try {
            $this->recovery->request(trim($data['username']), (string) ($data['code'] ?? ''), trim($data['email']), $data['contact_number'] ?? null);
        } catch (ValidationException $exception) {
            if (! array_key_exists('delivery', $exception->errors())) {
                throw $exception;
            }
        }

        return redirect()->route('password.code.request')->with('recovery_requested', true);
    }

    /**
     * Report whether a username belongs to an active admin, so the recovery form
     * can show only username + email for admins (no recovery code / contact).
     */
    public function mode(Request $request): JsonResponse
    {
        $data = $request->validate(['username' => ['required', 'string', 'max:50']]);
        $user = User::with('role')->where('username', trim($data['username']))->where('status', 'active')->first();

        return response()->json(['admin' => (bool) ($user && ! $user->retired_at && $user->isAdmin())]);
    }

    public function showVerification(string $token): Response
    {
        return response()->view('auth.verify-recovery-email', [
            'valid' => $this->recovery->verificationForToken($token) !== null, 'token' => $token,
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function verifyEmail(string $token): RedirectResponse
    {
        if (! $this->recovery->verify($token)) {
            return redirect()->route('password.code.request')->with('error', 'This verification link is invalid, expired, or already used. Submit a new recovery request.');
        }

        return redirect()->route('password.code.request')->with('email_verified', true);
    }

    public function showResetForm(string $token): Response
    {
        return response()->view('auth.reset-password', [
            'valid' => $this->recovery->resetForToken($token) !== null, 'token' => $token,
            'resetAction' => route('password.code.complete'), 'requestUrl' => route('password.code.request'),
        ])->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }

    public function completeReset(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'token' => ['required', 'string', 'size:64'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'confirm_password' => ['required', 'string', 'same:password'],
        ]);
        if (! $this->recovery->complete($data['token'], $data['password'])) {
            return back()->withErrors(['token' => 'This reset link is invalid, expired, revoked, or already used. Submit a new recovery request.']);
        }

        return redirect()->route('login')->with('success', 'Your password has been reset. You can now sign in with your new password.');
    }
}
