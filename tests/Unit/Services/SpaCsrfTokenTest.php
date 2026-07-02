<?php

namespace Ssntpl\Neev\Tests\Unit\Services;

use Ssntpl\Neev\Services\SpaCsrfToken;
use Ssntpl\Neev\Tests\TestCase;

class SpaCsrfTokenTest extends TestCase
{
    private SpaCsrfToken $csrf;

    protected function setUp(): void
    {
        parent::setUp();
        $this->csrf = new SpaCsrfToken();
    }

    public function test_issued_token_validates_against_itself(): void
    {
        $token = $this->csrf->issue();

        $this->assertTrue($this->csrf->validate($token, $token));
    }

    public function test_header_must_match_cookie(): void
    {
        $this->assertFalse($this->csrf->validate($this->csrf->issue(), $this->csrf->issue()));
    }

    public function test_missing_values_fail(): void
    {
        $token = $this->csrf->issue();

        $this->assertFalse($this->csrf->validate(null, $token));
        $this->assertFalse($this->csrf->validate($token, null));
        $this->assertFalse($this->csrf->validate(null, null));
        $this->assertFalse($this->csrf->validate('', ''));
    }

    public function test_unsigned_or_tampered_tokens_fail(): void
    {
        $forged = 'value.wrongsignature';
        $this->assertFalse($this->csrf->validate($forged, $forged));

        $noSignature = 'justavalue';
        $this->assertFalse($this->csrf->validate($noSignature, $noSignature));

        [$value] = explode('.', $this->csrf->issue(), 2);
        $resigned = $value . '.' . hash_hmac('sha256', $value, 'wrong-key');
        $this->assertFalse($this->csrf->validate($resigned, $resigned));
    }
}
