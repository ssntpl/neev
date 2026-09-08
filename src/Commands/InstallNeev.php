<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDOException;

use function Laravel\Prompts\select;

class InstallNeev extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'neev:install    {tenant : Enable multi-tenant isolation (yes/no)}
                                            {teams : Enable team support (yes/no)}
                                            {kit : Frontend starter kit (blade/none)}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Install the Neev components and resources.';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // Every argument is checked before anything is published or edited:
        // a typo used to be read as "no" for the flags, or to fail inside
        // neev:ui once the config had already been written.
        if (! $this->validateArguments()) {
            return self::FAILURE;
        }

        $this->info('Installing Neev...');

        // Nothing below this point touches the database — the check is an early
        // warning, so an unreachable database must not stop the install. It is
        // routine for this command to run before the database is set up: it is
        // documented to run before `migrate`, and the migration that drops the
        // users table repeats this same check at the point it actually matters.
        try {
            if (Schema::hasTable('users') && DB::table('users')->exists()) {
                $this->error('Installation failed: Users table is not empty. Please run this command on a fresh installation.');
                return 1;
            }
        } catch (PDOException $e) {
            $this->warn('Could not reach the database, so the users table was not checked.');
            $this->warn('Continuing — `php artisan migrate` refuses to run if the users table has records.');
        }

        $this->callSilent('vendor:publish', ['--tag' => 'neev-config', '--force' => true]);

        $file = config_path('neev.php');

        if ($this->argument('tenant') === 'yes') {
            $this->replaceInFile("'tenant' => false,", "'tenant' => true,", $file);
        }

        if ($this->argument('teams') === 'yes') {
            $this->replaceInFile("'team' => false,", "'team' => true,", $file);
        }

        // Eject the chosen starter kit (and, always, the email templates).
        $this->call('neev:ui', ['kit' => $this->argument('kit')]);

        $this->info('Neev installed successfully!');
    }

    /**
     * Check every argument up front, reporting all bad values at once.
     */
    protected function validateArguments(): bool
    {
        $allowed = [
            'tenant' => ['yes', 'no'],
            'teams' => ['yes', 'no'],
            'kit' => ['blade', 'none'],
        ];

        $errors = [];

        foreach ($allowed as $argument => $values) {
            $given = $this->argument($argument);

            if (! in_array($given, $values, true)) {
                $errors[] = "  {$argument}: [{$given}] is not valid. Expected " . implode(' or ', $values) . '.';
            }
        }

        if ($errors === []) {
            return true;
        }

        $this->error('Installation aborted — nothing was changed:');
        foreach ($errors as $error) {
            $this->line($error);
        }

        return false;
    }

    /**
     * Replace a given string within a given file.
     *
     * @param  string  $search
     * @param  string  $replace
     * @param  string  $path
     * @return void
     */
    protected function replaceInFile($search, $replace, $path)
    {
        file_put_contents($path, str_replace($search, $replace, file_get_contents($path)));
    }

    /**
     * Prompt for missing input arguments using the returned questions.
     *
     * @return array
     */
    protected function promptForMissingArgumentsUsing()
    {
        return [
            'tenant' => fn () => select(
                label: 'Would you like to enable multi-tenant isolation?',
                options: ['yes' => 'Yes', 'no' => 'No'],
                default: 'no'
            ),

            'teams' => fn () => select(
                label: 'Would you like to install team support?',
                options: [
                    'yes' => 'Yes',
                    'no' => 'No'
                ],
                default: 'yes'
            ),

            'kit' => fn () => select(
                label: 'Which frontend starter kit would you like?',
                options: [
                    'blade' => 'Blade — ready-made pages ejected into your app',
                    'none' => 'None — headless, I will build the frontend myself',
                ],
                default: 'blade'
            ),
        ];
    }
}
