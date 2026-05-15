<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;

test('filament install command fails clearly when Filament is not installed', function () {
    $this->artisan('rabbitmq:install-filament')
        ->assertExitCode(1)
        ->expectsOutputToContain('Filament is not installed');
});

test('filament install command supports dry runs with custom paths', function () {
    $basePath = sys_get_temp_dir().'/laravel-rabbitmq-filament-'.uniqid();
    $pagePath = $basePath.'/RabbitMQFailedJobs.php';
    $viewPath = $basePath.'/rabbitmq-failed-jobs.blade.php';

    try {
        $this->artisan('rabbitmq:install-filament', [
            '--force' => true,
            '--dry-run' => true,
            '--path' => $pagePath,
            '--view-path' => $viewPath,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain($pagePath)
            ->expectsOutputToContain($viewPath);

        expect(File::exists($pagePath))->toBeFalse();
        expect(File::exists($viewPath))->toBeFalse();
    } finally {
        File::deleteDirectory($basePath);
    }
});

test('filament install command publishes page and view stubs', function () {
    $basePath = sys_get_temp_dir().'/laravel-rabbitmq-filament-'.uniqid();
    $pagePath = $basePath.'/RabbitMQFailedJobs.php';
    $viewPath = $basePath.'/rabbitmq-failed-jobs.blade.php';

    try {
        $this->artisan('rabbitmq:install-filament', [
            '--force' => true,
            '--path' => $pagePath,
            '--view-path' => $viewPath,
        ])
            ->assertExitCode(0)
            ->expectsOutputToContain('Filament DLQ page installed');

        expect(File::exists($pagePath))->toBeTrue();
        expect(File::exists($viewPath))->toBeTrue();
        expect(File::get($pagePath))->toContain('class RabbitMQFailedJobs extends Page');
        expect(File::get($pagePath))->toContain("protected static string \$view = 'filament.pages.rabbitmq-failed-jobs';");
        expect(File::get($pagePath))->toContain('queue.failed.driver');
        expect(File::get($viewPath))->toContain('rabbitmq:dlq-inspect');
    } finally {
        File::deleteDirectory($basePath);
    }
});
