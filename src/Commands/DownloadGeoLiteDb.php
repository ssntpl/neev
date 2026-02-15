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
        $licenseKey = config('neev.maxmind_license_key');
        if (empty($licenseKey)) {
            $this->error('MAXMIND_LICENSE_KEY is not configured. Set it in your .env file.');
            return 1;
        }

        $edition = config('neev.edition', 'GeoLite2-City');
        $tempPath = storage_path('app/geoip.tar.gz');
        $extractDir = storage_path('app/geoip');

        if (!is_dir($extractDir)) {
            mkdir($extractDir, 0755, true);
        }

        $url = "https://download.maxmind.com/app/geoip_download?edition_id={$edition}&license_key={$licenseKey}&suffix=tar.gz";

        $this->info("Downloading {$edition} database...");
        $response = Http::withOptions(['sink' => $tempPath])->get($url);
        if ($response?->successful()) {
            $output = null;
            $result = 0;
            exec("tar -xzf " . escapeshellarg($tempPath) . " -C " . escapeshellarg($extractDir), $output, $result);
            if ($result === 0) {
                $files = glob($extractDir . "/{$edition}_*/{$edition}.mmdb");
                if (!empty($files)) {
                    rename($files[0], $extractDir . "/{$edition}.mmdb");
                    $this->info("Database extracted to: {$extractDir}/{$edition}.mmdb");

                    // Clean up extracted subdirectory
                    $extractedDirs = glob($extractDir . "/{$edition}_*");
                    foreach ($extractedDirs as $dir) {
                        if (is_dir($dir)) {
                            $this->removeDirectory($dir);
                        }
                    }
                } else {
                    $this->error("{$edition}.mmdb not found in archive.");
                }
            } else {
                $this->error("Failed to extract the tar.gz archive.");
            }
            @unlink($tempPath);
        } else {
            $this->error("Failed to download: " . $response?->status());
        }

        return 0;
    }

    private function removeDirectory(string $dir): void
    {
        $items = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \RecursiveDirectoryIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getRealPath()) : unlink($item->getRealPath());
        }

        rmdir($dir);
    }
}
