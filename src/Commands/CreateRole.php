<?php

namespace Ssntpl\Neev\Commands;

use Exception;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Ssntpl\Neev\Models\Permission;
use Ssntpl\Neev\Models\Role;
use function Laravel\Prompts\text;

class CreateRole extends Command implements PromptsForMissingInput
{
    protected $signature = 'neev:create-role    {name : The name of the permission}
                                                {resource_type : Type of the resource}
                                                {permissions : A list of permissions to assign to the role, separated by | }';

    protected $description = 'Create a role';

     public function handle()
    {
        $name = $this->argument('name');
        $resource_type = $this->argument('resource_type');

        try {
            $role = Role::firstOrCreate([
                'name' => $name,
                'resource_type' => $resource_type
            ],[
                'name' => $name,
                'resource_type' => $resource_type
            ]);

            $role->syncPermissions($this->makePermissions($this->argument('permissions')));
        }catch(Exception $e) {
            $this->error($e->getMessage());
            $this->newLine();
            $this->info("Role `{$name}` was not created.");
            return;
        }
        
        $this->info("Role `{$name}` created");
        $this->newLine();
    }

    /**
     * @param  array|null|string  $string
     */
    protected function makePermissions($string = null)
    {
        if (empty($string)) {
            return;
        }

        $permissions = explode('|', $string);

        $models = [];

        foreach ($permissions as $permission) {
            $models[] = Permission::firstOrCreate([
                'name' => trim($permission)
            ], [
                'name' => trim($permission)
            ]);
        }

        return collect($models);
    }

    protected function promptForMissingArgumentsUsing()
    {
        return [
            'name' => fn () => text(
                label: 'Enter role name',
                placeholder: 'create',
                default: '',
                required: true,
            ),
            'resource_type' => fn () => text(
                label: 'Enter resource type',
                placeholder: 'resource type',
                default: '',
            ),
            'permissions' => fn () => text(
                label: 'Enter permisison name',
                placeholder: 'assign',
                default: '',
            ),
        ];
    }
}