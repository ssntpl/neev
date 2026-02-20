<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

use function Laravel\Prompts\select;

class InstallNeev extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'neev:install    {teams : Indicates if team support should be installed}
                                            {verification : Indicates if email verification support should be installed}
                                            {domain_federation : Indicates if domain federation support should be installed}
                                            {tenant_isolation : Indicates if hard user-level tenant isolation should be installed}';

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
        $this->info('🔧 Installing with options:');

        // Check if users table exists and is not empty
        if (Schema::hasTable('users') && DB::table('users')->exists()) {
            $this->error('❌ Installation failed: Users table is not empty. Please run this command on a fresh installation.');
            return 1;
        }

        $this->callSilent('vendor:publish', ['--tag' => 'neev-config', '--force' => true]);

        $file = config_path('neev.php');

        if ($this->argument('teams') === 'yes') {
            $this->installTeam();
        }


        if ($this->argument('domain_federation') === 'yes') {
            $this->replaceInFile("'domain_federation' => false,", "'domain_federation' => true,", $file);
        }

        if ($this->argument('verification') === 'yes') {
            $this->replaceInFile("'email_verified' => false,", "'email_verified' => true,", $file);
        }

        if ($this->argument('tenant_isolation') === 'yes') {
            $this->installTenantIsolation();
        }

        // $this->call('migrate');

        $this->info('✅ Neev installed successfully!');
    }

    protected function installTeam()
    {
        $this->replaceInFile("'team' => false", "'team' => true", config_path('neev.php'));
    }

    protected function installTenantIsolation()
    {
        $file = config_path('neev.php');

        $this->replaceInFile("'identity_strategy' => 'shared',", "'identity_strategy' => 'isolated',", $file);
        $this->replaceInFile("'tenant_isolation' => false,", "'tenant_isolation' => true,", $file);
        $this->replaceInFile("'single_tenant_users' => false,", "'single_tenant_users' => true,", $file);

        $stubPath = __DIR__.'/../../stubs/add_tenant_id_to_users_table.php.stub';
        $timestamp = date('Y_m_d_His');
        $migrationPath = database_path("migrations/{$timestamp}_add_tenant_id_to_users_table.php");

        copy($stubPath, $migrationPath);

        $this->info('Published migration: '.$migrationPath);
        $this->info('Add the BelongsToTenant trait to your User model:');
        $this->info('  use Ssntpl\Neev\Traits\BelongsToTenant;');
    }

    /**
     * Replace a given string within a given file.
     *
     * @param  string  $replace
     * @param  string|array  $search
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
            'teams' => fn () => select(
                label: 'Would you like to install team support?',
                options: [
                    'yes' => 'Yes',
                    'no' => 'No'
                ],
                default: 'yes'
            ),

            'verification' => fn () => select(
                label: 'Would you like to enable email verification?',
                options: ['yes' => 'Yes', 'no' => 'No'],
                default: 'no'
            ),

            'domain_federation' => fn () => select(
                label: 'Would you like to enable domain federation?',
                options: ['yes' => 'Yes', 'no' => 'No'],
                default: 'no'
            ),

            'tenant_isolation' => fn () => select(
                label: 'Would you like to enable hard user-level tenant isolation (tenant_id on users table)?',
                options: ['yes' => 'Yes', 'no' => 'No'],
                default: 'no'
            ),
        ];
    }
}
