<?php
declare(strict_types=1);
// Append-only analytics store under private/data/analytics.
// Standalone on purpose: fixtures may pre-define DATA_DIR and tighter caps
// before including this file, and no bootstrap helper is required. Only
// baseline PHP is used (no mbstring); UTF-8 truncation is PCRE-based.
// The dedicated analytics lock must never be taken while the state lock is
// held, and no nested PHP file lock is used (it can block forever).
if (!defined('ANALYTICS_RETENTION_DAYS')) { define('ANALYTICS_RETENTION_DAYS', 90); }
if (!defined('ANALYTICS_MAX_DAY_BYTES')) { define('ANALYTICS_MAX_DAY_BYTES', 4 * 1024 * 1024); }
if (!defined('ANALYTICS_MAX_TOTAL_BYTES')) { define('ANALYTICS_MAX_TOTAL_BYTES', 128 * 1024 * 1024); }
if (!defined('ANALYTICS_MAX_LINE_BYTES')) { define('ANALYTICS_MAX_LINE_BYTES', 2048); }
if (!defined('ANALYTICS_MAX_FILES')) { define('ANALYTICS_MAX_FILES', ANALYTICS_RETENTION_DAYS + 2); }
if (!defined('ANALYTICS_WRITE_LOCK_MS')) { define('ANALYTICS_WRITE_LOCK_MS', 1500); }
if (!defined('ANALYTICS_READ_LOCK_MS')) { define('ANALYTICS_READ_LOCK_MS', 2000); }
if (!defined('ANALYTICS_SCAN_EVENT_CAP')) { define('ANALYTICS_SCAN_EVENT_CAP', 50000); }
if (!defined('ANALYTICS_CARDINALITY_CAP')) { define('ANALYTICS_CARDINALITY_CAP', 2000); }
if (!defined('ANALYTICS_SESSION_GAP')) { define('ANALYTICS_SESSION_GAP', 1800); }
if (!defined('ANALYTICS_SEEN_TTL')) { define('ANALYTICS_SEEN_TTL', 3600); }
if (!defined('ANALYTICS_SEEN_MAX')) { define('ANALYTICS_SEEN_MAX', 5000); }
if (!defined('ANALYTICS_VISITOR_PATTERN')) { define('ANALYTICS_VISITOR_PATTERN', '/^[a-f0-9]{32}$/D'); }

function analytics_dir(): string { return DATA_DIR . '/analytics'; }

/** UTF-8 aware truncation without mbstring; byte fallback on broken input. */
function analytics_shorten(string $value, int $maxChars): string {
    if (preg_match('/\A.{0,' . $maxChars . '}/us', $value, $match) === 1) { return $match[0]; }
    return substr($value, 0, $maxChars * 4);
}

/**
 * Resolve the analytics directory, refusing links and any location outside
 * the real private data directory. Every later file access re-checks paths
 * through this, so planted symlinks/junctions can never be followed.
 */
function analytics_safe_dir(): string {
    $dir = analytics_dir();
    if (is_link($dir)) { throw new RuntimeException('analytics directory is a link'); }
    $dataReal = realpath(DATA_DIR);
    if ($dataReal === false || !is_dir($dataReal)) { throw new RuntimeException('private data directory unavailable'); }
    if (is_dir($dir)) {
        // Exact canonical location: the analytics directory itself must be
        // precisely data/analytics, not merely somewhere inside data.
        if (realpath($dir) !== $dataReal . DIRECTORY_SEPARATOR . 'analytics') { throw new RuntimeException('unexpected analytics directory location'); }
    }
    return $dir;
}

/** Validated absolute path for a fixed server-controlled analytics file name. */
function analytics_safe_path(string $name): string {
    $fixed = ['analytics.lock', 'status.json', 'status.json.tmp', 'seen.json', 'seen.json.tmp'];
    $allowed = in_array($name, $fixed, true) || (bool)preg_match('/^d-\d{8}\.jsonl$/D', $name);
    if (!$allowed) { throw new RuntimeException('unexpected analytics file name'); }
    $dir = analytics_safe_dir();
    $path = $dir . '/' . $name;
    if (is_link($path)) { throw new RuntimeException('analytics file is a link: ' . $name); }
    if (is_file($path)) {
        $real = realpath($path);
        $dirReal = realpath($dir);
        if ($real === false || $dirReal === false || dirname($real) !== $dirReal) { throw new RuntimeException('analytics file escapes directory: ' . $name); }
    }
    // A missing analytics directory simply has no files yet; safe_dir already
    // verified the directory location for existing directories.
    return $path;
}

/** Bounded exclusive/shared lock around a dedicated lock file; throws on timeout. */
function analytics_with_lock(callable $fn, bool $exclusive = true, int $waitMs = 0): mixed {
    $dir = analytics_safe_dir();
    if (!is_dir($dir) && !@mkdir($dir, 0700, true) && !is_dir($dir)) { throw new RuntimeException('analytics directory unavailable'); }
    $handle = @fopen(analytics_safe_path('analytics.lock'), 'c');
    if ($handle === false) { throw new RuntimeException('analytics lock unavailable'); }
    $deadline = microtime(true) + max(0, $waitMs ?: ($exclusive ? ANALYTICS_WRITE_LOCK_MS : ANALYTICS_READ_LOCK_MS)) / 1000;
    $mode = $exclusive ? LOCK_EX : LOCK_SH;
    do { $held = @flock($handle, $mode | LOCK_NB); if (!$held) { usleep(15000); } }
    while (!$held && microtime(true) < $deadline);
    if (!$held) { fclose($handle); throw new RuntimeException('analytics lock busy'); }
    try { return $fn(); } finally { flock($handle, LOCK_UN); fclose($handle); }
}

function analytics_status_path(): string { return analytics_safe_path('status.json'); }

/** @param array<string,mixed> $patch */
function analytics_patch_status_locked(array $patch): void {
    $path = analytics_status_path();
    $status = is_file($path) ? json_decode((string)@file_get_contents($path, false, null, 0, 65536), true) : [];
    if (!is_array($status)) { $status = []; }
    $status = array_merge($status, $patch);
    $temp = analytics_safe_path('status.json.tmp');
    if (@file_put_contents($temp, json_encode($status, JSON_UNESCAPED_SLASHES)) !== false) {
        @chmod($temp, 0600);
        if (!@rename($temp, $path)) { @unlink($temp); }
    }
}

function analytics_read_status(): array {
    try {
        if (analytics_path_state() === 'absent') { return []; }
        $path = analytics_status_path();
        $status = is_file($path) ? json_decode((string)@file_get_contents($path, false, null, 0, 65536), true) : [];
        return is_array($status) ? $status : [];
    } catch (Throwable $e) {
        error_log('analytics status: ' . $e->getMessage());
        return [];
    }
}

/**
 * Analytics path state: 'absent' when nothing was ever recorded (fresh
 * install), 'ready' when usable. A link, a non-directory or an unreadable
 * directory throws: permission/path failures must surface, never masquerade
 * as zero traffic.
 */
function analytics_path_state(): string {
    $dir = analytics_dir();
    if (is_link($dir)) { throw new RuntimeException('analytics path is a link'); }
    if (!file_exists($dir)) { return 'absent'; }
    if (!is_dir($dir)) { throw new RuntimeException('analytics path is not a directory'); }
    analytics_safe_dir();
    return 'ready';
}

/** Sorted day files by UTC day number; names are always server-controlled. */
function analytics_day_files_locked(): array {
    if (analytics_path_state() === 'absent') { return []; }
    $entries = @scandir(analytics_safe_dir());
    if ($entries === false) { throw new RuntimeException('analytics directory unreadable'); }
    $files = [];
    foreach ($entries as $name) {
        if (preg_match('/^d-(\d{8})\.jsonl$/D', $name, $m)
            && checkdate((int)substr($m[1], 4, 2), (int)substr($m[1], 6, 2), (int)substr($m[1], 0, 4))) { $files[(int)$m[1]] = $name; }
    }
    ksort($files);
    return $files;
}

/**
 * Drop expired days, then oldest days until the incoming line fits the total
 * cap and the file count cap. Returns the remaining total size. Bounded
 * metadata reads: at most ANALYTICS_MAX_FILES + slack files are ever stat-ed.
 */
function analytics_prune_locked(int $now, int $incomingBytes): int {
    $files = analytics_day_files_locked();
    $today = (int)gmdate('Ymd', $now);
    $cutoff = (int)gmdate('Ymd', $now - ANALYTICS_RETENTION_DAYS * 86400);
    $sizes = []; $total = 0;
    foreach ($files as $day => $name) {
        $size = @filesize(analytics_safe_path($name));
        $sizes[$day] = $size === false ? 0 : $size;
        $total += $sizes[$day];
    }
    $remaining = count($files);
    $removed = false;
    foreach ($files as $day => $name) {
        $expired = $day < $cutoff;
        $overTotal = ($total + $incomingBytes) > ANALYTICS_MAX_TOTAL_BYTES;
        $tooMany = $remaining > ANALYTICS_MAX_FILES;
        if (!$expired && !$overTotal && !$tooMany) { break; }
        // The newest day is never deleted to make room; the overflow flag covers it.
        if ($day === $today) { break; }
        if (@unlink(analytics_safe_path($name))) { $total -= $sizes[$day]; $remaining--; $removed = true; unset($files[$day]); }
    }
    if ($removed) {
        // The original collection start is preserved; the earliest retained
        // day is tracked separately because pruning may delete older files.
        $earliest = null;
        foreach ($files as $name) {
            $handle = @fopen(analytics_safe_path($name), 'r');
            if ($handle === false) { continue; }
            while (($line = fgets($handle, ANALYTICS_MAX_LINE_BYTES + 64)) !== false) {
                $event = json_decode(trim($line), true, 16);
                if (is_array($event) && isset($event['t']) && is_int($event['t'])) { $earliest = $event['t']; break; }
            }
            fclose($handle);
            if ($earliest !== null) { break; }
        }
        analytics_patch_status_locked(['earliest' => $earliest]);
    }
    return $total;
}

/**
 * Record one event. Returns 1 written, 0 duplicate (dedup key seen), -1 dropped.
 * All failures are contained: the caller's page or redirect must keep working.
 * Writers are serialized by the dedicated lock, so the append itself never
 * takes a second file lock (that could block forever under contention). The
 * seen index is committed only after a successful append, so a dropped event
 * can still be counted when the client retries with the same id.
 */
function analytics_record_event(array $event, ?string $dedupKey = null): int {
    try {
        return (int)analytics_with_lock(static function () use ($event, $dedupKey): int {
            $now = $event['t'];
            $line = json_encode($event, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PARTIAL_OUTPUT_ON_ERROR);
            if (!is_string($line)) { return -1; }
            $line .= "\n";
            if (strlen($line) > ANALYTICS_MAX_LINE_BYTES) { return -1; }

            $seen = null;
            if ($dedupKey !== null) {
                $seenPath = analytics_safe_path('seen.json');
                $seen = is_file($seenPath) ? json_decode((string)@file_get_contents($seenPath, false, null, 0, 1048576), true) : [];
                if (!is_array($seen)) { $seen = []; }
                foreach ($seen as $id => $ts) { if (!is_int($ts) || $ts < $now - ANALYTICS_SEEN_TTL) { unset($seen[$id]); } }
                if (count($seen) > ANALYTICS_SEEN_MAX) { asort($seen); $seen = array_slice($seen, -ANALYTICS_SEEN_MAX, null, true); }
                if (array_key_exists($dedupKey, $seen)) { return 0; }
                $seen[$dedupKey] = $now;
            }

            $total = analytics_prune_locked($now, strlen($line));
            if (count(analytics_day_files_locked()) > ANALYTICS_MAX_FILES || $total + strlen($line) > ANALYTICS_MAX_TOTAL_BYTES) {
                analytics_patch_status_locked(['overflow' => true]);
                return -1;
            }
            $file = analytics_safe_path('d-' . gmdate('Ymd', $now) . '.jsonl');
            $existing = @filesize($file);
            if ($existing === false) { $existing = 0; }
            if ($existing + strlen($line) > ANALYTICS_MAX_DAY_BYTES) {
                analytics_patch_status_locked(['overflow' => true]);
                return -1;
            }
            if (@file_put_contents($file, $line, FILE_APPEND) === false) { return -1; }
            @chmod($file, 0600);

            if ($seen !== null) {
                $temp = analytics_safe_path('seen.json.tmp');
                if (@file_put_contents($temp, json_encode($seen, JSON_UNESCAPED_SLASHES)) === false
                    || !@rename($temp, analytics_safe_path('seen.json'))) {
                    @unlink($temp);
                    // Event written but dedup unmarked: a retry may over-count,
                    // which is safer than silently losing a real click.
                    error_log('analytics seen commit failed');
                }
            }
            $status = analytics_read_status();
            $patch = ['overflow' => false, 'last' => $now, 'earliest' => $status['earliest'] ?? $now];
            if (!isset($status['first'])) { $patch['first'] = $now; }
            analytics_patch_status_locked($patch);
            return 1;
        });
    } catch (Throwable $e) {
        error_log('analytics record: ' . $e->getMessage());
        return -1;
    }
}

/**
 * Yield parsed events with scanFrom <= t <= to, oldest file first.
 * Skips corrupt lines (counted in $stats) and bounds file count for a window.
 * @param array<string,int|bool> $stats
 */
function analytics_iter_events(int $scanFrom, int $to, array &$stats = []): iterable {
    $startDay = (int)gmdate('Ymd', $scanFrom);
    $endDay = (int)gmdate('Ymd', $to);
    $names = [];
    foreach (analytics_day_files_locked() as $day => $name) {
        if ($day >= $startDay && $day <= $endDay) { $names[$day] = $name; }
    }
    foreach ($names as $name) {
        $handle = @fopen(analytics_safe_path($name), 'r');
        if ($handle === false) { $stats['unreadable'] = ($stats['unreadable'] ?? 0) + 1; continue; }
        try {
            while (($line = fgets($handle, ANALYTICS_MAX_LINE_BYTES + 64)) !== false) {
                $line = trim($line);
                if ($line === '') { continue; }
                $event = json_decode($line, true, 16);
                if (!is_array($event) || !isset($event['k']) || !is_string($event['k']) || !isset($event['t']) || !is_int($event['t'])) {
                    $stats['corrupt'] = ($stats['corrupt'] ?? 0) + 1;
                    continue;
                }
                if ($event['t'] < $scanFrom || $event['t'] > $to) { continue; }
                yield $event;
            }
        } finally { fclose($handle); }
    }
}

/** Compact in-memory signature of one pv's source fields. */
function analytics_source_key(array $event): string {
    $u = isset($event['u']) && is_array($event['u']) ? $event['u'] : [];
    $s = isset($u['s']) && is_string($u['s']) ? $u['s'] : '';
    $m = isset($u['m']) && is_string($u['m']) ? $u['m'] : '';
    $r = isset($event['r']) && is_string($event['r']) ? $event['r'] : '';
    return $s . "\x1f" . $m . "\x1f" . $r;
}

function analytics_source_label(string $key): string {
    [$s, $m, $r] = array_pad(explode("\x1f", $key, 3), 3, '');
    if ($s !== '' || $m !== '') {
        return analytics_shorten(trim(($s !== '' ? $s : '—') . ' / ' . ($m !== '' ? $m : '—')), 80);
    }
    return $r !== '' ? analytics_shorten($r, 80) : '直接 / 未知';
}

/** The key a row lands on once the cardinality cap folds extras into one row. */
function analytics_capped_key(array $table, string $key): string {
    if (isset($table[$key]) || count($table) < ANALYTICS_CARDINALITY_CAP) { return $key; }
    return "\x00overflow";
}

/** Row skeleton so every dimension table shares the same shape. */
function analytics_dim_row(): array { return ['sessions' => 0, 'pv' => 0, 'clicks' => 0, 'click_visits' => 0]; }

/** Pure in-memory empty window: the fallback that never touches a broken store. */
function analytics_empty_aggregate(): array {
    return ['totals' => ['pv' => 0, 'uv' => 0, 'sessions' => 0, 'clicks' => 0, 'click_visits' => 0, 'click_rate' => null,
                         'clicks_unlinked' => 0, 'allocations' => 0, 'allocations_linked' => 0, 'allocations_unlinked' => 0],
            'trend' => [], 'sources' => [], 'campaigns' => [], 'devices' => [], 'browsers' => [], 'systems' => [],
            'numbers' => [], 'stats' => ['corrupt' => 0, 'unreadable' => 0, 'truncated' => false]];
}

/**
 * Fold one finished session into the dimension tables when it has pvs in the
 * window. Tables live in one array so no per-table references are needed.
 */
function analytics_close_session(array $firstPv, array $pvInWindowFlags, array &$totals, array &$dims): void {
    $windowPv = count(array_keys($pvInWindowFlags, true, true));
    if ($windowPv === 0) { return; }
    $totals['sessions']++;
    $labels = [
        'sources' => analytics_source_label($firstPv[1]),
        'campaigns' => $firstPv[2] !== '' ? analytics_shorten($firstPv[2], 60) : '(未设置)',
        'devices' => $firstPv[3], 'browsers' => $firstPv[4], 'systems' => $firstPv[5],
    ];
    foreach (['sources', 'campaigns', 'devices', 'browsers', 'systems'] as $table) {
        $rowKey = analytics_capped_key($dims[$table], $labels[$table]);
        $dims[$table][$rowKey] = $dims[$table][$rowKey] ?? analytics_dim_row();
        $dims[$table][$rowKey]['sessions']++;
        $dims[$table][$rowKey]['pv'] += $windowPv;
    }
}

/**
 * Aggregate one window [from,to] (UTC timestamps). Sessions chain over pvs
 * with a 30-minute inactivity gap; the scan starts 30 minutes early so
 * sessions beginning just before the window still chain correctly. Clicks and
 * allocations link to the latest pv of the same visitor at or before the
 * event, when it falls inside the same 30-minute activity window. Conversion
 * numerator and denominator both use only sessions that have a pv in window.
 */
function analytics_aggregate(int $from, int $to, ?int $retainedFrom = null): array {
    $stats = ['corrupt' => 0, 'unreadable' => 0, 'truncated' => false];
    $scanFrom = $from - ANALYTICS_SESSION_GAP;
    $tz = new DateTimeZone('Asia/Shanghai');
    $hourly = ($to - $from) < 48 * 3600;
    $bucketKey = static function (int $ts) use ($tz, $hourly): string {
        return (new DateTimeImmutable('@' . $ts))->setTimezone($tz)->format($hourly ? 'Y-m-d H:00' : 'Y-m-d');
    };

    $visitors = [];      // vid => list of pv rows: [t, sourceKey, campaign, device, browser, os, sessionSeq]
    $clicks = [];        // [t, vid]
    $allocs = [];        // [t, vid, number]
    $scanned = 0;
    $incompleteFrom = null;
    foreach (analytics_iter_events($scanFrom, $to, $stats) as $event) {
        $t = $event['t'];
        if (++$scanned > ANALYTICS_SCAN_EVENT_CAP) {
            $stats['truncated'] = true;
            // Events within the same UTC file can be appended out of order.
            $incompleteFrom = intdiv($t, 86400) * 86400;
            break;
        }
        $vid = isset($event['v']) && is_string($event['v']) && preg_match(ANALYTICS_VISITOR_PATTERN, $event['v']) ? $event['v'] : '';
        if ($event['k'] === 'pv') {
            if ($vid === '') { continue; }
            $u = isset($event['u']) && is_array($event['u']) ? $event['u'] : [];
            $visitors[$vid][] = [$t, analytics_source_key($event),
                isset($u['c']) && is_string($u['c']) ? $u['c'] : '',
                isset($event['d']) && is_string($event['d']) ? $event['d'] : '?',
                isset($event['b']) && is_string($event['b']) ? $event['b'] : '?',
                isset($event['o']) && is_string($event['o']) ? $event['o'] : '?', -1];
        } elseif ($event['k'] === 'cc' && $t >= $from) {
            $clicks[] = [$t, $vid];
        } elseif ($event['k'] === 'as' && $t >= $from) {
            $allocs[] = [$t, $vid, isset($event['n']) && is_string($event['n']) ? $event['n'] : ''];
        }
    }

    $totals = ['pv' => 0, 'uv' => 0, 'sessions' => 0, 'clicks' => 0, 'click_visits' => 0, 'click_rate' => null,
               'clicks_unlinked' => 0, 'allocations' => 0, 'allocations_linked' => 0, 'allocations_unlinked' => 0];
    $buckets = [];       // key => ['pv', 'uv' => vid set, 'clicks', 'allocations']
    $dims = ['sources' => [], 'campaigns' => [], 'devices' => [], 'browsers' => [], 'systems' => []];
    $numbers = [];
    $clickVisitSessions = [];   // "vid:seq" => true
    $campaignConverting = [];   // campaign label => set of "vid:seq"
    $sessionMeta = [];          // vid => [seq => first pv index, seq => has pv in window]

    foreach ($visitors as $vid => &$pvs) {
        usort($pvs, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        // Chain sessions: sequence number increments on a gap over the limit.
        // Session start index and window membership are recorded here so the
        // click loop needs one binary search instead of linear rescans.
        $seq = -1; $prevT = PHP_INT_MIN; $sessionFirst = null; $flags = [];
        $firstIdx = []; $windowFlag = [];
        foreach ($pvs as $index => &$pv) {
            if ($prevT === PHP_INT_MIN || $pv[0] - $prevT > ANALYTICS_SESSION_GAP) {
                if ($sessionFirst !== null) { analytics_close_session($sessionFirst, $flags, $totals, $dims); }
                $seq++; $sessionFirst = $pv; $flags = [];
                $firstIdx[$seq] = $index; $windowFlag[$seq] = false;
            }
            $pv[6] = $seq;
            $prevT = $pv[0];
            $flags[] = $pv[0] >= $from;
            if ($pv[0] >= $from) {
                $windowFlag[$seq] = true;
                $totals['pv']++;
                $key = $bucketKey($pv[0]);
                $buckets[$key] = $buckets[$key] ?? ['pv' => 0, 'uv' => [], 'clicks' => 0, 'allocations' => 0];
                $buckets[$key]['pv']++;
                $buckets[$key]['uv'][$vid] = true;
            }
        }
        unset($pv);
        if ($sessionFirst !== null) { analytics_close_session($sessionFirst, $flags, $totals, $dims); }
        $sessionMeta[$vid] = [$firstIdx, $windowFlag];
    }
    unset($pvs);

    // Latest pv of the visitor at or before t, inside the activity gap; the
    // returned pv index also unlocks O(1) session metadata lookups.
    $findPvIndex = static function (int $t, string $vid) use ($visitors): int {
        if ($vid === '' || !isset($visitors[$vid])) { return -1; }
        $pvs = $visitors[$vid];
        $low = 0; $high = count($pvs) - 1; $found = -1;
        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);
            if ($pvs[$mid][0] <= $t) { $found = $mid; $low = $mid + 1; } else { $high = $mid - 1; }
        }
        if ($found === -1 || $t - $pvs[$found][0] > ANALYTICS_SESSION_GAP) { return -1; }
        return $found;
    };

    foreach ($clicks as [$t, $vid]) {
        $totals['clicks']++;
        $key = $bucketKey($t);
        $buckets[$key] = $buckets[$key] ?? ['pv' => 0, 'uv' => [], 'clicks' => 0, 'allocations' => 0];
        $buckets[$key]['clicks']++;
        $found = $findPvIndex($t, $vid);
        if ($found < 0) { $totals['clicks_unlinked']++; continue; }
        $pvs = $visitors[$vid];
        $seq = $pvs[$found][6];
        [$firstIdx, $windowFlag] = $sessionMeta[$vid];
        // Both conversion numerator and denominator need an in-window session.
        if (!$windowFlag[$seq]) { continue; }
        $first = $pvs[$firstIdx[$seq]];
        $marker = $vid . ':' . $seq;
        if (isset($clickVisitSessions[$marker])) {
            // Repeat real clicks still count as clicks, conversions only once.
        } else {
            $clickVisitSessions[$marker] = true;
            $sourceLabel = analytics_source_label($first[1]);
            $rowKey = analytics_capped_key($dims['sources'], $sourceLabel);
            $dims['sources'][$rowKey] = $dims['sources'][$rowKey] ?? analytics_dim_row();
            $dims['sources'][$rowKey]['click_visits']++;
            $campaignLabel = $first[2] !== '' ? analytics_shorten($first[2], 60) : '(未设置)';
            $campaignConverting[$campaignLabel][$marker] = true;
        }
        $sourceLabel = analytics_source_label($first[1]);
        $rowKey = analytics_capped_key($dims['sources'], $sourceLabel);
        $dims['sources'][$rowKey] = $dims['sources'][$rowKey] ?? analytics_dim_row();
        $dims['sources'][$rowKey]['clicks']++;
    }
    foreach ($allocs as [$t, $vid, $number]) {
        $totals['allocations']++;
        $key = $bucketKey($t);
        $buckets[$key] = $buckets[$key] ?? ['pv' => 0, 'uv' => [], 'clicks' => 0, 'allocations' => 0];
        $buckets[$key]['allocations']++;
        $number = $number === '' ? '(未知号码)' : $number;
        $numbers[$number] = $numbers[$number] ?? ['allocations' => 0, 'linked' => 0];
        $numbers[$number]['allocations']++;
        if ($findPvIndex($t, $vid) >= 0) { $numbers[$number]['linked']++; $totals['allocations_linked']++; }
        else { $totals['allocations_unlinked']++; }
    }
    $uvVisitors = 0;
    foreach ($visitors as $pvs) { foreach ($pvs as $pv) { if ($pv[0] >= $from) { $uvVisitors++; break; } } }
    $totals['uv'] = $uvVisitors;
    $totals['click_visits'] = count($clickVisitSessions);
    $totals['click_rate'] = $totals['sessions'] > 0 ? round($totals['click_visits'] * 100 / $totals['sessions'], 1) : null;

    // Fold per-campaign click conversions into campaign rows.
    foreach ($campaignConverting as $campaignLabel => $markers) {
        $rowKey = analytics_capped_key($dims['campaigns'], $campaignLabel);
        $dims['campaigns'][$rowKey] = $dims['campaigns'][$rowKey] ?? analytics_dim_row();
        $dims['campaigns'][$rowKey]['click_visits'] += count($markers);
    }

    // Null marks unknown buckets; zero is reserved for observed quiet periods.
    $trend = [];
    $start = (new DateTimeImmutable('@' . $from))->setTimezone($tz);
    $cursor = $start->setTime($hourly ? (int)$start->format('G') : 0, 0);
    $end = (new DateTimeImmutable('@' . $to))->setTimezone($tz);
    $step = $hourly ? '+1 hour' : '+1 day';
    while ($cursor <= $end && count($trend) < 750) {
        $key = $bucketKey($cursor->getTimestamp());
        $next = $cursor->modify($step);
        $unknown = ($retainedFrom !== null && $next->getTimestamp() <= $retainedFrom)
            || ($incompleteFrom !== null && $next->getTimestamp() > $incompleteFrom);
        $row = $buckets[$key] ?? null;
        $trend[] = [
            'label' => $key, 'pv' => $unknown ? null : ($row['pv'] ?? 0),
            'uv' => $unknown ? null : count($row['uv'] ?? []),
            'clicks' => $unknown ? null : ($row['clicks'] ?? 0),
            'allocations' => $unknown ? null : ($row['allocations'] ?? 0),
        ];
        $cursor = $next;
    }

    $orderRows = static function (array $table, array $order, callable $labelOf): array {
        $rows = [];
        foreach ($table as $key => $row) {
            // PHP casts purely numeric array keys (phone numbers) to int;
            // cast back before the strictly typed label callback.
            $out = ['label' => $labelOf((string)$key)];
            foreach ($order as $field) { $out[$field] = $row[$field] ?? 0; }
            if (in_array('click_visits', $order, true)) {
                $out['rate'] = $out['sessions'] > 0 ? round($out['click_visits'] * 100 / $out['sessions'], 1) : null;
            }
            $rows[] = $out;
        }
        usort($rows, static fn(array $a, array $b): int => ($b[$order[0]] <=> $a[$order[0]]) ?: strcmp((string)$a['label'], (string)$b['label']));
        return $rows;
    };
    $plainLabel = static fn(string $key): string => $key === "\x00overflow" ? '(超出统计上限)' : $key;
    return [
        'totals' => $totals,
        'trend' => $trend,
        // Session rows already carry formatted source labels; plain passthrough
        // keeps them exactly as folded (no second analytics_source_label call).
        'sources' => $orderRows($dims['sources'], ['sessions', 'pv', 'clicks', 'click_visits'], $plainLabel),
        'campaigns' => $orderRows($dims['campaigns'], ['sessions', 'pv', 'click_visits'], $plainLabel),
        'devices' => $orderRows($dims['devices'], ['sessions', 'pv'], $plainLabel),
        'browsers' => $orderRows($dims['browsers'], ['sessions', 'pv'], $plainLabel),
        'systems' => $orderRows($dims['systems'], ['sessions', 'pv'], $plainLabel),
        'numbers' => $orderRows($numbers, ['allocations', 'linked'], $plainLabel),
        'stats' => $stats,
    ];
}
