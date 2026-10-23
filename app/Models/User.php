<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property string|null $remember_token
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'password'])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail, PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    /**
     * The households the user belongs to (exactly one in the MVP).
     *
     * @return BelongsToMany<Household, $this>
     */
    public function households(): BelongsToMany
    {
        return $this->belongsToMany(Household::class)->withPivot('role')->withTimestamps();
    }

    /**
     * Whether the user already belongs to a household. Without one, only the household setup and
     * the invitations are available (RN-01).
     */
    public function hasHousehold(): bool
    {
        return $this->households()->exists();
    }

    /**
     * The user's role in their household, or null without one (RN-38).
     */
    public function householdRole(): ?HouseholdRole
    {
        $role = $this->households()->first()?->getRelationValue('pivot')?->getAttribute('role');

        return is_string($role) ? HouseholdRole::tryFrom($role) : null;
    }

    /**
     * The household whose data the user works with (one per user in V1, RN-01). Always derived
     * from the authenticated user's membership, never from data sent by the client (RN-02).
     */
    public function currentHousehold(): Household
    {
        return $this->households()->firstOrFail();
    }

    /**
     * Whether the user is a member of the given household (RN-02).
     */
    public function belongsToHousehold(int $householdId): bool
    {
        return $this->households()->whereKey($householdId)->exists();
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }
}
