<?php
declare(strict_types=1);
// Maintenance fence comes first and depends only on private/update state, so
// replaceable files (version.php and everything below) can never break fencing.
const DATA_DIR = __DIR__ . '/data';
const STATE_FILE = DATA_DIR . '/state.php';
const PREFIX = "<?php exit; ?>\n";
const PUBLIC_DIR = __DIR__ . '/../public_html';

// Shared request gate + maintenance flag: every request holds a SHARED gate
// lock from before session start until after the session flush at shutdown,
// so login/logout, session writes, uploads, deletions and the contact cursor
// can never overlap an installer holding the EXCLUSIVE gate during a swap.
// POSTs to the updater API skip the shared grant (the engine takes the
// exclusive gate itself) but still respect the maintenance flag. A narrow
// probe capability lets fenced health checks reach two sessionless routes.
if (!defined('OCPEPTIDE_REQUEST_GATE_READY') && is_dir(__DIR__ . '/update')) {
    $update_gate = @fopen(__DIR__ . '/update/gate.lock', 'c');
    if ($update_gate === false) {
        http_response_code(503);
        header('Content-Type: text/plain; charset=utf-8');
        exit('网站更新目录不可用，请联系管理员检查 private/update 权限。');
    }
    $update_is_updater_post = ($_SERVER['REQUEST_METHOD'] ?? '') === 'POST'
        && ($_SERVER['SCRIPT_NAME'] ?? '') === '/admin/update-api.php';
    $update_probe_valid = false;
    $update_maintenance = @file_get_contents(__DIR__ . '/update/maintenance.json');
    if (is_string($update_maintenance)) {
        $update_fence = json_decode($update_maintenance, true);
        if (is_array($update_fence) && is_string($update_fence['probe_token'] ?? null)) {
            $update_presented = (string)($_SERVER['HTTP_X_UPDATE_PROBE'] ?? '');
            $update_method = $_SERVER['REQUEST_METHOD'] ?? '';
            $update_route = (string)(strtok((string)($_SERVER['REQUEST_URI'] ?? ''), '?') ?: '/');
            // Header-only token, expiring, exact sessionless GET/HEAD routes.
            $update_probe_valid = $update_presented !== ''
                && in_array($update_method, ['GET', 'HEAD'], true)
                && in_array($update_route, ['/', '/contact.php'], true)
                && (int)($update_fence['probe_expires'] ?? 0) > time()
                && hash_equals($update_fence['probe_token'], $update_presented);
        }
    }
    if ($update_is_updater_post) {
        // The engine serializes its own writes; this request must not hold the
        // shared gate it is about to wait for exclusively.
        fclose($update_gate);
        if (is_string($update_maintenance)) {
            http_response_code(503);
            header('Retry-After: 10');
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-store, private');
            exit('{"error":"网站正在系统更新维护中，请等待当前更新任务完成后重试。"}');
        }
    } elseif (!flock($update_gate, LOCK_SH | LOCK_NB)) {
        if ($update_probe_valid) {
            fclose($update_gate);
            // Read-only verification traffic on the two safe routes.
        } else {
            fclose($update_gate);
            http_response_code(503);
            header('Retry-After: 30');
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, private');
            exit('网站正在系统更新维护，请稍后刷新重试。');
        }
    } elseif (is_string($update_maintenance)) {
        if ($update_probe_valid) {
            flock($update_gate, LOCK_UN);
            fclose($update_gate);
            // A valid probe never falls into the stale-maintenance branch.
        } else {
            // Stale fence from a crashed install: drop the shared grant so
            // engine recovery can take the exclusive gate, then answer 503.
            flock($update_gate, LOCK_UN);
            fclose($update_gate);
            $update_engine = __DIR__ . '/update/protected/updater-engine.php';
            if (!is_file($update_engine)) { $update_engine = __DIR__ . '/updater-engine.php'; }
            if (is_file($update_engine)) { include_once $update_engine; updater_try_autorecover(); }
            http_response_code(503);
            header('Retry-After: 10');
            header('Content-Type: text/plain; charset=utf-8');
            header('Cache-Control: no-store, private');
            exit('网站正在完成系统更新，请稍后刷新重试。');
        }
    } else {
        register_shutdown_function(static function () use ($update_gate): void {
            // Flush the session while the shared gate is still held so session
            // writes can never land after the installer took the exclusive gate.
            if (session_status() === PHP_SESSION_ACTIVE) { session_write_close(); }
            flock($update_gate, LOCK_UN);
            fclose($update_gate);
        });
    }
}
require __DIR__ . '/version.php';

final class ApiError extends RuntimeException {
    public function __construct(string $message, public readonly int $status = 422) { parent::__construct($message); }
}
function esc(string $value): string { return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }
function site_initials(string $name): string {
    $name = trim($name);
    $pattern = preg_match('/^\p{Han}/u', $name) ? '/^./us' : '/^.{1,2}/us';
    preg_match($pattern, $name, $match);
    return $match[0] ?? '';
}
function json_out(array $data, int $status = 200): never {
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    exit;
}
function read_state(): array {
    if (!is_file(STATE_FILE)) { throw new RuntimeException('Site is not initialized. Run scripts/prepare.py before deployment.'); }
    $raw = file_get_contents(STATE_FILE);
    if ($raw === false || !str_starts_with($raw, PREFIX)) { throw new RuntimeException('Invalid state file.'); }
    $state = json_decode(substr($raw, strlen(PREFIX)), true, 64, JSON_THROW_ON_ERROR);
    // Upgrade old installations in memory, without replacing credentials or data.
    $state['settings'] = array_replace(['site_name' => 'OCPEPTIDE', 'button_text' => 'Contact us on WhatsApp', 'footer_text' => 'OCPEPTIDE · Research Use Only', 'title_align' => 'left'], pixel_defaults(), $state['settings']);
    $state['logs'] = $state['logs'] ?? [];
    $state['login_limits'] = $state['login_limits'] ?? [];
    return $state;
}
function pixel_defaults(): array {
    return ['tiktok_pixel_id'=>'', 'tiktok_enabled'=>false, 'tiktok_contact'=>true,
            'meta_pixel_id'=>'', 'meta_enabled'=>false, 'meta_contact'=>true];
}
/** Shared by the real redirect and its read-only update health check. */
function contact_selection(array $settings, int $cursor): ?array {
    $numbers = $settings['numbers'];
    if (!is_array($numbers)) { throw new RuntimeException('Invalid contact settings.'); }
    if (count($numbers) === 0) { return null; }
    $index = max(0, $cursor) % count($numbers);
    $number = $numbers[$index];
    if (!is_string($number) || !preg_match('/^[1-9][0-9]{6,14}$/D', $number)) { throw new RuntimeException('Invalid contact number.'); }
    return ['location' => 'https://wa.me/' . $number . '?text=' . rawurlencode($settings['message']),
            'next' => ($index + 1) % count($numbers)];
}
function persist_state_bytes(string $bytes): void {
    $temp = tempnam(DATA_DIR, 'state-');
    if ($temp === false) { throw new RuntimeException('Cannot create data file.'); }
    try {
        if (file_put_contents($temp, $bytes) !== strlen($bytes)) { throw new RuntimeException('Cannot save data.'); }
        chmod($temp, 0600);
        if (!rename($temp, STATE_FILE)) { throw new RuntimeException('Cannot replace data.'); }
    } finally { if (is_file($temp)) { unlink($temp); } }
}
function transaction(callable $callback, ?callable $afterPersist = null): mixed {
    $lock = fopen(DATA_DIR . '/state.lock', 'c');
    if (!$lock || !flock($lock, LOCK_EX)) { throw new RuntimeException('Cannot lock data.'); }
    try {
        $state = read_state();
        $original = $afterPersist !== null ? file_get_contents(STATE_FILE) : null;
        if ($original === false) { throw new RuntimeException('Cannot read data for rollback.'); }
        $result = $callback($state);
        $bytes = PREFIX . json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        persist_state_bytes($bytes);
        if ($afterPersist !== null) {
            try { $afterPersist($result); }
            catch (Throwable $error) {
                persist_state_bytes($original);
                throw $error;
            }
        }
        return $result;
    } finally { flock($lock, LOCK_UN); fclose($lock); }
}
function managed_image_file(string $path): ?string {
    if ($path !== '/hero.webp' && !preg_match('~^/uploads/[a-f0-9]{32}\.(?:jpg|png|webp|gif)$~D', $path)) {
        throw new ApiError('只能删除网站图片库中的图片。');
    }
    $candidate = PUBLIC_DIR . $path;
    if (is_link($candidate) || ($path !== '/hero.webp' && is_link(PUBLIC_DIR . '/uploads'))) {
        throw new ApiError('不允许删除链接文件。');
    }
    if (!file_exists($candidate)) { return null; }
    $resolved = realpath($candidate);
    $public = realpath(PUBLIC_DIR);
    $directory = realpath($path === '/hero.webp' ? PUBLIC_DIR : PUBLIC_DIR . '/uploads');
    if ($resolved === false || $public === false || $directory === false
        || ($path !== '/hero.webp' && dirname($directory) !== $public)
        || dirname($resolved) !== $directory || !is_file($resolved)) {
        throw new ApiError('图片路径不正确，无法删除。');
    }
    return $resolved;
}
require __DIR__ . '/accounts.php';
function text_field(array $input, string $key, int $min, int $max): string {
    if (!isset($input[$key]) || !is_string($input[$key])) { throw new ApiError('字段格式错误：' . $key); }
    $value = trim($input[$key]);
    $length = preg_match_all('/./us', $value);
    if ($length === false || $length < $min || $length > $max) { throw new ApiError($key . ' 长度须为 ' . $min . '–' . $max . ' 个字符。'); }
    return $value;
}
function int_field(array $input, string $key, int $min, int $max): int {
    $value = $input[$key] ?? null;
    if (!is_int($value) || $value < $min || $value > $max) { throw new ApiError($key . ' 必须介于 ' . $min . ' 和 ' . $max . '。'); }
    return $value;
}
function validate_settings(array $input, array $state): array {
    $out = [];
    $input['site_name'] = $input['site_name'] ?? $state['settings']['site_name'];
    $out['site_name'] = text_field($input, 'site_name', 1, 60);
    $input['button_text'] = $input['button_text'] ?? $state['settings']['button_text'];
    $out['button_text'] = text_field($input, 'button_text', 1, 100);
    $input['footer_text'] = $input['footer_text'] ?? $state['settings']['footer_text'];
    $out['footer_text'] = text_field($input, 'footer_text', 0, 200);
    $out['title'] = text_field($input, 'title', 1, 200);
    $out['title_align'] = $input['title_align'] ?? $state['settings']['title_align'];
    if (!in_array($out['title_align'], ['left','center','right'], true)) { throw new ApiError('标题对齐只能选择居左、居中或居右。'); }
    $out['body'] = text_field($input, 'body', 1, 3000);
    $out['title_size'] = int_field($input, 'title_size', 16, 80);
    $out['body_size'] = int_field($input, 'body_size', 12, 40);
    foreach (['title_weight', 'body_weight'] as $key) {
        $out[$key] = int_field($input, $key, 100, 900);
        if ($out[$key] % 100 !== 0) { throw new ApiError('字重必须为 100 的倍数。'); }
    }
    $out['button_id'] = text_field($input, 'button_id', 0, 64);
    if ($out['button_id'] !== '' && !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/D', $out['button_id'])) { throw new ApiError('按钮 ID 须以英文字母开头，仅含字母、数字、下划线或连字符。'); }
    $classes = preg_split('/\s+/', text_field($input, 'button_class', 0, 300), -1, PREG_SPLIT_NO_EMPTY);
    if (count($classes) > 8) { throw new ApiError('最多添加 8 个 class。'); }
    foreach ($classes as $class) {
        if (!preg_match('/^[a-zA-Z][a-zA-Z0-9_-]{0,63}$/D', $class)) { throw new ApiError('Class 须以英文字母开头，仅含字母、数字、下划线或连字符。'); }
    }
    $out['button_class'] = implode(' ', array_unique($classes));
    $out['message'] = text_field($input, 'message', 0, 1000);
    $numbers = $input['numbers'] ?? null;
    if (!is_array($numbers) || !array_is_list($numbers) || count($numbers) > 10) { throw new ApiError('最多配置 10 个 WhatsApp 号码。'); }
    $out['numbers'] = [];
    foreach ($numbers as $number) {
        if (!is_string($number)) { throw new ApiError('号码格式不正确。'); }
        $number = preg_replace('/[\s()+-]/', '', $number);
        if (!preg_match('/^[1-9][0-9]{6,14}$/D', $number)) { throw new ApiError('请输入带国家区号的 7–15 位国际号码。'); }
        $out['numbers'][] = $number;
    }
    if (count(array_unique($out['numbers'])) !== count($out['numbers'])) { throw new ApiError('WhatsApp 号码不能重复。'); }
    $images = $input['images'] ?? null;
    if (!is_array($images) || !array_is_list($images) || count($images) < 1 || count($images) > 12) { throw new ApiError('请选择 1–12 张头图。'); }
    foreach ($images as $image) { if (!is_string($image) || !in_array($image, $state['assets'], true)) { throw new ApiError('图片不存在，请重新上传。'); } }
    if (count(array_unique($images)) !== count($images)) { throw new ApiError('头图不能重复。'); }
    $out['images'] = $images;
    $out['interval'] = int_field($input, 'interval', 2, 15);
    foreach (['tiktok', 'meta'] as $platform) {
        $idKey = $platform . '_pixel_id';
        // Older clients may omit pixel fields; preserve their current values.
        $value = $input[$idKey] ?? $state['settings'][$idKey];
        if (!is_string($value)) { throw new ApiError('Pixel ID 必须是文本。'); }
        $value = trim($value);
        $pattern = $platform === 'tiktok' ? '/^[A-Z0-9]{20}$/D' : '/^[0-9]{5,30}$/D';
        if ($value !== '' && !preg_match($pattern, $value)) { throw new ApiError($platform === 'tiktok' ? 'TikTok Pixel ID 应为 20 位大写字母或数字，请勿粘贴整段代码。' : 'Facebook Pixel ID 应为 5–30 位数字，请勿粘贴整段代码。'); }
        $out[$idKey] = $value;
        foreach (['_enabled', '_contact'] as $suffix) {
            $key = $platform . $suffix;
            $out[$key] = $input[$key] ?? $state['settings'][$key];
            if (!is_bool($out[$key])) { throw new ApiError('像素开关格式不正确。'); }
        }
        if ($out[$platform . '_enabled'] && $value === '') { throw new ApiError('请先填写 ' . ($platform === 'meta' ? 'Facebook' : 'TikTok') . ' Pixel ID，再启用像素。'); }
    }
    return $out;
}
