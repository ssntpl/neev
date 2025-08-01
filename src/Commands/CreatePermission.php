<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Ssntpl\Neev\Models\Permission;

class CreatePermission extends Command
{
    protected $signature = 'neev:create-permission 
                {name : The name of the permission}';

    protected $description = 'Create a permission';

    public function handle()
    {

        $name = $this->argument('name');

        Permission::updateOrCreate([
            'name' => $name
        ], [
            'name' => $name
        ]);

        $this->info("Permission `{$name}` created");
    }
}
