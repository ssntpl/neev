<?php

namespace Ssntpl\Neev\Contracts;

interface ContextContainerInterface
{
    public function getContextId(): int;

    public function getContextSlug(): string;

    public function getContextType(): string;
}
