<?php
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require __DIR__ . '/request-entry.php';
ocpeptide_begin_request();
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/analytics-store.php';

try {
    analytics_cleanup_retention();
} catch (Throwable $e) {
    fwrite(STDERR, 'Analytics retention: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
