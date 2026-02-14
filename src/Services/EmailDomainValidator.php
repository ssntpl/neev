<?php

namespace Ssntpl\Neev\Services;

class EmailDomainValidator
{
    // TODO: Look at these resources as they provide a centralized list of free email providers.
    // https://gist.github.com/ammarshah/f5c2624d767f91a7cbdc4e54db8dd0bf
    // https://github.com/disposable/disposable-email-domains
    // https://github.com/disposable/disposable
    // 
    // This can also be extracted as a package. with option to score email addresses like if its temporary, free, or check mx record if its company, etc.


    /**
     * Common free email providers.
     * This list can be extended via config.
     */
    protected array $freeEmailDomains = [
        // Google
        'gmail.com',
        'googlemail.com',

        // Yahoo
        'yahoo.com',
        'yahoo.co.in',
        'yahoo.co.uk',
        'yahoo.fr',
        'yahoo.de',
        'yahoo.es',
        'yahoo.it',
        'yahoo.ca',
        'yahoo.com.br',
        'yahoo.com.au',
        'ymail.com',
        'rocketmail.com',

        // Microsoft
        'hotmail.com',
        'hotmail.co.uk',
        'hotmail.fr',
        'hotmail.de',
        'hotmail.it',
        'hotmail.es',
        'outlook.com',
        'outlook.co.uk',
        'outlook.fr',
        'outlook.de',
        'live.com',
        'live.co.uk',
        'live.fr',
        'msn.com',

        // Apple
        'icloud.com',
        'me.com',
        'mac.com',

        // AOL
        'aol.com',
        'aim.com',

        // ProtonMail
        'protonmail.com',
        'protonmail.ch',
        'proton.me',
        'pm.me',

        // Other popular providers
        'mail.com',
        'email.com',
        'zoho.com',
        'zohomail.com',
        'yandex.com',
        'yandex.ru',
        'gmx.com',
        'gmx.net',
        'gmx.de',
        'web.de',
        'mail.ru',
        'inbox.com',
        'fastmail.com',
        'fastmail.fm',
        'tutanota.com',
        'tutanota.de',
        'rediffmail.com',
        'qq.com',
        '163.com',
        '126.com',
        'sina.com',

        // Temporary/disposable email services
        'mailinator.com',
        'guerrillamail.com',
        'tempmail.com',
        'throwaway.email',
        '10minutemail.com',
    ];

    /**
     * Get the list of free email domains.
     */
    public function getFreeEmailDomains(): array
    {
        $configDomains = config('neev.free_email_domains', []);

        return array_unique(array_merge($this->freeEmailDomains, $configDomains));
    }

    /**
     * Extract the domain from an email address.
     */
    public function extractDomain(string $email): string
    {
        return strtolower(substr(strrchr($email, '@'), 1));
    }

    /**
     * Check if the email is from a free email provider.
     */
    public function isFreeEmail(string $email): bool
    {
        $domain = $this->extractDomain($email);

        return in_array($domain, $this->getFreeEmailDomains(), true);
    }

    /**
     * Check if the email is from a company/business domain.
     */
    public function isCompanyEmail(string $email): bool
    {
        return ! $this->isFreeEmail($email);
    }

    /**
     * Validate that an email is from a company domain.
     * Returns true if valid, false if it's a free email.
     */
    public function validateCompanyEmail(string $email): bool
    {
        return $this->isCompanyEmail($email);
    }
}
