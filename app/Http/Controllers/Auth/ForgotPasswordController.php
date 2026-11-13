<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordOtpMail;
use App\Models\AuditLog;
use App\Models\RememberToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rules\Password;

class ForgotPasswordController extends Controller
{
    private const OTP_TTL_MINUTES = 10;

    private const OTP_MAX_ATTEMPTS = 5;

    public function showForm()
    {
        return view('auth.forgot-password');
    }

    /**
     * Entry point for the "forgot password" email form.
     *
     * Admin accounts keep the emailed reset-link flow. Every other active
     * account gets a 6-digit one-time code (OTP) emailed to them and is sent to
     * the self-service code + new-password page. The response is deliberately
     * uniform so the page never confirms whether an address exists.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $user = User::where('email', $request->email)->where('status', 'active')->first();

        // Every active account — admin included — uses the OTP self-service flow.
        // An OTP is only generated for an eligible active account; the page is
        // reached regardless so the response never reveals whether an email exists.
        if ($user) {
            $this->issueOtp($user, $request);
        }

        $request->session()->put('otp_email', $request->email);

        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'mode' => 'otp', 'email' => $request->email]);
        }

        return redirect()->route('password.otp')->with('otp_sent', true);
    }

    /** Show the OTP + new-password self-service form. */
    public function showOtpForm(Request $request)
    {
        $email = $request->session()->get('otp_email');
        if (! $email) {
            return redirect()->route('password.request');
        }

        return view('auth.otp-reset', ['email' => $email]);
    }

    /** Verify the OTP and set the new password in one step. */
    public function verifyOtp(Request $request)
    {
        $email = $request->session()->get('otp_email');
        if (! $email) {
            return redirect()->route('password.request');
        }

        $request->validate([
            'code' => ['required', 'string', 'regex:/^\d{6}$/'],
            'password' => ['required', 'string', Password::min(8)->mixedCase()->numbers()->symbols()],
            'confirm_password' => ['required', 'string', 'same:password'],
        ], [], ['code' => 'code']);

        $user = User::where('email', $email)->where('status', 'active')->first();
        $generic = 'The code is invalid or has expired. Request a new one.';
        $fail = function (string $message) use ($request) {
            if ($request->expectsJson()) {
                return response()->json(['ok' => false, 'errors' => ['code' => [$message]]], 422);
            }

            return back()->withErrors(['code' => $message]);
        };

        if (! $user || ! $user->otp_code_hash || ! $user->otp_expires_at || $user->otp_expires_at->isPast()) {
            return $fail($generic);
        }
        if ($user->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            return $fail('Too many attempts. Request a new code.');
        }
        if (! Hash::check($request->code, $user->otp_code_hash)) {
            $user->increment('otp_attempts');

            return $fail($generic);
        }

        $user->update([
            'password_hash' => Hash::make($request->password),
            'otp_code_hash' => null,
            'otp_expires_at' => null,
            'otp_attempts' => 0,
            'reset_token' => null,
            'reset_token_expires' => null,
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);
        RememberToken::where('user_id', $user->id)->delete();

        AuditLog::create([
            'user_id' => $user->id, 'action' => 'password_reset_otp', 'table_name' => 'users',
            'record_id' => $user->id, 'ip_address' => $request->ip(),
        ]);

        $request->session()->forget('otp_email');

        $message = 'Your password has been reset. You can now sign in with your new password.';
        if ($request->expectsJson()) {
            return response()->json(['ok' => true, 'message' => $message]);
        }

        return redirect()->route('login')->with('success', $message);
    }

    /** Verify the OTP only (no password change) so the UI can reveal the
     *  new-password fields only after a valid code. */
    public function checkOtp(Request $request)
    {
        $email = $request->session()->get('otp_email');
        if (! $email) {
            return response()->json(['ok' => false, 'error' => 'Your session expired. Start over.'], 422);
        }
        $request->validate(['code' => ['required', 'string', 'regex:/^\d{6}$/']], [], ['code' => 'code']);

        $user = User::where('email', $email)->where('status', 'active')->first();
        $generic = 'The code is invalid or has expired. Request a new one.';

        if (! $user || ! $user->otp_code_hash || ! $user->otp_expires_at || $user->otp_expires_at->isPast()) {
            return response()->json(['ok' => false, 'errors' => ['code' => [$generic]]], 422);
        }
        if ($user->otp_attempts >= self::OTP_MAX_ATTEMPTS) {
            return response()->json(['ok' => false, 'errors' => ['code' => ['Too many attempts. Request a new code.']]], 422);
        }
        if (! Hash::check($request->code, $user->otp_code_hash)) {
            $user->increment('otp_attempts');

            return response()->json(['ok' => false, 'errors' => ['code' => [$generic]]], 422);
        }

        return response()->json(['ok' => true]);
    }

    /** Resend a fresh OTP to the address held in the session. */
    public function resendOtp(Request $request)
    {
        $email = $request->session()->get('otp_email');
        if (! $email) {
            return redirect()->route('password.request');
        }
        $user = User::where('email', $email)->where('status', 'active')->first();
        if ($user) {
            $this->issueOtp($user, $request);
        }

        if ($request->expectsJson()) {
            return response()->json(['ok' => true]);
        }

        return redirect()->route('password.otp')->with('otp_sent', true);
    }

    /** Generate, store (hashed) and email a 6-digit OTP for the account. */
    private function issueOtp(User $user, Request $request): void
    {
        $code = str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
        $user->update([
            'otp_code_hash' => Hash::make($code),
            'otp_expires_at' => now()->addMinutes(self::OTP_TTL_MINUTES),
            'otp_attempts' => 0,
        ]);
        AuditLog::create([
            'user_id' => $user->id, 'action' => 'password_reset_otp_sent', 'table_name' => 'users',
            'record_id' => $user->id, 'ip_address' => $request->ip(),
        ]);
        try {
            Mail::to($user->email)->send(new PasswordOtpMail($user->full_name, $code, self::OTP_TTL_MINUTES));
        } catch (\Throwable $e) {
            Log::error('Password OTP email failed to send.', ['user_id' => $user->id, 'error' => $e->getMessage()]);
        }
    }
}
