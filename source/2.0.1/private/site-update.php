<?php
declare(strict_types=1);
// Super-admin update screen. Separate from the editor so update operations can
// never publish unrelated content drafts.
require __DIR__ . '/bootstrap.php';
admin_headers();
$error = '';
$authenticated = false;
$siteName = 'OCPEPTIDE';
$isSuper = false;
try {
    $state = read_state();
    $siteName = $state['settings']['site_name'];
    $admin = current_admin($state);
    $authenticated = true;
    if (!empty($admin['must_change_credentials'])) {
        header('Location: /admin/', true, 303);
        exit;
    }
    if ($admin['role'] !== 'super') { http_response_code(403); $error = '只有超级管理员可以使用系统更新。'; }
    else { $isSuper = true; }
} catch (ApiError $e) { if ($e->status !== 401) { $error = $e->getMessage(); } }
catch (Throwable $e) { error_log((string)$e); http_response_code(503); $error = '后台暂未就绪，请检查部署目录与数据文件。'; }
?><!doctype html>
<html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= esc($siteName) ?> · 系统更新</title>
<link rel="stylesheet" href="/assets/admin.css?v=<?= urlencode(CODE_VERSION) ?>">
</head><body>
<?php if (!$authenticated): ?>
<main class="login-state"><div class="brand-mark"><?= esc(site_initials($siteName)) ?></div><h1>系统更新</h1>
<p id="login-message" class="login-message dismissible-message error" role="status"><span id="login-message-text">请先登录超级管理员账号后再使用系统更新。</span></p>
<a href="/admin/">返回登录</a></main>
<?php elseif (!$isSuper): ?>
<main class="login-state"><div class="brand-mark"><?= esc(site_initials($siteName)) ?></div><h1>系统更新</h1>
<p id="login-message" class="login-message dismissible-message error" role="status"><span id="login-message-text"><?= esc($error) ?></span></p>
<a href="/admin/">返回后台</a></main>
<?php else: ?>
<div class="admin-shell update-shell">
  <aside class="sidebar">
    <div class="brand"><span class="brand-mark"><?= esc(site_initials($siteName)) ?></span><span class="brand-copy"><span><?= esc($siteName) ?></span><small>系统更新</small></span></div>
    <nav aria-label="更新导航">
      <a class="nav-item" href="/admin/"><span aria-hidden="true">↩</span> 返回后台</a>
      <a class="nav-item" href="/update-recovery.php" target="_blank" rel="noreferrer"><span aria-hidden="true">⛭</span> 恢复入口</a>
    </nav>
    <div class="sidebar-bottom"><span id="role-label">正在加载…</span></div>
  </aside>
  <main class="workspace">
    <header class="topbar"><div><span class="domain"><?= esc($_SERVER['HTTP_HOST'] ?? '') ?></span><span class="status-dot">系统更新</span></div><a href="/" target="_blank" rel="noreferrer">打开网站 ↗</a></header>
    <div class="page-heading"><div><h1>系统更新</h1><p>检查并安装签名更新包；更新前自动备份，可随时回滚。客户数据、图片和账号不受影响。</p></div></div>
    <div id="message" class="dismissible-message" role="status" aria-live="polite" hidden><span id="message-content"></span><button type="button" id="dismiss-message" class="notice-close" aria-label="关闭提示" title="关闭提示">×</button></div>
    <div id="update-editor" aria-busy="true">
      <section class="panel" id="update-panel">
        <section class="form-section"><div class="section-heading"><div><h2>当前状态</h2><p>版本、环境与更新器配置。</p></div><span id="update-version" class="pill">…</span></div>
          <ul id="update-env" class="update-list"></ul>
          <p class="help" id="update-trust"></p>
        </section>
        <section class="form-section"><div class="section-heading"><div><h2>在线检查更新</h2><p>从部署时配置的 HTTPS 更新源获取签名版本信息。</p></div><button id="check-update" class="secondary" type="button">检查更新</button></div>
          <div id="update-latest" aria-live="polite"></div>
        </section>
        <section class="form-section"><div class="section-heading"><div><h2>安装更新</h2><p>安装前会完整备份现有代码；安装过程中网站短暂维护，客户数据不改动。</p></div></div>
          <form id="install-source-form" hidden>
            <p class="help" id="install-source-notes"></p>
            <label class="field">确认密码<input id="install-password" type="password" required maxlength="72" autocomplete="current-password"></label>
            <button id="install-source" class="primary" type="submit">安装此版本</button>
          </form>
          <form id="install-upload-form">
            <label class="field">或上传签名更新包（.zip）<input id="package-upload" type="file" accept=".zip" required></label>
            <label class="field">确认密码<input id="upload-password" type="password" required maxlength="72" autocomplete="current-password"></label>
            <button id="install-upload" class="secondary" type="submit">上传并安装</button>
            <p class="help">适用于服务器无法下载大包的情况；仍需连接更新源核对发行与撤回状态。签名或文件摘要不符的包会被拒绝。</p>
          </form>
        </section>
        <section class="form-section"><div class="section-heading"><div><h2>任务与恢复</h2><p>最近一次更新的阶段、健康检查结果和回滚入口。</p></div></div>
          <div id="update-task" aria-live="polite"></div>
          <form id="rollback-form" hidden>
            <label class="field">确认密码<input id="rollback-password" type="password" required maxlength="72" autocomplete="current-password"></label>
            <button id="rollback" class="danger" type="submit">回滚到上一版本</button>
            <p class="help">回滚只恢复程序代码，不回退你之后修改的内容、图片或联系分流。</p>
          </form>
          <button id="verify-update" class="secondary" type="button" hidden>重新健康检查</button>
          <form id="cleanup-form">
            <label class="field">清理确认密码<input id="cleanup-password" type="password" required maxlength="72" autocomplete="current-password"></label>
            <button id="cleanup-backups" class="text-button" type="submit">清理过期备份</button>
            <p class="help">按保留策略保留近期备份，并保留当前版本的回滚备份；未知目录保留待人工核对。删除后无法恢复。</p>
          </form>
        </section>
        <section class="form-section"><h2>备份</h2><ul id="update-backups" class="update-list"></ul>
          <p class="help">备份保存在服务器私有目录 private/update/backups，不对外公开。恢复入口：<code>/update-recovery.php</code>。</p>
        </section>
      </section>
    </div>
  </main>
</div><script src="/assets/update-ui.js?v=<?= urlencode(CODE_VERSION) ?>" defer></script>
<?php endif; ?></body></html>
