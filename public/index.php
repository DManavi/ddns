<?php

declare(strict_types=1);

/**
 * HTTP entrypoint.
 *
 * Point a web server's document root here, or run the whole thing with PHP's
 * built-in server: `php -S 0.0.0.0:8080 -t public`.
 */

use Ddns\Bootstrap;
use Ddns\Http\AppFactoryBuilder;

require dirname(__DIR__) . '/vendor/autoload.php';

try {
    $container = Bootstrap::container();
    AppFactoryBuilder::create($container)->run();
} catch (Throwable $e) {
    // Nothing is bootstrapped yet, so this has to be handled by hand. A
    // configuration problem is the overwhelmingly likely cause.
    http_response_code(500);
    header('Content-Type: application/json');

    error_log('[ddns] fatal during bootstrap: ' . $e->getMessage());

    echo json_encode([
        'error' => [
            'code' => 'bootstrap_failed',
            'message' => 'The server could not start. Check the logs and verify the configuration file.',
        ],
    ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
}
