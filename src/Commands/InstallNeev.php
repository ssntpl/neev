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
                                            {domain_federation : Indicates if domain federation support should be installed}';

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
        
        // $this->call('migrate');
        
        $this->info('✅ Neev installed successfully!');
    }

    protected function installTeam() {
        $this->replaceInFile("'team' => false", "'team' => true", config_path('neev.php'));
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
        ];
    }
}
