<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class ResetPasswordController extends Controller
{
    public function showForm(string $token)
    {
        $token_hash = hash('sha256', $token);

        $user = User::where('reset_token', $token_hash)
            ->where('reset_token_expires', '>', now())
            ->first();

        if (!$user) {
            return view('auth.reset-password', [
                'valid'   => false,
                'token'   => $token,
                'user'    => null,
                'error'   => 'This reset link is invalid or has expired. Please request a new one.',
            ]);
        }

        return view('auth.reset-password', [
            'valid' => true,
            'token' => $token,
            'user'  => $user,
            'error' => null,
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token'            => 'required|string',
            'password'         => 'required|min:6',
            'confirm_password' => 'required|same:password',
        ]);

        $token_hash = hash('sha256', $request->token);

        $user = User::where('reset_token', $token_hash)
            ->where('reset_token_expires', '>', now())
            ->first();

        if (!$user) {
            return back()->withErrors(['token' => 'This reset link is invalid or has expired.']);
        }

        $user->update([
            'password_hash'       => Hash::make($request->password),
            'reset_token'         => null,
            'reset_token_expires' => null,
            'failed_attempts'     => 0,
            'locked_until'        => null,
        ]);

        AuditLog::create([
            'user_id'    => $user->id,
            'action'     => 'password_reset',
            'table_name' => 'users',
            'record_id'  => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('login')
            ->with('success', 'Your password has been reset successfully. You can now log in.');
    }
}
