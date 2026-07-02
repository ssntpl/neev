<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Support\Facades\File;
use Ssntpl\Neev\Tests\TestCase;

class InstallUiCommandTest extends TestCase
{
    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('views/vendor/neev'));

        parent::tearDown();
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
}
