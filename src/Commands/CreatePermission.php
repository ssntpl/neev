<?php

namespace Ssntpl\Neev\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Models\Permission;
use function Laravel\Prompts\text;

class CreatePermission extends Command implements PromptsForMissingInput
{
    protected $signature = 'neev:create-permission  {name : The name of the permission}';

    protected $description = 'Create a permission';

    public function handle()
    {

        $name = $this->argument('name');

        try {
            Permission::create([
                'name' => $name
            ]);
        }catch(Exception $e) {
            $this->error($e->getMessage());
            $this->newLine();
            $this->info("Permission was not created.");
            return;
        }
        
        $this->info("Permission `{$name}` created");
        $this->newLine();
    }

    protected function promptForMissingArgumentsUsing()
    {
        return [
            'name' => fn () => text(
                label: 'Enter permisison name',
                placeholder: 'create',
                default: '',
                required: true,
            ),
        ];
    }
}
