<?php
namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Http;

class DownloadGeoLiteDb extends Command
{
    protected $signature = 'neev:download-geoip';
    protected $description = 'Download and extract the latest GeoLite2 City database from MaxMind';

    public function handle()
    {
        $tempPath = storage_path('app/geoip.tar.gz');
        $extractDir = storage_path('app/geoip');
        
        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }
        
        $licenseKey = config('neev.maxmind_license_key');
        $edition = config('neev.edition');
        $url = "https://download.maxmind.com/app/geoip_download?edition_id={$edition}&license_key={$licenseKey}&suffix=tar.gz";
       
        $response = Http::withOptions(['sink' => $tempPath])->get($url);
        if ($response?->successful()) {
            $output = null;
            $result = 0;
            exec("tar -xzf {$tempPath} -C " . escapeshellarg($extractDir), $output, $result);
            if ($result === 0) {
                $files = glob($extractDir . '/GeoLite2-City_*/GeoLite2-City.mmdb');
                if (!empty($files)) {
                    rename($files[0], $extractDir . '/GeoLite2-City.mmdb');
                    $this->info("Database extracted to: {$extractDir}/GeoLite2-City.mmdb");
                } else {
                    $this->error("GeoLite2-City.mmdb not found.");
                }
            } else {
                $this->error("Failed to extract the tar.gz archive.");
            }
            unlink($tempPath);
        } else {
            $this->error("Failed to download: " . $response?->status());
        }
    }
}
