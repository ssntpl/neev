<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

class CreatePermission extends Command implements PromptsForMissingInput
{
    protected $signature = 'neev:create-permission';

    protected $description = 'Create a permission';

    public function handle()
    {
        $this->call('permissions:create-permission');
    }
}
