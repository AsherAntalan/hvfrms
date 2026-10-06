<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class IdleTimeout
{
    /**
     * Handle an incoming request.
     *
     * Check the session's last_activity timestamp. If the user has been idle
     * longer than the configured threshold, destroy the session and redirect
     * to login. Otherwise, update last_activity to the current time.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $maxIdleMinutes = config('session.idle_timeout', 120);
        $lastActivity = $request->session()->get('last_activity');

        if ($lastActivity && (time() - $lastActivity) > ($maxIdleMinutes * 60)) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')
                ->with('warning', 'You were logged out due to inactivity.');
        }

        $request->session()->put('last_activity', time());

        return $next($request);
    }
}
