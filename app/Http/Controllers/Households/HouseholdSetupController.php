<?php

namespace App\Http\Controllers\Households;

use App\Actions\Households\HouseholdMembership;
use App\Http\Controllers\Controller;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Before using the app, a user without a household creates one or accepts an invitation
 * (RN-01, RN-39). Also reached again after logging in or leaving a household.
 */
class HouseholdSetupController extends Controller
{
    public function __construct(private readonly HouseholdMembership $membership) {}

    public function show(Request $request): Response|RedirectResponse
    {
        $user = $this->user($request);

        if ($user->hasHousehold()) {
            return to_route('dashboard');
        }

        return Inertia::render('household/Setup', [
            'suggestedName' => 'Hogar de '.$user->name,
            'invitations' => $this->membership->pendingFor($user)->map(fn (HouseholdInvitation $invitation) => [
                'id' => $invitation->id,
                'household' => $invitation->household->name,
                'role' => $invitation->role->label(),
                'inviter' => $invitation->inviter?->name,
                'expires_at' => $invitation->expires_at->timezone('Europe/Madrid')->format('d/m/Y H:i'),
            ])->values(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']], [], ['name' => 'nombre del hogar']);
        $user = $this->user($request);
        $had = $user->hasHousehold();
        $household = $this->membership->create($user, $validated['name']);

        Inertia::flash('toast', [
            'type' => $had ? 'info' : 'success',
            'message' => $had ? "Ya perteneces al hogar «{$household->name}»." : "Hogar «{$household->name}» creado. Eres su administrador.",
        ]);

        return to_route('dashboard');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
