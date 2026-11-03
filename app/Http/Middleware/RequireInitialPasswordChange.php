<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class RequireInitialPasswordChange
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->user() && $request->session()->has('password_fingerprint')
            && ! hash_equals($request->session()->get('password_fingerprint'), hash('sha256', $request->user()->password_hash))) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return $request->expectsJson()
                ? response()->json(['message' => 'Your password changed. Sign in again.'], 401)
                : redirect()->route('login')->with('warning', 'Your password changed. Sign in again.');
        }

        if ($request->user()?->must_change_password && ! $request->routeIs('password.initial*', 'logout')) {
            if ($request->expectsJson()) {
                return response()->json(['message' => 'Set your own password before using the system.', 'redirect' => route('password.initial')], 403);
            }

            return redirect()->route('password.initial');
        }

        return $next($request);
    }
}
