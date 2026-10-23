<?php

namespace App\Models;

use App\Enums\HouseholdRole;
use App\Models\Concerns\BelongsToHousehold;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Invitation to join a household with a role (RN-39). The link carries a random token; only
 * its SHA-256 hash is stored.
 *
 * @property int $id
 * @property int $household_id
 * @property string $email
 * @property HouseholdRole $role
 * @property string $token_hash
 * @property int|null $invited_by
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property int|null $accepted_by
 * @property Carbon|null $declined_at
 * @property Carbon|null $revoked_at
 * @property Carbon|null $created_at
 * @property-read Household $household
 * @property-read User|null $inviter
 */
#[Fillable(['household_id', 'email', 'role', 'token_hash', 'invited_by', 'expires_at'])]
class HouseholdInvitation extends Model
{
    use BelongsToHousehold;

    public const PENDING = 'pending';

    public const ACCEPTED = 'accepted';

    public const DECLINED = 'declined';

    public const REVOKED = 'revoked';

    public const EXPIRED = 'expired';

    /** Days an invitation can be accepted. */
    public const VALID_DAYS = 7;

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by');
    }

    public function status(): string
    {
        return match (true) {
            $this->accepted_at !== null => self::ACCEPTED,
            $this->declined_at !== null => self::DECLINED,
            $this->revoked_at !== null => self::REVOKED,
            $this->expires_at->isPast() => self::EXPIRED,
            default => self::PENDING,
        };
    }

    public function isPending(): bool
    {
        return $this->status() === self::PENDING;
    }

    public function statusLabel(): string
    {
        return match ($this->status()) {
            self::ACCEPTED => 'Aceptada',
            self::DECLINED => 'Rechazada',
            self::REVOKED => 'Revocada',
            self::EXPIRED => 'Caducada',
            default => 'Pendiente',
        };
    }

    /**
     * @param  Builder<self>  $query
     */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('accepted_at')->whereNull('declined_at')->whereNull('revoked_at')->where('expires_at', '>', now());
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'role' => HouseholdRole::class,
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'declined_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }
}
