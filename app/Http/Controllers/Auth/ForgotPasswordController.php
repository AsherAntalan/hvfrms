<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;

class ForgotPasswordController extends Controller
{
    public function showForm()
    {
        return view('auth.forgot-password');
    }

    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user) {
            return back()->with('success', 'If that email is registered, a reset link has been generated.');
        }

        if ($user->status !== 'active') {
            return back()->withErrors(['email' => 'This account is not active. Please contact your administrator.']);
        }

        // Generate a secure token
        $raw_token  = bin2hex(random_bytes(32));
        $token_hash = hash('sha256', $raw_token);
        $expires_at = now()->addHour();

        $user->update([
            'reset_token'         => $token_hash,
            'reset_token_expires' => $expires_at,
        ]);

        $reset_link = url('/reset-password/' . $raw_token);

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'password_reset_request',
            'table_name' => 'users',
            'record_id'  => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return back()->with([
            'success'    => 'A password reset link has been generated for ' . $user->full_name . '.',
            'reset_link' => $reset_link,
        ]);
    }
}
