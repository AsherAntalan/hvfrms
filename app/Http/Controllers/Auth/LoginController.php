<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class LoginController extends Controller
{
    /**
     * Max failed login attempts before lockout.
     */
    const MAX_LOGIN_ATTEMPTS = 3;

    /**
     * Minutes to lock an account after max failed attempts.
     */
    const LOCKOUT_MINUTES = 15;

    /**
     * Show the login form.
     * If already authenticated, redirect to the appropriate landing page.
     */
    public function showLoginForm()
    {
        if (Auth::check()) {
            return redirect($this->redirectPath(Auth::user()));
        }

        return view('auth.login');
    }

    /**
     * Handle a login attempt.
     */
    public function login(Request $request)
    {
        $request->validate([
            'login' => 'required|string',
            'password' => 'required|string',
        ]);

        $login = $request->input('login');
        $password = $request->input('password');

        // Find user by username or email with their role
        $user = User::with('role')
            ->where('username', $login)
            ->orWhere('email', $login)
            ->first();

        // --- User not found ---
        if (! $user) {
            return back()
                ->withInput($request->only('login'))
                ->with('error', 'Invalid username or password.');
        }

        // --- Account not active ---
        if ($user->status !== 'active') {
            return back()
                ->withInput($request->only('login'))
                ->with('error', 'Your account is not active yet. Please wait for approval.');
        }

        // --- Account locked ---
        if ($user->locked_until && $user->locked_until->isFuture()) {
            return back()
                ->withInput($request->only('login'))
                ->with('error', 'Account locked due to failed attempts. Try again later.');
        }

        // --- Wrong password ---
        if (! password_verify($password, $user->password_hash)) {
            $attempts = $user->failed_attempts + 1;
            $lockedUntil = null;

            if ($attempts >= self::MAX_LOGIN_ATTEMPTS) {
                $lockedUntil = now()->addMinutes(self::LOCKOUT_MINUTES);
                $error = 'Account locked after '.self::MAX_LOGIN_ATTEMPTS
                       .' failed attempts. Try again in '.self::LOCKOUT_MINUTES.' minutes.';
            } else {
                $error = 'Invalid username or password. Attempt '
                       .$attempts.' of '.self::MAX_LOGIN_ATTEMPTS.'.';
            }

            $user->update([
                'failed_attempts' => $attempts,
                'locked_until' => $lockedUntil,
            ]);

            return back()
                ->withInput($request->only('login'))
                ->with('error', $error);
        }

        // --- Success ---
        // Reset failed attempts and lockout
        $user->update([
            'failed_attempts' => 0,
            'locked_until' => null,
        ]);

        // Log the user in and regenerate session to prevent fixation
        Auth::login($user);
        $request->session()->regenerate();

        // Write audit log
        AuditLog::create([
            'user_id' => $user->id,
            'action' => 'login',
            'table_name' => 'users',
            'record_id' => $user->id,
            'ip_address' => $request->ip(),
        ]);

        if ($user->isBranchStaff() || $user->isCommissaryStaff()) {
            $request->session()->forget('url.intended');
        }

        return redirect()->intended($this->redirectPath($user));
    }

    /**
     * Log the user out.
     */
    public function logout(Request $request)
    {
        Auth::logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    /**
     * Determine the post-login redirect path based on role.
     */
    protected function redirectPath(User $user): string
    {
        // Branch Staff scan their branch stock from the QR scanner.
        if ($user->isBranchStaff()) {
            return route('qr');
        }

        // Commissary Staff release prepared goods from the Stock Management
        // scanner (the same Deliver Product page as the Commissary Manager).
        if ($user->isCommissaryStaff()) {
            return route('stock');
        }

        return route('dashboard');
    }
}
