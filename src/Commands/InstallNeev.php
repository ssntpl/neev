<?php

namespace Ssntpl\Neev\Commands;

use Artisan;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\multiselect;
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
                                            {stack : Indicates if API support should be installed}
                                            {verification : Indicates if email verification support should be installed}';

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
        if ($stack = $this->argument('stack')) {

            $this->replaceInFile(
                "'stack' => '" . $this->getCurrentStackValue($file) . "',",
                "'stack' => '{$stack}',",
                $file
            );
        }
        
        if ($this->argument('verification') === 'yes') {
            $this->replaceInFile("'email_verified' => false,", "'email_verified' => true,", $file);
        }

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

    protected function getCurrentStackValue($file)
    {
        $content = file_get_contents($file);

        if (preg_match("/'stack'\s*=>\s*'([^']+)'/", $content, $matches)) {
            return $matches[1];
        }

        return 'ui'; // default fallback
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

            'stack' => fn () => select(
                label: 'Which stack would you like to install?',
                options: [
                    'api' => 'API only',
                    'ui' => 'UI only',
                    'both' => 'Both API and UI',
                ],
                default: 'ui'
            ),

            'verification' => fn () => select(
                label: 'Would you like to enable email verification?',
                options: ['yes' => 'Yes', 'no' => 'No'],
                default: 'no'
            ),
        ];
    }
}
