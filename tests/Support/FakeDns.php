<?php

/*
 * Fake DNS TXT answers for verify() without touching production code.
 *
 * Domain::verify() calls dns_get_record() unqualified from inside the
 * Ssntpl\Neev\Models namespace, and Hostname and EmailDomain from the
 * VerifiesWithDns trait in Ssntpl\Neev\Traits, so PHP looks for the function
 * in that namespace before the global one. Defining it in both lets a test
 * answer for a record name and hands every other lookup to the real function.
 *
 * PHP remembers which function a call site resolved to, so this file has to be
 * loaded before anything calls verify(). Require it at the top of the test
 * file (not from inside a test): PHPUnit loads every test file before it runs
 * the first test.
 */

namespace Ssntpl\Neev\Tests\Support {
    final class FakeDns
    {
        /** @var array<string, array<int, array<string, mixed>>> */
        private static array $txt = [];

        /**
         * Answer TXT lookups for $name with these values. No values means the
         * name has no TXT records.
         */
        public static function txt(string $name, string ...$values): void
        {
            self::$txt[$name] = array_map(
                fn (string $value) => ['host' => $name, 'class' => 'IN', 'type' => 'TXT', 'txt' => $value],
                $values,
            );
        }

        /** @var callable|null */
        private static $onLookup = null;

        /**
         * Run $callback during the next lookups, standing in for whatever
         * happens elsewhere while a real DNS query is in flight.
         */
        public static function duringLookup(callable $callback): void
        {
            self::$onLookup = $callback;
        }

        /**
         * @return array<int, array<string, mixed>>|null
         */
        public static function lookup(string $name): ?array
        {
            if (self::$onLookup !== null) {
                $callback = self::$onLookup;
                self::$onLookup = null;
                $callback($name);
            }

            return self::$txt[$name] ?? null;
        }

        public static function reset(): void
        {
            self::$txt = [];
            self::$onLookup = null;
        }
    }
}

namespace Ssntpl\Neev\Models {
    use Ssntpl\Neev\Tests\Support\FakeDns;

    if (! function_exists(__NAMESPACE__ . '\dns_get_record')) {
        function dns_get_record(
            string $hostname,
            int $type = DNS_ANY,
            &$authoritative_name_servers = null,
            &$additional_records = null,
            bool $raw = false,
        ): array|false {
            if ($type === DNS_TXT && ($records = FakeDns::lookup($hostname)) !== null) {
                return $records;
            }

            return \dns_get_record($hostname, $type, $authoritative_name_servers, $additional_records, $raw);
        }
    }
}

namespace Ssntpl\Neev\Traits {
    use Ssntpl\Neev\Tests\Support\FakeDns;

    if (! function_exists(__NAMESPACE__ . '\dns_get_record')) {
        function dns_get_record(
            string $hostname,
            int $type = DNS_ANY,
            &$authoritative_name_servers = null,
            &$additional_records = null,
            bool $raw = false,
        ): array|false {
            if ($type === DNS_TXT && ($records = FakeDns::lookup($hostname)) !== null) {
                return $records;
            }

            return \dns_get_record($hostname, $type, $authoritative_name_servers, $additional_records, $raw);
        }
    }
}
