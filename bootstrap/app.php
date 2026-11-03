<?php

use App\Http\Middleware\BranchScope;
use App\Http\Middleware\CheckRole;
use App\Http\Middleware\IdleTimeout;
use App\Http\Middleware\RequireInitialPasswordChange;
use App\Http\Middleware\SecurityHeaders;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            SecurityHeaders::class,
            RequireInitialPasswordChange::class,
        ]);

        $middleware->alias([
            'role' => CheckRole::class,
            'branch.scope' => BranchScope::class,
            'idle.timeout' => IdleTimeout::class,
        ]);

        // Redirect guests to the login page (override default '/').
        $middleware->redirectGuestsTo('/login');

        // Redirect authenticated users away from guest-only routes to their
        // landing page: Branch Staff → QR scanner, Commissary Staff → Stock
        // Management (Deliver Product) scanner, everyone else → dashboard.
        $middleware->redirectUsersTo(function (Request $request): string {
            $user = $request->user();
            if ($user && $user->isBranchStaff()) {
                return route('qr');
            }
            if ($user && $user->isCommissaryStaff()) {
                return route('stock');
            }

            return route('dashboard');
        });
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->dontFlash(['confirm_password', 'code', 'token', 'current_password']);
        $exceptions->respond(function (Response $response, Throwable $exception, Request $request): Response {
            if ($response->getStatusCode() === 419 && $request->routeIs('login.submit') && ! $request->expectsJson()) {
                return redirect()->route('login')->with('warning', 'Your sign-in session expired. Please enter your credentials again.');
            }

            return $response;
        });

        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
