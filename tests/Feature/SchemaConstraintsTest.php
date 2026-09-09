<?php

namespace Ssntpl\Neev\Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Ssntpl\Neev\Tests\TestCase;

/**
 * A schema guarantee that no behavioural test would notice being broken:
 * these columns are only ever exercised through SQLite here, which does not
 * enforce VARCHAR length, so a truncating column looks perfectly healthy in
 * CI and fails only on MySQL or PostgreSQL in production.
 */
class SchemaConstraintsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A base64url-encoded RS256 COSE public key is 376 characters — the format
     * TPM-backed authenticators (Windows Hello) issue. A `string` column caps
     * at 255 and would truncate it, which SQLite would silently allow.
     */
    public function test_passkey_columns_are_not_length_capped(): void
    {
        foreach (['public_key', 'aaguid', 'transports'] as $column) {
            $this->assertSame(
                'text',
                Schema::getColumnType('passkeys', $column),
                "passkeys.{$column} must be text — a string column caps at 255 characters and truncates RS256 credentials."
            );
        }
    }
}
