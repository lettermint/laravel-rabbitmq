<?php

declare(strict_types=1);

/**
 * Test bootstrap file.
 *
 * Uses php-amqplib for RabbitMQ connectivity.
 * All AMQP classes are provided by the php-amqplib package.
 */

// Set up a temporary error handler to suppress deprecation warnings during autoload.
// This is necessary because older dependencies (Guzzle, Symfony, PsySH) may trigger
// deprecation warnings with PHP 8.4's stricter nullable parameter checking.
// These warnings would otherwise cause errors when Laravel's HandleExceptions
// tries to log them before the application is fully bootstrapped.
set_error_handler(function ($level, $message, $file = '', $line = 0) {
    // Suppress deprecation warnings during bootstrap
    if (in_array($level, [E_DEPRECATED, E_USER_DEPRECATED])) {
        return true;
    }

    // Let other errors through
    return false;
});

// Load Composer autoloader
require dirname(__DIR__).'/vendor/autoload.php';

// Restore the default error handler so Laravel's exception handling works normally
restore_error_handler();
