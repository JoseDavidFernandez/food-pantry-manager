<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * Every request that changes household data needs the `household.write` gate (RN-38): viewers
 * only read. Applied to the whole household data group, so no route can be forgotten; reading
 * (GET, HEAD) is open to every member. From the app (Inertia) the refusal is a toast; a direct
 * request gets a 403.
 */
class EnsureHouseholdWriteAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethodSafe()) {
            return $next($request);
        }

        $user = $request->user();

        if ($user instanceof User && Gate::forUser($user)->allows('household.write')) {
            return $next($request);
        }

        if ($request->header('X-Inertia') !== null) {
            Inertia::flash('toast', ['type' => 'error', 'message' => 'Tu rol en el hogar es de lectura: no puedes modificar datos.']);

            return back();
        }

        abort(403, 'Tu rol en el hogar es de lectura: no puedes modificar datos.');
    }
}
