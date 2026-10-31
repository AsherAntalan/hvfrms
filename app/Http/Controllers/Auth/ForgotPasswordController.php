<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Mail\PasswordResetMail;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

class ForgotPasswordController extends Controller
{
    public function showForm()
    {
        return view('auth.forgot-password');
    }

    /**
     * Issue a reset link to an eligible, active account and email it.
     *
     * The public response is identical for registered-active, unknown, and
     * inactive addresses so the page never reveals whether an email exists or
     * is active. Only an eligible active account actually receives mail, and the
     * reset URL/token is never flashed to the session or shown in the browser.
     */
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        // Only eligible active accounts receive a token + email; every outcome
        // returns the same generic confirmation below.
        if ($user && $user->status === 'active') {
            $rawToken = bin2hex(random_bytes(32));

            // A new request invalidates any previous reset link: the stored hash
            // is overwritten, so the older raw token no longer matches.
            $user->update([
                'reset_token' => hash('sha256', $rawToken),
                'reset_token_expires' => now()->addHour(),
            ]);

            AuditLog::create([
                'user_id' => $user->id,
                'action' => 'password_reset_request',
                'table_name' => 'users',
                'record_id' => $user->id,
                'ip_address' => $request->ip(),
            ]);

            try {
                Mail::to($user->email)->send(new PasswordResetMail(
                    $user->full_name,
                    route('password.reset', $rawToken),
                ));
            } catch (\Throwable $e) {
                // Never expose mail/transport errors, credentials, or the token.
                Log::error('Password reset email failed to send.', [
                    'user_id' => $user->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return redirect()->route('password.request')->with('reset_requested', true);
    }
}
