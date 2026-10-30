<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
header('Referrer-Policy: no-referrer');
if (defined('OCPEPTIDE_UPDATE_PROBE') && OCPEPTIDE_UPDATE_PROBE && $_SERVER['REQUEST_METHOD'] === 'HEAD') {
    try {
        $state = read_state();
        $selected = contact_selection($state['settings'], (int)($state['wa_next_index'] ?? 0));
        header('X-Update-Contact: ' . ($selected === null ? 'unconfigured' : 'ready'));
    } catch (Throwable) { http_response_code(503); exit; }
}
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    header('Allow: GET');
    http_response_code(405);
    exit;
}
try {
    // Select and advance under the same lock: all visitors share one durable sequence.
    $location = transaction(function (array &$state): string {
        $selection = contact_selection($state['settings'], (int)($state['wa_next_index'] ?? 0));
        if ($selection === null) { throw new RuntimeException('No contact number configured.'); }
        $state['wa_next_index'] = $selection['next'];
        return $selection['location'];
    });
    header('Location: ' . $location, true, 302);
} catch (Throwable $e) {
    error_log((string)$e);
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo '联系入口暂时不可用，请稍后重试。';
}
