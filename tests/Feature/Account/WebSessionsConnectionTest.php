<?php

namespace Ssntpl\Neev\Tests\Feature\Account;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;
use Ssntpl\Neev\Tests\Traits\WithNeevConfig;

/**
 * Laravel's database session driver stores sessions on `session.connection`,
 * which need not be the default connection. The Blade sessions page and both
 * logout-other-sessions actions queried `sessions` on the default connection,
 * so with a separate session database they listed nothing and revoked nothing.
 */
class WebSessionsConnectionTest extends TestCase
{
    use RefreshDatabase;
    use WithNeevConfig;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'session.driver' => 'database',
            'session.connection' => 'sessions_db',
            'database.connections.sessions_db' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => ''],
        ]);

        if (!Schema::connection('sessions_db')->hasTable('sessions')) {
            Schema::connection('sessions_db')->create('sessions', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->foreignId('user_id')->nullable()->index();
                $table->string('ip_address', 45)->nullable();
                $table->text('user_agent')->nullable();
                $table->longText('payload');
                $table->integer('last_activity')->index();
            });
        }
    }

    private function sessions()
    {
        return DB::connection('sessions_db')->table('sessions');
    }

    private function seedSession(string $id, int $userId): void
    {
        $this->sessions()->insert([
            'id' => $id,
            'user_id' => $userId,
            'ip_address' => '10.0.0.9',
            'user_agent' => 'Mozilla/5.0 (Macintosh) Safari/605.1.15',
            'payload' => '',
            'last_activity' => time(),
        ]);
    }

    public function test_the_sessions_page_lists_sessions_from_the_session_connection(): void
    {
        $user = User::factory()->create();
        $this->seedSession('elsewhere', $user->id);

        $this->actingAs($user)
            ->get(route('account.sessions'))
            ->assertOk()
            ->assertViewHas('sessions', fn ($sessions) => $sessions->pluck('id')->contains('elsewhere'));
    }

    public function test_logging_out_other_sessions_deletes_from_the_session_connection(): void
    {
        $user = User::factory()->create(['password' => 'secret']);
        $other = User::factory()->create();
        $this->seedSession('stale', $user->id);
        $this->seedSession('theirs', $other->id);

        $this->actingAs($user)
            ->post(route('logout.sessions'), ['password' => 'secret'])
            ->assertRedirect()
            ->assertSessionHas('logoutStatus');

        $this->assertSame(0, $this->sessions()->where('id', 'stale')->count());
        $this->assertSame(1, $this->sessions()->where('id', 'theirs')->count(), 'Another account\'s session is untouched.');
    }

    public function test_logging_out_one_session_only_touches_the_callers_own_row(): void
    {
        $user = User::factory()->create();
        $other = User::factory()->create();
        $this->seedSession('stale', $user->id);
        $this->seedSession('theirs', $other->id);

        $this->actingAs($user)
            ->post(route('logout.sessions'), ['session_id' => 'theirs'])
            ->assertRedirect();
        $this->assertSame(1, $this->sessions()->where('id', 'theirs')->count());

        $this->actingAs($user)
            ->post(route('logout.sessions'), ['session_id' => 'stale'])
            ->assertRedirect();
        $this->assertSame(0, $this->sessions()->where('id', 'stale')->count());
    }

    /**
     * Off the database driver there is no way to reach another session, and
     * the action used to rotate the caller's own session id and report
     * "Logged out from other sessions." — the devices the user was trying to
     * sign out stayed signed in, and nothing said so.
     */
    public function test_logging_out_other_sessions_says_so_when_the_driver_cannot(): void
    {
        config(['session.driver' => 'file']);

        $user = User::factory()->create(['password' => 'secret']);
        $this->seedSession('stale', $user->id);

        $this->actingAs($user)
            ->post(route('logout.sessions'), ['password' => 'secret'])
            ->assertRedirect()
            ->assertSessionHasErrors('message')
            ->assertSessionMissing('logoutStatus');

        // Nothing was revoked, and nothing claimed otherwise.
        $this->assertSame(1, $this->sessions()->where('id', 'stale')->count());
    }
}
