<?php

declare(strict_types=1);

/**
 * Test bootstrap file.
 *
 * Now that ext-amqp is installed, we only need to load the Composer autoloader.
 * The AMQP classes and constants are provided by the extension.
 */

// Load Composer autoloader
require dirname(__DIR__).'/vendor/autoload.php';
