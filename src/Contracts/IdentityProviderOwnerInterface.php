<?php

namespace Ssntpl\Neev\Contracts;

interface IdentityProviderOwnerInterface
{
    public function getAuthMethod(): string;

    public function requiresSSO(): bool;

    public function hasSSOConfigured(): bool;

    public function getSSOProvider(): ?string;

    /**
     * @return array<string, mixed>|null
     */
    public function getSocialiteConfig(): ?array;

    public function allowsAutoProvision(): bool;

    public function getAutoProvisionRole(): ?string;
}
