<?php

namespace App\Actions\Households;

use App\Enums\HouseholdRole;
use App\Models\Household;
use App\Models\HouseholdInvitation;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Households and their members (RN-01, RN-38, RN-39): creating the household of a new user,
 * invitations, roles, removal and leaving. One household per user in V1 (unique `user_id` in
 * `household_user`). A household always keeps at least one admin; deleting a household and
 * transferring it are not part of V1.
 */
class HouseholdMembership
{
    /**
     * The user creates their household and becomes its admin. Exactly once: the user row is
     * locked, so a repeated or concurrent request finds the household already created.
     */
    public function create(User $user, string $name): Household
    {
        return DB::transaction(function () use ($user, $name) {
            User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();

            $existing = $user->households()->first();

            if ($existing !== null) {
                return $existing;
            }

            $household = Household::create(['name' => Str::limit(trim($name), 255, '')]);
            $household->members()->attach($user, ['role' => HouseholdRole::Admin->value]);

            return $household;
        });
    }

    /**
     * A new invitation for this email and role. Returns it with the plain token, which is only
     * available now (the link is built from it); the database keeps its hash.
     *
     * @return array{0: HouseholdInvitation, 1: string}
     */
    public function invite(Household $household, User $inviter, string $email, HouseholdRole $role): array
    {
        $email = self::normalizeEmail($email);

        return DB::transaction(function () use ($household, $inviter, $email, $role) {
            // Serializes invitations of the household: no duplicate pending invitation.
            Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();

            if ($household->members()->whereRaw('LOWER(users.email) = ?', [$email])->exists()) {
                throw new HouseholdActionRefused("{$email} ya es miembro de este hogar.");
            }

            if ($household->invitations()->pending()->where('email', $email)->exists()) {
                throw new HouseholdActionRefused("Ya hay una invitación pendiente para {$email}. Revócala si quieres enviar otra.");
            }

            $token = Str::random(64);

            $invitation = $household->invitations()->create([
                'email' => $email,
                'role' => $role,
                'token_hash' => HouseholdInvitation::hashToken($token),
                'invited_by' => $inviter->id,
                'expires_at' => now()->addDays(HouseholdInvitation::VALID_DAYS),
            ]);

            return [$invitation, $token];
        });
    }

    public function revoke(HouseholdInvitation $invitation): void
    {
        DB::transaction(function () use ($invitation) {
            $current = HouseholdInvitation::query()->whereKey($invitation->id)->lockForUpdate()->firstOrFail();

            if (! $current->isPending()) {
                throw new HouseholdActionRefused('Esta invitación ya no está pendiente.');
            }

            $current->forceFill(['revoked_at' => now()])->save();
        });
    }

    /**
     * The invitation of this token, if any (whatever its state).
     */
    public function findByToken(string $token): ?HouseholdInvitation
    {
        return HouseholdInvitation::query()->where('token_hash', HouseholdInvitation::hashToken($token))->first();
    }

    /**
     * The recipient joins the household with the invited role. Only once, only by the user whose
     * email it was sent to, only while pending, and only if the user has no household yet.
     */
    public function accept(User $user, HouseholdInvitation $invitation): Household
    {
        try {
            return DB::transaction(function () use ($user, $invitation) {
                $invitation = $this->lockedFor($user, $invitation->id);

                if ($user->hasHousehold()) {
                    throw new HouseholdActionRefused('Ya perteneces a un hogar y cada cuenta pertenece a uno solo. La invitación sigue disponible: sal antes de tu hogar desde Ajustes › Hogar y vuelve a abrirla.');
                }

                $invitation->household->members()->attach($user, ['role' => $invitation->role->value]);
                $invitation->forceFill(['accepted_at' => now(), 'accepted_by' => $user->id])->save();

                return $invitation->household;
            });
        } catch (UniqueConstraintViolationException) {
            // Another request made the user a member at the same time (one household per user).
            throw new HouseholdActionRefused('Ya perteneces a un hogar.');
        }
    }

    public function decline(User $user, HouseholdInvitation $invitation): void
    {
        DB::transaction(function () use ($user, $invitation) {
            $this->lockedFor($user, $invitation->id)->forceFill(['declined_at' => now()])->save();
        });
    }

    /**
     * Pending invitations addressed to this user's email.
     *
     * @return Collection<int, HouseholdInvitation>
     */
    public function pendingFor(User $user): Collection
    {
        return HouseholdInvitation::query()->pending()
            ->where('email', self::normalizeEmail($user->email))
            ->with(['household', 'inviter'])
            ->orderBy('id')
            ->get();
    }

    /**
     * Change the role of a member. The household never runs out of admins.
     */
    public function changeRole(Household $household, User $member, HouseholdRole $role): void
    {
        DB::transaction(function () use ($household, $member, $role) {
            $current = $this->lockedRole($household, $member);

            if ($current === HouseholdRole::Admin && $role !== HouseholdRole::Admin && $household->countMembers(HouseholdRole::Admin) === 1) {
                throw new HouseholdActionRefused('El hogar necesita al menos un administrador. Nombra antes a otro miembro administrador.');
            }

            $household->members()->updateExistingPivot($member->id, ['role' => $role->value]);
        });
    }

    /**
     * An admin removes another member. Leaving the household is done by the member (leave()).
     */
    public function remove(Household $household, User $actor, User $member): void
    {
        if ($actor->is($member)) {
            throw new HouseholdActionRefused('Para salir del hogar usa «Salir del hogar».');
        }

        DB::transaction(function () use ($household, $member) {
            $role = $this->lockedRole($household, $member);

            if ($role === HouseholdRole::Admin && $household->countMembers(HouseholdRole::Admin) === 1) {
                throw new HouseholdActionRefused('No se puede retirar al único administrador del hogar.');
            }

            $household->members()->detach($member->id);
        });
    }

    /**
     * The member leaves the household; they will need to create or join another one. The data
     * stays with the household. The last admin can only leave after naming another admin. The
     * only member can leave only an empty household, which is then deleted (nobody could ever
     * reach it again and it holds nothing); a household with data is never deleted in V1.
     */
    public function leave(Household $household, User $user): void
    {
        DB::transaction(function () use ($household, $user) {
            $this->lockedRole($household, $user);
            $blocker = $this->leaveBlocker($household, $user);

            if ($blocker !== null) {
                throw new HouseholdActionRefused($blocker);
            }

            $household->members()->detach($user->id);

            if ($household->countMembers() === 0) {
                $household->delete();
            }
        });
    }

    /**
     * Why this member cannot leave the household now, or null when they can (RN-38).
     */
    public function leaveBlocker(Household $household, User $user): ?string
    {
        if ($household->countMembers() === 1) {
            return $this->isEmpty($household)
                ? null
                : 'Eres el único miembro y el hogar tiene datos: no puedes salir de él. Eliminar un hogar con datos no está disponible en esta versión.';
        }

        if ($user->householdRole() === HouseholdRole::Admin && $household->countMembers(HouseholdRole::Admin) === 1) {
            return 'Eres el único administrador. Nombra antes a otro miembro administrador para poder salir.';
        }

        return null;
    }

    /**
     * A household with nothing in it: no foods, stock, recipes, planned dishes, list lines or
     * purchases (an empty list opened automatically does not count).
     */
    public function isEmpty(Household $household): bool
    {
        return ! $household->ingredients()->exists()
            && ! $household->recipes()->exists()
            && ! $household->inventoryItems()->exists()
            && ! DB::table('planned_dishes')->whereIn('menu_id', $household->menus()->select('id'))->exists()
            && ! DB::table('shopping_list_items')->whereIn('shopping_list_id', $household->shoppingLists()->select('id'))->exists()
            && ! DB::table('receptions')->where('household_id', $household->id)->exists();
    }

    /**
     * Why this user cannot delete their account, or null (RN-38, 2026-10-22). Deleting an account
     * only removes its membership: the household keeps its data. So the account cannot be
     * deleted when that would leave the household without an admin, or when it is the only
     * member of a household with data (nobody could reach that data again, and households with
     * data are never deleted in this version). The only member of an empty household can: the
     * household is removed with the account.
     */
    public function accountDeletionBlocker(User $user): ?string
    {
        $household = $user->households()->first();

        if ($household === null) {
            return null;
        }

        if ($this->isLastAdminWithOthers($user)) {
            return 'Eres el único administrador de tu hogar y hay más miembros. Nombra antes a otro administrador para poder eliminar tu cuenta.';
        }

        if ($household->countMembers() === 1 && ! $this->isEmpty($household)) {
            return "Eres el único miembro de «{$household->name}» y el hogar tiene datos: si eliminaras tu cuenta, nadie podría volver a acceder a ellos. Invita antes a otra persona y nómbrala administradora; en esta versión no se pueden eliminar hogares con datos.";
        }

        return null;
    }

    /**
     * Whether removing this user's account would leave the household without an admin.
     */
    public function isLastAdminWithOthers(User $user): bool
    {
        $household = $user->households()->first();

        return $household !== null
            && $user->householdRole() === HouseholdRole::Admin
            && $household->countMembers(HouseholdRole::Admin) === 1
            && $household->countMembers() > 1;
    }

    public static function normalizeEmail(string $email): string
    {
        return mb_strtolower(trim($email));
    }

    private function lockedFor(User $user, int $invitationId): HouseholdInvitation
    {
        $invitation = HouseholdInvitation::query()
            ->whereKey($invitationId)
            ->with('household')
            ->lockForUpdate()
            ->first();

        // Wrong recipient: the same answer as an unknown token, so nothing about it is revealed.
        if ($invitation === null || $invitation->email !== self::normalizeEmail($user->email)) {
            throw new HouseholdActionRefused('Esta invitación no existe o no es para tu cuenta.');
        }

        if (! $invitation->isPending()) {
            throw new HouseholdActionRefused(match ($invitation->status()) {
                HouseholdInvitation::ACCEPTED => 'Esta invitación ya se ha utilizado.',
                HouseholdInvitation::DECLINED => 'Esta invitación se rechazó.',
                HouseholdInvitation::REVOKED => 'El hogar ha revocado esta invitación.',
                default => 'Esta invitación ha caducado. Pide una nueva al administrador del hogar.',
            });
        }

        return $invitation;
    }

    /**
     * Lock the household and return the member's role there (404 when not a member).
     */
    private function lockedRole(Household $household, User $member): HouseholdRole
    {
        Household::query()->whereKey($household->id)->lockForUpdate()->firstOrFail();

        $role = $household->members()->whereKey($member->id)->first()?->getRelationValue('pivot')?->getAttribute('role');

        if (! is_string($role)) {
            abort(404);
        }

        return HouseholdRole::from($role);
    }
}
