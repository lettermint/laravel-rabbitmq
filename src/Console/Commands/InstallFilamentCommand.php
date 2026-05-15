<?php

declare(strict_types=1);

namespace Lettermint\RabbitMQ\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

/**
 * Install optional Filament assets into the host application.
 */
class InstallFilamentCommand extends Command
{
    protected $signature = 'rabbitmq:install-filament
        {--force : Overwrite existing files and allow installing stubs when Filament is not installed}
        {--dry-run : Show which files would be written without writing them}
        {--path= : Custom destination path for the Filament page class}
        {--view-path= : Custom destination path for the Blade view}';

    protected $description = 'Install an optional Filament page for RabbitMQ DLQ failed jobs';

    public function handle(): int
    {
        if (! class_exists('Filament\\Pages\\Page') && ! $this->option('force')) {
            $this->components->error('Filament is not installed.');
            $this->line('Install Filament first, then run this command again:');
            $this->line('  composer require filament/filament');

            return self::FAILURE;
        }

        $pagePath = $this->option('path') ?: app_path('Filament/Pages/RabbitMQFailedJobs.php');
        $viewPath = $this->option('view-path') ?: resource_path('views/filament/pages/rabbitmq-failed-jobs.blade.php');
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($dryRun) {
            $this->components->info('Filament DLQ page dry run');
            $this->line("  Page: {$pagePath}");
            $this->line("  View: {$viewPath}");

            return self::SUCCESS;
        }

        foreach ([$pagePath, $viewPath] as $path) {
            if (File::exists($path) && ! $force) {
                $this->components->error("File already exists: {$path}");
                $this->line('Run with --force to overwrite it.');

                return self::FAILURE;
            }
        }

        $this->writeStub(
            source: __DIR__.'/../../../stubs/filament/RabbitMQFailedJobs.php.stub',
            destination: $pagePath,
            replacements: [
                '{{ namespace }}' => $this->guessPageNamespace($pagePath),
                '{{ view }}' => 'filament.pages.rabbitmq-failed-jobs',
            ],
        );

        $this->writeStub(
            source: __DIR__.'/../../../stubs/filament/rabbitmq-failed-jobs.blade.php.stub',
            destination: $viewPath,
            replacements: [],
        );

        $this->components->success('Filament DLQ page installed');
        $this->line("  Page: {$pagePath}");
        $this->line("  View: {$viewPath}");
        $this->newLine();
        $this->line('Register it in your Filament panel with:');
        $this->line('  ->pages([\\App\\Filament\\Pages\\RabbitMQFailedJobs::class])');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, string>  $replacements
     */
    private function writeStub(string $source, string $destination, array $replacements): void
    {
        File::ensureDirectoryExists(dirname($destination));

        $contents = File::get($source);
        $contents = str_replace(array_keys($replacements), array_values($replacements), $contents);

        File::put($destination, $contents);
    }

    private function guessPageNamespace(string $pagePath): string
    {
        $appPath = app_path();
        $normalizedPagePath = str_replace('\\', '/', $pagePath);
        $normalizedAppPath = rtrim(str_replace('\\', '/', $appPath), '/').'/';

        if (! str_starts_with($normalizedPagePath, $normalizedAppPath)) {
            return 'App\\Filament\\Pages';
        }

        $relativeDirectory = trim(dirname(substr($normalizedPagePath, strlen($normalizedAppPath))), '/');

        if ($relativeDirectory === '') {
            return 'App';
        }

        return 'App\\'.str_replace('/', '\\', $relativeDirectory);
    }
}
