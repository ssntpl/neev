<?php

namespace Ssntpl\Neev\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Hash;

/**
 * An invitation for an address that has no account yet.
 *
 * The emailed link carries a high-entropy secret; only its hash is stored, so
 * holding the link is what proves the invitation reached that inbox. Before
 * that secret existed the link carried the row id and `sha1(email)` — neither
 * unguessable — while redemption marked the address verified and granted the
 * invited role, so an attacker could claim an invited address outright.
 *
 * @property int $id
 * @property int $team_id
 * @property string|null $role
 * @property string $email
 * @property string|null $token
 * @property Carbon|null $expires_at
 * @property-read Team|null $team
 */
class TeamInvitation extends Model
{
    protected $fillable = [
        'team_id',
        'role',
        'email',
        'token',
        'expires_at',
    ];

    protected $hidden = [
        'token',
    ];

    protected $casts = [
        'expires_at' => 'datetime',
        'token' => 'hashed',
    ];

    /** The secret for a new invitation. Returned once, for the emailed link. */
    public static function generateToken(): string
    {
        return bin2hex(random_bytes(32));
    }

    /**
     * Whether this plaintext secret is the one this invitation was issued
     * with. A row from before the column existed carries none and can never
     * match — such an invitation has to be sent again.
     */
    public function tokenMatches(?string $plain): bool
    {
        if ($this->token === null || $plain === null || $plain === '') {
            return false;
        }

        return Hash::check($plain, $this->token);
    }

    /**
     * The mail says the link lasts seven days; nothing used to enforce it.
     * A row with no deadline never expires, as before.
     */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && now()->gte($this->expires_at);
    }

    public function getProfilePhotoUrlAttribute()
    {
        return strtoupper(substr($this->email, 0, 1));
    }

    public function team()
    {
        return $this->belongsTo(Team::getClass(), 'team_id');
    }

    /**
     * The invited team, if this user may join it; null otherwise.
     *
     * Under isolation a user belongs to one tenant and joins only that
     * tenant's teams. The row itself is not tenant-scoped, so an invitation
     * to another tenant's team can be named from anywhere: its team is then
     * hidden by the team tenant scope, or sits in a tenant the user is not
     * in. Either way there is nothing to join.
     */
    public function teamFor($user): ?Team
    {
        return $this->teamInTenant($user->tenant_id);
    }

    /**
     * The invited team, if a user of this tenant (null: a platform user) may
     * join it; null otherwise. For a user not created yet, such as a visitor
     * opening the registration form, pass the tenant they would be created in.
     */
    public function teamInTenant(?int $tenantId): ?Team
    {
        $team = $this->team;

        if ($team === null) {
            return null;
        }

        if (config('neev.tenant', false) && $team->tenant_id !== $tenantId) {
            return null;
        }

        return $team;
    }
}
