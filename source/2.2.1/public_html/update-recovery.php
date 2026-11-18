<?php
declare(strict_types=1);
if ((string)ini_get('opcache.preload') !== '') { http_response_code(503); exit('Preloaded business PHP is not supported.'); }
if (ini_get('opcache.enable') && function_exists('ini_set')) { @ini_set('opcache.enable', '0'); }
if (ini_get('opcache.enable')) { http_response_code(503); exit('Request cache cannot be disabled.'); }
// Independent recovery entry. It loads no business code and never touches
// customer sessions or state — only the protected engine copy. Visiting this
// page resumes clearly interrupted tasks (or cancels stale untouched ones);
// anonymous visitors get a stage-level summary only, never task ids, paths,
// tokens or raw errors. Manual action stays a host-panel operation.
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store, private, max-age=0');
header('X-Robots-Tag: noindex, nofollow, noarchive');
header('X-Content-Type-Options: nosniff');
$private = dirname(__DIR__) . '/private';
$engine = $private . '/update/protected/updater-engine.php';
if (!is_file($engine)) { $engine = $private . '/updater-engine.php'; }
if (!is_file($engine)) {
    http_response_code(500);
    echo "恢复引擎缺失。\n\n请在主机面板确认 private/updater-engine.php 或 private/update/protected/updater-engine.php 存在，\n或按 docs/UPDATE.md 的恢复章节手动处理 private/update/ 内的任务与备份。";
    exit;
}
require $engine;
echo "OCPEPTIDE 更新恢复入口\n======================\n";
try {
    $lock = updater_lock('update.lock', false);
    if ($lock === null) {
        echo "另一个更新进程正在运行（update.lock 被占用）。\n请等待其完成，或稍后刷新本页。若确认无更新在运行，请检查服务器进程。";
        exit;
    }
    try { $actions = updater_resolve_active_tasks(); }
    finally { updater_unlock($lock); }
    echo $actions === [] ? "没有需要自动恢复的更新任务。\n" : "已执行恢复动作：\n- " . implode("\n- ", $actions) . "\n";
    $maintenance = updater_maintenance_active();
    $last = updater_last_task();
    if ($last !== null) {
        echo "\n最近任务阶段：" . ($last['stage'] ?? '?') . "\n";
        if (($last['stage'] ?? '') === 'recovery_required') {
            echo "该任务需要人工恢复：请按 docs/UPDATE.md 在主机面板恢复完整程序和保护引擎，\n核对可信基线与健康检查后再由维护人员解除维护，不要直接删除标记。\n";
        }
    }
    if ($maintenance && ($last === null || in_array($last['stage'] ?? '', ['recovery_required'], true) || $actions === [])) {
        echo "\n维护标志仍然存在（任务日志缺失或损坏时不会自动清除）。\n不要仅删除维护标记或任务日志；请按 docs/UPDATE.md 从完整可信快照恢复并校验后解除维护。\n";
    }
    echo "\n维护状态：" . ($maintenance ? '维护中' : '正常') . "\n";
    $info = updater_code_info();
    echo '当前代码版本：' . $info['version'] . "\n";
    echo "回滚等危险操作请由超级管理员在后台“系统更新”页完成。\n";
} catch (Throwable $error) {
    http_response_code(500);
    echo "恢复过程出现错误（详情见服务器日志与 private/update/tasks/）。\n请按 docs/UPDATE.md 的手动恢复章节处理，不要直接删除任务文件。";
    error_log('[update-recovery] ' . $error->getMessage());
}
