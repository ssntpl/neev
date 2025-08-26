<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use function Laravel\Prompts\select;

class InstallNeev extends Command implements PromptsForMissingInput
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'neev:install    {teams : Indicates if team support should be installed}
                                            {roles : Indicates if roles support should be installed}
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
        $this->callSilent('vendor:publish', ['--tag' => 'neev-config', '--force' => true]);
        $this->callSilent('vendor:publish', ['--tag' => 'neev-migrations', '--force' => true]);
        
        if ($this->argument('teams') === 'yes') {
            $this->installTeam();
            if ($this->argument('roles') === 'yes') {
                $this->installRole();
            }
        }
        
        $file = config_path('neev.php');
        
        if ($this->argument('verification') === 'yes') {
            $this->replaceInFile("'email_verified' => false,", "'email_verified' => true,", $file);
        }
        
        if ($this->argument('domain_federation') === 'yes') {
            $this->replaceInFile("'domain_federation' => false,", "'domain_federation' => true,", $file);
            $this->callSilent('vendor:publish', ['--tag' => 'neev-domain-federation-migrations', '--force' => true]);
        }
        
        // $this->call('migrate');
        
        $this->info('✅ Neev installed successfully!');
    }

    protected function installTeam() {
        $this->replaceInFile("'team' => false", "'team' => true", config_path('neev.php'));
        
        $this->callSilent('vendor:publish', ['--tag' => 'neev-team-migrations', '--force' => true]);
    }
  
    protected function installRole() {
        $this->replaceInFile("'roles' => false", "'roles' => true", config_path('neev.php'));

        $this->callSilent('vendor:publish', ['--tag' => 'neev-role-migrations', '--force' => true]);
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
            
            'roles' => fn () => select(
                label: 'Would you like to install role support?',
                options: [
                    'yes' => 'Yes',
                    'no' => 'No'
                ],
                default: 'no'
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
