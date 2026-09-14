<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Support\Facades\File;
use Ssntpl\Neev\Tests\TestCase;

class InstallUiCommandTest extends TestCase
{
    private bool $wroteConfig = false;

    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/vendor/neev'));

        if ($this->wroteConfig) {
            File::delete(config_path('neev.php'));
        }

        parent::tearDown();
    }

    private function publishConfig(string $uiLine): void
    {
        File::put(config_path('neev.php'), "<?php\n\nreturn [\n    {$uiLine}\n    'home' => '/home',\n];\n");
        $this->wroteConfig = true;
    }

    public function test_blade_kit_ejects_views_and_email_templates(): void
    {
        $this->artisan('neev:ui', ['kit' => 'blade'])->assertSuccessful();

        $this->assertFileExists(resource_path('views/vendor/neev/auth/login.blade.php'));
        $this->assertFileExists(resource_path('views/vendor/neev/account/security.blade.php'));
        $this->assertFileExists(resource_path('views/vendor/neev/components/button.blade.php'));
        $this->assertFileExists(resource_path('views/vendor/neev/emails/email-verify.blade.php'));
    }

    public function test_none_kit_ejects_only_email_templates(): void
    {
        $this->artisan('neev:ui', ['kit' => 'none'])->assertSuccessful();

        $this->assertFileExists(resource_path('views/vendor/neev/emails/email-verify.blade.php'));
        $this->assertFileDoesNotExist(resource_path('views/vendor/neev/auth/login.blade.php'));
    }

    public function test_existing_app_files_are_kept_without_force(): void
    {
        $target = resource_path('views/vendor/neev/emails/email-verify.blade.php');
        File::ensureDirectoryExists(dirname($target));
        File::put($target, 'app-owned customisation');

        $this->artisan('neev:ui', ['kit' => 'none'])->assertSuccessful();
        $this->assertSame('app-owned customisation', File::get($target));

        $this->artisan('neev:ui', ['kit' => 'none', '--force' => true])->assertSuccessful();
        $this->assertNotSame('app-owned customisation', File::get($target));
    }

    public function test_unknown_kit_fails(): void
    {
        $this->artisan('neev:ui', ['kit' => 'angular'])->assertFailed();
    }

    /**
     * `env('NEEV_UI', 'blade')` is the ordinary way to write the key, and it
     * has a comma inside it. Stopping at that comma wrote
     * `'ui' => 'blade', 'blade'),` — a parse error in the app's config.
     */
    public function test_the_ui_key_is_rewritten_around_an_env_default(): void
    {
        $this->publishConfig("'ui' => env('NEEV_UI', 'blade'),");

        $this->artisan('neev:ui', ['kit' => 'none'])->assertSuccessful();

        $written = File::get(config_path('neev.php'));
        $this->assertStringContainsString("'ui' => env('NEEV_UI'),", $written);
        $this->assertStringNotContainsString("'blade')", $written);

        $config = include config_path('neev.php');
        $this->assertSame('/home', $config['home']);
    }

    public function test_the_ui_key_is_rewritten_when_it_is_a_plain_value(): void
    {
        $this->publishConfig("'ui' => null, // set by the installer");

        $this->artisan('neev:ui', ['kit' => 'blade'])->assertSuccessful();

        $config = include config_path('neev.php');
        $this->assertSame('blade', $config['ui']);
        $this->assertSame('/home', $config['home']);
    }

    /** A value the rewrite cannot parse is reported, not mangled. */
    public function test_an_unparseable_ui_value_is_left_alone(): void
    {
        $this->publishConfig("'ui' => env(\n        'NEEV_UI',\n        'blade',\n    ),");
        $before = File::get(config_path('neev.php'));

        $this->artisan('neev:ui', ['kit' => 'none'])
            ->expectsOutputToContain("Could not update 'ui'")
            ->assertSuccessful();

        $this->assertSame($before, File::get(config_path('neev.php')));
    }

    /**
     * The shape that defeats a line-based match: the first line ends in a
     * comma, so a naive rewrite leaves `'blade'),` dangling. Unbalanced
     * brackets are the tell.
     */
    public function test_a_value_wrapped_after_its_first_argument_is_left_alone(): void
    {
        $this->publishConfig("'ui' => env('NEEV_UI',\n        'blade'),");
        $before = File::get(config_path('neev.php'));

        $this->artisan('neev:ui', ['kit' => 'none'])
            ->expectsOutputToContain("Could not update 'ui'")
            ->assertSuccessful();

        $this->assertSame($before, File::get(config_path('neev.php')));
    }

    /** Another key on the same line must not be swallowed with the value. */
    public function test_a_line_carrying_another_key_is_left_alone(): void
    {
        $this->publishConfig("'ui' => env('NEEV_UI'), 'dashboard' => '/dash',");
        $before = File::get(config_path('neev.php'));

        $this->artisan('neev:ui', ['kit' => 'blade'])
            ->expectsOutputToContain("Could not update 'ui'")
            ->assertSuccessful();

        $this->assertSame($before, File::get(config_path('neev.php')));
        $this->assertSame('/dash', (include config_path('neev.php'))['dashboard']);
    }

    /** A config file with Windows line endings is rewritten like any other. */
    public function test_the_ui_key_is_rewritten_in_a_crlf_file(): void
    {
        File::put(config_path('neev.php'), "<?php\r\n\r\nreturn [\r\n    'ui' => env('NEEV_UI', 'blade'),\r\n    'home' => '/home',\r\n];\r\n");
        $this->wroteConfig = true;

        $this->artisan('neev:ui', ['kit' => 'blade'])->assertSuccessful();

        $written = File::get(config_path('neev.php'));
        $this->assertStringContainsString("    'ui' => 'blade',\r\n", $written);
        $this->assertSame('blade', (include config_path('neev.php'))['ui']);
    }
}
