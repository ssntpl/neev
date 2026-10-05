<?php

namespace Ssntpl\Neev\Http\Controllers;

use Illuminate\Http\Request;
use InvalidArgumentException;
use Ssntpl\Neev\Exceptions\HostnameTakenException;
use Ssntpl\Neev\Models\Hostname;
use Ssntpl\Neev\Models\Team;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Rules\Hostname as HostnameRule;

/**
 * The Blade page for a team's custom hosts (RFC 006). Members see them; only
 * the owner changes them. Every host is proven by its `_neev-host` record.
 */
class TeamHostnameController extends Controller
{
    public function index(Request $request, Team $team)
    {
        /** @var User|null $user */
        $user = User::model()->find($request->user()?->id);
        if (!$user || !$team->hasMember($user)) {
            return back()->withErrors(['message' => 'You cannot perform this action on this team.']);
        }

        return view('neev::team.hostnames', [
            'user' => $user,
            'team' => $team,
            'hostnames' => $team->hostnames()->orderBy('id')->get(),
            'platformHost' => $team->platformHost(),
        ]);
    }

    public function store(Request $request, Team $team)
    {
        if (!$this->isOwner($request, $team)) {
            return back()->withErrors(['message' => 'You do not have the required permissions to add a host.']);
        }

        $request->validate([
            'host' => ['bail', 'required', 'string', 'max:255', new HostnameRule()],
        ]);

        if ($team->hostnames()->forHost($request->host)->exists()) {
            return back()->withErrors(['message' => 'This team has already added this host.']);
        }

        try {
            $hostname = $team->claimHost($request->host);
        } catch (HostnameTakenException|InvalidArgumentException $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return $this->withRecord(back(), $hostname);
    }

    /**
     * One form per action: `verify`, `token` or `primary`.
     */
    public function update(Request $request, Hostname $hostname)
    {
        $team = $hostname->owner;
        if (!$team instanceof Team || !$this->isOwner($request, $team)) {
            return back()->withErrors(['message' => 'You do not have the required permissions to update this host.']);
        }

        if ($request->verify) {
            return $hostname->verify()
                ? back()->with('status', 'Host verified successfully!')
                : back()->withErrors(['message' => 'DNS record not found. Please try again later.']);
        }

        if ($request->token) {
            if ($hostname->generateVerificationToken() === null) {
                return back()->withErrors(['message' => 'This host is disabled.']);
            }

            return $this->withRecord(back(), $hostname);
        }

        if ($request->primary) {
            if (!$hostname->isVerified()) {
                return back()->withErrors(['message' => 'Only a verified host can be primary.']);
            }

            $team->makePrimaryHostname($hostname);

            return back()->with('status', 'Primary host has been changed.');
        }

        return back();
    }

    public function destroy(Request $request, Hostname $hostname)
    {
        $team = $hostname->owner;
        if (!$team instanceof Team || !$this->isOwner($request, $team)) {
            return back()->withErrors(['message' => 'You do not have the required permissions to delete this host.']);
        }

        $hostname->release();

        return back()->with('status', 'Host has been deleted.');
    }

    protected function isOwner(Request $request, Team $team): bool
    {
        return $request->user() !== null && $team->user_id === $request->user()->getKey();
    }

    /**
     * Flash the TXT record the owner has to publish.
     */
    protected function withRecord($response, Hostname $hostname)
    {
        return $response->with('token', $hostname->verification_token)
            ->with('dns_record_name', $hostname->getDnsRecordName());
    }
}
