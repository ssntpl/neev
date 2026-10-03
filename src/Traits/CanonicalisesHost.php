<?php

namespace Ssntpl\Neev\Traits;

/**
 * One spelling of a host for every table that stores one, so that a
 * verification decision, a uniqueness reservation and a resolution lookup all
 * compare the same value.
 */
trait CanonicalisesHost
{
    /**
     * The column that holds the host.
     */
    abstract protected function hostColumn(): string;

    /**
     * `acme.otper.com.` is the fully qualified form of `acme.otper.com` and
     * `ACME.otper.com` is the same name again; stored as written they are three
     * distinct strings, so a second owner could claim an alias of a host another
     * owner already holds and the reservation would not notice.
     */
    public static function canonicalHost(string $host): string
    {
        return strtolower(trim($host, " \t\n\r\0\x0B."));
    }

    /**
     * Rows on this host, however it is spelled. Rows hold the canonical form,
     * so every lookup by host goes through here rather than comparing the raw
     * string a caller was given.
     */
    public function scopeForHost($query, string $host)
    {
        return $query->where($this->hostColumn(), static::canonicalHost($host));
    }

    /**
     * Canonicalise on the way in, whichever code path writes the row.
     */
    protected function canonicaliseHostAttribute(?string $value): void
    {
        $this->attributes[$this->hostColumn()] = $value === null ? null : static::canonicalHost($value);
    }
}
