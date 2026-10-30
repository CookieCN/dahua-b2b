<?php
declare(strict_types=1);
// Signed updater engine. Include-safe: function definitions only.
// Loaded either from private/updater-engine.php (current code) or from the
// protected copy under private/update/protected/ (recovery path), so it never
// depends on business bootstrap code being healthy.
if (defined('OCPEPTIDE_UPDATER_ENGINE')) { return; }
define('OCPEPTIDE_UPDATER_ENGINE', '1');

final class UpdateError extends RuntimeException {
    /** @var array<string,mixed> */
    public function __construct(string $message, public readonly string $errorCode = 'update_error',
                                public readonly int $status = 422, public readonly array $context = []) { parent::__construct($message); }
}

function updater_dirs(): array {
    $base = dirname(__FILE__);
    // The protected recovery copy lives at private/update/protected/updater-engine.php.
    $private = basename($base) === 'protected' ? dirname($base, 2) : $base;
    return ['root' => dirname($private), 'private' => $private, 'public' => dirname($private) . '/public_html',
            'data' => $private . '/data', 'update' => $private . '/update'];
}
function updater_dir(string $key): string { return updater_dirs()[$key]; }

/** Parse private/version.php as text so recovery never includes business code. */
function updater_code_info(): array {
    $info = ['product' => 'ocpeptide-admin', 'version' => 'unknown', 'requires_php' => '8.2',
             'requires_extensions' => ['json','openssl','zip'], 'schema_min' => 2, 'schema_max' => 2];
    $raw = @file_get_contents(updater_dir('private') . '/version.php');
    if (!is_string($raw)) { return $info; }
    if (preg_match("/PRODUCT_ID\s*=\s*'([^']+)'/", $raw, $m)) { $info['product'] = $m[1]; }
    if (preg_match("/CODE_VERSION\s*=\s*'([0-9]+\.[0-9]+\.[0-9]+)'/", $raw, $m)) { $info['version'] = $m[1]; }
    if (preg_match("/UPDATE_REQUIRES_PHP\s*=\s*'([0-9.]+)'/", $raw, $m)) { $info['requires_php'] = $m[1]; }
    if (preg_match('/UPDATE_REQUIRES_EXTENSIONS\s*=\s*\[([^\]]*)\]/', $raw, $m)
        && preg_match_all("/'([a-z0-9_]+)'/", $m[1], $list)) { $info['requires_extensions'] = $list[1]; }
    if (preg_match('/SCHEMA_MIN\s*=\s*(\d+)/', $raw, $m)) { $info['schema_min'] = (int)$m[1]; }
    if (preg_match('/SCHEMA_MAX\s*=\s*(\d+)/', $raw, $m)) { $info['schema_max'] = (int)$m[1]; }
    return $info;
}

/** Trust config is deploy-managed and lives outside every updatable path. */
function updater_config(): ?array {
    $file = updater_dir('update') . '/trust.php';
    if (!is_file($file)) { return null; }
    $config = @include $file;
    if (!is_array($config)) { return null; }
    $key = $config['public_key'] ?? '';
    if (!is_string($key) || !str_contains($key, 'BEGIN PUBLIC KEY')
        || @openssl_pkey_get_public($key) === false) { return null; }
    $source = $config['source_url'] ?? '';
    return [
        'public_key' => $key,
        'source_url' => is_string($source) ? $source : '',
        'allow_loopback_source' => (bool)($config['allow_loopback_source'] ?? false),
        'backup_retention' => max(1, min(10, (int)($config['backup_retention'] ?? 3))),
        'probe_base_url' => is_string($config['probe_base_url'] ?? null) ? rtrim($config['probe_base_url'], '/') : '',
    ];
}

function updater_task_dir(): string { return updater_dir('update') . '/tasks'; }
function updater_maintenance_file(): string { return updater_dir('update') . '/maintenance.json'; }
function updater_maintenance_active(): bool { return is_file(updater_maintenance_file()); }

function updater_ensure_dirs(): void {
    foreach (['', '/tasks', '/staging', '/backups', '/incoming', '/protected'] as $sub) {
        $dir = updater_dir('update') . $sub;
        if (updater_path_escapes_root('private/update' . $sub)) { throw new RuntimeException('Updater runtime directory escapes the site root.'); }
        if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('Cannot create updater directory.'); }
    }
    $data = updater_dir('data');
    if (!is_dir($data) && !@mkdir($data, 0700, true) && !is_dir($data)) { throw new RuntimeException('Cannot create data directory.'); }
}
function updater_json_write(string $path, array $payload): void {
    $temp = $path . '.tmp-' . bin2hex(random_bytes(4));
    if (@file_put_contents($temp, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)) === false) {
        throw new RuntimeException('Cannot write updater state.');
    }
    if (!@rename($temp, $path)) { @unlink($temp); throw new RuntimeException('Cannot persist updater state.'); }
}
function updater_read_json(string $path): ?array {
    if (!is_file($path)) { return null; }
    $raw = @file_get_contents($path);
    if (!is_string($raw)) { return null; }
    try { $value = json_decode($raw, true, 64, JSON_THROW_ON_ERROR); } catch (JsonException) { return null; }
    return is_array($value) ? $value : null;
}
function updater_task_path(string $id): string { return updater_task_dir() . '/' . preg_replace('/[^a-f0-9]/', '', $id) . '.json'; }
function updater_load_task(string $id): array {
    $path = updater_task_path($id);
    $task = updater_read_json($path);
    if ($task === null || ($task['id'] ?? '') !== $id) { throw new UpdateError('更新任务不存在。', 'task_missing', 404); }
    return $task;
}
function updater_save_task(array $task): void {
    // Updates can finish within one second. Persist finer timestamps so the
    // status page and rollback grant select the actual most recent task.
    $task['updated_at'] = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format('Y-m-d\TH:i:s.uP');
    updater_json_write(updater_task_path($task['id']), $task);
}

// Note: recovery_required is intentionally NOT terminal: it stays listed as an
// active task so the orphan-fence guard in updater_resolve_active_tasks keeps
// its maintenance fence up until an operator resolves it manually.
function updater_terminal_stages(): array { return ['verified', 'rolled_back', 'aborted', 'failed']; }
function updater_active_tasks(): array {
    $tasks = [];
    foreach (glob(updater_task_dir() . '/*.json') ?: [] as $file) {
        $task = updater_read_json($file);
        if ($task !== null && !in_array($task['stage'] ?? '', updater_terminal_stages(), true)) { $tasks[] = $task; }
    }
    usort($tasks, fn($a, $b) => ($a['created_at'] ?? '') <=> ($b['created_at'] ?? ''));
    return $tasks;
}
function updater_last_task(): ?array {
    $latest = null;
    foreach (glob(updater_task_dir() . '/*.json') ?: [] as $file) {
        $task = updater_read_json($file);
        if ($task !== null && ($latest === null || ($task['updated_at'] ?? '') > ($latest['updated_at'] ?? ''))) { $latest = $task; }
    }
    return $latest;
}

/** Sensitive file names never update, in private or public trees alike. */
function updater_sensitive_name(string $name): bool {
    return (bool)preg_match('~\.(pem|key|env|p12|pfx|crt|cer|sqlite3?|db|log|zip)$~iD', $name)
        || (bool)preg_match('~^(id_rsa.*|.*\.env.*|credentials.*|secrets?[-_.].*|password.*|.*_secret.*)$~iD', $name);
}

/** Updateable-path allowlist; data, trust config, uploads and tooling stay untouchable. */
function updater_code_path(string $relative): bool {
    // Baseline and manifest paths must ALREADY be canonical forward-slash
    // form. Backslash, colon or control bytes, empty segments (leading,
    // trailing or repeated slashes) and dot/dot-dot segments are aliases of
    // other real paths and reject before any target byte is hashed or read.
    if ($relative === '' || $relative[0] === '/' || str_contains($relative, '\\')
        || preg_match('~[\x00-\x1f]~', $relative) || preg_match('~/[^/]+:~', $relative)) { return false; }
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..') { return false; }
    }
    if (str_starts_with($relative, 'private/')) {
        $rest = substr($relative, 8);
        if ($rest === '' || str_starts_with($rest, 'data/') || str_starts_with($rest, 'update/')) { return false; }
        if (updater_sensitive_name(basename($rest))) { return false; }
        return (bool)preg_match('~^(?:[^/]+\.php|\.htaccess|code-baseline\.json)$~D', $rest);
    }
    if (str_starts_with($relative, 'public_html/')) {
        $rest = substr($relative, 12);
        if ($rest === '' || str_ends_with($rest, '/') || str_contains($rest, '//')) { return false; }
        // Tooling, tests, backups and repository metadata never ship under the site.
        foreach (['tests/', 'delivery/', 'docs/', 'scripts/', '.agents/', '.tools/', '.git/', 'private/', 'backups/'] as $blocked) {
            if (str_starts_with($rest, $blocked)) { return false; }
        }
        // Customer uploads and the customer-managed hero image never update.
        if ($rest === 'hero.webp' || (str_starts_with($rest, 'uploads/') && $rest !== 'uploads/.htaccess')) { return false; }
        $name = basename($rest);
        if ($name === '.htaccess') { return true; }
        if (updater_sensitive_name($name)) { return false; }
        // Static/code assets only; nothing else ships unseen into public paths.
        return (bool)preg_match('~\.(php|css|js|json|svg|ico|txt|woff2?|map)$~iD', $name);
    }
    return false;
}

/** Complete baselines include the fixed entry files, but online payloads
 *  never replace/delete public PHP entry points or their private gate.
 *  First installation and changes to this small bootstrap use the manual
 *  deployment package. Reject Windows short-name aliases in payload paths. */
function updater_allowed_path(string $relative): bool {
    return updater_code_path($relative)
        && !str_contains($relative, '~')
        && strtolower($relative) !== 'private/request-entry.php'
        && !(str_starts_with($relative, 'public_html/') && preg_match('/\.php$/iD', $relative));
}

/** UTF-8 safe truncation without requiring mbstring on shared hosting. */
function updater_truncate(string $text, int $limit): string {
    if (preg_match_all('/./us', $text, $matches) && count($matches[0]) <= $limit) { return $text; }
    preg_match('/^\.{0,' . $limit . '}/us', $text, $prefix);
    return ($prefix[0] ?? substr($text, 0, $limit)) ;
}

function updater_version_compare(string $a, string $b): int {
    $parse = static function (string $v): array {
        if (!preg_match('/^(\d+)\.(\d+)\.(\d+)$/', trim($v), $m)) { throw new UpdateError('版本号格式不正确：' . $v, 'bad_version'); }
        return [(int)$m[1], (int)$m[2], (int)$m[3]];
    };
    $left = $parse($a); $right = $parse($b);
    foreach ([0, 1, 2] as $i) { if ($left[$i] !== $right[$i]) { return $left[$i] <=> $right[$i]; } }
    return 0;
}

// ---------------------------------------------------------------------------
// Package inspection: signature, manifest contract, zip hygiene (P0).
// ---------------------------------------------------------------------------

/**
 * @return array{manifest: array, manifest_bytes: string, zip: ZipArchive}
 */
function updater_open_package(string $zipPath, ?array $config): array {
    if (!is_file($zipPath) || !is_readable($zipPath)) { throw new UpdateError('更新包不存在或不可读。', 'package_missing', 422); }
    $config ??= updater_config();
    if ($config === null) { throw new UpdateError('更新器信任配置缺失，更新功能已禁用。请先由部署人员配置 private/update/trust.php。', 'trust_missing', 503); }
    $zip = new ZipArchive();
    if (($error = $zip->open($zipPath, ZipArchive::CHECKCONS)) !== true) {
        throw new UpdateError('更新包不是有效的 ZIP 文件。', 'zip_invalid', 422, ['zip_error' => (int)$error]);
    }
    $names = [];
    for ($i = 0; $i < $zip->numFiles; $i++) {
        $name = $zip->getNameIndex($i);
        $stat = $zip->statIndex($i);
        if ($name === false || $name === '' || str_ends_with($name, '/') || $stat === false) {
            throw new UpdateError('更新包含有目录或异常条目，已拒绝。', 'zip_entry_invalid', 422);
        }
        if (isset($names[$name])) { throw new UpdateError('更新包含有重复路径条目，已拒绝。', 'zip_duplicate', 422); }
        $names[$name] = true;
        // Reject non-regular unix entries (symlinks S_IFLNK etc.) via the
        // canonical per-index attribute API, regardless of the link target.
        $opsys = 0; $attr = 0;
        if (!$zip->getExternalAttributesIndex($i, $opsys, $attr)) {
            throw new UpdateError('无法读取更新包条目属性。', 'zip_entry_invalid', 422);
        }
        if ($opsys === ZipArchive::OPSYS_UNIX && ((($attr >> 16) & 0xF000) !== 0x8000)) {
            throw new UpdateError('更新包含有链接或非常规文件条目，已拒绝。', 'zip_symlink', 422);
        }
        if (preg_match('~(^|/)\.\.(/|$)|^/|[\x00-\x1f]|[A-Za-z]:~', $name)) {
            throw new UpdateError('更新包含有越界或异常路径，已拒绝。', 'zip_traversal', 422);
        }
    }
    $manifestBytes = $zip->getFromName('manifest.json');
    $signature = $zip->getFromName('manifest.sig');
    if (!is_string($manifestBytes) || !is_string($signature)) {
        throw new UpdateError('更新包缺少清单或签名文件。', 'manifest_missing', 422);
    }
    if (@openssl_verify($manifestBytes, base64_decode($signature, true), $config['public_key'], OPENSSL_ALGO_SHA256) !== 1) {
        throw new UpdateError('更新包清单签名验证失败，来源不可信。', 'bad_signature', 422);
    }
    try { $manifest = json_decode($manifestBytes, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new UpdateError('更新包清单不是有效 JSON。', 'manifest_invalid', 422); }
    if (!is_array($manifest)) { throw new UpdateError('更新包清单格式不正确。', 'manifest_invalid', 422); }
    return ['manifest' => $manifest, 'manifest_bytes' => $manifestBytes, 'zip' => $zip];
}

/**
 * @return array{manifest: array, zip: ZipArchive}
 */
function updater_validate_manifest(array $manifest, ZipArchive $zip): array {
    $info = updater_code_info();
    if (($manifest['product'] ?? '') !== $info['product']) { throw new UpdateError('更新包产品标识与本站不匹配。', 'product_mismatch', 422); }
    $version = $manifest['version'] ?? '';
    if (!is_string($version) || !preg_match('/^\d+\.\d+\.\d+$/D', $version)) { throw new UpdateError('更新包版本号格式不正确。', 'bad_version', 422); }
    // min_upgradable is a product version and must be full x.y.z semver;
    // requires_php legitimately uses shorter forms like '8.2'.
    if (!is_string($manifest['min_upgradable'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/D', $manifest['min_upgradable'])) {
        throw new UpdateError('更新包兼容性声明不完整：min_upgradable', 'compat_field', 422);
    }
    if (!is_string($manifest['requires_php'] ?? null) || !preg_match('/^\d+\.\d+(\.\d+)?$/D', $manifest['requires_php'])) {
        throw new UpdateError('更新包兼容性声明不完整：requires_php', 'compat_field', 422);
    }
    foreach (['schema_min', 'schema_max'] as $key) {
        if (!is_int($manifest[$key] ?? null) || $manifest[$key] < 1) { throw new UpdateError('更新包数据格式声明不完整：' . $key, 'compat_field', 422); }
    }
    if ($manifest['schema_min'] > $manifest['schema_max']) {
        throw new UpdateError('更新包数据格式兼容范围不正确（min 大于 max）。', 'compat_field', 422);
    }
    if (!is_array($manifest['requires_extensions'] ?? null) || array_filter($manifest['requires_extensions'], 'is_string') !== $manifest['requires_extensions']) {
        throw new UpdateError('更新包扩展要求格式不正确。', 'compat_field', 422);
    }
    if (!is_string($manifest['notes'] ?? null) || strlen($manifest['notes']) > 5000) { throw new UpdateError('更新说明格式不正确。', 'notes_invalid', 422); }
    $files = $manifest['files'] ?? null;
    if (!is_array($files) || $files === [] || count($files) > 2000) { throw new UpdateError('更新包文件清单为空或超出上限。', 'files_invalid', 422); }
    $seen = [];
    $total = 0;
    foreach ($files as $file) {
        $path = $file['path'] ?? null; $sha = $file['sha256'] ?? null; $size = $file['size'] ?? null;
        if (!is_string($path) || !is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha) || !is_int($size) || $size < 0 || $size > 64 * 1024 * 1024) {
            throw new UpdateError('文件清单条目格式不正确。', 'files_invalid', 422);
        }
        if (isset($seen[$path])) { throw new UpdateError('文件清单存在重复路径。', 'duplicate_path', 422); }
        $seen[$path] = true;
        if (!updater_allowed_path($path)) { throw new UpdateError('更新包含有禁止更新的路径：' . $path, 'forbidden_path', 422); }
        $total += $size;
    }
    if (($manifest['total_size'] ?? null) !== $total) { throw new UpdateError('清单大小声明与文件总和不一致。', 'size_mismatch', 422); }
    if ($total > 120 * 1024 * 1024) { throw new UpdateError('更新包解压后超过大小上限。', 'zip_bomb', 422); }
    $deletions = $manifest['deletions'] ?? [];
    if (!is_array($deletions)) { throw new UpdateError('删除清单格式不正确。', 'deletions_invalid', 422); }
    $seenDeletions = [];
    foreach ($deletions as $path) {
        if (!is_string($path) || isset($seen[$path]) || isset($seenDeletions[$path])) {
            throw new UpdateError('删除清单包含无效、冲突或重复路径。', 'deletions_invalid', 422);
        }
        $seenDeletions[$path] = true;
        if (!updater_allowed_path($path)) { throw new UpdateError('删除清单包含禁止路径：' . $path, 'forbidden_path', 422); }
    }
    // ZIP entries must exactly equal the manifest payload plus the two meta files.
    $expected = ['manifest.json' => true, 'manifest.sig' => true];
    foreach ($files as $file) { $expected['payload/' . $file['path']] = true; }
    $actual = [];
    for ($i = 0; $i < $zip->numFiles; $i++) { $actual[$zip->getNameIndex($i)] = true; }
    if (array_diff_key($actual, $expected) || array_diff_key($expected, $actual)) {
        throw new UpdateError('更新包内容与签名清单不完全一致（多余或缺少文件）。', 'content_mismatch', 422);
    }
    // Pre-extraction bomb accounting using declared uncompressed sizes.
    $zipTotal = 0;
    foreach (array_keys($actual) as $name) {
        $stat = $zip->statName($name);
        if ($stat === false) { throw new UpdateError('无法读取更新包条目。', 'zip_stat_failed', 422); }
        if ($stat['size'] > 64 * 1024 * 1024) { throw new UpdateError('单个文件解压后过大，疑似压缩炸弹。', 'zip_bomb', 422); }
        $zipTotal += $stat['size'];
    }
    if ($zipTotal > 128 * 1024 * 1024) { throw new UpdateError('更新包解压总量过大，疑似压缩炸弹。', 'zip_bomb', 422); }
    return ['manifest' => $manifest, 'zip' => $zip];
}

/** Environment and baseline preflight; nothing may be modified before this passes. */
function updater_preflight(array $manifest, ?array $baseline = null): array {
    $problems = [];
    $info = updater_code_info();
    if (version_compare(PHP_VERSION, $manifest['requires_php']) < 0) { $problems[] = 'PHP 版本过低：需要 ' . $manifest['requires_php'] . '，当前 ' . PHP_VERSION; }
    foreach ($manifest['requires_extensions'] as $extension) {
        if (!extension_loaded($extension)) { $problems[] = '缺少 PHP 扩展：' . $extension; }
    }
    $state = updater_read_state_summary();
    if ($state === null) { $problems[] = '站点数据文件无法读取，取消更新以保证数据安全。'; }
    else {
        $schema = (int)($state['schema_version'] ?? 1);
        if ($schema < $manifest['schema_min'] || $schema > $manifest['schema_max']) {
            $problems[] = '站点数据格式版本 ' . $schema . ' 与更新包要求（' . $manifest['schema_min'] . '–' . $manifest['schema_max'] . '）不兼容。';
        }
    }
    if (updater_version_compare($manifest['version'], $info['version']) <= 0) {
        $problems[] = '目标版本 ' . $manifest['version'] . ' 不高于当前版本 ' . $info['version'] . '，拒绝降级或重装。';
    }
    if (updater_version_compare($info['version'], $manifest['min_upgradable']) < 0) {
        $problems[] = '当前版本 ' . $info['version'] . ' 低于此包最低可升级版本 ' . $manifest['min_upgradable'] . '，请先升级中间版本。';
    }
    return array_merge($problems, updater_baseline_problems($manifest, $baseline), updater_disk_problems($manifest));
}

function updater_read_state_summary(): ?array {
    $raw = @file_get_contents(updater_dir('data') . '/state.php');
    $prefix = "<?php exit; ?>\n";
    if (!is_string($raw) || !str_starts_with($raw, $prefix)) { return null; }
    try { $state = json_decode(substr($raw, strlen($prefix)), true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return null; }
    return is_array($state) ? $state : null;
}

function updater_baseline_path(): string { return updater_dir('private') . '/code-baseline.json'; }

/** Every EXISTING segment of a root-relative path must resolve back inside
 *  the intended site root: symlinks, Windows junctions or substituted
 *  ancestors that point outside reject BEFORE the target byte is hashed or
 *  read. Missing leaves are fine here; existence is judged by the callers. */
function updater_path_escapes_root(string $relative): bool {
    $rootReal = realpath(updater_dir('root'));
    if ($rootReal === false) { return true; }
    $current = updater_dir('root');
    $segments = explode('/', $relative);
    foreach ($segments as $index => $segment) {
        $current .= '/' . $segment;
        if (is_link($current)) { return true; }
        if (!file_exists($current)) { continue; }
        $real = realpath($current);
        if ($real === false) { return true; }
        $boundary = rtrim($rootReal, '/\\') . DIRECTORY_SEPARATOR;
        $inside = DIRECTORY_SEPARATOR === '\\'
            ? strncasecmp($real, $boundary, strlen($boundary)) === 0
            : str_starts_with($real, $boundary);
        if (!$inside || ($index < count($segments) - 1 && !is_dir($current))) { return true; }
    }
    return false;
}

/** Customer-modified system code fails closed; colliding extra files are rejected. */
/** Bounded, regular, non-link RAW read (max $cap bytes). Every path-safety
 *  check runs BEFORE any byte is read; oversize files reject instead of
 *  truncating silently. */
function updater_read_bounded_bytes(string $path, int $cap): ?string {
    $root = str_replace('\\', '/', updater_dir('root'));
    $logical = str_replace('\\', '/', $path);
    if (!str_starts_with($logical, $root . '/')) { return null; }
    $relative = substr($logical, strlen($root) + 1);
    foreach (explode('/', $relative) as $segment) {
        if ($segment === '' || $segment === '.' || $segment === '..' || str_contains($segment, ':')
            || preg_match('/[\x00-\x1f\x7f]/', $segment)) { return null; }
    }
    if (updater_path_escapes_root($relative)) { return null; }
    if (is_link($path) || !is_file($path) || !is_readable($path)) { return null; }
    if (filesize($path) > $cap) { return null; }
    $raw = @file_get_contents($path, false, null, 0, $cap + 1);
    if (!is_string($raw) || strlen($raw) > $cap) { return null; }
    return $raw;
}

/** Bounded, regular, non-link JSON read (max $cap bytes). */
function updater_read_bounded_json(string $path, int $cap): ?array {
    $raw = updater_read_bounded_bytes($path, $cap);
    if ($raw === null) { return null; }
    try { $value = json_decode($raw, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return null; }
    return is_array($value) ? $value : null;
}

/** Strict baseline shape validation against EXPECTED product/version
 *  (the trusted installed baseline uses the current identity; the signed
 *  target baseline uses the manifest target identity). Files must be an
 *  object of canonical allowed code paths to strict lower-hex digests,
 *  never self-referencing the rolling baseline file. */
function updater_baseline_shape_problems(?array $baseline, string $expectedProduct, string $expectedVersion): array {
    if ($baseline === null) { return ['基线缺失或不可读。']; }
    if (!is_array($baseline)) { return ['基线必须是 JSON 对象。']; }
    if (($baseline['product'] ?? null) !== $expectedProduct) { return ['基线产品标识不一致。']; }
    if (($baseline['version'] ?? null) !== $expectedVersion) { return ['基线版本不一致。']; }
    $files = $baseline['files'] ?? null;
    if ($files === null || !is_array($files) || array_is_list($files)) { return ['基线缺少 files 对象映射。']; }
    foreach ($files as $path => $sha) {
        if (!is_string($path) || !updater_code_path($path) || $path === 'private/code-baseline.json') {
            return ['基线包含非法、受保护或自引用路径：' . (is_string($path) ? $path : '(非字符串键)')];
        }
        if (!is_string($sha) || !preg_match('/^[a-f0-9]{64}$/D', $sha)) {
            return ['基线哈希格式不正确：' . $path];
        }
    }
    return [];
}

/** Parse product/version constants from staged source TEXT with the native
 *  tokenizer (TOKEN_PARSE, comments filtered out); no staged PHP is ever
 *  executed or included. Only a unique top-level literal const declaration
 *  in the global namespace counts as identity: namespaces, class or block
 *  scopes, duplicates, expressions (non-literal token shape), escape
 *  sequences, a broken file (ParseError) or a missing tokenizer reject. */
function updater_staged_code_identity(string $bytes): ?array {
    if (strlen($bytes) > 65536 || !function_exists('token_get_all')) { return null; }
    try { $all = token_get_all($bytes, TOKEN_PARSE); }
    catch (Throwable) { return null; }
    $tokens = array_values(array_filter($all, static fn($t): bool =>
        !is_array($t) || !in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)));
    $found = []; $depth = 0;
    foreach ($tokens as $index => $token) {
        if ($token === '{') { $depth++; continue; }
        if ($token === '}') { if (--$depth < 0) { return null; } continue; }
        if (!is_array($token)) { continue; }
        // This site's version file declares global constants, never namespaces.
        if ($token[0] === T_NAMESPACE) { return null; }
        if ($depth !== 0 || $token[0] !== T_CONST) { continue; }
        $name = $tokens[$index + 1] ?? null;
        if (!is_array($name) || $name[0] !== T_STRING
            || !in_array($name[1], ['PRODUCT_ID', 'CODE_VERSION'], true)) { continue; }
        if (isset($found[$name[1]])) { return null; }
        $literal = $tokens[$index + 3] ?? null;
        if (($tokens[$index + 2] ?? null) !== '=' || !is_array($literal)
            || $literal[0] !== T_CONSTANT_ENCAPSED_STRING || ($tokens[$index + 4] ?? null) !== ';') { return null; }
        $quoted = $literal[1];
        if (strlen($quoted) < 2 || !in_array($quoted[0], ["'", '"'], true)
            || substr($quoted, -1) !== $quoted[0] || str_contains($quoted, '\\')) { return null; }
        $found[$name[1]] = substr($quoted, 1, -1);
    }
    if ($depth !== 0 || !isset($found['PRODUCT_ID'], $found['CODE_VERSION'])
        || !preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $found['CODE_VERSION'])) { return null; }
    return ['product' => $found['PRODUCT_ID'], 'version' => $found['CODE_VERSION']];
}

/** Validate the SIGNED TARGET baseline from fully staged bytes, before any
 *  prepared task exists. Expected full target map = old trusted map minus
 *  manifest deletions plus every manifest file except the rolling baseline
 *  itself; unchanged entries keep old digests, changed/added use manifest
 *  digests. Incremental manifests may omit unchanged payload files. */
function updater_staged_baseline_problems(array $manifest, array $oldBaseline, string $stagingPayloadDir): array {
    $shipped = [];
    foreach ($manifest['files'] as $file) { $shipped[$file['path']] = $file['sha256']; }
    if (!isset($shipped['private/code-baseline.json'])) { return ['更新包未随附新的签名代码基线。']; }
    if (!isset($shipped['private/version.php'])) { return ['更新包未随附目标版本文件 private/version.php。']; }
    $identity = updater_staged_code_identity((string)@file_get_contents($stagingPayloadDir . '/private/version.php'));
    if ($identity === null) { return ['暂存的版本文件缺少明确的 PRODUCT_ID/CODE_VERSION。']; }
    if ($identity['product'] !== $manifest['product'] || $identity['version'] !== $manifest['version']) {
        return ['暂存版本文件身份与清单不一致。'];
    }
    $target = updater_read_bounded_json($stagingPayloadDir . '/private/code-baseline.json', CHANNEL_PAYLOAD_MAX_BYTES);
    foreach (updater_baseline_shape_problems($target, $manifest['product'], $manifest['version']) as $problem) {
        return ['目标基线：' . $problem];
    }
    $oldFiles = $oldBaseline['files'];
    $expected = $oldFiles;
    foreach ($manifest['deletions'] as $path) {
        if (!isset($expected[$path])) { return ['删除目标不在可信旧基线内，拒绝：' . $path]; }
        unset($expected[$path]);
    }
    foreach ($shipped as $path => $sha) {
        if ($path === 'private/code-baseline.json') { continue; }
        $expected[$path] = $sha;
    }
    $targetFiles = $target['files'];
    $missing = array_diff_key($expected, $targetFiles);
    if ($missing) { return ['目标基线缺少文件映射条目：' . implode(',', array_keys($missing))]; }
    $extra = array_diff_key($targetFiles, $expected);
    if ($extra) { return ['目标基线含多余或未随运条目：' . implode(',', array_keys($extra))]; }
    foreach ($expected as $path => $sha) {
        if (($targetFiles[$path] ?? null) !== $sha) { return ['目标基线哈希与期望不符：' . $path]; }
    }
    return [];
}

/** Prove the COMPLETE installed tree against the identity pin recorded at
 *  trusted preparation time ('target' = signed package baseline digest,
 *  'source' = verified prior-install baseline digest). The live baseline
 *  file's BYTES must hash to the pinned digest first, so its parsed file map
 *  is trusted expected data instead of a self-vouching claim; the live
 *  version.php identity must match the pin; every mapped file (changed AND
 *  unchanged) is hashed on disk; on the target side declared deletions must
 *  be fully absent, on the source side update-added files must be absent.
 *  Missing or ambiguous pin/op data fails closed.
 * @param 'target'|'source' $side
 * @return string[] problems; empty list = complete trusted tree proven
 */
function updater_pinned_tree_problems(array $task, string $side): array {
    if (!in_array($side, ['target', 'source'], true)) { throw new RuntimeException('Unknown proof side.'); }
    $pins = $task['identity_pin'] ?? null;
    $pin = is_array($pins) ? ($pins[$side] ?? null) : null;
    if (!is_array($pin) || !is_string($pin['product'] ?? null) || $pin['product'] === ''
        || !is_string($pin['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/D', (string)$pin['version'])
        || !is_string($pin['baseline_sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', (string)$pin['baseline_sha256'])) {
        return ['任务缺少可信身份基准（identity_pin.' . $side . '），无法证明安装完整性。'];
    }
    // The pin must stay bound to the journal's own version record, and both
    // pins must describe the same product: a drifted or edited journal can
    // never serve as its own proof.
    $journalVersion = $side === 'target' ? ($task['to_version'] ?? null) : ($task['from_version'] ?? null);
    if (!is_string($journalVersion) || $pin['version'] !== $journalVersion) {
        return ['身份基准版本与任务记录（' . $side . '）不一致。'];
    }
    $otherPin = $pins[$side === 'target' ? 'source' : 'target'] ?? null;
    if (!is_array($otherPin) || !is_string($otherPin['product'] ?? null) || $otherPin['product'] !== $pin['product']) {
        return ['身份基准产品标识不一致，无法证明安装完整性。'];
    }
    $bytes = updater_read_bounded_bytes(updater_baseline_path(), CHANNEL_PAYLOAD_MAX_BYTES);
    if ($bytes === null) { return ['基线文件缺失、越界或不可读，无法证明安装完整性。']; }
    if (hash('sha256', $bytes) !== $pin['baseline_sha256']) {
        return ['当前基线字节与可信基准哈希不一致。'];
    }
    try { $baseline = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return ['基线字节无法解析，无法证明安装完整性。']; }
    $shape = updater_baseline_shape_problems(is_array($baseline) ? $baseline : null, $pin['product'], $pin['version']);
    if ($shape) { return array_map(static fn(string $problem): string => '完整性：' . $problem, $shape); }
    $identityBytes = updater_read_bounded_bytes(updater_dir('private') . '/version.php', 65536);
    $identity = $identityBytes === null ? null : updater_staged_code_identity($identityBytes);
    if ($identity === null || $identity['product'] !== $pin['product'] || $identity['version'] !== $pin['version']) {
        return ['当前代码身份与可信基准（' . $pin['product'] . ' ' . $pin['version'] . '）不一致。'];
    }
    $problems = [];
    $root = updater_dir('root');
    foreach ($baseline['files'] as $path => $sha) {
        if (updater_path_escapes_root($path)) { $problems[] = '文件路径经链接或 junction 越界：' . $path; continue; }
        $absolute = $root . '/' . $path;
        if (!is_file($absolute)) { $problems[] = '文件缺失：' . $path; continue; }
        if (hash_file('sha256', $absolute) !== $sha) { $problems[] = '文件哈希与可信基线不一致：' . $path; }
    }
    // Presence means ANY filesystem object: directories, links and dangling
    // links all count, not only regular files. Invalid op paths reject.
    $present = static function (string $absolute): bool { return file_exists($absolute) || is_link($absolute); };
    foreach (($task['ops'] ?? []) as $op) {
        if (!is_array($op)) { return ['任务操作记录损坏，无法证明安装完整性。']; }
        $path = $op['path'] ?? null;
        $relevant = $side === 'target'
            ? (($op['delete'] ?? null) === true)
            : (($op['delete'] ?? null) !== true && ($op['prior_sha256'] ?? null) === null);
        if (!$relevant) { continue; }
        if (!is_string($path) || !updater_allowed_path($path) || updater_path_escapes_root($path)) {
            return ['任务操作路径无效（' . $side . '），无法证明安装完整性。'];
        }
        if ($present($root . '/' . $path)) {
            $problems[] = ($side === 'target' ? '已声明删除的文件仍存在：' : '更新新增的文件在回滚后仍存在：') . $path;
        }
    }
    return $problems;
}

/** Digest of the live updater engine as recorded in the baseline bound to a
 *  preparation identity pin. Callers use it right after updater_pinned_tree_
 *  problems proved those exact baseline bytes, so the protected-engine handoff
 *  can copy the live engine against a verified expected hash; unproven or
 *  missing data returns null and callers fail closed. */
function updater_pinned_engine_sha256(?array $pin): ?string {
    if (!is_array($pin) || !is_string($pin['baseline_sha256'] ?? null)
        || !preg_match('/^[a-f0-9]{64}$/D', $pin['baseline_sha256'])) { return null; }
    $bytes = updater_read_bounded_bytes(updater_baseline_path(), CHANNEL_PAYLOAD_MAX_BYTES);
    if ($bytes === null || hash('sha256', $bytes) !== $pin['baseline_sha256']) { return null; }
    try { $baseline = json_decode($bytes, true, 512, JSON_THROW_ON_ERROR); }
    catch (JsonException) { return null; }
    $sha = is_array($baseline) ? ($baseline['files']['private/updater-engine.php'] ?? null) : null;
    return is_string($sha) && preg_match('/^[a-f0-9]{64}$/D', $sha) ? $sha : null;
}

function updater_baseline_problems(array $manifest, ?array $baseline = null): array {
    $problems = [];
    $info = updater_code_info();
    $baseline ??= updater_read_bounded_json(updater_baseline_path(), CHANNEL_PAYLOAD_MAX_BYTES);
    $shape = updater_baseline_shape_problems($baseline, $info['product'], $info['version']);
    if ($shape) { return array_merge(['缺少或无效的可信代码基线（private/code-baseline.json）。旧站点请先按文档手动安装更新器。'], $shape); }
    foreach ($baseline['files'] as $path => $sha) {
        $absolute = updater_dir('root') . '/' . $path;
        if (updater_path_escapes_root($path)) { $problems[] = '基线路径经链接或 junction 指向站点根目录之外：' . $path; continue; }
        if (!is_file($absolute)) { $problems[] = '基线文件缺失，站点代码可能已改动：' . $path; continue; }
        if (hash_file('sha256', $absolute) !== $sha) { $problems[] = '站点代码与可信基线不一致：' . $path; }
    }
    foreach ($manifest['files'] as $file) {
        $path = $file['path'];
        if (!isset($baseline['files'][$path])) {
            $absolute = updater_dir('root') . '/' . $path;
            // Files the baseline never shipped may only be created when absent
            // or already identical. The rolling code-baseline itself is the one
            // legitimate exception: every signed release replaces it by design.
            if ($path !== 'private/code-baseline.json' && updater_path_escapes_root($path)) {
                $problems[] = '新文件路径经链接或 junction 指向站点根目录之外：' . $path;
                continue;
            }
            if ($path !== 'private/code-baseline.json' && is_file($absolute) && hash_file('sha256', $absolute) !== $file['sha256']) {
                $problems[] = '新版本文件与服务器上已存在的同名文件冲突：' . $path;
            }
        }
    }
    return $problems;
}

function updater_disk_problems(array $manifest): array {
    $problems = [];
    $needed = ($manifest['total_size'] ?? 0) * 2;
    foreach (['private', 'public'] as $key) {
        $free = @disk_free_space(updater_dir($key));
        if ($free === false || $free < $needed) {
            $problems[] = '磁盘空间不足（需要约 ' . (int)ceil($needed / 1048576) . 'MB 可用）。';
            break;
        }
    }
    $targets = [];
    foreach ($manifest['files'] as $file) { $targets[dirname(updater_dir('root') . '/' . $file['path'])] = true; }
    foreach ($manifest['deletions'] as $path) { $targets[dirname(updater_dir('root') . '/' . $path)] = true; }
    foreach (array_keys($targets) as $directory) {
        if (is_dir($directory)) {
            if (!is_writable($directory)) { $problems[] = '目录不可写：' . str_replace(updater_dir('root') . '/', '', $directory); }
            continue;
        }
        $parent = $directory;
        while (!is_dir($parent) && $parent !== dirname($parent)) { $parent = dirname($parent); }
        if (!is_writable($parent)) { $problems[] = '无法创建目标目录：' . str_replace(updater_dir('root') . '/', '', $directory); }
    }
    return $problems;
}

// ---------------------------------------------------------------------------
// Locks and the maintenance fence (P1).
// ---------------------------------------------------------------------------

function updater_lock(string $name, bool $blocking): mixed {
    $handle = @fopen(updater_dir('update') . '/' . $name, 'c');
    if ($handle === false) { throw new RuntimeException('Cannot open updater lock.'); }
    if (!flock($handle, LOCK_EX | ($blocking ? 0 : LOCK_NB))) { fclose($handle); return null; }
    return $handle;
}
function updater_unlock(mixed $handle): void { if (is_resource($handle)) { flock($handle, LOCK_UN); fclose($handle); } }

/** Test seam, inert in production: the fault driver defines ocpeptide_update_barrier
 *  in-process to abort the real pipeline at exact crash windows (journal vs disk).
 *  No shipped file ever defines it, and web requests cannot inject code. */
function updater_test_barrier(string $point, ?int $index = null): void {
    // CLI-only: web requests can never trigger test barriers.
    if (PHP_SAPI === 'cli' && function_exists('ocpeptide_update_barrier')) { ocpeptide_update_barrier($point, $index); }
}

/** Fixed lock order: update.lock, then the request gate, then the state lock. */
function updater_gate_file(): string { return updater_dir('update') . '/gate.lock'; }
function updater_acquire_gate(int $timeoutSeconds = 30): mixed {
    $handle = @fopen(updater_gate_file(), 'c');
    if ($handle === false) { throw new RuntimeException('Cannot open update gate.'); }
    $deadline = microtime(true) + $timeoutSeconds;
    while (true) {
        if (flock($handle, LOCK_EX | LOCK_NB)) { return $handle; }
        if (microtime(true) >= $deadline) { fclose($handle); throw new UpdateError('等待访问请求结束超时，请稍后重试。', 'gate_timeout', 503); }
        usleep(250000);
    }
}
function updater_acquire_state_lock(int $timeoutSeconds = 20): mixed {
    $handle = @fopen(updater_dir('data') . '/state.lock', 'c');
    if ($handle === false) { throw new RuntimeException('Cannot open state lock.'); }
    $deadline = microtime(true) + $timeoutSeconds;
    while (true) {
        if (flock($handle, LOCK_EX | LOCK_NB)) { return $handle; }
        if (microtime(true) >= $deadline) { fclose($handle); throw new UpdateError('等待业务写入完成超时，请稍后重试。', 'state_lock_timeout', 503); }
        usleep(200000);
    }
}

function updater_set_maintenance(array &$task): string {
    // The probe token lets fenced health checks reach the site during the
    // exclusive window; it is random per install and never logged.
    if (empty($task['probe_token'])) { $task['probe_token'] = bin2hex(random_bytes(16)); updater_save_task($task); }
    updater_json_write(updater_maintenance_file(), ['task_id' => $task['id'], 'since' => gmdate('c'),
                                                    'stage' => $task['stage'], 'probe_token' => $task['probe_token'],
                                                    'probe_expires' => time() + 300, 'probe_ready' => false]);
    return $task['probe_token'];
}
function updater_maintenance_probe_token(): ?string {
    $data = updater_read_json(updater_maintenance_file());
    return is_string($data['probe_token'] ?? null) ? $data['probe_token'] : null;
}
function updater_clear_maintenance(): void { @unlink(updater_maintenance_file()); }
/** Clear the fence only when it provably belongs to the given task. Orphan
 *  markers (lost or corrupt journals) are never cleared automatically: fail
 *  closed and let the recovery entry demand manual action. */
function updater_clear_maintenance_for(array $task): void {
    $marker = updater_read_json(updater_maintenance_file());
    if ($marker === null) { return; }
    if (($marker['task_id'] ?? '') === ($task['id'] ?? '')) { updater_clear_maintenance(); }
}

/** Best-effort crash recovery triggered by any fenced request; safe to repeat. */
function updater_try_autorecover(): void {
    try {
        $lock = updater_lock('update.lock', false);
        if ($lock === null) { return; } // Another process already owns the updater.
        try { updater_resolve_active_tasks(); }
        finally { updater_unlock($lock); }
    } catch (Throwable) { /* Persistent failures stay fenced; the recovery entry surfaces details. */ }
}

/**
 * @return string[] human readable actions taken
 */
function updater_resolve_active_tasks(): array {
    $actions = [];
    $now = time();
    // Exact marker-owner dispatch BEFORE any nonterminal resumption: while the
    // fence is up, only its EXACT owner journal decides. A terminal commit
    // owner (verified, or restored rolled_back/failed) is finished with its
    // stage's pinned proof side (proof/health/handoff/unfence) and resolve
    // returns without executing any other task, so a conflicting active task
    // can never run first and overwrite or unfence another task's marker.
    // Unknown, corrupt, mismatched or unsupported owners (e.g. aborted) stay
    // fenced for manual recovery. A recognized nonterminal owner falls
    // through, and while its marker remains recovery may act on that exact
    // owner only; the cached active list is scanned for a different task
    // BEFORE any task executes, so a conflict fails closed without late
    // discovery after writes.
    $fencedOwnerId = null;
    if (updater_maintenance_active()) {
        $marker = updater_read_bounded_json(updater_maintenance_file(), 8192);
        $ownerId = is_string($marker['task_id'] ?? null) ? $marker['task_id'] : '';
        $owner = ($marker !== null && preg_match('/^[a-f0-9]{24}$/D', $ownerId))
            ? updater_read_bounded_json(updater_task_path($ownerId), CHANNEL_PAYLOAD_MAX_BYTES) : null;
        if ($owner === null || ($owner['id'] ?? '') !== $ownerId) { return $actions; }
        $ownerStage = (string)($owner['stage'] ?? '');
        if (in_array($ownerStage, ['verified', 'rolled_back', 'failed'], true)) {
            return updater_finish_terminal_fence();
        }
        if (in_array($ownerStage, updater_terminal_stages(), true)) { return $actions; }
        if (!in_array($ownerStage, ['recovery_required', 'prepared', 'backing_up', 'backed_up', 'applying', 'applied', 'rolling_back'], true)) {
            return $actions; // Unknown stage in the owner journal: fenced.
        }
        $fencedOwnerId = $ownerId;
    }
    $activeTasks = updater_active_tasks();
    if ($fencedOwnerId !== null) {
        foreach ($activeTasks as $task) {
            if ((string)($task['id'] ?? '') !== $fencedOwnerId) {
                return $actions; // Marker plus a different active task: conflict, fail closed.
            }
        }
    }
    foreach ($activeTasks as $task) {
        $stage = $task['stage'] ?? '';
        $age = $now - strtotime((string)($task['updated_at'] ?? 'now'));
        if ($stage === 'recovery_required') { continue; } // Manual recovery; its fence stays up.
        if (in_array($stage, ['prepared', 'backing_up'], true)) {
            // Only cancel clearly interrupted tasks. An in-flight installer
            // holds update.lock and never overlaps this code; the small gap
            // between staging and execute is covered by the freshness age.
            if ($stage === 'prepared' && $age < 120) { continue; }
            updater_discard_staging($task);
            $task['stage'] = 'aborted';
            $task['error'] = $task['error'] ?? '安装过程被中断且尚未修改任何代码，已自动取消；请重新发起更新。';
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
            updater_clear_maintenance_for($task);
            $actions[] = 'aborted stale untouched task';
        } elseif (in_array($stage, ['backed_up', 'applying', 'applied'], true)) {
            updater_execute_task($task['id'], 'auto-recovery', true);
            $actions[] = 'resumed task';
        } elseif ($stage === 'rolling_back') {
            updater_rollback_task($task['id'], 'auto-recovery', true);
            $actions[] = 'resumed rollback';
        }
    }
    // No orphan-fence auto-clear: a marker without a resolvable task journal
    // (deleted or corrupt) stays up and must be resolved via the recovery
    // entry / host panel. Clearing always requires a durable task-scoped
    // reason (aborted / verified / failed / rolled_back) elsewhere.
    return $actions;
}

/** Finish an OWNED fence whose exact owner journal is a terminal commit: the
 *  installer died between persisting the terminal stage (verified, or the
 *  restored rolled_back/failed) and clearing its own marker. The marker and
 *  its owner journal are re-read while holding update.lock (caller) and then
 *  the exclusive request gate, in the established lock order. The stage maps
 *  to an explicit pinned proof side — verified proves the TARGET tree,
 *  rolled_back/failed prove the complete prior SOURCE tree; a failed label
 *  never falls back to target trust. Only a bounded, canonical marker
 *  pointing at its own terminal journal with no conflicting active task is
 *  finished: the complete pinned proof runs before any live PHP health probe
 *  and again immediately before the protected engine adopts the proven bytes
 *  and the fence opens. Every other outcome (corrupt or mismatched evidence,
 *  failed proof/health/handoff) keeps the fence up; retrying via ordinary
 *  requests is idempotent. The original failure cause on a restored terminal
 *  'failed' journal is preserved: that site runs its previous version, not a
 *  successful new-version installation. */
function updater_finish_terminal_fence(): array {
    if (!updater_maintenance_active()) { return []; }
    $gate = null;
    try {
        $gate = updater_acquire_gate();
        $marker = updater_read_bounded_json(updater_maintenance_file(), 8192);
        $ownerId = is_string($marker['task_id'] ?? null) ? $marker['task_id'] : '';
        if ($marker === null || !preg_match('/^[a-f0-9]{24}$/D', $ownerId)) { return []; }
        $task = updater_read_bounded_json(updater_task_path($ownerId), CHANNEL_PAYLOAD_MAX_BYTES);
        $stage = (string)($task['stage'] ?? '');
        if ($task === null || ($task['id'] ?? '') !== $ownerId
            || !in_array($stage, ['verified', 'rolled_back', 'failed'], true)) { return []; }
        $side = $stage === 'verified' ? 'target' : 'source';
        $phase = $side === 'target' ? '提交后' : '恢复后';
        if (updater_active_tasks() !== []) { return []; }
        // Complete pinned proof on the stage's own side BEFORE any live PHP
        // health probe.
        $integrity = updater_pinned_tree_problems($task, $side);
        if ($integrity !== []) {
            $task['stage'] = 'recovery_required';
            $reason = $phase . '完整性复核失败，网站保持维护状态，需要人工恢复：' . implode('；', $integrity);
            $task['error'] = ($task['error'] ?? null) === null ? $reason : $task['error'] . '；' . $reason;
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
            return [];
        }
        // Re-arm the owned probe capability when its window expired while the
        // installer was down; the token itself is never logged.
        $probeToken = is_string($marker['probe_token'] ?? null) ? $marker['probe_token'] : '';
        $taskToken = is_string($task['probe_token'] ?? null) ? $task['probe_token'] : '';
        if ($probeToken !== '' && $taskToken !== '' && !hash_equals($taskToken, $probeToken)) { return []; }
        if ($probeToken === '' || (int)($marker['probe_expires'] ?? 0) <= time()) {
            $probeToken = updater_set_maintenance($task);
        }
        $probe = updater_self_probe($probeToken, false);
        $failed = array_values(array_filter($probe, static fn(array $check): bool => $check['ok'] !== true));
        if ($failed !== []) {
            // Behavior unproven but no disk evidence of corruption: keep the
            // owned fence; the next request retries. The original failure
            // cause on a restored 'failed' journal is never overwritten.
            if (($task['error'] ?? null) === null) {
                $task['error'] = $phase . '健康检查未通过，保持维护围栏等待下次请求重试：'
                    . implode('；', array_map(static fn(array $check): string => $check['label'], $failed));
                $task['updated_at'] = gmdate('c');
                updater_save_task($task);
            }
            return [];
        }
        // Nothing may change underneath while the probe ran.
        $integrity = updater_pinned_tree_problems($task, $side);
        if ($integrity !== []) {
            $task['stage'] = 'recovery_required';
            $reason = $phase . '完整性二次复核失败，网站保持维护状态，需要人工恢复：' . implode('；', $integrity);
            $task['error'] = ($task['error'] ?? null) === null ? $reason : $task['error'] . '；' . $reason;
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
            return [];
        }
        // The protected engine adopts the freshly proven live bytes BEFORE
        // the fence opens; the expected digest comes from the proven baseline
        // of this stage's own side.
        $engineSha = updater_pinned_engine_sha256($task['identity_pin'][$side] ?? null);
        if ($engineSha === null) {
            $task['stage'] = 'recovery_required';
            $reason = '无法从已证明的' . ($side === 'target' ? '目标' : '旧') . '基线确定保护引擎哈希，网站保持维护状态，需要人工恢复。';
            $task['error'] = ($task['error'] ?? null) === null ? $reason : $task['error'] . '；' . $reason;
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
            return [];
        }
        if (updater_path_escapes_root('private/update/protected/updater-engine.php')) {
            throw new RuntimeException('Protected engine destination escapes the trusted runtime tree.');
        }
        updater_copy_file(updater_dir('private') . '/updater-engine.php',
                          updater_dir('update') . '/protected/updater-engine.php', $engineSha);
        // Transient handoff-era errors may clear after the confirmed finish —
        // except on 'failed', whose original failure cause must survive.
        if ($stage !== 'failed' && ($task['error'] ?? null) !== null) {
            $task['error'] = null;
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
        }
        updater_clear_maintenance_for($task);
        clearstatcache(true, updater_maintenance_file());
        if (file_exists(updater_maintenance_file()) || is_link(updater_maintenance_file())) {
            return []; // Unlink failed or marker changed: preserve staging and retry later.
        }
        if (($task['staging_dir'] ?? null) === 'staging/' . $ownerId
            && !updater_path_escapes_root('private/update/staging/' . $ownerId)) {
            updater_discard_staging($task);
        }
        return ['finished interrupted ' . $stage . ' commit'];
    } catch (Throwable) {
        return []; // Keep the owned marker up; the next ordinary request retries.
    } finally {
        if ($gate !== null) { updater_unlock($gate); }
    }
}

// ---------------------------------------------------------------------------
// Install pipeline (P1).
// ---------------------------------------------------------------------------

function updater_discard_staging(array $task): void {
    $relative = (string)($task['staging_dir'] ?? '');
    if (preg_match('~^staging/[a-f0-9]+$~D', $relative)) { updater_rm_dir(updater_dir('update') . '/' . $relative); }
}

function updater_rm_dir(string $dir): void {
    // Every caller owns an updater child, never the runtime root itself or
    // customer folders. Check the lexical boundary and every real ancestor
    // before traversal; a junction at the removal root must never be followed.
    $base = rtrim(str_replace('\\', '/', updater_dir('update')), '/') . '/';
    $logical = str_replace('\\', '/', $dir);
    $inside = DIRECTORY_SEPARATOR === '\\'
        ? strncasecmp($logical, $base, strlen($base)) === 0 : str_starts_with($logical, $base);
    $relative = $inside ? substr($logical, strlen($base)) : '';
    if (!$inside || $relative === '' || is_link($dir)) { throw new RuntimeException('Unsafe updater removal root.'); }
    foreach (explode('/', $relative) as $part) {
        if ($part === '' || $part === '.' || $part === '..' || str_contains($part, ':')) { throw new RuntimeException('Unsafe updater removal path.'); }
    }
    if (updater_path_escapes_root('private/update/' . $relative)) { throw new RuntimeException('Updater removal path escapes the site root.'); }
    if (!is_dir($dir)) { return; }
    $entries = scandir($dir);
    if ($entries === false) { throw new RuntimeException('Cannot list updater removal directory.'); }
    foreach ($entries as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $path = $dir . '/' . $name;
        if (updater_path_escapes_root('private/update/' . $relative . '/' . $name)) { throw new RuntimeException('Linked updater artifact is not removable automatically.'); }
        if (is_dir($path)) { updater_rm_dir($path); }
        elseif (!@unlink($path) && (file_exists($path) || is_link($path))) { throw new RuntimeException('Cannot remove updater artifact.'); }
    }
    if (!@rmdir($dir) && is_dir($dir)) { throw new RuntimeException('Cannot remove updater directory.'); }
}
function updater_dir_bytes(string $dir): int {
    if (!is_dir($dir)) { return 0; }
    $total = 0;
    foreach (scandir($dir) ?: [] as $name) {
        if ($name === '.' || $name === '..') { continue; }
        $path = $dir . '/' . $name;
        $total += is_dir($path) && !is_link($path) ? updater_dir_bytes($path) : (int)(@filesize($path) ?: 0);
    }
    return $total;
}
function updater_copy_file(string $source, string $target, ?string $expectSha = null): void {
    $directory = dirname($target);
    if (!is_dir($directory) && !@mkdir($directory, 0755, true) && !is_dir($directory)) { throw new RuntimeException('Cannot create directory: ' . $directory); }
    $temp = $target . '.updater-' . bin2hex(random_bytes(4));
    if (!@copy($source, $temp)) { throw new RuntimeException('Cannot copy file to ' . $target); }
    if ($expectSha !== null && hash_file('sha256', $temp) !== $expectSha) { @unlink($temp); throw new UpdateError('复制文件校验失败：' . $target, 'copy_hash', 500); }
    if (!@rename($temp, $target)) { @unlink($temp); throw new RuntimeException('Cannot replace file: ' . $target); }
    @chmod($target, 0644);
}

/**
 * Validate + stage a package into private/update/staging and create the task
 * journal. Performs zero modifications outside private/update.
 * @return array task
 */
function updater_start_task(string $zipPath, string $actor, string $mode, bool $holdsLock = false, ?string $expectedVersion = null): array {
    if (!function_exists('curl_init')) { throw new UpdateError('请先启用 PHP cURL 扩展，安装尚未开始。', 'curl_missing', 503); }
    updater_ensure_dirs();
    $lock = $holdsLock ? null : updater_lock('update.lock', false);
    if (!$holdsLock && $lock === null) { throw new UpdateError('已有更新任务在进行中。', 'busy', 409); }
    $zip = null;
    $ownedStaging = null;
    $preparedSaved = false;
    try {
        updater_resolve_active_tasks(); // Crash cleanup precedes any new task.
        $package = updater_open_package($zipPath, null);
        $zip = $package['zip'];
        $package = updater_validate_manifest($package['manifest'], $package['zip']);
        $manifest = $package['manifest'];
        $zip = $package['zip'];
        if ($expectedVersion !== null && $manifest['version'] !== $expectedVersion) {
            throw new UpdateError('更新包内版本与选定的签名发行版本不一致。', 'package_mismatch', 422);
        }
        $oldBaseline = updater_read_bounded_json(updater_baseline_path(), CHANNEL_PAYLOAD_MAX_BYTES);
        $info = updater_code_info();
        $shape = updater_baseline_shape_problems($oldBaseline, $info['product'], $info['version']);
        if ($shape) { throw new UpdateError('可信基线无效：' . implode('；', $shape), 'preflight_failed', 422); }
        $problems = updater_preflight($manifest, $oldBaseline);
        if ($problems) { throw new UpdateError('更新预检未通过：' . implode('；', $problems), 'preflight_failed', 422, ['problems' => $problems]); }
        $id = bin2hex(random_bytes(12));
        $staging = 'staging/' . $id;
        $stagingDir = updater_dir('update') . '/' . $staging;
        // Claim only an exclusively created task parent, never an existing collision.
        if (!@mkdir($stagingDir, 0700)) { throw new RuntimeException('Cannot exclusively create staging directory.'); }
        $ownedStaging = $stagingDir;
        if (!@mkdir($stagingDir . '/payload', 0700)) { throw new RuntimeException('Cannot create staging payload directory.'); }
        $payloadRoot = realpath($stagingDir . '/payload');
        try {
            $ops = [];
            foreach ($manifest['files'] as $file) {
                $entry = 'payload/' . $file['path'];
                $stream = $zip->getStream($entry);
                if ($stream === false) { throw new UpdateError('无法读取更新包内文件：' . $file['path'], 'zip_read', 422); }
                $target = $stagingDir . '/payload/' . $file['path'];
                if (!is_dir(dirname($target)) && !@mkdir(dirname($target), 0700, true)) { throw new RuntimeException('Cannot create staging directory.'); }
                // Defense in depth: every extracted path stays inside staging.
                $resolvedDirectory = realpath(dirname($target));
                if ($payloadRoot === false || $resolvedDirectory === false
                    || !str_starts_with($resolvedDirectory, $payloadRoot . DIRECTORY_SEPARATOR)) {
                    throw new UpdateError('解压路径越界，已拒绝。', 'zip_traversal', 422);
                }
                $written = 0;
                $out = @fopen($target . '.part', 'wb');
                if ($out === false) { fclose($stream); throw new RuntimeException('Cannot write staging file.'); }
                try {
                    while (!feof($stream)) {
                        $chunk = fread($stream, 1 << 20);
                        if ($chunk === false || ($chunk !== '' && fwrite($out, $chunk) !== strlen($chunk))) { throw new RuntimeException('Staging write failed.'); }
                        $written += strlen((string)$chunk);
                        if ($written > 64 * 1024 * 1024) { throw new UpdateError('解压文件超过大小上限。', 'zip_bomb', 422); }
                    }
                } finally { fclose($stream); fclose($out); }
                if (!@rename($target . '.part', $target)) { throw new RuntimeException('Cannot finalize staging file.'); }
                if (hash_file('sha256', $target) !== $file['sha256'] || (int)filesize($target) !== $file['size']) {
                    throw new UpdateError('解压文件与清单哈希不一致：' . $file['path'], 'content_mismatch', 422);
                }
                $current = updater_dir('root') . '/' . $file['path'];
                $ops[] = ['path' => $file['path'], 'sha256' => $file['sha256'], 'size' => $file['size'], 'delete' => false,
                          'prior_sha256' => is_file($current) ? hash_file('sha256', $current) : null];
            }
            foreach ($manifest['deletions'] as $path) {
                if (!isset($oldBaseline['files'][$path]) || !updater_allowed_path($path) || updater_path_escapes_root($path)) {
                    throw new UpdateError('删除目标不属于安全的旧可信基线：' . $path, 'staged_baseline', 422);
                }
                $current = updater_dir('root') . '/' . $path;
                if (!is_file($current)) { throw new UpdateError('要删除的文件不存在：' . $path, 'delete_missing', 422); }
                $ops[] = ['path' => $path, 'sha256' => null, 'size' => 0, 'delete' => true, 'prior_sha256' => hash_file('sha256', $current)];
            }
            // Signed target baseline semantics: validated from fully staged
            // bytes BEFORE any prepared task, backup or live change exists.
            $stagedProblems = updater_staged_baseline_problems($manifest, $oldBaseline, $stagingDir . '/payload');
            if ($stagedProblems) {
                throw new UpdateError('签名目标基线校验未通过：' . implode('；', $stagedProblems), 'staged_baseline', 422, ['problems' => $stagedProblems]);
            }
            // Identity pin for the later complete-tree proofs: the signed new
            // baseline digest and the freshly hashed trusted OLD baseline
            // digest both come from the ops above (signed package data), never
            // from the mutable live baseline read at proof time.
            $pinTarget = null; $pinSource = null;
            foreach ($ops as $op) {
                if ($op['path'] === 'private/code-baseline.json') { $pinTarget = $op['sha256']; $pinSource = $op['prior_sha256']; }
            }
            if (!is_string($pinTarget) || !is_string($pinSource)) {
                throw new UpdateError('无法从签名包固定基线身份哈希，拒绝创建任务。', 'identity_pin', 422);
            }
        } catch (Throwable $error) {
            if (isset($zip) && $zip instanceof ZipArchive) { try { $zip->close(); } catch (Throwable) {} }
            updater_rm_dir($stagingDir);
            throw $error;
        }
        $zip->close();
        $zip = null;
        $task = ['id' => $id, 'created_at' => gmdate('c'), 'updated_at' => gmdate('c'), 'actor' => $actor, 'mode' => $mode,
                 'from_version' => $info['version'], 'to_version' => $manifest['version'],
                 'package_sha256' => hash_file('sha256', $zipPath), 'notes' => updater_truncate($manifest['notes'], 500),
                 'stage' => 'prepared', 'ops_done' => 0, 'rb_done' => 0, 'ops' => $ops,
                 'identity_pin' => ['source' => ['product' => $info['product'], 'version' => $info['version'], 'baseline_sha256' => $pinSource],
                                    'target' => ['product' => $manifest['product'], 'version' => $manifest['version'], 'baseline_sha256' => $pinTarget]],
                 'staging_dir' => $staging, 'backup_dir' => 'backups/' . $id,
                 'error' => null, 'verified_by' => null, 'checks' => null];
        updater_save_task($task);
        $preparedSaved = true;
        return $task;
    } finally {
        try {
            if ($zip instanceof ZipArchive) { try { $zip->close(); } catch (Throwable) {} }
            if (!$preparedSaved && $ownedStaging !== null) { updater_rm_dir($ownedStaging); }
        } finally { if ($lock !== null) { updater_unlock($lock); } }
    }
}

/**
 * Fenced, journalled apply: full backup, then reversible per-file swaps while
 * holding the business state lock. Safe to resume after a crash at any point.
 */
function updater_execute_task(string $taskId, string $actor, bool $holdsLock = false): array {
    $task = updater_load_task($taskId);
    // 'applied' re-entry finishes the missing health/commit phase after a crash
    // instead of silently reopening an unverified installation.
    if (!in_array($task['stage'], ['prepared', 'backed_up', 'applying', 'applied'], true)) {
        throw new UpdateError('任务已处于阶段 ' . $task['stage'] . '，无需重复执行。', 'wrong_stage', 409);
    }
    updater_ensure_dirs();
    $lock = null;
    if (!$holdsLock) {
        $lock = updater_lock('update.lock', false);
        if ($lock === null) { throw new UpdateError('已有更新任务在进行中。', 'busy', 409); }
        // Locks serialize writers: re-read the journal before acting on it.
        $task = updater_load_task($taskId);
        if (!in_array($task['stage'], ['prepared', 'backed_up', 'applying', 'applied'], true)) {
            throw new UpdateError('任务已处于阶段 ' . $task['stage'] . '，无需重复执行。', 'wrong_stage', 409);
        }
    }
    $root = updater_dir('root');
    $stagingRoot = updater_dir('update') . '/' . $task['staging_dir'] . '/payload';
    $backupRoot = updater_dir('update') . '/' . $task['backup_dir'];
    $stateLock = null;
    $gate = null;
    try {
        // Recheck the persisted operation paths before any backup/apply work.
        if (!is_array($task['ops'] ?? null)) { throw new UpdateError('任务操作记录损坏。', 'forbidden_path', 422); }
        foreach ($task['ops'] as $op) {
            if (!is_array($op) || !is_string($op['path'] ?? null) || !updater_allowed_path($op['path'])) {
                throw new UpdateError('任务包含受保护或非法的更新路径。', 'forbidden_path', 422);
            }
        }
        if (!in_array($task['stage'], ['backed_up', 'applying', 'applied'], true)) {
            $task['stage'] = 'backing_up';
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
        }
        // Backup phase only reads live code; the site keeps serving normally.
        if ($task['stage'] === 'backing_up') {
            if (is_dir($backupRoot)) { updater_rm_dir($backupRoot); } // Restart a torn backup from scratch.
            foreach ($task['ops'] as $index => $op) {
                $source = $root . '/' . $op['path'];
                if ($op['prior_sha256'] === null) { continue; }
                updater_test_barrier('before-backup-op', $index);
                if (!is_file($source)) { throw new UpdateError('备份时源文件缺失：' . $op['path'], 'backup_source_missing', 500); }
                updater_copy_file($source, $backupRoot . '/' . $op['path'], $op['prior_sha256']);
                updater_test_barrier('after-backup-op', $index);
            }
            updater_json_write($backupRoot . '/backup.json', ['task_id' => $task['id'], 'ops' => count($task['ops'])]);
            $task['stage'] = 'backed_up';
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
            updater_test_barrier('backed-up');
        }
        // Ensure a known-good protected engine exists BEFORE any swap. It is
        // seeded from the currently running engine and only replaced with the
        // new code after that release has fully passed its health checks.
        $protectedEngine = updater_dir('update') . '/protected/updater-engine.php';
        if (!is_file($protectedEngine) && is_file(__FILE__)) { updater_copy_file(__FILE__, $protectedEngine); }
        // Fence every business request (sessions, uploads, cursor) for the swap.
        $gate = updater_acquire_gate();
        $probeToken = updater_set_maintenance($task);
        $stateLock = updater_acquire_state_lock();
        if ($task['stage'] !== 'applied') {
            $task['stage'] = 'applying';
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
        }
        updater_test_barrier('fence-held');
        for ($index = (int)$task['ops_done'], $total = count($task['ops']); $index < $total; $index++) {
            $op = $task['ops'][$index];
            // Journal position is persisted before each destructive step.
            $task['ops_done'] = $index;
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
            updater_test_barrier('before-op', $index);
            $target = $root . '/' . $op['path'];
            if ($op['delete']) {
                if (is_file($target) && !@unlink($target)) { throw new RuntimeException('Cannot delete ' . $op['path']); }
            } else {
                updater_copy_file($stagingRoot . '/' . $op['path'], $target, $op['sha256']);
            }
            updater_test_barrier('after-op', $index);
            $task['ops_done'] = $index + 1;
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
        }
        updater_unlock($stateLock); $stateLock = null;
        if (function_exists('opcache_reset')) { @opcache_reset(); }
        // Durable applied stage is committed while still fenced; the commit
        // (verified + unfence) happens only after the health checks pass.
        if ($task['stage'] !== 'applied') {
            $task['stage'] = 'applied';
            $task['error'] = null;
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
        }
        updater_test_barrier('after-applied');
        // Complete trusted-target proof runs BEFORE any unverified target PHP
        // executes: an HTTP 200 or doctype can never certify disk bytes, and
        // the live baseline never vouches for itself. The proof is repeated
        // immediately before commit so nothing may change underneath while
        // the health probe ran; any failure routes into the fenced restore.
        $integrity = updater_pinned_tree_problems($task, 'target');
        $probe = [];
        if ($integrity === []) {
            // Mandatory behavior health checks run before traffic is
            // re-exposed; every check must be strictly true, skipped checks
            // count as failure.
            $probe = updater_self_probe($probeToken, false);
            $integrity = updater_pinned_tree_problems($task, 'target');
        }
        if ($integrity !== []) {
            $probe = [['label' => '安装文件完整性', 'ok' => false, 'detail' => implode('；', $integrity)]];
        }
        $task['checks'] = $probe;
        $task['updated_at'] = gmdate('c');
        updater_save_task($task);
        $failed = array_values(array_filter($probe, static fn(array $check): bool => $check['ok'] !== true));
        if ($failed !== []) {
            // Fail closed: restore the previous code inside the same fence.
            $stateLock = updater_acquire_state_lock();
            $failureMessage = '更新后健康检查失败：' . implode('；', array_map(static fn(array $check): string => $check['label'], $failed));
            $task['installation_failure'] = ['message' => $failureMessage, 'checks' => $probe];
            $task['error'] = $failureMessage;
            $task['stage'] = 'rolling_back';
            updater_save_task($task);
            try { updater_restore_ops($task, $backupRoot, $root); }
            catch (Throwable $restoreError) {
                $task['stage'] = 'recovery_required';
                $task['error'] = $failureMessage . '；回滚中断：' . $restoreError->getMessage();
                $task['updated_at'] = gmdate('c');
                updater_save_task($task);
                throw $restoreError;
            }
            if (function_exists('opcache_reset')) { @opcache_reset(); }
            updater_unlock($stateLock); $stateLock = null;
            // The complete OLD tree must be proven from the pinned prior
            // baseline BEFORE any restored live PHP is probed, and again
            // immediately before the fence may open: an ops-only restore can
            // never repair corruption in files outside the manifest delta,
            // and nothing may change underneath while the probe ran.
            $restoreProblems = updater_pinned_tree_problems($task, 'source');
            $restored = $restoreProblems === [] ? updater_self_probe($probeToken, false) : [];
            if ($restoreProblems === []) {
                $restoreProblems = updater_pinned_tree_problems($task, 'source');
                if ($restoreProblems !== []) { $restored = []; }
            }
            if ($restoreProblems !== []) {
                $restored[] = ['label' => '回滚文件完整性', 'ok' => false, 'detail' => implode('；', $restoreProblems)];
            }
            $task['checks'] = array_merge($probe, $restored);
            if (array_filter($restored, static fn(array $check): bool => $check['ok'] !== true)) {
                $task['stage'] = 'recovery_required';
                $task['error'] = $failureMessage . '；回滚后的代码未通过完整性或健康检查，网站保持维护状态，需要人工恢复。';
                $task['updated_at'] = gmdate('c');
                updater_save_task($task);
                throw new UpdateError($task['error'], 'recovery_required', 500);
            }
            $task['stage'] = 'failed';
            $task['error'] = $failureMessage . '；已自动回滚到 ' . $task['from_version'] . '。';
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
            updater_test_barrier('restore-committed');
            // Same handoff-before-unfence ordering as the verified commit, but
            // the expected digest comes from the PROVEN prior-version baseline
            // (source identity pin): a restored terminal never trusts target
            // bytes. A failed handoff/unfence keeps this owned fence up for
            // the terminal-commit recovery to finish idempotently.
            $engineSha = updater_pinned_engine_sha256($task['identity_pin']['source'] ?? null);
            if ($engineSha === null) {
                throw new UpdateError('无法从已证明的旧基线确定保护引擎哈希，拒绝开放流量。', 'protected_engine_hash', 500);
            }
            if (updater_path_escapes_root('private/update/protected/updater-engine.php')) {
                throw new RuntimeException('Protected engine destination escapes the trusted runtime tree.');
            }
            updater_copy_file($root . '/private/updater-engine.php', $protectedEngine, $engineSha);
            updater_clear_maintenance_for($task);
            clearstatcache(true, updater_maintenance_file());
            if (file_exists(updater_maintenance_file()) || is_link(updater_maintenance_file())) {
                // The unlink silently failed or the marker changed underneath:
                // keep the fence so recovery retries instead of reporting a
                // finished restore that is still fenced.
                throw new UpdateError('维护标记清除失败，网站保持围栏，等待自动恢复重试。', 'commit_unfence', 500);
            }
            updater_unlock($gate); $gate = null;
            if (($task['staging_dir'] ?? null) === 'staging/' . $task['id']
                && !updater_path_escapes_root('private/update/staging/' . $task['id'])) {
                updater_discard_staging($task);
            }
            return $task;
        }
        // Commit while fenced: verified is durable before traffic reopens.
        $task['stage'] = 'verified';
        $task['verified_by'] = 'server';
        $task['updated_at'] = gmdate('c');
        updater_save_task($task);
        updater_test_barrier('commit-verified');
        // The protected engine adopts the freshly proven live bytes BEFORE
        // the fence opens, so a crash can never reopen traffic with the
        // recovery-engine adoption still pending. The expected digest comes
        // from the baseline the pinned proof just verified twice; a failed
        // handoff keeps this owned fence up for the verified-commit recovery
        // to finish idempotently.
        $engineSha = updater_pinned_engine_sha256($task['identity_pin']['target'] ?? null);
        if ($engineSha === null) {
            throw new UpdateError('无法从已证明的目标基线确定保护引擎哈希，拒绝开放流量。', 'protected_engine_hash', 500);
        }
        if (updater_path_escapes_root('private/update/protected/updater-engine.php')) {
            throw new RuntimeException('Protected engine destination escapes the trusted runtime tree.');
        }
        updater_copy_file($root . '/private/updater-engine.php', $protectedEngine, $engineSha);
        updater_clear_maintenance_for($task);
        clearstatcache(true, updater_maintenance_file());
        if (file_exists(updater_maintenance_file()) || is_link(updater_maintenance_file())) {
            // The unlink silently failed or the marker changed underneath:
            // never report verified or clean evidence while the site stays
            // fenced; the owned-marker recovery retries the unfence.
            throw new UpdateError('维护标记清除失败，网站保持围栏，等待自动恢复重试。', 'commit_unfence', 500);
        }
        updater_unlock($gate); $gate = null;
        if (($task['staging_dir'] ?? null) === 'staging/' . $task['id']
            && !updater_path_escapes_root('private/update/staging/' . $task['id'])) {
            updater_discard_staging($task);
        }
        return $task;
    } catch (Throwable $error) {
        if ($stateLock !== null) { updater_unlock($stateLock); }
        if ($gate !== null) { updater_unlock($gate); }
        // A completed automatic restore still represents a failed install.
        // Keep that original cause if the protected handoff or unfence fails.
        $origin = $task['installation_failure']['message'] ?? null;
        if (is_string($origin) && $origin !== '' && !str_contains($error->getMessage(), $origin)) {
            $task['error'] = (is_string($task['error'] ?? null) && str_contains($task['error'], $origin) ? $task['error'] : $origin)
                . '；' . $error->getMessage();
        } elseif ($task['stage'] === 'failed' && is_string($task['error'] ?? null) && $task['error'] !== '') {
            $task['error'] .= '；' . $error->getMessage();
        } else {
            $task['error'] = $error->getMessage();
        }
        $task['updated_at'] = gmdate('c');
        updater_save_task($task);
        if (!in_array($task['stage'], ['applying', 'applied', 'verified', 'failed', 'rolling_back', 'recovery_required'], true)) {
            // Nothing was swapped yet (prepared/backing_up/backed_up): drop
            // our own fence; auto-recovery later aborts or resumes the task.
            updater_clear_maintenance_for($task);
        }
        // applying/applied/rolling_back/recovery_required keep the fence up:
        // mixed or unverified code must not serve; the next request resumes.
        // 'verified' and restored 'failed' keep it up too: a failure after the
        // terminal journal belongs to the protected handoff / unfence window,
        // exactly what updater_finish_terminal_fence
        // retries under the owned marker.
        throw $error;
    } finally { if ($lock !== null) { updater_unlock($lock); } }
}

/** Keep a known-good engine copy outside the updatable tree for recovery.
 *  Callers pass an explicitly trusted source: the currently running engine
 *  before a swap, or the live tree only after that release passed all health
 *  checks. Unverified staging code is never installed here. */
function updater_refresh_protected_engine(string $source): void {
    $protected = updater_dir('update') . '/protected';
    if (!is_dir($protected) && !@mkdir($protected, 0700, true) && !is_dir($protected)) { throw new RuntimeException('Cannot create protected directory.'); }
    if (is_file($source)) { updater_copy_file($source, $protected . '/updater-engine.php'); }
}

/** Restore the backup over live code; journalled per op and resumable. */
function updater_restore_ops(array &$task, string $backupRoot, string $root): void {
    // Record that destructive restore writes may now exist BEFORE the first
    // one happens, so a crash between the write and the rb_done journal
    // update can never be mistaken for a not-yet-started rollback.
    if (empty($task['rb_started'])) {
        $task['rb_started'] = true;
        $task['updated_at'] = gmdate('c');
        updater_save_task($task);
    }
    $reverse = array_reverse($task['ops']);
    $done = (int)($task['rb_done'] ?? 0);
    foreach (array_slice($reverse, $done) as $op) {
        if (!is_array($op) || !is_string($op['path'] ?? null) || !updater_allowed_path($op['path'])) {
            throw new UpdateError('回滚任务包含受保护或非法路径。', 'forbidden_path', 422);
        }
        updater_test_barrier('before-rollback-op', $done);
        $target = $root . '/' . $op['path'];
        if ($op['prior_sha256'] === null) {
            // File did not exist before the update: remove the added file.
            if (is_file($target) && !@unlink($target)) { throw new RuntimeException('Cannot remove added file ' . $op['path']); }
        } else {
            $backup = $backupRoot . '/' . $op['path'];
            if (!is_file($backup)) { throw new UpdateError('回滚备份文件缺失：' . $op['path'], 'backup_missing', 500); }
            updater_copy_file($backup, $target, $op['prior_sha256']);
        }
        updater_test_barrier('after-rollback-op', $done);
        $done++;
        $task['rb_done'] = $done;
        $task['updated_at'] = gmdate('c');
        updater_save_task($task);
    }
}

function updater_rollback_task(string $taskId, string $actor, bool $holdsLock = false): array {
    $task = updater_load_task($taskId);
    if (!in_array($task['stage'], ['applying', 'applied', 'verified', 'failed', 'rolling_back'], true)) {
        throw new UpdateError('当前阶段 ' . $task['stage'] . ' 不允许回滚。', 'wrong_stage', 409);
    }
    // Only a previously verified (or restored-and-verified) state justifies an
    // unfenced abort when the backup turns out to be unusable BEFORE anything
    // was written. 'applied' is unverified and any started restore leaves the
    // tree potentially mixed, so those cases must stay fenced for recovery.
    $initialStage = $task['stage'];
    $rbBefore = (int)($task['rb_done'] ?? 0);
    updater_ensure_dirs();
    $lock = null;
    if (!$holdsLock) {
        $lock = updater_lock('update.lock', false);
        if ($lock === null) { throw new UpdateError('已有更新任务在进行中。', 'busy', 409); }
        // Locks serialize writers: re-read the journal before acting on it.
        $task = updater_load_task($taskId);
        if (!in_array($task['stage'], ['applying', 'applied', 'verified', 'failed', 'rolling_back'], true)) {
            throw new UpdateError('当前阶段 ' . $task['stage'] . ' 不允许回滚。', 'wrong_stage', 409);
        }
        $initialStage = $task['stage'];
    }
    $stateLock = null;
    $gate = null;
    $installationFailure = $task['installation_failure'] ?? null;
    try {
        if ($installationFailure !== null && (!is_array($installationFailure) || !is_string($installationFailure['message'] ?? null)
            || $installationFailure['message'] === '' || !is_array($installationFailure['checks'] ?? null)
            || !array_filter($installationFailure['checks'], static fn(mixed $check): bool => is_array($check) && ($check['ok'] ?? null) === false))) {
            $task['stage'] = 'recovery_required';
            throw new UpdateError('原始安装失败记录损坏，网站保持维护，请人工核对。', 'recovery_required', 500);
        }
        $root = updater_dir('root');
        $backupRoot = updater_dir('update') . '/' . $task['backup_dir'];
        if (!is_file($backupRoot . '/backup.json')) {
            throw new UpdateError('备份缺失或不完整，无法自动回滚；请按文档使用主机面板手动恢复。', 'backup_missing', 500);
        }
        $task['stage'] = 'rolling_back';
        $task['updated_at'] = gmdate('c');
        updater_save_task($task);
        $gate = updater_acquire_gate();
        $probeToken = updater_set_maintenance($task);
        $stateLock = updater_acquire_state_lock();
        updater_restore_ops($task, $backupRoot, $root);
        if (function_exists('opcache_reset')) { @opcache_reset(); }
        updater_unlock($stateLock); $stateLock = null;
        // The complete OLD tree must be proven from the pinned prior baseline
        // BEFORE any restored live PHP is probed, and again immediately before
        // the fence may open: an ops-only restore can never repair corruption
        // in files outside the manifest delta, and nothing may change
        // underneath while the probe ran.
        $restoreProblems = updater_pinned_tree_problems($task, 'source');
        $restored = $restoreProblems === [] ? updater_self_probe($probeToken, false) : [];
        if ($restoreProblems === []) {
            $restoreProblems = updater_pinned_tree_problems($task, 'source');
            if ($restoreProblems !== []) { $restored = []; }
        }
        if ($restoreProblems !== []) {
            $restored[] = ['label' => '回滚文件完整性', 'ok' => false, 'detail' => implode('；', $restoreProblems)];
        }
        $task['checks'] = array_merge($installationFailure['checks'] ?? [], $restored);
        if (array_filter($restored, static fn(array $check): bool => $check['ok'] !== true)) {
            $task['stage'] = 'recovery_required';
            $task['error'] = ($installationFailure === null ? '' : $installationFailure['message'] . '；')
                . '回滚后的代码未通过完整性或健康检查，网站保持维护状态，需要人工恢复。';
            $task['updated_at'] = gmdate('c');
            updater_save_task($task);
            throw new UpdateError($task['error'], 'recovery_required', 500);
        }
        $task['stage'] = $installationFailure === null ? 'rolled_back' : 'failed';
        $task['error'] = $installationFailure === null ? null : $installationFailure['message'] . '；已自动回滚到 ' . $task['from_version'] . '。';
        $task['updated_at'] = gmdate('c');
        updater_save_task($task);
        updater_test_barrier('restore-committed');
        // Adopt the proven SOURCE engine before opening traffic. If this
        // handoff or unfence fails, the durable rolled_back journal and its
        // owned fence let an ordinary request retry the terminal finish.
        $engineSha = updater_pinned_engine_sha256($task['identity_pin']['source'] ?? null);
        if ($engineSha === null) {
            throw new UpdateError('无法从已证明的旧基线确定保护引擎哈希，拒绝开放流量。', 'protected_engine_hash', 500);
        }
        if (updater_path_escapes_root('private/update/protected/updater-engine.php')) {
            throw new RuntimeException('Protected engine destination escapes the trusted runtime tree.');
        }
        updater_copy_file($root . '/private/updater-engine.php',
                          updater_dir('update') . '/protected/updater-engine.php', $engineSha);
        updater_clear_maintenance_for($task);
        clearstatcache(true, updater_maintenance_file());
        if (file_exists(updater_maintenance_file()) || is_link(updater_maintenance_file())) {
            throw new UpdateError('维护标记清除失败，网站保持围栏，等待自动恢复重试。', 'commit_unfence', 500);
        }
        updater_unlock($gate); $gate = null;
        if (($task['staging_dir'] ?? null) === 'staging/' . $task['id']
            && !updater_path_escapes_root('private/update/staging/' . $task['id'])) {
            updater_discard_staging($task);
        }
        return $task;
    } catch (Throwable $error) {
        if ($stateLock !== null) { updater_unlock($stateLock); }
        if ($gate !== null) { updater_unlock($gate); }
        $task['error'] = (is_array($installationFailure) && is_string($installationFailure['message'] ?? null) ? $installationFailure['message'] . '；' : '')
            . '回滚中断：' . $error->getMessage();
        $task['updated_at'] = gmdate('c');
        $restoreBegan = !empty($task['rb_started']);
        if ($error instanceof UpdateError && $error->errorCode === 'backup_missing') {
            if (!$restoreBegan && in_array($initialStage, ['verified', 'failed'], true)) {
                // Nothing was written and the site still runs its verified
                // version: report the unusable backup and stop without fencing.
                $task['stage'] = 'failed';
                updater_save_task($task);
                updater_clear_maintenance_for($task);
            } else {
                // Missing backup with a partial or unverified tree: fenced
                // manual recovery, never a silent reopen of mixed code. The
                // fence is (re)created durably so it cannot be lost silently.
                $task['stage'] = 'recovery_required';
                updater_save_task($task);
                if (!updater_maintenance_active()) { updater_set_maintenance($task); }
            }
        } else {
            // rolling_back stays active: the next request resumes the remaining
            // ops, and the maintenance fence stays up while code may be mixed.
            updater_save_task($task);
        }
        throw $error;
    } finally { if ($lock !== null) { updater_unlock($lock); } }
}

/**
 * Real behavior verification. Customer state is never modified.
 * @return array{passed: bool, checks: array<int, array{label: string, ok: bool|null, detail: string}>}
 */
function updater_verify_task(string $taskId, bool $holdsLock = false): array {
    updater_ensure_dirs();
    $lock = $holdsLock ? null : updater_lock('update.lock', false);
    if (!$holdsLock && $lock === null) { throw new UpdateError('已有更新任务在进行中。', 'busy', 409); }
    try {
        $task = updater_load_task($taskId);
        if (!in_array($task['stage'], ['applied', 'verified'], true)) {
            throw new UpdateError('当前阶段不允许验证。', 'wrong_stage', 409);
        }
        $checks = [];
        $add = static function (string $label, bool|null $ok, string $detail) use (&$checks): void {
            $checks[] = ['label' => $label, 'ok' => $ok, 'detail' => $detail];
        };
        $info = updater_code_info();
        $add('代码版本', $info['version'] === $task['to_version'], '当前 ' . $info['version'] . ' / 目标 ' . $task['to_version']);
        $state = updater_read_state_summary();
        $add('站点数据读取', $state !== null, $state !== null ? 'schema_version=' . ($state['schema_version'] ?? '?') : 'state.php 无法解析');
        if ($state !== null) {
            $schema = (int)($state['schema_version'] ?? 1);
            $add('数据格式兼容', $schema >= $info['schema_min'] && $schema <= $info['schema_max'], 'schema ' . $schema);
            $add('账号数据完整', isset($state['users']) && is_array($state['users']) && $state['users'] !== [], count($state['users'] ?? []) . ' 个账号');
            $add('网站配置完整', isset($state['settings']['title']), '标题字段存在');
            $add('联系游标保留', array_key_exists('wa_next_index', $state), 'wa_next_index=' . var_export($state['wa_next_index'] ?? null, true));
        }
        // Pinned complete-tree proof replaces the old mutable-baseline loop: the
        // trusted pin decides, never the live baseline's own claim. Missing pin
        // is a failed diagnostic with no probe and no promotion, for every stage.
        $initialStage = $task['stage'];
        $pinProblems = updater_pinned_tree_problems($task, 'target');
        $add('安装文件完整性', $pinProblems === [], $pinProblems === [] ? '身份基准逐文件校验通过' : implode('；', $pinProblems));
        // An applied task still inside its maintenance fence has not been
        // committed: verification may only report, never promote or probe live
        // traffic. Commit and unfencing stay the executor's responsibility.
        $pendingCommit = $initialStage === 'applied' && updater_maintenance_active();
        if ($pendingCommit) {
            $add('提交状态', false, '任务仍处于维护围栏中，须先由执行器完成提交（verified 并解除围栏）。');
        }
        if ($pinProblems === [] && !$pendingCommit) {
            // Post-commit verification runs with the site live, so it may also
            // exercise the login entrance without a probe capability. Only proven
            // code is ever probed, and nothing may change underneath while the
            // probes ran: the proof is repeated immediately afterwards.
            foreach (updater_self_probe(null, true) as $item) { $add($item['label'], $item['ok'], $item['detail']); }
            $afterProblems = updater_pinned_tree_problems($task, 'target');
            $add('安装文件完整性复核', $afterProblems === [], $afterProblems === [] ? '身份基准逐文件复核通过' : implode('；', $afterProblems));
        }
        // Every check must be strictly true; null/skipped checks count as failure.
        $passed = !in_array(true, array_map(static fn(mixed $ok): bool => $ok !== true, array_column($checks, 'ok')), true);
        // Promotion only with a fully proven tree; a failed diagnostic never
        // reactivates a task that was already terminal when verification began.
        if ($passed) {
            $task['stage'] = 'verified';
            $task['verified_by'] = 'server';
        } elseif ($initialStage !== 'verified') {
            $task['stage'] = 'applied';
        }
        $task['checks'] = $checks;
        $task['updated_at'] = gmdate('c');
        updater_save_task($task);
        return ['passed' => $passed, 'checks' => $checks];
    } finally { if ($lock !== null) { updater_unlock($lock); } }
}

/** Local HTTP probes exercise the freshly installed code for real behavior.
 * The destination is a deploy-configured own-site origin, never derived from
 * request headers; loopback HTTP is accepted only for explicit fixtures.
 */
function updater_open_probe_window(string $token): ?array {
    $marker = updater_read_bounded_json(updater_maintenance_file(), 8192);
    $id = $marker['task_id'] ?? null;
    if (!is_string($id) || !preg_match('/^[a-f0-9]{24}$/D', $id)
        || !is_string($marker['probe_token'] ?? null) || !hash_equals($marker['probe_token'], $token)) { return null; }
    $task = updater_read_bounded_json(updater_task_path($id), CHANNEL_PAYLOAD_MAX_BYTES);
    if ($task === null || ($task['id'] ?? null) !== $id || !is_string($task['probe_token'] ?? null)
        || !hash_equals($task['probe_token'], $token)) { return null; }
    $stage = $task['stage'] ?? '';
    $side = in_array($stage, ['applied', 'verified'], true) ? 'target'
        : (in_array($stage, ['rolling_back', 'rolled_back', 'failed'], true) ? 'source' : null);
    if ($side === null || updater_pinned_tree_problems($task, $side) !== []) { return null; }
    $marker['probe_ready'] = true;
    $marker['probe_expires'] = time() + 30;
    updater_json_write(updater_maintenance_file(), $marker);
    return $marker;
}
function updater_close_probe_window(array $lease): void {
    $current = updater_read_bounded_json(updater_maintenance_file(), 8192);
    if ($current === null || ($current['task_id'] ?? null) !== $lease['task_id']
        || ($current['probe_token'] ?? null) !== $lease['probe_token']) {
        throw new RuntimeException('Health probe owner changed.');
    }
    $current['probe_ready'] = false;
    updater_json_write(updater_maintenance_file(), $current);
}

function updater_self_probe(?string $probeToken = null, bool $includeLoginCheck = false): array {
    // Every probe result is strictly true or false: skipped checks fail.
    if (!function_exists('curl_init')) {
        return [['label' => 'HTTP 自检', 'ok' => false, 'detail' => '服务器未启用 PHP cURL 扩展，无法执行 HTTP 自检。']];
    }
    $config = updater_config();
    $base = rtrim((string)($config['probe_base_url'] ?? ''), '/');
    if ($base === '') {
        return [['label' => 'HTTP 自检', 'ok' => false, 'detail' => '未配置自检地址（private/update/trust.php 的 probe_base_url），健康检查无法执行。']];
    }
    try { updater_source_allowed_url($base . '/', $config); }
    catch (UpdateError $error) {
        return [['label' => 'HTTP 自检', 'ok' => false, 'detail' => '自检地址配置无效：' . $error->getMessage()]];
    }
    $lease = $probeToken === null ? null : updater_open_probe_window($probeToken);
    if ($probeToken !== null && $lease === null) {
        return [['label' => '自检任务授权', 'ok' => false, 'detail' => '维护任务、操作凭证或完整代码证明不匹配。']];
    }
    try {
        $deadline = microtime(true) + 20; // total budget across all probe requests
        $probeOne = static function (string $path, string $method) use ($base, $config, $probeToken, &$deadline): array {
            $remaining = min(6, $deadline - microtime(true));
            if ($remaining <= 0) { return ['status' => 0, 'body' => '', 'headers' => [], 'timeout' => true]; }
            $headers = $probeToken === null ? [] : ['X-Update-Probe: ' . $probeToken];
            try {
                $response = updater_http_request($base . $path, $config, $method, 512 * 1024, $remaining, $headers);
                if ($probeToken !== null && isset($response['headers']['set-cookie'])) { $response['status'] = -3; }
                $response['timeout'] = false;
                return $response;
            } catch (UpdateError $error) {
                return ['status' => $error->errorCode === 'source_too_large' ? -1 : ($error->errorCode === 'source_timeout' ? -2 : 0),
                        'body' => '', 'headers' => [], 'timeout' => $error->errorCode === 'source_timeout'];
            }
        };
        $results = [];
        $home = $probeOne('/', 'GET');
        $version = updater_code_info()['version'];
        $homeOk = $home['status'] === 200 && str_contains($home['body'], '<!doctype html')
            && str_contains($home['body'], 'data-site-config') && str_contains($home['body'], '/assets/site.js?v=' . urlencode($version))
            && str_contains($home['body'], '</html>')
            && !str_contains(strtolower($home['body']), 'fatal error');
        $results[] = ['label' => '首页渲染', 'ok' => $homeOk, 'detail' => 'HTTP ' . $home['status'] . ($homeOk ? '' : '，响应异常')];
        // HEAD hits the method guard and never consumes the WhatsApp cursor.
        $contact = $probeOne('/contact.php', 'HEAD');
        $contactOk = $contact['status'] === 405 && ($probeToken === null || in_array($contact['headers']['x-update-contact'] ?? '', ['ready', 'unconfigured'], true));
        $results[] = ['label' => '联系入口', 'ok' => $contactOk, 'detail' => 'HTTP ' . $contact['status'] . '（只读计算，不消耗号码）'];
        if ($probeToken !== null) {
            $login = $probeOne('/admin/', 'GET');
            $loginOk = $login['status'] === 200 && str_contains($login['body'], 'id="login-form"')
                && str_contains($login['body'], 'id="math-answer"') && str_contains($login['body'], '/assets/login.js?v=' . urlencode($version))
                && str_contains($login['body'], '</html>');
            $results[] = ['label' => '登录页面', 'ok' => $loginOk, 'detail' => 'HTTP ' . $login['status']];
            $health = $probeOne('/admin/api.php?resource=update_health', 'GET');
            $data = json_decode($health['body'], true);
            $healthOk = $health['status'] === 200 && is_array($data) && ($data['product'] ?? '') === updater_code_info()['product']
                && ($data['version'] ?? '') === $version && ($data['configuration_readable'] ?? null) === true && ($data['login_ready'] ?? null) === true;
            $results[] = ['label' => '配置与登录接口', 'ok' => $healthOk, 'detail' => 'HTTP ' . $health['status']];
        } elseif ($includeLoginCheck) {
            // Only used post-commit with the site live: an anonymous bootstrap
            // fetch exercises the login entrance like a fresh visitor.
            $admin = $probeOne('/admin/api.php?resource=bootstrap', 'GET');
            $adminOk = $admin['status'] === 200 && str_contains($admin['body'], 'csrf') && str_contains($admin['body'], 'challenge');
            $results[] = ['label' => '登录入口', 'ok' => $adminOk, 'detail' => 'HTTP ' . $admin['status'] . ($adminOk ? '' : '，登录接口异常')];
        }
        return $results;
    } finally { if ($lease !== null) { updater_close_probe_window($lease); } }
}

// ---------------------------------------------------------------------------
// Online source client (P3).
// ---------------------------------------------------------------------------

function updater_source_allowed_url(string $url, array $config): array {
    if (strlen($url) > 4096 || preg_match('/[\x00-\x20\x7f]/', $url) || str_contains($url, '\\')) {
        throw new UpdateError('更新源 URL 格式不正确。', 'source_invalid', 422);
    }
    $parts = parse_url($url);
    if ($parts === false || !isset($parts['scheme'], $parts['host'])) { throw new UpdateError('更新源 URL 无效。', 'source_invalid', 422); }
    $host = $parts['host'];
    $loopback = in_array($host, ['127.0.0.1', 'localhost', '[::1]'], true);
    if ($parts['scheme'] !== 'https' && !($loopback && $config['allow_loopback_source'])) {
        throw new UpdateError('更新源必须是 HTTPS。', 'source_insecure', 422);
    }
    if (isset($parts['user']) || isset($parts['pass']) || isset($parts['fragment'])) {
        throw new UpdateError('更新源 URL 不能包含凭据。', 'source_invalid', 422);
    }
    if (isset($parts['port']) && $parts['port'] !== 443 && !$loopback) {
        throw new UpdateError('更新源端口不受支持。', 'source_invalid', 422);
    }
    $addresses = [trim($host, '[]')];
    if (!filter_var($addresses[0], FILTER_VALIDATE_IP)) {
        $resolved = gethostbynamel($host);
        if ($resolved === false) { throw new UpdateError('更新源域名无法解析。', 'source_dns', 422); }
        $addresses = $resolved;
    }
    foreach ($addresses as $address) {
        if (!filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)
            && !($loopback && $config['allow_loopback_source'])) {
            throw new UpdateError('更新源解析到内网或保留地址，已拒绝。', 'source_private', 422);
        }
    }
    $parts['addresses'] = array_values(array_unique($addresses));
    return $parts;
}

/** Policy adapter around native libcurl: resolved IPs are pinned before the
 * connection, HTTP/TLS/framing/timeouts stay with the mature transport. */
function updater_http_request(string $url, array $config, string $method, int $maxBytes, float $timeout, array $requestHeaders = [], mixed $destination = null): array {
    if (!function_exists('curl_init')) { throw new UpdateError('请启用 PHP cURL 扩展后重试。', 'curl_missing', 503); }
    if (!in_array($method, ['GET', 'HEAD'], true) || $maxBytes < 1 || $maxBytes > 100 * 1024 * 1024
        || $timeout <= 0 || $timeout > 120 || ($destination !== null && !is_resource($destination))) {
        throw new RuntimeException('Invalid updater transport parameters.');
    }
    $deadline = microtime(true) + $timeout;
    $parts = updater_source_allowed_url($url, $config);
    $milliseconds = (int)floor(($deadline - microtime(true)) * 1000);
    if ($milliseconds < 1) { throw new UpdateError('连接更新源超时。', 'source_timeout', 504); }
    $handle = curl_init($url);
    if ($handle === false) { throw new UpdateError('无法初始化更新连接。', 'source_fetch_failed', 502); }
    $body = ''; $headers = []; $bytes = 0; $headerBytes = 0; $failure = null;
    try {
        $options = [CURLOPT_FOLLOWLOCATION => false, CURLOPT_MAXREDIRS => 0,
            CURLOPT_SSL_VERIFYPEER => true, CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_PROXY => '', CURLOPT_NOPROXY => '*', CURLOPT_NOSIGNAL => true,
            CURLOPT_CONNECTTIMEOUT_MS => min(10000, $milliseconds), CURLOPT_TIMEOUT_MS => $milliseconds,
            CURLOPT_USERAGENT => 'ocpeptide-updater', CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_NOBODY => $method === 'HEAD',
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$headers, &$headerBytes, &$failure): int {
                $headerBytes += strlen($line);
                if ($headerBytes > 65536) { $failure = 'source_too_large'; return 0; }
                if (preg_match('~^HTTP/\S+\s+\d+~', $line)) { $headers = []; }
                elseif (str_contains($line, ':')) {
                    [$name, $value] = explode(':', $line, 2);
                    $headers[strtolower(trim($name))] = trim($value);
                }
                return strlen($line);
            },
            CURLOPT_WRITEFUNCTION => static function ($curl, string $chunk) use (&$body, &$bytes, &$failure, $maxBytes, $destination): int {
                $length = strlen($chunk);
                if ($bytes + $length > $maxBytes) { $failure = 'source_too_large'; return 0; }
                if ($destination !== null) {
                    if (fwrite($destination, $chunk) !== $length) { $failure = 'source_store_failed'; return 0; }
                } else { $body .= $chunk; }
                $bytes += $length;
                return $length;
            }];
        $host = trim($parts['host'], '[]');
        if (!filter_var($host, FILTER_VALIDATE_IP)) {
            $port = $parts['port'] ?? ($parts['scheme'] === 'https' ? 443 : 80);
            $pinned = array_map(static fn(string $ip): string => str_contains($ip, ':') ? '[' . $ip . ']' : $ip, $parts['addresses']);
            $options[CURLOPT_RESOLVE] = [$host . ':' . $port . ':' . implode(',', $pinned)];
        }
        if (!curl_setopt_array($handle, $options)) { throw new RuntimeException('Cannot configure updater transport.'); }
        $ok = curl_exec($handle);
        $errno = curl_errno($handle);
        if ($ok === false || $failure !== null) {
            $code = $failure ?? ($errno === CURLE_OPERATION_TIMEDOUT ? 'source_timeout' : 'source_fetch_failed');
            $message = $code === 'source_too_large' ? '更新源响应超过大小上限。'
                : ($code === 'source_timeout' ? '连接或读取更新源超时。' : '更新连接失败，请检查网络、TLS 证书或存储权限。');
            throw new UpdateError($message, $code, $code === 'source_too_large' ? 422 : 502);
        }
        $peer = (string)curl_getinfo($handle, CURLINFO_PRIMARY_IP);
        $peerBytes = inet_pton($peer);
        if ($peerBytes === false || !in_array($peerBytes, array_map('inet_pton', $parts['addresses']), true)) {
            throw new UpdateError('连接地址与已检查地址不符，已拒绝。', 'source_private', 422);
        }
        return ['status' => (int)curl_getinfo($handle, CURLINFO_HTTP_CODE), 'body' => $body, 'headers' => $headers, 'bytes' => $bytes];
    } finally { unset($handle); }
}

function updater_http_get(string $url, array $config, int $maxBytes, int $timeout): string {
    $response = updater_http_request($url, $config, 'GET', $maxBytes, $timeout);
    if ($response['status'] !== 200) { throw new UpdateError('更新源返回 HTTP ' . $response['status'] . '（重定向已被拒绝）。', 'source_status', 502); }
    return $response['body'];
}

// ---------------------------------------------------------------------------
// Single signed online channel envelope (ocpeptide-channel/v1).
// Pure include-safe helpers: strict envelope decode, signature verification
// over the EXACT decoded payload bytes, and full payload/entry schema checks.
// No I/O, sessions, trust config or customer data — callers supply bytes.
// The release publisher (scripts/release.php) shares these definitions so
// both sides validate identical structures.
// ---------------------------------------------------------------------------
const CHANNEL_ENVELOPE_FORMAT = 'ocpeptide-channel/v1';
const CHANNEL_PURPOSE = 'ocpeptide-release-channel/v1';
const CHANNEL_ENVELOPE_MAX_BYTES = 4194304; // 4 MiB live envelope cap
const CHANNEL_PAYLOAD_MAX_BYTES = 2097152;  // 2 MiB decoded payload cap
const CHANNEL_SIGNATURE_MAX_BYTES = 65536;  // 64 KiB signature representation cap
const CHANNEL_MAX_RELEASES = 50;
const CHANNEL_STATE_MAX_BYTES = 65536;      // local replay-state file cap

/** Strict ISO-8601 UTC timestamp with real calendar/clock validation. */
function updater_channel_valid_timestamp(mixed $value): bool {
    if (!is_string($value)) { return false; }
    if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})(?:\.\d+)?(?:\+00:00|Z)$/D', $value, $m)) { return false; }
    [, $year, $month, $day, $hour, $minute, $second] = $m;
    return checkdate((int)$month, (int)$day, (int)$year)
        && (int)$hour < 24 && (int)$minute < 60 && (int)$second < 60;
}

/** Strict canonical base64: decode must succeed and re-encode identically. */
function updater_channel_base64(string $value, int $representationLimit): string {
    if (strlen($value) > $representationLimit) { throw new UpdateError('Base64 表示超过长度上限。', 'envelope_invalid', 422); }
    $decoded = base64_decode($value, true);
    if ($decoded === false || base64_encode($decoded) !== $value) {
        throw new UpdateError('Base64 编码不严格或无效。', 'envelope_invalid', 422);
    }
    return $decoded;
}

/** Decode + authenticate one live envelope; returns the exact payload bytes. */
function updater_channel_decode_envelope(string $envelopeBytes, string $publicKeyPem): string {
    if (strlen($envelopeBytes) > CHANNEL_ENVELOPE_MAX_BYTES) {
        throw new UpdateError('通道信封超过 4MiB 上限。', 'envelope_too_large', 422);
    }
    try { $envelope = json_decode($envelopeBytes, true, 8, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new UpdateError('通道信封不是有效 JSON。', 'envelope_invalid', 422); }
    if (!is_array($envelope) || count($envelope) !== 3
        || !array_key_exists('format', $envelope) || !array_key_exists('payload', $envelope) || !array_key_exists('signature', $envelope)) {
        throw new UpdateError('通道信封字段不完整或含多余字段。', 'envelope_invalid', 422);
    }
    if (!is_string($envelope['format']) || $envelope['format'] !== CHANNEL_ENVELOPE_FORMAT) {
        throw new UpdateError('通道信封格式标识不正确。', 'envelope_invalid', 422);
    }
    if (!is_string($envelope['payload']) || !is_string($envelope['signature'])) {
        throw new UpdateError('通道信封字段类型不正确。', 'envelope_invalid', 422);
    }
    $payloadBytes = updater_channel_base64($envelope['payload'], CHANNEL_ENVELOPE_MAX_BYTES);
    if (strlen($payloadBytes) > CHANNEL_PAYLOAD_MAX_BYTES) {
        throw new UpdateError('解码后的通道负载超过 2MiB 上限。', 'payload_too_large', 422);
    }
    $signatureBytes = updater_channel_base64($envelope['signature'], CHANNEL_SIGNATURE_MAX_BYTES);
    if (@openssl_verify($payloadBytes, $signatureBytes, $publicKeyPem, OPENSSL_ALGO_SHA256) !== 1) {
        throw new UpdateError('通道信封签名验证失败。', 'source_bad_signature', 422);
    }
    return $payloadBytes;
}

/** Per-entry signed schema rules; a trusted signature never excuses bad data. */
function updater_channel_release_error(array $release, string $expectedProduct): ?string {
    if (!is_string($release['version'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/D', $release['version'])) { return 'invalid release version'; }
    if (!is_string($release['channel'] ?? null) || !preg_match('/^[A-Za-z0-9][A-Za-z0-9_-]{0,31}$/D', $release['channel'])) { return 'invalid channel name'; }
    if (!is_bool($release['withdrawn'] ?? null)) { return 'non-boolean withdrawal flag'; }
    if (!is_string($release['min_upgradable'] ?? null) || !preg_match('/^\d+\.\d+\.\d+$/D', $release['min_upgradable'])) { return 'missing/invalid min_upgradable'; }
    if (!is_string($release['requires_php'] ?? null) || !preg_match('/^\d+\.\d+(\.\d+)?$/D', $release['requires_php'])) { return 'missing/invalid requires_php'; }
    foreach (['schema_min', 'schema_max'] as $bound) {
        if (!is_int($release[$bound] ?? null) || $release[$bound] < 0) { return "missing/invalid $bound"; }
    }
    if ($release['schema_min'] > $release['schema_max']) { return 'inverted schema range'; }
    if (!is_string($release['notes'] ?? null) || strlen($release['notes']) > 5000) { return 'notes must be a bounded string'; }
    if (!updater_channel_valid_timestamp($release['published_at'] ?? null)) { return 'invalid published_at'; }
    $package = $release['package'] ?? null;
    $file = $package['file'] ?? null;
    if (!is_array($package) || !is_string($file)) { return 'missing package descriptor'; }
    // Safe basename AND bound to this product and this entry's version.
    $expectedFile = $expectedProduct . '-' . $release['version'] . '.zip';
    if ($file !== $expectedFile || strlen($file) > 128
        || !preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]*$/D', $file)
        || str_contains($file, '/') || str_contains($file, '\\') || str_contains($file, ':')
        || str_contains($file, '..') || preg_match('/[\x00-\x1f]/', $file)) {
        return 'package file must be the exact safe product-version basename';
    }
    if (!is_string($package['sha256'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $package['sha256'])) { return 'malformed package sha256'; }
    if (!is_int($package['size'] ?? null) || $package['size'] < 1 || $package['size'] > 100 * 1048576) { return 'invalid package size'; }
    return null;
}

/** Pure payload parser: decodes exact payload bytes as JSON OBJECTS first
 *  (associative decoding alone cannot distinguish {} from []), asserts the
 *  top level and every release/package entry is a real object, then applies
 *  the full associative schema. Returns the validated associative payload. */
function updater_channel_parse_payload(string $payloadBytes, string $expectedProduct): array {
    if (strlen($payloadBytes) > CHANNEL_PAYLOAD_MAX_BYTES) {
        throw new UpdateError('通道负载超过 2MiB 上限。', 'payload_too_large', 422);
    }
    try { $objects = json_decode($payloadBytes, false, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new UpdateError('通道负载不是有效 JSON。', 'source_invalid', 422); }
    if (!$objects instanceof stdClass) { throw new UpdateError('通道负载必须是 JSON 对象。', 'source_invalid', 422); }
    if (!($objects->releases ?? null) instanceof ArrayObject && !is_array($objects->releases ?? null)) {
        throw new UpdateError('通道负载 releases 必须是数组。', 'source_invalid', 422);
    }
    foreach ($objects->releases as $entry) {
        if (!$entry instanceof stdClass) { throw new UpdateError('版本条目必须是 JSON 对象。', 'source_invalid', 422); }
        if (!($entry->package ?? null) instanceof stdClass) { throw new UpdateError('版本条目 package 必须是 JSON 对象。', 'source_invalid', 422); }
    }
    try { $payload = json_decode($payloadBytes, true, 64, JSON_THROW_ON_ERROR); }
    catch (JsonException) { throw new UpdateError('通道负载解析失败。', 'source_invalid', 422); }
    if (!is_array($payload) || !is_array($payload['releases'] ?? null) || !array_is_list($payload['releases'])) {
        throw new UpdateError('通道负载 releases 必须是列表。', 'source_invalid', 422);
    }
    $error = updater_channel_payload_error($payload, $expectedProduct);
    if ($error !== null) { throw new UpdateError('更新源元数据无效：' . $error, 'source_invalid', 422); }
    return $payload;
}

/** Full decoded payload schema: purpose, product, sequence, timestamp, list. */
function updater_channel_payload_error(array $payload, string $expectedProduct): ?string {
    if (($payload['purpose'] ?? null) !== CHANNEL_PURPOSE) { return 'payload purpose mismatch'; }
    if (($payload['product'] ?? null) !== $expectedProduct) { return 'channel product mismatch'; }
    if (!is_int($payload['sequence'] ?? null) || $payload['sequence'] < 1) { return 'missing/invalid sequence'; }
    if (!updater_channel_valid_timestamp($payload['generated_at'] ?? null)) { return 'missing/invalid generated_at'; }
    $releases = $payload['releases'] ?? null;
    if (!is_array($releases) || !array_is_list($releases)) { return 'releases must be a list'; }
    if (count($releases) > CHANNEL_MAX_RELEASES) { return 'too many releases'; }
    $seen = [];
    foreach ($releases as $release) {
        if (!is_array($release)) { return 'release entry must be an object'; }
        $error = updater_channel_release_error($release, $expectedProduct);
        if ($error !== null) { return $error; }
        if (isset($seen[$release['version']])) { return 'duplicate channel version ' . $release['version']; }
        $seen[$release['version']] = true;
    }
    return null;
}

/** Strictly load the local replay state under the replay lock: an existing
 *  but malformed/legacy/unreadable state fails closed instead of silently
 *  re-initializing (which would permit sequence rollback). Returns null only
 *  when the file genuinely does not exist. */
function updater_channel_load_replay_state(string $path): ?array {
    if (!file_exists($path) && !is_link($path)) { return null; }
    if (is_link($path) || !is_file($path) || !is_readable($path)) {
        throw new UpdateError('本地更新源状态文件不可读或不是普通文件，已拒绝。', 'source_state_invalid', 422);
    }
    if (filesize($path) > CHANNEL_STATE_MAX_BYTES) {
        throw new UpdateError('本地更新源状态文件超过大小上限，已拒绝。', 'source_state_invalid', 422);
    }
    $raw = @file_get_contents($path, false, null, 0, CHANNEL_STATE_MAX_BYTES + 1);
    $data = is_string($raw) && strlen($raw) <= CHANNEL_STATE_MAX_BYTES ? json_decode($raw, true, 16) : null;
    if (!is_array($data)) {
        throw new UpdateError('本地更新源状态文件损坏，已拒绝；请人工检查 private/update/source-state.json。', 'source_state_invalid', 422);
    }
    if (!is_int($data['sequence'] ?? null) || $data['sequence'] < 1
        || !is_string($data['fingerprint'] ?? null) || !preg_match('/^[a-f0-9]{64}$/D', $data['fingerprint'])
        || !updater_channel_valid_timestamp($data['generated_at'] ?? null)) {
        throw new UpdateError('本地更新源状态文件格式不正确（缺少 sequence 等字段），已拒绝；请人工处理。', 'source_state_invalid', 422);
    }
    return $data;
}

function updater_fetch_channel(array $config): array {
    if ($config['source_url'] === '' || !str_starts_with($config['source_url'], 'http')) {
        throw new UpdateError('更新源未配置。', 'source_unconfigured', 422);
    }
    $source = rtrim($config['source_url'], '/');
    // Exactly one fetch; plain legacy JSON or detached-signature sources are
    // structurally incompatible with the envelope and rejected by the decoder.
    $envelope = updater_http_get($source . '/releases.json', $config, CHANNEL_ENVELOPE_MAX_BYTES, 15);
    $payloadBytes = updater_channel_decode_envelope($envelope, $config['public_key']);
    $payload = updater_channel_parse_payload($payloadBytes, updater_code_info()['product']);
    $sequence = $payload['sequence'];
    $generated = (string)$payload['generated_at'];
    $fingerprint = hash('sha256', $payloadBytes);
    // Replay guard: sequence + fingerprint AND the pre-existing timestamp
    // regression refusal (same timestamp with a higher sequence is valid).
    // The local read/check/write is serialized so a concurrent older fetch
    // can never overwrite a higher accepted sequence.
    updater_ensure_dirs();
    $stateFile = updater_dir('update') . '/source-state.json';
    $lock = @fopen(updater_dir('update') . '/replay.lock', 'c');
    if ($lock === false) { throw new UpdateError('无法锁定更新源状态。', 'source_state_locked', 503); }
    try {
        if (!flock($lock, LOCK_EX)) { throw new UpdateError('无法锁定更新源状态。', 'source_state_locked', 503); }
        $previous = updater_channel_load_replay_state($stateFile);
        if ($previous !== null) {
            if ($sequence < $previous['sequence']) {
                throw new UpdateError('更新源元数据序列号低于已记录值，疑似重放，已拒绝。', 'source_replay', 422);
            }
            if ($sequence === $previous['sequence'] && $previous['fingerprint'] !== $fingerprint) {
                throw new UpdateError('更新源元数据序列号相同但签名内容不同，疑似重放，已拒绝。', 'source_replay', 422);
            }
            // Compare instants, not spellings: equivalent UTC forms (Z vs
            // +00:00) must not falsely block a higher sequence, fractional
            // seconds are preserved exactly, genuinely older instants reject.
            if (new DateTimeImmutable($generated) < new DateTimeImmutable((string)$previous['generated_at'])) {
                throw new UpdateError('更新源元数据时间戳比已记录版本更旧，疑似重放，已拒绝。', 'source_replay', 422);
            }
        }
        $releases = [];
        foreach ($payload['releases'] as $release) {
            $releases[] = [
                'version' => (string)$release['version'], 'channel' => (string)$release['channel'],
                'withdrawn' => (bool)$release['withdrawn'], 'published_at' => (string)$release['published_at'],
                'min_upgradable' => (string)$release['min_upgradable'], 'requires_php' => (string)$release['requires_php'],
                'schema_min' => (int)$release['schema_min'], 'schema_max' => (int)$release['schema_max'],
                'notes' => updater_truncate((string)($release['notes'] ?? ''), 500),
                'package_file' => (string)$release['package']['file'], 'package_sha256' => (string)$release['package']['sha256'],
                'package_size' => (int)$release['package']['size'],
            ];
        }
        // Persist replay state only after full schema/signature validity, and
        // preserve any unrelated fields already recorded locally.
        $nextState = $previous ?? [];
        $nextState['sequence'] = $sequence;
        $nextState['generated_at'] = $generated;
        $nextState['fingerprint'] = $fingerprint;
        $nextState['checked_at'] = gmdate('c');
        updater_json_write($stateFile, $nextState);
    } finally { if (is_resource($lock)) { flock($lock, LOCK_UN); fclose($lock); } }
    return ['sequence' => $sequence, 'generated_at' => $generated, 'releases' => $releases, 'fingerprint' => $fingerprint];
}

/** Pick the newest stable release this site may install (no downgrade, no withdrawn). */
function updater_select_release(array $releases): ?array {
    $info = updater_code_info();
    $state = updater_read_state_summary();
    $schema = (int)($state['schema_version'] ?? 2);
    $best = null;
    foreach ($releases as $release) {
        if ($release['channel'] !== 'stable' || $release['withdrawn']) { continue; }
        if (!preg_match('/^\d+\.\d+\.\d+$/D', $release['version']) || !preg_match('/^\d+(\.\d+){0,2}$/D', $release['min_upgradable'])) { continue; }
        if (updater_version_compare($release['version'], $info['version']) <= 0) { continue; }
        if (updater_version_compare($info['version'], $release['min_upgradable']) < 0) { continue; }
        if (version_compare(PHP_VERSION, $release['requires_php']) < 0) { continue; }
        if ($schema < $release['schema_min'] || $schema > $release['schema_max']) { continue; }
        if ($best === null || updater_version_compare($release['version'], $best['version']) > 0) { $best = $release; }
    }
    return $best;
}

function updater_download_release(array $release, array $config): string {
    if (!preg_match('/^[A-Za-z0-9._-]+$/D', $release['package_file'])
        || $release['package_size'] < 1 || $release['package_size'] > 100 * 1024 * 1024
        || !preg_match('/^[a-f0-9]{64}$/D', $release['package_sha256'])) {
        throw new UpdateError('更新源包描述不正确。', 'source_invalid', 422);
    }
    updater_ensure_dirs();
    $target = updater_dir('update') . '/incoming/' . bin2hex(random_bytes(8)) . '.zip';
    $output = @fopen($target, 'xb');
    if ($output === false) { throw new RuntimeException('Cannot exclusively create downloaded package.'); }
    try {
        $response = updater_http_request(rtrim($config['source_url'], '/') . '/' . $release['package_file'], $config, 'GET',
            min(100 * 1024 * 1024, $release['package_size']), 120, [], $output);
        if ($response['status'] !== 200) { throw new UpdateError('下载更新包返回 HTTP ' . $response['status'] . '。', 'source_status', 502); }
        if (!fflush($output) || $response['bytes'] !== $release['package_size']
            || hash_file('sha256', $target) !== $release['package_sha256']) {
            throw new UpdateError('下载的更新包与签名元数据不一致。', 'package_mismatch', 422);
        }
        return $target;
    } catch (Throwable $error) {
        fclose($output); $output = null;
        @unlink($target);
        throw $error;
    } finally { if (is_resource($output)) { fclose($output); } }
}

// ---------------------------------------------------------------------------
// Housekeeping.
// ---------------------------------------------------------------------------

function updater_cleanup(bool $holdsLock = false): array {
    updater_ensure_dirs();
    $lock = $holdsLock ? null : updater_lock('update.lock', false);
    if (!$holdsLock && $lock === null) { throw new UpdateError('已有更新任务在进行中。', 'busy', 409); }
    try {
        if (file_exists(updater_maintenance_file()) || is_link(updater_maintenance_file())) {
            throw new UpdateError('存在未完成的更新或恢复任务，拒绝清理其证据。', 'active_task', 409);
        }
        $config = updater_config();
        $retention = $config['backup_retention'] ?? 3;
        // Prove that ALL task records are terminal before deleting anything.
        // A missing/corrupt owner cannot be treated as an unused backup.
        $tasks = [];
        foreach (glob(updater_task_dir() . '/*.json') ?: [] as $file) {
            $id = basename($file, '.json');
            $task = updater_read_bounded_json($file, CHANNEL_PAYLOAD_MAX_BYTES);
            if (!preg_match('/^[a-f0-9]{24}$/D', $id) || $task === null || ($task['id'] ?? '') !== $id
                || !is_string($task['stage'] ?? null)
                || ($task['backup_dir'] ?? '') !== 'backups/' . $id
                || ($task['staging_dir'] ?? '') !== 'staging/' . $id) {
                throw new UpdateError('任务记录损坏或归属无法确认，未执行清理。', 'cleanup_unverifiable', 409);
            }
            if (!in_array($task['stage'], updater_terminal_stages(), true)) {
                throw new UpdateError('存在未完成或未知状态的更新任务，未执行清理。', 'active_task', 409);
            }
            $tasks[$id] = $task;
        }
        $removed = ['staging' => 0, 'backups' => 0, 'skipped' => 0];
        $staging = [];
        foreach (glob(updater_dir('update') . '/staging/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (!preg_match('/^[a-f0-9]{24}$/D', $id) || updater_path_escapes_root('private/update/staging/' . $id)) { $removed['skipped']++; continue; }
            $staging[] = $dir;
        }
        $protected = [];
        $currentVersion = updater_code_info()['version'];
        $backups = [];
        foreach (glob(updater_dir('update') . '/backups/*', GLOB_ONLYDIR) ?: [] as $dir) {
            $id = basename($dir);
            if (!preg_match('/^[a-f0-9]{24}$/D', $id) || !isset($tasks[$id])
                || updater_path_escapes_root('private/update/backups/' . $id)) { $removed['skipped']++; continue; }
            $meta = updater_read_bounded_json($dir . '/backup.json', 8192);
            if ($meta === null || ($meta['task_id'] ?? '') !== $id) { $removed['skipped']++; continue; }
            $backups[$dir] = filemtime($dir) ?: 0;
            if ($tasks[$id]['stage'] === 'verified' && ($tasks[$id]['to_version'] ?? null) === $currentVersion) { $protected[$dir] = true; }
        }
        arsort($backups);
        $times = array_values($backups);
        // Retain the entire cutoff timestamp group, including ties. The
        // currently installed verified version also keeps its rollback copy.
        $cutoff = $times[min($retention, count($times)) - 1] ?? PHP_INT_MIN;
        foreach ($staging as $dir) { updater_rm_dir($dir); $removed['staging']++; }
        foreach ($backups as $dir => $mtime) {
            if ($mtime < $cutoff && !isset($protected[$dir])) { updater_rm_dir($dir); $removed['backups']++; }
        }
        return $removed;
    } finally { if ($lock !== null) { updater_unlock($lock); } }
}

function updater_status(): array {
    $info = updater_code_info();
    $config = updater_config();
    $status = [
        'product' => $info['product'], 'version' => $info['version'], 'php' => PHP_VERSION,
        'extensions' => [], 'updater_enabled' => $config !== null,
        'source_configured' => $config !== null && $config['source_url'] !== '',
        'source_host' => $config !== null && $config['source_url'] !== '' ? (string)(parse_url($config['source_url'], PHP_URL_HOST) ?? '') : '',
        'maintenance' => updater_maintenance_active(),
        'baseline' => null, 'last_task' => null, 'active_task' => null, 'backups' => [], 'disk_free' => null, 'state_schema' => null,
    ];
    foreach ($info['requires_extensions'] as $extension) { $status['extensions'][$extension] = extension_loaded($extension); }
    $baseline = updater_read_json(updater_baseline_path());
    if ($baseline !== null) { $status['baseline'] = ['version' => $baseline['version'] ?? null, 'files' => count($baseline['files'] ?? [])]; }
    foreach (['private', 'public'] as $key) { $free = @disk_free_space(updater_dir($key)); if ($free !== false) { $status['disk_free'] = (int)$free; break; } }
    $state = updater_read_state_summary();
    if ($state !== null) { $status['state_schema'] = (int)($state['schema_version'] ?? 1); }
    $active = updater_active_tasks();
    $status['active_task'] = $active ? updater_task_summary($active[0]) : null;
    $last = updater_last_task();
    $status['last_task'] = $last ? updater_task_summary($last) : null;
    foreach (glob(updater_dir('update') . '/backups/*', GLOB_ONLYDIR) ?: [] as $dir) {
        $meta = updater_read_json($dir . '/backup.json');
        $status['backups'][] = ['dir' => basename($dir), 'created' => gmdate('c', filemtime($dir) ?: time()),
                                'size' => updater_dir_bytes($dir), 'task_id' => $meta['task_id'] ?? null];
    }
    return $status;
}

function updater_task_summary(array $task): array {
    return ['id' => $task['id'], 'stage' => $task['stage'], 'from_version' => $task['from_version'] ?? null,
            'to_version' => $task['to_version'] ?? null, 'actor' => $task['actor'] ?? '', 'mode' => $task['mode'] ?? '',
            'ops_total' => count($task['ops'] ?? []), 'ops_done' => (int)($task['ops_done'] ?? 0),
            'rb_done' => (int)($task['rb_done'] ?? 0), 'error' => $task['error'] ?? null,
            'created_at' => $task['created_at'] ?? null, 'updated_at' => $task['updated_at'] ?? null,
            'notes' => $task['notes'] ?? '', 'checks' => $task['checks'] ?? null, 'verified_by' => $task['verified_by'] ?? null];
}

/** One-shot grants are isolated by session, purpose, version and task. A
 *  status refresh reuses an unconsumed grant in that exact context; it never
 *  overwrites a version-bound install or another administrator's grant. */
function updater_nonce_path(string $purpose, string $sessionId = '', ?string $version = null, ?string $taskId = null): string {
    if (!in_array($purpose, ['install', 'rollback', 'cleanup'], true)) { throw new RuntimeException('Unknown nonce purpose.'); }
    if (strlen($sessionId) > 256 || ($version !== null && strlen($version) > 64)
        || ($taskId !== null && !preg_match('/^[a-f0-9]{24}$/D', $taskId))) {
        throw new UpdateError('操作凭证上下文无效。', 'nonce_invalid', 403);
    }
    $context = json_encode([$purpose, $sessionId, $version, $taskId], JSON_THROW_ON_ERROR);
    return updater_dir('update') . '/nonce-' . $purpose . '-' . hash('sha256', $context) . '.json';
}
function updater_nonce_is_current(?array $record, string $purpose, string $sessionId, ?string $version, ?string $taskId): bool {
    return $record !== null
        && is_string($record['value'] ?? null) && (bool)preg_match('/^[a-f0-9]{32}$/D', $record['value'])
        && ($record['purpose'] ?? null) === $purpose && ($record['session_id'] ?? null) === $sessionId
        && array_key_exists('version', $record) && $record['version'] === $version
        && array_key_exists('task_id', $record) && $record['task_id'] === $taskId
        && is_int($record['expires'] ?? null) && $record['expires'] > time()
        && ($record['used'] ?? null) === false;
}
function updater_issue_nonce(string $purpose, string $sessionId = '', ?string $version = null, ?string $taskId = null): array {
    updater_ensure_dirs();
    $path = updater_nonce_path($purpose, $sessionId, $version, $taskId);
    $handle = @fopen($path . '.lock', 'c');
    if ($handle === false) { throw new UpdateError('操作繁忙，请重试。', 'nonce_busy', 503); }
    if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); throw new UpdateError('操作繁忙，请重试。', 'nonce_busy', 503); }
    try {
        $nonce = updater_read_bounded_json($path, 4096);
        if (!updater_nonce_is_current($nonce, $purpose, $sessionId, $version, $taskId)) {
            $nonce = ['value' => bin2hex(random_bytes(16)), 'purpose' => $purpose, 'session_id' => $sessionId,
                      'version' => $version, 'task_id' => $taskId, 'expires' => time() + 1800, 'used' => false];
            updater_json_write($path, $nonce);
        }
        return ['nonce' => $nonce['value'], 'expires_at' => $nonce['expires']];
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
function updater_consume_nonce(mixed $value, string $purpose, string $sessionId = '', ?string $version = null, ?string $taskId = null): void {
    if (!is_string($value) || !preg_match('/^[a-f0-9]{32}$/D', $value)) {
        throw new UpdateError('操作凭证无效或已过期，请刷新后重试。', 'nonce_invalid', 403);
    }
    $path = updater_nonce_path($purpose, $sessionId, $version, $taskId);
    $handle = @fopen($path . '.lock', 'c');
    if ($handle === false) { throw new UpdateError('操作繁忙，请重试。', 'nonce_busy', 503); }
    if (!flock($handle, LOCK_EX | LOCK_NB)) { fclose($handle); throw new UpdateError('操作繁忙，请重试。', 'nonce_busy', 503); }
    try {
        $stored = updater_read_bounded_json($path, 4096);
        if (!updater_nonce_is_current($stored, $purpose, $sessionId, $version, $taskId)
            || !hash_equals($stored['value'], $value)) {
            throw new UpdateError('操作凭证无效或已过期，请刷新后重试。', 'nonce_invalid', 403);
        }
        $stored['used'] = true;
        updater_json_write($path, $stored);
    } finally { flock($handle, LOCK_UN); fclose($handle); }
}
