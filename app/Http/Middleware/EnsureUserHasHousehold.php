<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app is used inside a household (RN-01): a user without one is sent to create it or to
 * accept an invitation, also after logging in again or leaving an interrupted setup.
 */
class EnsureUserHasHousehold
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && ! $user->hasHousehold()) {
            return $request->expectsJson()
                ? response()->json(['message' => 'Primero crea un hogar o acepta una invitación.'], 409)
                : redirect()->route('household.setup');
        }

        return $next($request);
    }
}
