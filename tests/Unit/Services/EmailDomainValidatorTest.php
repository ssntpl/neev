<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Ssntpl\Neev\Services\EmailDomainValidator;
use Ssntpl\Neev\Tests\TestCase;

class EmailDomainValidatorTest extends TestCase
{
    private EmailDomainValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new EmailDomainValidator();
    }

    // ---------------------------------------------------------------
    // getFreeEmailDomains()
    // ---------------------------------------------------------------

    public function test_get_free_email_domains_returns_default_domains(): void
    {
        $domains = $this->validator->getFreeEmailDomains();

        $this->assertIsArray($domains);
        $this->assertNotEmpty($domains);
        $this->assertContains('gmail.com', $domains);
    }

    public function test_get_free_email_domains_includes_custom_config_domains(): void
    {
        config(['neev.free_email_domains' => ['custom-free.org', 'another-free.net']]);

        $domains = $this->validator->getFreeEmailDomains();

        $this->assertContains('custom-free.org', $domains);
        $this->assertContains('another-free.net', $domains);
        // Default domains should still be present
        $this->assertContains('gmail.com', $domains);
    }

    public function test_get_free_email_domains_deduplicates_when_config_overlaps(): void
    {
        config(['neev.free_email_domains' => ['gmail.com', 'custom-free.org']]);

        $domains = $this->validator->getFreeEmailDomains();

        // gmail.com should only appear once
        $gmailCount = array_count_values($domains)['gmail.com'];
        $this->assertSame(1, $gmailCount);
    }

    // ---------------------------------------------------------------
    // extractDomain()
    // ---------------------------------------------------------------

    public function test_extract_domain_returns_domain_part(): void
    {
        $this->assertSame('example.com', $this->validator->extractDomain('user@example.com'));
    }

    public function test_extract_domain_returns_lowercase(): void
    {
        $this->assertSame('example.com', $this->validator->extractDomain('user@EXAMPLE.COM'));
    }

    public function test_extract_domain_handles_mixed_case(): void
    {
        $this->assertSame('mycompany.io', $this->validator->extractDomain('John.Doe@MyCompany.IO'));
    }

    public function test_extract_domain_handles_subdomain_emails(): void
    {
        $this->assertSame('mail.example.com', $this->validator->extractDomain('user@mail.example.com'));
    }

    // ---------------------------------------------------------------
    // isFreeEmail()
    // ---------------------------------------------------------------

    public function test_is_free_email_returns_true_for_gmail(): void
    {
        $this->assertTrue($this->validator->isFreeEmail('user@gmail.com'));
    }

    public function test_is_free_email_returns_true_for_yahoo(): void
    {
        $this->assertTrue($this->validator->isFreeEmail('user@yahoo.com'));
    }

    public function test_is_free_email_returns_true_for_hotmail(): void
    {
        $this->assertTrue($this->validator->isFreeEmail('user@hotmail.com'));
    }

    public function test_is_free_email_returns_true_for_outlook(): void
    {
        $this->assertTrue($this->validator->isFreeEmail('user@outlook.com'));
    }

    public function test_is_free_email_returns_true_for_icloud(): void
    {
        $this->assertTrue($this->validator->isFreeEmail('user@icloud.com'));
    }

    public function test_is_free_email_returns_true_for_protonmail(): void
    {
        $this->assertTrue($this->validator->isFreeEmail('user@protonmail.com'));
    }

    public function test_is_free_email_returns_false_for_company_domain(): void
    {
        $this->assertFalse($this->validator->isFreeEmail('user@company.com'));
    }

    public function test_is_free_email_returns_false_for_custom_company_domain(): void
    {
        $this->assertFalse($this->validator->isFreeEmail('john@acme-corp.io'));
    }

    // ---------------------------------------------------------------
    // isCompanyEmail()
    // ---------------------------------------------------------------

    public function test_is_company_email_returns_true_for_company_domain(): void
    {
        $this->assertTrue($this->validator->isCompanyEmail('user@company.com'));
    }

    public function test_is_company_email_returns_false_for_gmail(): void
    {
        $this->assertFalse($this->validator->isCompanyEmail('user@gmail.com'));
    }

    public function test_is_company_email_returns_false_for_yahoo(): void
    {
        $this->assertFalse($this->validator->isCompanyEmail('user@yahoo.com'));
    }

    public function test_is_company_email_returns_true_for_unknown_domain(): void
    {
        $this->assertTrue($this->validator->isCompanyEmail('admin@startup.co'));
    }

    // ---------------------------------------------------------------
    // validateCompanyEmail()
    // ---------------------------------------------------------------

    public function test_validate_company_email_returns_true_for_company_email(): void
    {
        $this->assertTrue($this->validator->validateCompanyEmail('user@company.com'));
    }

    public function test_validate_company_email_returns_false_for_free_email(): void
    {
        $this->assertFalse($this->validator->validateCompanyEmail('user@gmail.com'));
    }

    public function test_validate_company_email_matches_is_company_email(): void
    {
        $testEmails = [
            'user@gmail.com',
            'user@company.com',
            'admin@outlook.com',
            'ceo@startup.io',
            'dev@protonmail.com',
        ];

        foreach ($testEmails as $email) {
            $this->assertSame(
                $this->validator->isCompanyEmail($email),
                $this->validator->validateCompanyEmail($email),
                "validateCompanyEmail and isCompanyEmail should return the same result for {$email}"
            );
        }
    }

    // ---------------------------------------------------------------
    // Default list coverage
    // ---------------------------------------------------------------

    /**
     * @dataProvider commonFreeEmailProvidersProvider
     */
    public function test_common_providers_are_in_default_list(string $domain): void
    {
        $domains = $this->validator->getFreeEmailDomains();

        $this->assertContains($domain, $domains, "Expected {$domain} to be in the default free email domains list");
    }

    public static function commonFreeEmailProvidersProvider(): array
    {
        return [
            'Gmail'       => ['gmail.com'],
            'Yahoo'       => ['yahoo.com'],
            'Hotmail'     => ['hotmail.com'],
            'Outlook'     => ['outlook.com'],
            'iCloud'      => ['icloud.com'],
            'ProtonMail'  => ['protonmail.com'],
            'AOL'         => ['aol.com'],
            'Yandex'      => ['yandex.com'],
            'Mail.ru'     => ['mail.ru'],
            'GMX'         => ['gmx.com'],
            'Zoho'        => ['zoho.com'],
            'Fastmail'    => ['fastmail.com'],
            'Tutanota'    => ['tutanota.com'],
            'Live'        => ['live.com'],
            'MSN'         => ['msn.com'],
            'Mailinator'  => ['mailinator.com'],
        ];
    }
}
