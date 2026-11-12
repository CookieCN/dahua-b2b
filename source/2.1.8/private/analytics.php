<?php
declare(strict_types=1);
// Beacon intake for the public homepage POST route, the contact allocation
// hook, and the authenticated report endpoints. Only baseline PHP is used.
// Analytics failures are always contained: the homepage, the WhatsApp
// redirect and the admin API must keep working when analytics cannot write.
require_once __DIR__ . '/analytics-store.php';

const ANALYTICS_COOKIE = 'dc_visitor';
const ANALYTICS_COOKIE_TTL = 30 * 86400;
const ANALYTICS_MAX_BODY_BYTES = 2048;

/** Visitor id from the first-party cookie, or a fresh random id. */
function analytics_visitor_id(): string {
    $value = $_COOKIE[ANALYTICS_COOKIE] ?? '';
    if (is_string($value) && preg_match(ANALYTICS_VISITOR_PATTERN, $value)) { return $value; }
    return bin2hex(random_bytes(16));
}

function analytics_secure(): bool { return !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'; }

/** Refresh the 30-day rolling first-party cookie on beacon responses only. */
function analytics_send_visitor_cookie(string $id): void {
    setcookie(ANALYTICS_COOKIE, $id, ['expires' => time() + ANALYTICS_COOKIE_TTL, 'path' => '/',
        'secure' => analytics_secure(), 'httponly' => true, 'samesite' => 'Lax']);
}

/**
 * Keep only the referrer host, lowercased and length-capped; the full URL is
 * never stored. Self referrals and unparseable input become "no source".
 */
function analytics_referrer_host(string $ref): string {
    $ref = trim(substr($ref, 0, 500));
    if ($ref === '' || strlen($ref) > 500) { return ''; }
    $host = parse_url($ref, PHP_URL_HOST);
    if (!is_string($host) || $host === '') { return ''; }
    $host = strtolower($host);
    if (strlen($host) > 100 || !preg_match('/^[a-z0-9.-]+(:[0-9]{1,5})?$/D', $host)) { return ''; }
    $hostBase = (string)preg_replace('/:[0-9]+$/', '', $host);
    $selfBase = (string)preg_replace('/:[0-9]+$/', '', strtolower((string)($_SERVER['HTTP_HOST'] ?? '')));
    if ($selfBase !== '' && $hostBase === $selfBase) { return ''; }
    return $host;
}

/** Whitelisted UTM fields only, control characters stripped, length capped. */
function analytics_clean_utm(mixed $input): array {
    if (!is_array($input)) { return []; }
    $out = [];
    foreach (['s', 'm', 'c', 'n', 'x'] as $key) {
        $value = $input[$key] ?? '';
        if (!is_string($value)) { continue; }
        $value = preg_replace('/[\x00-\x1F\x7F]/u', '', substr($value, 0, 300));
        if (!is_string($value) || $value === '') { continue; }
        if (preg_match_all('/./us', $value) > 100) { $value = analytics_shorten($value, 100); }
        $out[$key] = $value;
    }
    return $out;
}

/** Basic device / browser / OS grouping from the beacon request user agent. */
function analytics_device(string $ua): array {
    if ($ua === '') { return ['未知', '未知', '未知']; }
    $uaLower = strtolower($ua);
    $bot = analytics_is_bot($ua);
    if (str_contains($ua, 'iPad') || str_contains($ua, 'Tablet') || str_contains($ua, 'Nexus 7')) { $device = '平板'; }
    elseif (str_contains($ua, 'Mobile') || str_contains($ua, 'iPhone') || str_contains($ua, 'Android')) { $device = '手机'; }
    else { $device = '桌面'; }
    if ($bot) { $device = '桌面'; }
    if (str_contains($ua, 'Edg/') || str_contains($ua, 'EdgA/') || str_contains($ua, 'Edge/')) { $browser = 'Edge'; }
    elseif (str_contains($ua, 'OPR/') || str_contains($ua, 'Opera')) { $browser = 'Opera'; }
    elseif (str_contains($ua, 'SamsungBrowser/')) { $browser = 'Samsung Internet'; }
    elseif (str_contains($ua, 'FxiOS') || str_contains($ua, 'Firefox/')) { $browser = 'Firefox'; }
    elseif (str_contains($ua, 'CriOS')) { $browser = 'Chrome'; }
    elseif (str_contains($ua, 'Chrome/')) { $browser = 'Chrome'; }
    elseif (str_contains($ua, 'Safari/')) { $browser = 'Safari'; }
    else { $browser = $bot ? '自动化访问' : '其他'; }
    if (str_contains($ua, 'iPhone') || str_contains($ua, 'iPad') || str_contains($ua, 'iOS')) { $os = 'iOS'; }
    elseif (str_contains($ua, 'Android')) { $os = 'Android'; }
    elseif (str_contains($ua, 'Windows')) { $os = 'Windows'; }
    elseif (str_contains($ua, 'Mac OS X') || str_contains($ua, 'Macintosh')) { $os = 'macOS'; }
    elseif (str_contains($ua, 'Linux') || str_contains($ua, 'X11')) { $os = 'Linux'; }
    else { $os = $bot ? '未知' : '其他'; }
    return [$device, $browser, $os];
}

/** Known crawler UA tokens; excluded beacons never become traffic. */
function analytics_is_bot(string $ua): bool {
    $uaLower = strtolower($ua);
    foreach (['bot', 'spider', 'crawler', 'slurp', 'bingpreview', 'facebookexternalhit', 'headlesschrome'] as $token) {
        if (str_contains($uaLower, $token)) { return true; }
    }
    return false;
}

/**
 * Beacons are first-party only: an explicit Origin header must equal this
 * request's own scheme://host, and Sec-Fetch-Site (when the browser sends it)
 * must be same-origin. Older browsers without either header stay compatible.
 */
function analytics_same_origin(): bool {
    $fetchSite = (string)($_SERVER['HTTP_SEC_FETCH_SITE'] ?? '');
    if ($fetchSite !== '' && strcasecmp($fetchSite, 'same-origin') !== 0) { return false; }
    $origin = trim((string)($_SERVER['HTTP_ORIGIN'] ?? ''));
    if ($origin === '') { return true; }
    $scheme = analytics_secure() ? 'https' : 'http';
    $host = strtolower((string)($_SERVER['HTTP_HOST'] ?? ''));
    foreach ([$scheme . '://' . $host, $host] as $allowed) {
        if (strcasecmp($origin, $allowed) === 0) { return true; }
    }
    // Default ports are omitted in Origin but may appear in Host.
    $bare = (string)preg_replace('~:(80|443)$~', '', $host);
    return strcasecmp($origin, $scheme . '://' . $bare) === 0;
}

/**
 * Handle the small same-origin beacon POST to the homepage route. Exits with
 * 204 when recorded or contained, 400/405/413 for malformed traffic. Probes
 * and non-JSON posts never record anything.
 */
function analytics_beacon_entry(): never {
    header('Cache-Control: no-store, max-age=0');
    header('X-Content-Type-Options: nosniff');
    if (defined('OCPEPTIDE_UPDATE_PROBE') && OCPEPTIDE_UPDATE_PROBE) { header('Allow: GET'); http_response_code(405); exit; }
    if (!analytics_same_origin()) { http_response_code(403); exit; }
    $type = strtolower(trim((string)($_SERVER['CONTENT_TYPE'] ?? $_SERVER['HTTP_CONTENT_TYPE'] ?? '')));
    $baseType = str_contains($type, ';') ? trim(explode(';', $type, 2)[0]) : $type;
    // Exact base media type only: application/json with optional parameters,
    // never lookalikes such as application/json-invalid.
    if ($baseType !== 'application/json') { header('Allow: GET'); http_response_code(405); exit; }
    $body = @file_get_contents('php://input', false, null, 0, ANALYTICS_MAX_BODY_BYTES + 1);
    if ($body === false || strlen($body) > ANALYTICS_MAX_BODY_BYTES) { http_response_code(413); exit; }
    $input = json_decode($body, true, 8);
    if (!is_array($input)) { http_response_code(400); exit; }
    $userAgent = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
    // Known bots and http clients never become traffic; the quiet 204 keeps
    // them from probing further.
    if (analytics_is_bot($userAgent)) { http_response_code(204); exit; }
    try {
        $visitor = analytics_visitor_id();
        analytics_send_visitor_cookie($visitor);
        $event = ['k' => '', 't' => time(), 'v' => $visitor];
        // Retry ids are always scoped to visitor + event type, so a replayed
        // id from another visitor still counts as its own real click.
        $eventId = is_string($input['eid'] ?? null) ? (string)preg_replace('/[^A-Za-z0-9-]/', '', substr($input['eid'], 0, 64)) : '';
        if (($input['ev'] ?? null) === 'pv') {
            $event['k'] = 'pv';
            $ref = analytics_referrer_host(is_string($input['ref'] ?? null) ? $input['ref'] : '');
            if ($ref !== '') { $event['r'] = $ref; }
            $utm = analytics_clean_utm($input['utm'] ?? null);
            if ($utm !== []) { $event['u'] = $utm; }
            [$device, $browser, $os] = analytics_device($userAgent);
            $event += ['d' => $device, 'b' => $browser, 'o' => $os];
            analytics_record_event($event, $eventId !== '' ? 'pv:' . $visitor . ':' . $eventId : null);
        } elseif (($input['ev'] ?? null) === 'contact') {
            if ($eventId === '' || strlen($eventId) > 64) { http_response_code(400); exit; }
            $event['k'] = 'cc';
            $event['e'] = $eventId;
            analytics_record_event($event, 'cc:' . $visitor . ':' . $eventId);
        } else {
            http_response_code(400); exit;
        }
    } catch (Throwable $e) {
        error_log('analytics beacon: ' . $e->getMessage());
    }
    http_response_code(204);
    exit;
}

/** Best-effort allocation record; called after the 302 transaction released the state lock. */
function analytics_record_allocation(string $number): void {
    try {
        $visitor = $_COOKIE[ANALYTICS_COOKIE] ?? '';
        if (!is_string($visitor) || !preg_match(ANALYTICS_VISITOR_PATTERN, $visitor)) { $visitor = ''; }
        analytics_record_event(['k' => 'as', 't' => time(), 'v' => $visitor, 'n' => $number]);
    } catch (Throwable $e) {
        error_log('analytics allocation: ' . $e->getMessage());
    }
}

const ANALYTICS_CUSTOM_LOOKBACK_DAYS = 180;
const ANALYTICS_CUSTOM_MAX_DAYS = 31;

function analytics_custom_bounds(DateTimeImmutable $now): array {
    $today = $now->setTime(0, 0);
    return [$today->modify('-' . (ANALYTICS_CUSTOM_LOOKBACK_DAYS - 1) . ' days'), $today];
}

/** Parse and validate report range parameters (admin API GET). */
function analytics_report_params(): array {
    $tz = new DateTimeZone('Asia/Shanghai');
    $range = $_GET['range'] ?? 'today';
    if (!is_string($range)) { throw new ApiError('未知的时间范围。', 404); }
    $now = new DateTimeImmutable('now', $tz);
    if ($range === 'custom') {
        $fromText = $_GET['from'] ?? '';
        $toText = $_GET['to'] ?? '';
        if (!is_string($fromText) || !is_string($toText) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $fromText) || !preg_match('/^\d{4}-\d{2}-\d{2}$/D', $toText)) {
            throw new ApiError('自定义日期范围不正确，请选择开始和结束日期。');
        }
        $from = DateTimeImmutable::createFromFormat('!Y-m-d', $fromText, $tz);
        $fromErrors = DateTime::getLastErrors();
        $to = DateTimeImmutable::createFromFormat('!Y-m-d', $toText, $tz);
        $toErrors = DateTime::getLastErrors();
        // Errors are captured right after each parse, and the round-trip check
        // rejects normalized dates such as 2026-02-31 instead of accepting them.
        if ($from === false || $to === false
            || ($fromErrors['warning_count'] ?? 0) || ($fromErrors['error_count'] ?? 0)
            || ($toErrors['warning_count'] ?? 0) || ($toErrors['error_count'] ?? 0)
            || $from->format('Y-m-d') !== $fromText || $to->format('Y-m-d') !== $toText) {
            throw new ApiError('自定义日期范围不正确，请选择开始和结束日期。');
        }
        if ($to < $from) { throw new ApiError('结束日期不能早于开始日期。'); }
        [$earliest, $today] = analytics_custom_bounds($now);
        if ($from < $earliest) { throw new ApiError('开始日期须在最近 180 天内。'); }
        if ($to > $today) { throw new ApiError('结束日期不能晚于今天。'); }
        if ($from->diff($to)->days >= ANALYTICS_CUSTOM_MAX_DAYS) { throw new ApiError('自定义范围最多 31 天。'); }
        return ['from' => $from->getTimestamp(), 'to' => min($to->setTime(23, 59, 59)->getTimestamp(), $now->getTimestamp()),
                'label' => '自定义', 'range' => 'custom'];
    }
    $windows = [
        'today' => ['P0D', 'P0D', '今天', false],
        'yesterday' => ['P1D', 'P1D', '昨天', true],
        '3d' => ['P2D', 'P0D', '近 3 天', false],
        '7d' => ['P6D', 'P0D', '近 7 天', false],
        '30d' => ['P29D', 'P0D', '近 30 天', false],
        '90d' => ['P89D', 'P0D', '近 3 个月', false],
    ];
    if (!isset($windows[$range])) { throw new ApiError('未知的时间范围。', 404); }
    [$fromShift, $toShift, $label, $fullDays] = $windows[$range];
    $fromTs = $now->sub(new DateInterval($fromShift))->setTime(0, 0, 0)->getTimestamp();
    $toDay = $now->sub(new DateInterval($toShift));
    $toTs = $fullDays ? $toDay->setTime(23, 59, 59)->getTimestamp() : $now->getTimestamp();
    return ['from' => $fromTs, 'to' => $toTs, 'label' => $label, 'range' => $range];
}

/** Full report for the admin panel: window plus equal-length previous period. */
function analytics_build_report(array $params): array {
    $from = (int)$params['from'];
    $to = (int)$params['to'];
    $shift = max(1, $to - $from + 1);
    $previousFrom = $from - $shift;
    $current = null; $previous = null; $status = [];
    $retainedFrom = null;
    $hasPreviousHistory = false;
    try {
        // Aggregation runs lock-free over append-only day files: a torn last
        // line only counts as corrupt. Holding the shared analytics lock for a
        // whole large aggregation would stall the contact redirect instead.
        $status = analytics_read_status();
        $retainedFrom = $status['earliest'] ?? $status['first'] ?? null;
        $retainedFrom = is_int($retainedFrom) ? $retainedFrom : null;
        $current = analytics_aggregate($from, $to, $retainedFrom);
        $hasPreviousHistory = $retainedFrom === null || $previousFrom >= $retainedFrom;
        $previous = $hasPreviousHistory ? analytics_aggregate($previousFrom, $from - 1, $retainedFrom) : analytics_empty_aggregate();
    } catch (Throwable $e) {
        // A broken store must still answer the admin page: fall back to a pure
        // in-memory empty window without touching the damaged directory.
        error_log('analytics report: ' . $e->getMessage());
        $current = analytics_empty_aggregate();
        $current['stats']['unavailable'] = true;
        $previous = analytics_empty_aggregate();
    }
    $comparisonReliable = $hasPreviousHistory && empty($current['stats']['unavailable'])
        && empty($current['stats']['truncated']) && empty($previous['stats']['truncated']);
    $tz = new DateTimeZone('Asia/Shanghai');
    $fmt = static fn(int $ts): string => (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format('Y-m-d H:i');
    return [
        'meta' => [
            'timezone' => 'Asia/Shanghai',
            'range_label' => $params['label'],
            'range' => $params['range'],
            'from' => $fmt($from),
            'to' => $fmt(max($from, $to)),
            'since' => !empty($status['first']) ? $fmt((int)$status['first']) : null,
            'retained_from' => $retainedFrom === null ? null : $fmt($retainedFrom),
            'updated_at' => $fmt(time()),
            'limitations' => [
                '统计来自浏览器同源上报：广告拦截、无 JavaScript、跨站或被拒的请求不会计入，可能少量少计；已知爬虫 UA 不计入。',
                'UV 基于本站 30 天第一方 Cookie 估算，不代表可鉴别的自然人数；不存储 IP 和完整访问 URL；首次上报返回前发生的极快点击可能无法关联到访问。',
                '访问（Session）按同一访客 30 分钟内的页面浏览链计算，联系点击不延长会话；来源按访问在扫描范围内第一个页面浏览归因。',
                '联系点击为前端真实点击次数；服务端 302 分配单独统计，两者口径不同，不相加、不互相冒充。',
                '国家/地区未接入；未关联到页面访问的联系请求单独列出，不计入访问转化率。',
                '报表从最早事件开始扫描，超过上限时仅统计较早部分并标记截断；不鉴别人类访问，无法杜绝刷量。',
            ],
        ],
        'status' => [
            'overflow' => (bool)($status['overflow'] ?? false),
            'truncated' => (bool)($current['stats']['truncated'] ?? false),
            'corrupt' => (int)($current['stats']['corrupt'] ?? 0),
            'unavailable' => (bool)($current['stats']['unavailable'] ?? false),
            'comparison_reliable' => $comparisonReliable,
            'retention_days' => ANALYTICS_RETENTION_DAYS,
            'partial_history' => $retainedFrom !== null && $from < $retainedFrom,
        ],
        'totals' => $current['totals'],
        'trend' => $current['trend'],
        'sources' => $current['sources'],
        'campaigns' => $current['campaigns'],
        'devices' => $current['devices'],
        'browsers' => $current['browsers'],
        'systems' => $current['systems'],
        'numbers' => $current['numbers'],
        'previous' => ['totals' => $previous['totals'], 'trend' => $previous['trend']],
    ];
}

function analytics_csv_cell(mixed $value): string {
    $text = (string)($value ?? '');
    // Guard formula injection including leading whitespace/control characters
    // before = + - @ or a tab/CR start.
    if (preg_match('/^[\s\x00-\x1F]*[=@+\-\t\r]/', $text) === 1) {
        $text = "'" . $text;
    }
    if (strpbrk($text, ",\"\n\r") !== false) { $text = '"' . str_replace('"', '""', $text) . '"'; }
    return $text;
}

/** CSV export with the same window and counting as the panel. */
function analytics_csv_out(array $params): never {
    $report = analytics_build_report($params);
    $lines = [];
    $row = static fn(array $cells): string => implode(',', array_map('analytics_csv_cell', $cells));
    $pct = static fn(mixed $rate): string => $rate === null ? '—' : $rate . '%';
    $reliable = (bool)($report['status']['comparison_reliable'] ?? false);
    $delta = static function (int $now, int $before) use ($reliable): string {
        if (!$reliable) { return '—'; }   // Truncated periods must not fake a trend.
        return $before > 0 ? round(($now - $before) * 100 / $before, 1) . '%' : '—';
    };
    $t = $report['totals']; $p = $report['previous']['totals'];
    $lines[] = $row(['B2B广告询盘单页系统 数据魔方']);
    $lines[] = $row(['时区', $report['meta']['timezone'], '范围', $report['meta']['range_label'], $report['meta']['from'] . ' ~ ' . $report['meta']['to']]);
    $lines[] = $row(['指标', '当前', '上期', '变化']);
    foreach ([
        ['页面访问 PV', $t['pv'], $p['pv']],
        ['独立访客 UV（估算）', $t['uv'], $p['uv']],
        ['访问 Sessions', $t['sessions'], $p['sessions']],
        ['前端联系点击', $t['clicks'], $p['clicks']],
        ['发生联系点击的访问', $t['click_visits'], $p['click_visits']],
        ['服务端成功分配（302）', $t['allocations'], $p['allocations']],
        ['未关联联系点击', $t['clicks_unlinked'], $p['clicks_unlinked']],
        ['未关联分配', $t['allocations_unlinked'], $p['allocations_unlinked']],
    ] as [$label, $nowValue, $prevValue]) {
        $lines[] = $row([$label, $nowValue, $reliable ? $prevValue : '—', $delta((int)$nowValue, (int)$prevValue)]);
    }
    $lines[] = $row(['联系点击率', $pct($t['click_rate']), $reliable ? $pct($p['click_rate']) : '—']);
    if (!empty($report['status']['unavailable'])) { $lines[] = $row(['说明', '统计存储暂时不可读，以下为空数据；网站功能不受影响。']); }
    elseif (!$reliable) { $lines[] = $row(['说明', '数据不完整，环比不可比。']); }
    if (!empty($report['status']['truncated'])) { $lines[] = $row(['说明', '本范围事件数超过扫描上限，以上仅包含较早的部分事件。']); }
    if (!empty($report['status']['overflow'])) { $lines[] = $row(['说明', '统计存储已达容量上限，部分事件未被记录。']); }
    if ($report['status']['partial_history']) { $lines[] = $row(['说明', '现有数据从 ' . $report['meta']['retained_from'] . ' 开始，较早时段留空。']); }
    $lines[] = '';
    $lines[] = $row(['趋势', 'PV', 'UV', '联系点击', '成功分配']);
    foreach ($report['trend'] as $bucket) { $lines[] = $row([$bucket['label'], $bucket['pv'], $bucket['uv'], $bucket['clicks'], $bucket['allocations']]); }
    $lines[] = '';
    $lines[] = $row(['来源', '访问', 'PV', '联系点击', '发生点击的访问', '点击率']);
    foreach ($report['sources'] as $source) { $lines[] = $row([$source['label'], $source['sessions'], $source['pv'], $source['clicks'], $source['click_visits'], $pct($source['rate'] ?? null)]); }
    $lines[] = $row(['（未关联联系点击）', '—', '—', $t['clicks_unlinked'], '—', '—']);
    $lines[] = '';
    $lines[] = $row(['UTM 广告活动', '访问', 'PV', '发生点击的访问']);
    foreach ($report['campaigns'] as $campaign) { $lines[] = $row([$campaign['label'], $campaign['sessions'], $campaign['pv'], $campaign['click_visits']]); }
    $lines[] = '';
    foreach ([['设备', 'devices'], ['浏览器', 'browsers'], ['操作系统', 'systems']] as [$title, $key]) {
        $lines[] = $row([$title, '访问', 'PV']);
        foreach ($report[$key] as $item) { $lines[] = $row([$item['label'], $item['sessions'], $item['pv']]); }
        $lines[] = '';
    }
    $lines[] = $row(['号码分配（服务端 302）', '分配次数', '关联到访问']);
    foreach ($report['numbers'] as $number) { $lines[] = $row([$number['label'], $number['allocations'], $number['linked']]); }
    $lines[] = $row(['未关联访问的分配', $t['allocations_unlinked'], 0]);
    $lines[] = '';
    $lines[] = $row(['国家/地区', '未接入']);
    foreach ($report['meta']['limitations'] as $note) { $lines[] = $row([$note]); }
    $filename = 'analytics-' . $report['meta']['range'] . '-' . date('Ymd-His') . '.csv';
    http_response_code(200);
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: no-store, private');
    echo "\xEF\xBB\xBF" . implode("\r\n", $lines) . "\r\n";
    exit;
}
