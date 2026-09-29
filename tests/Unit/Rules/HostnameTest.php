<?php

namespace Ssntpl\Neev\Tests\Unit\Rules;

use Illuminate\Support\Facades\Validator;
use PHPUnit\Framework\Attributes\DataProvider;
use Ssntpl\Neev\Rules\Hostname;
use Ssntpl\Neev\Tests\TestCase;

class HostnameTest extends TestCase
{
    public static function hosts(): array
    {
        return [
            'domain' => ['acme.com', true],
            'subdomain' => ['app.eu.acme.com', true],
            'upper case and trailing dot' => ['ACME.com.', true],
            'hyphen' => ['my-company.co.uk', true],
            'punycode' => ['xn--mnchen-3ya.de', true],
            'only dots' => ['...', false],
            'single label' => ['localhost', false],
            'url' => ['https://acme.com/x', false],
            'path' => ['acme.com/x', false],
            'port' => ['acme.com:8080', false],
            'space' => ['ac me.com', false],
            'underscore' => ['my_host.acme.com', false],
            'label over 63 characters' => [str_repeat('a', 64) . '.com', false],
            'over 253 characters' => [implode('.', array_fill(0, 64, 'abc')) . '.com', false],
            'not a string' => [['acme.com'], false],
            'ip address' => ['192.168.1.1', false],
            'numeric top-level label' => ['acme.123', false],
            'digits before the top-level label' => ['123.acme.com', true],
        ];
    }

    #[DataProvider('hosts')]
    public function test_accepts_only_host_names(mixed $value, bool $passes): void
    {
        $validator = Validator::make(['domain' => $value], ['domain' => [new Hostname()]]);

        $this->assertSame($passes, $validator->passes());
    }
}
