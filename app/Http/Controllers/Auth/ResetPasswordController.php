<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\RememberToken;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class ResetPasswordController extends Controller
{
    /**
     * Resolve the account for a raw token: the stored hash must match, the
     * token must be unexpired, and the account must be active.
     */
    private function userForToken(string $rawToken): ?User
    {
        return User::where('reset_token', hash('sha256', $rawToken))
            ->where('reset_token_expires', '>', now())
            ->where('status', 'active')
            ->first();
    }

    public function showForm(string $token)
    {
        $user = $this->userForToken($token);

        return view('auth.reset-password', [
            'valid' => $user !== null,
            'token' => $token,
            'error' => $user ? null : 'This reset link is invalid or has expired. Please request a new one.',
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'password' => 'required|string|min:8',
            'confirm_password' => 'required|string|same:password',
        ]);

        // Consume the token atomically so two concurrent submissions cannot both
        // succeed: the first to clear reset_token for this row wins.
        $user = DB::transaction(function () use ($request) {
            $user = User::where('reset_token', hash('sha256', $request->token))
                ->where('reset_token_expires', '>', now())
                ->where('status', 'active')
                ->lockForUpdate()
                ->first();

            if (! $user) {
                return null;
            }

            $user->update([
                'password_hash' => Hash::make($request->password),
                'reset_token' => null,
                'reset_token_expires' => null,
                'failed_attempts' => 0,
                'locked_until' => null,
            ]);

            // Revoke persistent authentication for this account (remember-me
            // handles) so existing sessions cannot continue with the old secret.
            RememberToken::where('user_id', $user->id)->delete();

            return $user;
        });

        if (! $user) {
            return back()->withErrors(['token' => 'This reset link is invalid or has expired.']);
        }

        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'password_reset',
            'table_name' => 'users',
            'record_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);

        return redirect()->route('login')
            ->with('success', 'Your password has been reset. You can now sign in with your new password.');
    }
}
