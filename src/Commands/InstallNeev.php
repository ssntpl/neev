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
    protected $signature = 'neev:install    {tenant : Enable multi-tenant isolation (yes/no)}
                                            {teams : Enable team support (yes/no)}';

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
        $this->info('Installing Neev...');

        // Check if users table exists and is not empty
        if (Schema::hasTable('users') && DB::table('users')->exists()) {
            $this->error('Installation failed: Users table is not empty. Please run this command on a fresh installation.');
            return 1;
        }

        $this->callSilent('vendor:publish', ['--tag' => 'neev-config', '--force' => true]);

        $file = config_path('neev.php');

        if ($this->argument('tenant') === 'yes') {
            $this->replaceInFile("'tenant' => false,", "'tenant' => true,", $file);
        }

        if ($this->argument('teams') === 'yes') {
            $this->replaceInFile("'team' => false,", "'team' => true,", $file);
        }

        $this->info('Neev installed successfully!');
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
        ];
    }
}
