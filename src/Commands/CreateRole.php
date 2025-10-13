<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;

class CreateRole extends Command implements PromptsForMissingInput
{
    protected $signature = 'neev:create-role';

    protected $description = 'Create a role';

     public function handle()
    {
        $this->call('permissions:create-role');
    }
}