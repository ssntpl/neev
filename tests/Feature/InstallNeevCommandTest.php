<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use PDOException;
use Ssntpl\Neev\Models\User;
use Ssntpl\Neev\Tests\TestCase;

/**
 * `neev:install` is documented to run before `php artisan migrate`, so it is
 * routine for it to run against a database that is not set up — or not
 * reachable — yet. The users-table check is an early warning, not a gate:
 * nothing after it touches the database, and the migration that drops the
 * users table repeats the same check where it actually matters.
 */
class InstallNeevCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/vendor/neev'));
        File::delete(config_path('neev.php'));

        parent::tearDown();
    }

    public function test_install_succeeds_on_a_fresh_database(): void
    {
        $this->artisan('neev:install', ['tenant' => 'no', 'teams' => 'no', 'kit' => 'none'])
            ->expectsOutputToContain('Neev installed successfully!')
            ->assertSuccessful();
    }

    public function test_install_stops_when_the_users_table_already_has_records(): void
    {
        User::factory()->create();

        $this->artisan('neev:install', ['tenant' => 'no', 'teams' => 'no', 'kit' => 'none'])
            ->expectsOutputToContain('Installation failed: Users table is not empty.')
            ->assertExitCode(1);
    }

    public function test_install_warns_and_continues_when_the_database_cannot_be_reached(): void
    {
        Schema::shouldReceive('hasTable')
            ->with('users')
            ->andThrow(new PDOException('SQLSTATE[HY000] [2002] Connection refused'));

        $this->artisan('neev:install', ['tenant' => 'no', 'teams' => 'no', 'kit' => 'none'])
            ->expectsOutputToContain('Could not reach the database, so the users table was not checked.')
            ->expectsOutputToContain('Neev installed successfully!')
            ->assertSuccessful();
    }

    public function test_the_answers_are_written_into_the_published_config(): void
    {
        $this->artisan('neev:install', ['tenant' => 'yes', 'teams' => 'yes', 'kit' => 'none'])
            ->assertSuccessful();

        $published = File::get(config_path('neev.php'));

        $this->assertStringContainsString("'tenant' => true,", $published);
        $this->assertStringContainsString("'team' => true,", $published);
    }
}
