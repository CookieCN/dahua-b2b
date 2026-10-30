<?php
declare(strict_types=1);
// Fixed request entry; no business module is included before this gate.
function ocpeptide_gate_error(string $message): never {
    http_response_code(503);
    header('Cache-Control: no-store, private');
    header('Retry-After: 10');
    if (($_SERVER['SCRIPT_NAME'] ?? '') === '/admin/update-api.php') {
        header('Content-Type: application/json; charset=utf-8');
        exit(json_encode(['error' => $message], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
    header('Content-Type: text/plain; charset=utf-8');
    exit($message);
}

/** Flush the authenticated session before handing control to the installer. */
function ocpeptide_release_request_gate(): void {
    if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
    $gate = $GLOBALS['ocpeptide_request_gate'] ?? null;
    if (is_resource($gate)) { flock($gate, LOCK_UN); fclose($gate); }
    $GLOBALS['ocpeptide_request_gate'] = null;
}

/** Small fixed-entry reader: updater metadata must be a bounded regular file
 *  at its actual path, never reached through an alias or directory link. */
function ocpeptide_gate_json(string $path, int $limit): ?array {
    $real = realpath($path);
    $expected = str_replace('\\', '/', $path);
    $resolved = $real === false ? '' : str_replace('\\', '/', $real);
    $same = DIRECTORY_SEPARATOR === '\\' ? strcasecmp($expected, $resolved) === 0 : $expected === $resolved;
    if (!$same || is_link($path) || !is_file($path)) { return null; }
    $raw = @file_get_contents($path, false, null, 0, $limit + 1);
    if (!is_string($raw) || strlen($raw) > $limit) { return null; }
    $data = json_decode($raw, true, 64);
    return is_array($data) ? $data : null;
}

function ocpeptide_begin_request(): void {
    if (defined('OCPEPTIDE_REQUEST_GATE_READY')) { return; }
    if ((string)ini_get('opcache.preload') !== '') {
        ocpeptide_gate_error('本站更新器不支持预加载业务 PHP，请联系管理员检查 PHP 配置。');
    }
    if (ini_get('opcache.enable')) {
        if (function_exists('ini_set')) { @ini_set('opcache.enable', '0'); }
        if (ini_get('opcache.enable')) {
            ocpeptide_gate_error('无法关闭本次请求的 PHP 代码缓存，请联系管理员检查配置。');
        }
    }
    $directory = __DIR__ . '/update';
    if (!is_dir($directory) && !@mkdir($directory, 0700, true) && !is_dir($directory)) {
        ocpeptide_gate_error('网站更新目录不可用，请联系管理员检查权限。');
    }
    $actualDirectory = realpath($directory);
    $expectedDirectory = str_replace('\\', '/', (string)realpath(__DIR__)) . '/update';
    $actualDirectory = str_replace('\\', '/', $actualDirectory === false ? '' : $actualDirectory);
    if (is_link($directory) || (DIRECTORY_SEPARATOR === '\\' ? strcasecmp($actualDirectory, $expectedDirectory) !== 0 : $actualDirectory !== $expectedDirectory)) {
        ocpeptide_gate_error('网站更新目录不是独立目录，请联系管理员检查部署。');
    }
    if (is_link($directory . '/gate.lock')) { ocpeptide_gate_error('网站更新锁文件不可用。'); }
    $gate = @fopen($directory . '/gate.lock', 'c');
    if ($gate === false) { ocpeptide_gate_error('网站更新目录不可用，请联系管理员检查权限。'); }
    $markerPath = $directory . '/maintenance.json';
    $fenced = file_exists($markerPath) || is_link($markerPath);
    $marker = $fenced ? ocpeptide_gate_json($markerPath, 8192) : null;
    $presented = (string)($_SERVER['HTTP_X_UPDATE_PROBE'] ?? '');
    $method = (string)($_SERVER['REQUEST_METHOD'] ?? '');
    $uri = (string)($_SERVER['REQUEST_URI'] ?? '');
    // Contact probes can only be HEAD: GET would advance the shared cursor.
    $probe = is_array($marker)
        && is_string($marker['task_id'] ?? null) && preg_match('/^[a-f0-9]{24}$/D', $marker['task_id'])
        && is_string($marker['probe_token'] ?? null) && preg_match('/^[a-f0-9]{32}$/D', $marker['probe_token'])
        && preg_match('/^[a-f0-9]{32}$/D', $presented)
        && (($method === 'GET' && in_array($uri, ['/', '/admin/', '/admin/api.php?resource=update_health'], true)) || ($method === 'HEAD' && $uri === '/contact.php'))
        && ($marker['probe_ready'] ?? null) === true
        && is_int($marker['probe_expires'] ?? null) && $marker['probe_expires'] > time()
        && hash_equals($marker['probe_token'], $presented);
    if ($probe) {
        $task = ocpeptide_gate_json($directory . '/tasks/' . $marker['task_id'] . '.json', 2097152);
        $probe = $task !== null && ($task['id'] ?? null) === $marker['task_id']
            && is_string($task['probe_token'] ?? null) && hash_equals($marker['probe_token'], $task['probe_token'])
            && in_array($task['stage'] ?? null, ['applied', 'verified', 'rolling_back', 'rolled_back', 'failed'], true);
    }
    if (!flock($gate, LOCK_SH | LOCK_NB)) {
        fclose($gate);
        if (!$probe) { ocpeptide_gate_error('网站正在系统更新维护，请稍后重试。'); }
    } elseif ($fenced) {
        flock($gate, LOCK_UN);
        fclose($gate);
        if (!$probe) {
            $engine = $directory . '/protected/updater-engine.php';
            if (!is_file($engine)) { $engine = __DIR__ . '/updater-engine.php'; }
            if (is_file($engine)) {
                try { require_once $engine; updater_try_autorecover(); }
                catch (Throwable) { /* Keep the fence and expose no internal details. */ }
            }
            ocpeptide_gate_error('网站正在完成系统更新，请稍后重试。');
        }
    } else {
        $GLOBALS['ocpeptide_request_gate'] = $gate;
        register_shutdown_function('ocpeptide_release_request_gate');
    }
    define('OCPEPTIDE_REQUEST_GATE_READY', true);
    define('OCPEPTIDE_UPDATE_PROBE', $probe);
}

/** The updater API keeps update.lock until its response audit completes.
 *  It may take a shared gate for its own audit even if its failed task left
 *  maintenance up: this never serves business traffic or renews a session,
 *  and another installer cannot overlap while update.lock is held. */
function ocpeptide_hold_audit_gate(mixed $operationLock): void {
    if (!is_resource($operationLock)) { throw new RuntimeException('Update operation lock is missing.'); }
    if (is_resource($GLOBALS['ocpeptide_request_gate'] ?? null)) { return; }
    $gate = @fopen(__DIR__ . '/update/gate.lock', 'c');
    if ($gate === false) { throw new RuntimeException('Cannot open audit gate.'); }
    if (!flock($gate, LOCK_SH | LOCK_NB)) {
        fclose($gate);
        throw new RuntimeException('Cannot acquire audit gate while another installer owns it.');
    }
    $GLOBALS['ocpeptide_request_gate'] = $gate;
}
