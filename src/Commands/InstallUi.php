<?php

namespace Ssntpl\Neev\Commands;

use Illuminate\Console\Command;
use Illuminate\Filesystem\Filesystem;

class InstallUi extends Command
{
    protected $signature = 'neev:ui         {kit : Starter kit to eject (blade/none)}
                                            {--force : Overwrite files that already exist in the app}';

    protected $description = 'Eject a frontend starter kit (and the email templates) into the application';

    public function handle(Filesystem $files): int
    {
        $kit = $this->argument('kit');

        if (!in_array($kit, ['blade', 'none'], true)) {
            $this->error("Unknown starter kit [{$kit}]. Available: blade, none.");
            return self::FAILURE;
        }

        // Email templates are always ejected — they are the app's to edit
        // from day one. The package copies remain only as the headless
        // fallback. See docs/rfcs/002-starter-kits.md §5.5.
        $this->copyDirectory(
            $files,
            dirname(__DIR__, 2) . '/resources/views/emails',
            resource_path('views/vendor/neev/emails'),
        );

        if ($kit === 'blade') {
            $this->copyDirectory(
                $files,
                dirname(__DIR__, 2) . '/stubs/blade/views',
                resource_path('views/vendor/neev'),
            );

            $this->setUiConfig('blade');
            $this->info("Blade starter kit ejected to resources/views/vendor/neev — it's yours now.");
        } else {
            $this->setUiConfig(null);
            $this->info('Running headless: no page views ejected, Blade page routes disabled.');
        }

        return self::SUCCESS;
    }

    /**
     * Copy a directory file-by-file, skipping files the app already owns
     * unless --force is given.
     */
    protected function copyDirectory(Filesystem $files, string $from, string $to): void
    {
        $copied = 0;
        $skipped = 0;

        foreach ($files->allFiles($from) as $file) {
            $target = $to . '/' . $file->getRelativePathname();

            if ($files->exists($target) && !$this->option('force')) {
                $skipped++;
                continue;
            }

            $files->ensureDirectoryExists(dirname($target));
            $files->copy($file->getPathname(), $target);
            $copied++;
        }

        $label = str_replace(base_path() . '/', '', $to);
        $this->line("  {$label}: {$copied} file(s) copied" . ($skipped ? ", {$skipped} existing file(s) kept (use --force to overwrite)" : ''));
    }

    protected function setUiConfig(?string $value): void
    {
        $file = config_path('neev.php');
        if (!file_exists($file)) {
            $this->warn("config/neev.php is not published; set 'ui' => " . var_export($value, true) . ' after publishing.');
            return;
        }

        $contents = file_get_contents($file);
        $replacement = "'ui' => " . ($value === null ? "env('NEEV_UI')" : var_export($value, true)) . ',';
        $updated = preg_replace("/'ui' => [^,]+,/", $replacement, $contents, 1, $count);

        if ($count === 1) {
            file_put_contents($file, $updated);
        } else {
            $this->warn("Could not update 'ui' in config/neev.php — set it to " . var_export($value, true) . ' manually.');
        }
    }
}
