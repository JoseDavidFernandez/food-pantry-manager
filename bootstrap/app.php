<?php

use App\Actions\Households\HouseholdActionRefused;
use App\Http\Middleware\EnsureHouseholdWriteAccess;
use App\Http\Middleware\EnsureUserHasHousehold;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use App\Support\QuantityTooLarge;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Laravel\Fortify\Contracts\LoginViewResponse;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Reject requests whose Host header is not the application URL (no proxy in front), so a
        // forged Host cannot leak into generated links such as password-reset emails.
        $middleware->trustHosts(subdomains: false);

        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'household' => EnsureUserHasHousehold::class,
            'household.write' => EnsureHouseholdWriteAccess::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // A sum above the storable maximum: the transaction was rolled back; explain it instead of a 500.
        $exceptions->render(function (HouseholdActionRefused $exception, Request $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        });

        // Too many login attempts: keep the 429 and its Retry-After, but answer with the login page and
        // the reason under the email field, instead of Inertia's generic error dialog.
        $exceptions->render(function (ThrottleRequestsException $exception, Request $request) {
            if (! $request->routeIs('login.store') || $request->expectsJson()) {
                return null;
            }

            $headers = $exception->getHeaders();
            $seconds = max(1, (int) ($headers['Retry-After'] ?? 60));

            // Inertia resolved the shared "errors" before the route ran; replace them for this response
            // only (nothing is written to the session, so the next visit is clean).
            Inertia::share('errors', Inertia::always((object) [
                'email' => __('auth.throttle', ['seconds' => $seconds]),
            ]));

            return app(LoginViewResponse::class)->toResponse($request)
                ->setStatusCode(429)
                ->withHeaders($headers);
        });

        $exceptions->render(function (QuantityTooLarge $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        });
    })->create();
