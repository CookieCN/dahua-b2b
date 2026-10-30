<?php
declare(strict_types=1);
// Super-admin update API. The maintenance fence in bootstrap.php applies to
// these endpoints like any other request (POSTs skip only the shared gate so
// the engine can take it exclusively); during a fenced window the task state
// is served by the recovery entry instead.
require __DIR__ . '/bootstrap.php';
require __DIR__ . '/updater-engine.php';
admin_headers();

/**
 * Re-authenticate with the account password inside a transaction. Returns an
 * error array instead of throwing so the throttling counter and the audit
 * entry persist even when the password is wrong.
 */
function update_reauth(array &$state, array $admin, mixed $password): ?array {
    $keys = throttle_keys('update-reauth:' . $admin['username']);
    $wait = throttle_wait($state, $keys);
    if ($wait) { return ['error' => '尝试次数过多，请稍后再试。', 'status' => 429]; }
    if (!is_string($password) || strlen($password) > 72 || str_contains($password, "\0")
        || !password_verify($password, $admin['password_hash'])) {
        $wait = throttle_failed($state, $keys);
        audit($state, $admin, 'update_reauth_failed', $admin['username']);
        return ['error' => '密码验证失败。', 'status' => $wait ? 429 : 422];
    }
    foreach ($keys as $key) { unset($state['login_limits'][$key]); }
    return null;
}

$operationLock = null;
try {
    $state = read_state();
    $method = $_SERVER['REQUEST_METHOD'];
    $resource = $_GET['resource'] ?? '';
    if ($method === 'GET') {
        // Polling must not renew the sliding session: pass renew=false.
        $admin = current_admin($state, false);
        if (!empty($admin['must_change_credentials'])) { throw new ApiError('首次登录请先修改默认密码。', 403); }
        require_super($admin);
        if ($resource === 'status') {
            updater_ensure_dirs();
            $operationLock = updater_lock('update.lock', false);
            if ($operationLock === null) { throw new UpdateError('正在执行更新，请稍后刷新状态。', 'busy', 409); }
            if (function_exists('ocpeptide_release_request_gate')) { ocpeptide_release_request_gate(); }
            updater_resolve_active_tasks();
            if (function_exists('ocpeptide_hold_audit_gate')) { ocpeptide_hold_audit_gate($operationLock); }
            $status = updater_status();
            $enabled = $status['updater_enabled'];
            // Nonces bind dangerous actions to a fresh status read; only issued
            // when the trust configuration actually allows installing.
            $status['install_nonce'] = $enabled && !empty($status['extensions']['curl']) && $status['source_configured'] ? updater_issue_nonce('install', session_id())['nonce'] : null;
            $status['cleanup_nonce'] = $enabled ? updater_issue_nonce('cleanup', session_id(), $status['version'])['nonce'] : null;
            $rollbackTask = $status['last_task'];
            $rollbackId = is_array($rollbackTask) && in_array($rollbackTask['stage'] ?? '', ['applied', 'verified', 'failed'], true)
                ? ($rollbackTask['id'] ?? null) : null;
            $status['rollback_nonce'] = $enabled && is_string($rollbackId)
                ? updater_issue_nonce('rollback', session_id(), $status['version'], $rollbackId)['nonce'] : null;
            $status['csrf'] = $_SESSION['csrf'];
            $status['admin'] = public_user($admin);
            json_out($status);
        }
        if ($resource === 'check') {
            $config = updater_config();
            if ($config === null) { throw new UpdateError('更新器信任配置缺失，更新功能已禁用。请先由部署人员配置 private/update/trust.php。', 'trust_missing', 503); }
            if ($config['source_url'] === '') { throw new UpdateError('更新源未配置。在 private/update/trust.php 设置 source_url 后才能在线检查。', 'source_unconfigured', 422); }
            $channel = updater_fetch_channel($config);
            $latest = updater_select_release($channel['releases']);
            $latestVersion = $latest['version'] ?? null;
            transaction(function (array &$state) use ($admin, $latestVersion): void {
                audit($state, $admin, 'update_checked', 'source', ['fields' => [], 'latest' => $latestVersion]);
            });
            // The install nonce is bound to the version this check reported.
            $installNonce = $latestVersion !== null ? updater_issue_nonce('install', session_id(), $latestVersion)['nonce'] : null;
            json_out([
                'generated_at' => $channel['generated_at'],
                'latest_compatible' => $latest,
                'install_nonce' => $installNonce,
                'releases' => array_map(static fn(array $release): array => array_intersect_key($release, array_flip([
                    'version', 'channel', 'withdrawn', 'published_at', 'min_upgradable', 'requires_php', 'notes'])), $channel['releases']),
                'current_version' => updater_code_info()['version'],
            ]);
        }
        if ($resource === 'task') {
            $id = $_GET['id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)) { throw new ApiError('任务 ID 格式不正确。', 422); }
            json_out(updater_task_summary(updater_load_task($id)));
        }
        throw new ApiError('未知资源。', 404);
    }
    if ($method !== 'POST') { throw new ApiError('不支持此请求方式。', 405); }
    require_csrf();
    $action = $_GET['action'] ?? '';
    if (!in_array($action, ['install_upload', 'install_source', 'verify', 'rollback', 'cleanup'], true)) {
        throw new ApiError('未知操作。', 404);
    }
    // Uploads use multipart bodies; everything else is JSON.
    $upload = null;
    if ($action === 'install_upload') {
        $input = [
            'password' => $_POST['password'] ?? null,
            'nonce' => $_POST['nonce'] ?? null,
        ];
        $upload = $_FILES['package'] ?? null;
        if (!$upload || !is_int($upload['error']) || $upload['error'] !== UPLOAD_ERR_OK) {
            throw new ApiError('上传失败，请选择不超过 100MB 的签名更新包。');
        }
        if ($upload['size'] > 100 * 1024 * 1024 || $upload['size'] < 100) { throw new ApiError('更新包大小超出限制。', 413); }
    } else {
        $raw = file_get_contents('php://input');
        if (strlen($raw) > 20000) { throw new ApiError('请求内容过大。', 413); }
        try { $input = json_decode($raw, true, 32, JSON_THROW_ON_ERROR); }
        catch (JsonException) { throw new ApiError('请求格式不正确。'); }
        if (!is_array($input)) { throw new ApiError('请求格式不正确。'); }
    }
    // Authenticate, enforce first-password-change, super role and re-auth inside
    // one transaction; a wrong password persists its throttle + audit entry.
    $authorized = transaction(function (array &$state) use ($action, $input) {
        $admin = current_admin($state);
        if (!empty($admin['must_change_credentials'])) { throw new ApiError('首次登录请先修改默认密码。', 403); }
        require_super($admin);
        if (in_array($action, ['install_upload', 'install_source', 'rollback', 'cleanup'], true)) {
            $failure = update_reauth($state, $admin, $input['password'] ?? null);
            if ($failure !== null) { return ['error' => $failure]; }
        }
        return ['admin' => $admin];
    });
    if (isset($authorized['error'])) { throw new ApiError($authorized['error']['error'], $authorized['error']['status']); }
    $authorized = $authorized['admin'];
    // Nonblocking while the initial shared gate is held: a competing updater
    // cannot deadlock against this request. Keep this operation lock through
    // preparation, install/rollback and the final audit transaction.
    updater_ensure_dirs();
    $operationLock = updater_lock('update.lock', false);
    if ($operationLock === null) { throw new UpdateError('已有更新任务在进行中。', 'busy', 409); }
    if (function_exists('ocpeptide_release_request_gate')) { ocpeptide_release_request_gate(); }
    $task = null;
    try {
        if ($action === 'install_upload') {
            // Open the package first to learn its version, then enforce the
            // withdrawal status against the configured online source.
            $config = updater_config();
            if ($config === null) { throw new UpdateError('更新器信任配置缺失，更新功能已禁用。', 'trust_missing', 503); }
            if ($config['source_url'] === '') { throw new UpdateError('更新源未配置，无法核对发行与撤回状态，暂不能安装上传包。', 'source_unconfigured', 422); }
            updater_ensure_dirs();
            $incoming = updater_dir('update') . '/incoming/' . bin2hex(random_bytes(8)) . '.zip';
            if (!move_uploaded_file($upload['tmp_name'], $incoming)) { throw new RuntimeException('Cannot store uploaded package.'); }
            try {
                $package = updater_open_package($incoming, $config);
                $package = updater_validate_manifest($package['manifest'], $package['zip']);
                $manifest = $package['manifest'];
                $package['zip']->close();
                {
                    // Both install paths require current signed distribution
                    // authorization, including withdrawal and exact ZIP bytes.
                    $channel = updater_fetch_channel($config);
                    $listed = null;
                    foreach ($channel['releases'] as $candidate) {
                        if ($candidate['version'] === $manifest['version']) { $listed = $candidate; break; }
                    }
                    if ($listed === null || $listed['withdrawn'] || $listed['channel'] !== 'stable') {
                        throw new UpdateError('此版本未在更新源发布或已被撤回，拒绝安装。', 'version_unavailable', 422);
                    }
                    if (updater_select_release([$listed]) === null) {
                        throw new UpdateError('此发行版本与当前站点不兼容。', 'incompatible', 422);
                    }
                    if (filesize($incoming) !== $listed['package_size'] || hash_file('sha256', $incoming) !== $listed['package_sha256']) {
                        throw new UpdateError('上传包与签名发行清单的大小或 SHA256 不一致。', 'package_mismatch', 422);
                    }
                }
                updater_consume_nonce($input['nonce'] ?? null, 'install', session_id());
                $task = updater_start_task($incoming, $authorized['username'], 'upload', true);
                $task = updater_execute_task($task['id'], $authorized['username'], true);
            } finally { @unlink($incoming); }
        } elseif ($action === 'install_source') {
            $version = $input['version'] ?? null;
            if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) { throw new ApiError('版本号格式不正确。', 422); }
            $config = updater_config();
            if ($config === null) { throw new UpdateError('更新器信任配置缺失，更新功能已禁用。', 'trust_missing', 503); }
            if ($config['source_url'] === '') { throw new UpdateError('更新源未配置，无法在线安装。', 'source_unconfigured', 422); }
            // The nonce is bound to the session and the version reported by the
            // last successful signed channel check (stale checks are rejected).
            updater_consume_nonce($input['nonce'] ?? null, 'install', session_id(), $version);
            $channel = updater_fetch_channel($config);
            $release = null;
            foreach ($channel['releases'] as $candidate) {
                if ($candidate['version'] === $version && !$candidate['withdrawn'] && $candidate['channel'] === 'stable') { $release = $candidate; break; }
            }
            if ($release === null) { throw new UpdateError('更新源没有此版本或版本已撤回。', 'version_unavailable', 422); }
            // Compat is re-checked server side against the signed channel entry.
            if (updater_select_release(array_values(array_filter($channel['releases'], static fn(array $r): bool => $r['version'] === $version))) === null) {
                throw new UpdateError('此版本与当前站点不兼容。', 'incompatible', 422);
            }
            $zipPath = updater_download_release($release, $config);
            try {
                $task = updater_start_task($zipPath, $authorized['username'], 'source', true, $version);
                $task = updater_execute_task($task['id'], $authorized['username'], true);
            } finally { @unlink($zipPath); }
        } elseif ($action === 'verify') {
            $id = $input['task_id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)) { throw new ApiError('任务 ID 格式不正确。', 422); }
            $verification = updater_verify_task($id, true);
            $task = updater_load_task($id);
            if (function_exists('ocpeptide_hold_audit_gate')) { ocpeptide_hold_audit_gate($operationLock); }
            transaction(function (array &$state) use ($authorized, $task, $verification): void {
                audit($state, $authorized, 'update_verified', (string)($task['to_version'] ?? ''),
                      ['fields' => [], 'task_id' => $task['id'], 'passed' => $verification['passed']]);
            });
            json_out(['ok' => true, 'task' => updater_task_summary($task), 'verification' => $verification]);
        } elseif ($action === 'rollback') {
            $id = $input['task_id'] ?? '';
            if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)) { throw new ApiError('任务 ID 格式不正确。', 422); }
            // The grant names this task and the site version seen at issue time.
            updater_consume_nonce($input['nonce'] ?? null, 'rollback', session_id(), updater_code_info()['version'], $id);
            $task = updater_rollback_task($id, $authorized['username'], true);
        } elseif ($action === 'cleanup') {
            updater_consume_nonce($input['nonce'] ?? null, 'cleanup', session_id(), updater_code_info()['version']);
            $removed = updater_cleanup(true);
            if (function_exists('ocpeptide_hold_audit_gate')) { ocpeptide_hold_audit_gate($operationLock); }
            transaction(function (array &$state) use ($authorized, $removed): void {
                audit($state, $authorized, 'update_cleanup', 'backups', ['fields' => [], 'removed' => $removed]);
            });
            json_out(['ok' => true, 'removed' => $removed]);
        }
        // A healthy restoration keeps the site available but does not mean
        // that the requested new release installed successfully. Route this
        // result through the failure response/audit before the success tail.
        if (in_array($action, ['install_upload', 'install_source'], true)
            && ($task['stage'] ?? null) !== 'verified') {
            throw new UpdateError((string)($task['error'] ?? '更新未完成，网站已恢复旧版本。'),
                                  'installation_failed', 500);
        }
    } catch (UpdateError $error) {
        if (function_exists('ocpeptide_hold_audit_gate')) { ocpeptide_hold_audit_gate($operationLock); }
        transaction(function (array &$state) use ($authorized, $error): void {
            audit($state, $authorized, 'update_failed', $error->errorCode, ['fields' => [], 'message' => updater_truncate($error->getMessage(), 300)]);
        });
        throw $error;
    } catch (Throwable $error) {
        if (function_exists('ocpeptide_hold_audit_gate')) { ocpeptide_hold_audit_gate($operationLock); }
        transaction(function (array &$state) use ($authorized, $error): void {
            audit($state, $authorized, 'update_failed', 'engine_error', ['fields' => [], 'message' => updater_truncate($error->getMessage(), 300)]);
        });
        throw $error;
    }
    // Success audit with version/stage/result; no secrets are recorded.
    if (function_exists('ocpeptide_hold_audit_gate')) { ocpeptide_hold_audit_gate($operationLock); }
    transaction(function (array &$state) use ($authorized, $task): void {
        audit($state, $authorized, 'update_' . ($task['stage'] === 'rolled_back' ? 'rolled_back' : 'installed'),
            (string)($task['to_version'] ?? ''),
            ['fields' => [], 'task_id' => $task['id'] ?? '', 'stage' => $task['stage'] ?? '', 'mode' => $task['mode'] ?? '']);
    });
    json_out(['ok' => true, 'task' => updater_task_summary($task)]);
} catch (ApiError $e) {
    json_out(['error' => $e->getMessage()], $e->status);
} catch (UpdateError $e) {
    json_out(['error' => $e->getMessage(), 'code' => $e->errorCode, 'problems' => $e->context['problems'] ?? null], $e->status);
} catch (Throwable $e) {
    error_log((string)$e);
    json_out(['error' => '更新服务暂时不可用，请查看服务器错误日志或使用 /update-recovery.php。'], 500);
} finally {
    if ($operationLock !== null) { updater_unlock($operationLock); }
}
