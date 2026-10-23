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
 * The recipient's side of an invitation (RN-39): the link, accepting and declining. The link
 * requires an account (a guest logs in or registers with the invited email and comes back).
 */
class InvitationController extends Controller
{
    public function __construct(private readonly HouseholdMembership $membership) {}

    public function show(Request $request, string $token): Response
    {
        $user = $this->user($request);
        $invitation = $this->membership->findByToken($token);

        // Unknown token or another recipient: the same answer, nothing is revealed.
        if ($invitation === null || $invitation->email !== HouseholdMembership::normalizeEmail($user->email)) {
            return Inertia::render('household/Invitation', ['invitation' => null, 'email' => $user->email, 'hasHousehold' => $user->hasHousehold(), 'conflict' => null]);
        }

        $invitation->loadMissing(['household', 'inviter']);

        return Inertia::render('household/Invitation', [
            'invitation' => [
                'id' => $invitation->id,
                'household' => $invitation->household->name,
                'role' => $invitation->role->label(),
                'inviter' => $invitation->inviter?->name,
                'status' => $invitation->status(),
                'status_label' => $invitation->statusLabel(),
                'expires_at' => $invitation->expires_at->timezone('Europe/Madrid')->format('d/m/Y H:i'),
            ],
            'email' => $user->email,
            'hasHousehold' => $user->hasHousehold(),
            'conflict' => $this->conflict($user),
        ]);
    }

    /**
     * When the user already belongs to a household (one per account, RN-01): which one, and
     * what they can do. Nothing is changed here; the invitation stays pending.
     *
     * @return array{household: string, role: string, can_leave: bool, blocker: string|null, empty: bool}|null
     */
    private function conflict(User $user): ?array
    {
        $household = $user->households()->first();

        if ($household === null) {
            return null;
        }

        $blocker = $this->membership->leaveBlocker($household, $user);

        return [
            'household' => $household->name,
            'role' => $user->householdRole()?->label() ?? '',
            'can_leave' => $blocker === null,
            'blocker' => $blocker,
            'empty' => $household->countMembers() === 1 && $this->membership->isEmpty($household),
        ];
    }

    public function accept(Request $request, HouseholdInvitation $invitation): RedirectResponse
    {
        $household = $this->membership->accept($this->user($request), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => "Te has unido al hogar «{$household->name}» como {$invitation->role->label()}."]);

        return to_route('dashboard');
    }

    public function decline(Request $request, HouseholdInvitation $invitation): RedirectResponse
    {
        $this->membership->decline($this->user($request), $invitation);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitación rechazada.']);

        return $this->user($request)->hasHousehold() ? back() : to_route('household.setup');
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
