<?php

namespace App\Http\Controllers\Households;

use App\Actions\Households\HouseholdMembership;
use App\Enums\HouseholdRole;
use App\Http\Controllers\Controller;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use App\Notifications\HouseholdInvitationNotification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * The household page (RN-38, RN-39): every member sees the members and their roles and can
 * leave; only admins invite, change roles, remove members and rename the household. The
 * household always comes from the authenticated membership, never from the request.
 */
class HouseholdController extends Controller
{
    public function __construct(private readonly HouseholdMembership $membership) {}

    public function show(Request $request): Response
    {
        $user = $this->user($request);
        $household = $user->currentHousehold();
        $canManage = Gate::allows('household.manage');

        return Inertia::render('settings/Household', [
            'household' => ['id' => $household->id, 'name' => $household->name],
            'members' => $household->members()->orderBy('name')->get()->map(fn (User $member) => [
                'id' => $member->id,
                'name' => $member->name,
                'email' => $member->email,
                'role' => $member->getRelationValue('pivot')?->getAttribute('role'),
                'is_self' => $member->is($user),
            ])->values(),
            'invitations' => ! $canManage ? [] : $household->invitations()->with('inviter')->latest('id')->limit(30)->get()
                ->map(fn (HouseholdInvitation $invitation) => [
                    'id' => $invitation->id,
                    'email' => $invitation->email,
                    'role' => $invitation->role->label(),
                    'status' => $invitation->status(),
                    'status_label' => $invitation->statusLabel(),
                    'inviter' => $invitation->inviter?->name,
                    'expires_at' => $invitation->expires_at->timezone('Europe/Madrid')->format('d/m/Y H:i'),
                ])->values(),
            // Invitations received from other households while already in this one (RN-01).
            'receivedInvitations' => $this->membership->pendingFor($user)
                ->reject(fn (HouseholdInvitation $invitation) => $invitation->household_id === $household->id)
                ->map(fn (HouseholdInvitation $invitation) => [
                    'id' => $invitation->id,
                    'household' => $invitation->household->name,
                    'role' => $invitation->role->label(),
                    'inviter' => $invitation->inviter?->name,
                    'expires_at' => $invitation->expires_at->timezone('Europe/Madrid')->format('d/m/Y H:i'),
                ])->values(),
            'leaveBlocker' => $this->membership->leaveBlocker($household, $user),
            'leaveDeletesEmpty' => $household->countMembers() === 1 && $this->membership->isEmpty($household),
            'roles' => HouseholdRole::options(),
            'myRole' => $user->householdRole()?->value,
            'canManage' => $canManage,
            // In development the `log` mailer writes the email to the log instead of sending it.
            'mailSimulated' => in_array(config('mail.default'), ['log', 'array'], true),
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        Gate::authorize('household.manage');
        $validated = $request->validate(['name' => ['required', 'string', 'max:100']], [], ['name' => 'nombre del hogar']);

        $this->user($request)->currentHousehold()->update(['name' => trim($validated['name'])]);
        Inertia::flash('toast', ['type' => 'success', 'message' => 'Nombre del hogar actualizado.']);

        return back();
    }

    public function invite(Request $request): RedirectResponse
    {
        Gate::authorize('household.manage');
        $validated = $request->validate([
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(HouseholdRole::class)],
        ], [], ['email' => 'correo electrónico', 'role' => 'rol']);

        $user = $this->user($request);
        $household = $user->currentHousehold();
        [$invitation, $token] = $this->membership->invite($household, $user, $validated['email'], HouseholdRole::from($validated['role']));
        $url = route('invitations.show', $token);

        $sent = $this->send($invitation, $url);
        $simulated = in_array(config('mail.default'), ['log', 'array'], true);

        // The link is shown once, so the admin can also share it by other means.
        Inertia::flash('invitationLink', ['email' => $invitation->email, 'url' => $url]);
        Inertia::flash('toast', [
            'type' => $sent && ! $simulated ? 'success' : 'warning',
            'message' => match (true) {
                ! $sent => "Invitación creada para {$invitation->email}, pero no se ha podido enviar el correo. Comparte el enlace.",
                $simulated => "Invitación creada para {$invitation->email}. En este entorno el correo no se envía de verdad: comparte el enlace.",
                default => "Invitación enviada a {$invitation->email}.",
            },
        ]);

        return back();
    }

    public function revoke(Request $request, int $invitation): RedirectResponse
    {
        Gate::authorize('household.manage');
        $this->membership->revoke($this->user($request)->currentHousehold()->invitations()->findOrFail($invitation));

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Invitación revocada.']);

        return back();
    }

    public function updateMember(Request $request, int $member): RedirectResponse
    {
        Gate::authorize('household.manage');
        $validated = $request->validate(['role' => ['required', Rule::enum(HouseholdRole::class)]], [], ['role' => 'rol']);
        $household = $this->user($request)->currentHousehold();
        $target = $this->member($household, $member);
        $role = HouseholdRole::from($validated['role']);

        $this->membership->changeRole($household, $target, $role);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$target->name} ahora es {$role->label()}."]);

        return back();
    }

    public function removeMember(Request $request, int $member): RedirectResponse
    {
        Gate::authorize('household.manage');
        $user = $this->user($request);
        $household = $user->currentHousehold();
        $target = $this->member($household, $member);

        $this->membership->remove($household, $user, $target);
        Inertia::flash('toast', ['type' => 'success', 'message' => "{$target->name} ya no forma parte del hogar."]);

        return back();
    }

    public function leave(Request $request): RedirectResponse
    {
        $user = $this->user($request);
        $household = $user->currentHousehold();

        $this->membership->leave($household, $user);
        Inertia::flash('toast', ['type' => 'success', 'message' => "Has salido del hogar «{$household->name}». Crea un hogar o acepta una invitación para continuar."]);

        return to_route('household.setup');
    }

    private function send(HouseholdInvitation $invitation, string $url): bool
    {
        try {
            Notification::route('mail', $invitation->email)->notify(new HouseholdInvitationNotification($invitation, $url));

            return true;
        } catch (Throwable $exception) {
            report($exception);

            return false;
        }
    }

    /**
     * A member of this household only (404 otherwise, also for other households).
     */
    private function member(Household $household, int $id): User
    {
        return $household->members()->whereKey($id)->firstOrFail();
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
