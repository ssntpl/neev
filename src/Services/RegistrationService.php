<?php

namespace Ssntpl\Neev\Services;

use Illuminate\Auth\Events\Registered;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ssntpl\Neev\Exceptions\InvalidInvitationException;
use Ssntpl\Neev\Models\Domain;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\TeamInvitation;
use Ssntpl\Neev\Models\User;

/**
 * Central registration logic shared by the web, API, and OAuth flows.
 * Previously duplicated (with drift) across four controllers.
 */
class RegistrationService
{
    /**
     * Validation rules for password-based registration.
     */
    public function rules(): array
    {
        $rules = [
            'name' => 'required|string|max:255',
            'email' => ['required', 'string', 'email', 'max:255', User::uniqueEmailRule()],
            'password' => config('neev.password'),
        ];

        if (config('neev.support_username')) {
            $rules['username'] = config('neev.username');
        }

        return $rules;
    }

    /**
     * Register a user with a password, honouring team invitations and
     * federated-domain team-creation rules. Owns the transaction and
     * fires Registered after commit.
     *
     * @param array{name: string, email: string, password: string, username?: string} $data
     * @throws InvalidInvitationException When the invitation id/hash pair is invalid.
     */
    public function register(array $data, $invitationId = null, ?string $hash = null): User
    {
        $userData = [
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => $data['password'],
            'password_changed_at' => now(),
        ];

        if (config('neev.support_username') && isset($data['username'])) {
            $userData['username'] = $data['username'];
        }

        $user = DB::transaction(function () use ($userData, $data, $invitationId, $hash) {
            $user = User::model()->forceCreate($userData);
            $user = User::model()->find($user->id);

            if (config('neev.team')) {
                if ($invitationId) {
                    $this->acceptInvitation($user, $invitationId, $hash);
                } elseif (!Domain::isVerifiedForEmail($data['email'])) {
                    $this->createDefaultTeam($user)->addMember($user);
                }
            }

            return $user;
        });

        event(new Registered($user));

        return $user;
    }

    /**
     * Register a user from an OAuth/social profile: no password, email
     * pre-verified by the provider.
     */
    public function registerViaOAuth(string $name, string $email): User
    {
        $userData = [
            'name' => $name,
            'email' => $email,
            'email_verified_at' => now(),
        ];

        if (config('neev.support_username')) {
            $userData['username'] = $this->uniqueUsernameFrom($email);
        }

        $user = DB::transaction(function () use ($userData, $email) {
            $user = User::model()->forceCreate($userData);
            $user = User::model()->find($user->id);

            if (config('neev.team') && !Domain::isVerifiedForEmail($email)) {
                $this->createDefaultTeam($user)->addMember($user);
            }

            return $user;
        });

        event(new Registered($user));

        return $user;
    }

    /**
     * @throws InvalidInvitationException
     */
    protected function acceptInvitation(User $user, $invitationId, ?string $hash): void
    {
        $invitation = TeamInvitation::find($invitationId);
        if (!$invitation || !hash_equals(sha1($invitation->email), (string) $hash)) {
            throw new InvalidInvitationException();
        }

        // The invitation was sent to this address, so it is proven owned.
        $user->markEmailAsVerified();

        $team = $invitation->team;
        $team->users()->attach($user, ['joined' => true]);
        if ($invitation->role) {
            $user->assignRole($invitation->role, $team);
        }
        $invitation->delete();
    }

    protected function createDefaultTeam(User $user): Team
    {
        return Team::model()->forceCreate([
            'name' => explode(' ', $user->name, 2)[0] . "'s Team",
            'user_id' => $user->id,
            'is_public' => false,
            'activated_at' => now(),
        ]);
    }

    protected function uniqueUsernameFrom(string $email): string
    {
        $base = explode('@', $email)[0];
        $username = $base;
        while (User::getModel()->where('username', $username)->first()) {
            $username = $base . '_' . Str::random(4);
        }

        return $username;
    }
}
