<?php

namespace Ssntpl\Neev\Support;

use Illuminate\Database\Eloquent\Model;

/**
 * The rules an owner sets for its members (RFC 006 §3 (e)), by the name the
 * rules endpoints use and the auth-settings column that holds each. They were
 * rows of `domain_rules`, one set per domain; a policy is the owner's, so they
 * live on `team_auth_settings` / `tenant_auth_settings`.
 */
class OwnerRules
{
    /** @var array<string, string> rule name => auth-settings column */
    public const COLUMNS = [
        'mfa' => 'require_mfa',
    ];

    /**
     * The rules as the endpoints return them: a list of `name` and `value`.
     * An owner with no settings row has every rule off.
     *
     * @return array<int, array{name: string, value: bool}>
     */
    public static function of(?Model $settings): array
    {
        $rules = [];

        foreach (self::COLUMNS as $name => $column) {
            $rules[] = ['name' => $name, 'value' => (bool) $settings?->getAttribute($column)];
        }

        return $rules;
    }
}
