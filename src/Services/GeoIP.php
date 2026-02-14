<?php

namespace Ssntpl\Neev\Services;

use Exception;
use GeoIp2\Database\Reader;
use Illuminate\Support\Facades\Log;

class GeoIP
{
    protected ?Reader $reader = null;

    public function __construct()
    {
        $dbPath = storage_path(config('neev.geo_ip_db', 'app/geoip/GeoLite2-City.mmdb'));
        try {
            $this->reader = new Reader($dbPath);
        } catch (Exception $e) {
            Log::error($e->getMessage());
        }
    }

    public function getLocation(string $ip): ?array
    {
        try {
            $record = $this->reader?->city($ip);
            return [
                'city' => $record->city->name,
                'state' => $record->subdivisions[0]->name ?? null,
                'country' => $record->country->name,
                'country_iso' => $record->country->isoCode,
                'latitude' => $record->location->latitude,
                'longitude' => $record->location->longitude,
                'timezone' => $record->location->timeZone,
            ];
        } catch (Exception $e) {
            Log::error($e->getMessage());
            return null;
        }
    }
}
