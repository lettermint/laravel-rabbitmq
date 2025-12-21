<?php

declare(strict_types=1);

use Illuminate\Support\Str;

if (! function_exists('createTestPayload')) {
    /**
     * Create a raw job payload JSON string for testing.
     *
     * @param  array<string, mixed>  $overrides
     */
    function createTestPayload(string $jobClass, array $overrides = []): string
    {
        $defaults = [
            'uuid' => Str::uuid()->toString(),
            'displayName' => class_basename($jobClass),
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => [
                'commandName' => $jobClass,
                'command' => 'O:8:"stdClass":0:{}',
            ],
            'maxTries' => null,
            'maxExceptions' => null,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
        ];

        return json_encode(array_replace_recursive($defaults, $overrides));
    }
}

if (! function_exists('createFailedJobPayload')) {
    /**
     * Create a job payload that simulates a failed job with x-death headers.
     *
     * @param  array<string, mixed>  $overrides
     */
    function createFailedJobPayload(string $jobClass, string $originalQueue, int $attemptCount = 1, array $overrides = []): string
    {
        $payload = json_decode(createTestPayload($jobClass, $overrides), true);
        $payload['attempts'] = $attemptCount;

        return json_encode($payload);
    }
}

if (! function_exists('getTestFixturePath')) {
    /**
     * Get the path to a test fixture file.
     */
    function getTestFixturePath(string $relativePath = ''): string
    {
        $base = __DIR__.'/../Fixtures';

        return $relativePath ? $base.'/'.$relativePath : $base;
    }
}

if (! function_exists('getTestJobsPath')) {
    /**
     * Get the path to the test jobs fixtures.
     */
    function getTestJobsPath(): string
    {
        return getTestFixturePath('Jobs');
    }
}

if (! function_exists('getTestExchangesPath')) {
    /**
     * Get the path to the test exchanges fixtures.
     */
    function getTestExchangesPath(): string
    {
        return getTestFixturePath('Exchanges');
    }
}
