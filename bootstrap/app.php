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
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;
use Inertia\Inertia;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
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

        $exceptions->render(function (QuantityTooLarge $exception, Request $request) {
            if ($request->expectsJson()) {
                return null;
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $exception->getMessage()]);

            return back();
        });
    })->create();
