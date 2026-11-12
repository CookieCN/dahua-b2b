<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';
$updateProbe = defined('OCPEPTIDE_UPDATE_PROBE') && OCPEPTIDE_UPDATE_PROBE;
if (!$updateProbe) { admin_headers(); }
else { header('Cache-Control: no-store, private'); }
$error = '';
$authenticated = false;
$siteName = 'OCPEPTIDE';
try {
    if (isset($_GET['token'])) {
        header('Location: /admin/', true, 303);
        exit;
    }
    $state = read_state();
    $siteName = $state['settings']['site_name'];
    $modular = $state['settings']['display_mode'] === 'multi';
    if (!$updateProbe) { current_admin($state); $authenticated = true; }
} catch (ApiError $e) { if ($e->status !== 401) { $error = $e->getMessage(); } }
catch (Throwable $e) { error_log((string)$e); http_response_code(503); $error = '后台暂未就绪，请检查部署目录与数据文件。'; }
?><!doctype html>
<html lang="zh-CN"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow"><title><?= esc($siteName) ?> · 网站管理</title>
<link rel="stylesheet" href="/assets/admin.css?v=<?= urlencode(CODE_VERSION) ?>">
</head><body>
<?php if (!$authenticated): ?>
<main class="login-state"><div class="brand-mark"><?= esc(site_initials($siteName)) ?></div><h1>登录网站管理</h1><p id="login-description">使用你的管理员账号登录。</p>
<div id="login-message" class="login-message dismissible-message error" role="status" <?= $error === '' ? 'hidden' : '' ?>><span id="login-message-text"><?= esc($error) ?></span><button type="button" id="dismiss-login-message" class="notice-close" aria-label="关闭提示" title="关闭提示">×</button></div>
<form id="login-form"><label class="field">账号<input id="login-username" autocomplete="username" required maxlength="32" placeholder="请输入账号"></label><label class="field">密码<input id="login-password" type="password" autocomplete="current-password" required maxlength="72" placeholder="请输入密码"></label><button type="button" id="show-password" class="text-button">显示密码</button><div class="field"><label id="math-label" for="math-answer">真人校验</label><div class="math-challenge"><span id="math-question">正在加载题目…</span><input id="math-answer" type="text" inputmode="numeric" pattern="[0-9]{1,3}" maxlength="3" required autocomplete="off" aria-labelledby="math-label math-question" placeholder="结果"><button type="button" id="refresh-challenge" class="text-button" disabled>换一题</button></div></div><button type="submit" class="primary login-submit" disabled>登录</button></form>
<form id="upgrade-form" hidden><p class="help">验证原主管理员凭据后，为 admin 设置密码。此功能仅在旧版首次升级时可用，完成后旧 token 全部失效。</p><label class="field">原主管理员 token<input id="legacy-token" type="password" required maxlength="64" autocomplete="off"></label><label class="field">新密码<input id="upgrade-password" type="password" minlength="8" maxlength="72" required autocomplete="new-password"></label><label class="field">再次输入新密码<input id="upgrade-confirm" type="password" minlength="8" maxlength="72" required autocomplete="new-password"></label><button type="submit" class="primary login-submit" disabled>升级为账号密码登录</button></form>
<pre id="migration-credentials" class="migration-credentials" hidden></pre><a href="/">查看网站</a></main>
<script src="/assets/login.js?v=<?= urlencode(CODE_VERSION) ?>" defer></script>
<?php else: ?>
<div class="admin-shell">
  <aside class="sidebar">
    <button class="brand" id="brand-edit" type="button" aria-label="修改网站名称" aria-controls="brand-form" aria-expanded="false" title="点击修改网站名称" disabled><span class="brand-mark" id="brand-avatar"><?= esc(site_initials($siteName)) ?></span><span class="brand-copy"><span id="brand-name"><?= esc($siteName) ?></span><small>网站管理 <span aria-hidden="true">✎</span></small></span><span class="brand-pencil" aria-hidden="true">✎</span></button>
    <form id="brand-form" class="brand-form" hidden><fieldset id="brand-fields"><label for="site_name">网站名称</label><input id="site_name" type="text" maxlength="60" required autocomplete="off"><p>支持中文、英文、数字，最多 60 个字符。</p><div class="brand-actions"><button type="submit" id="brand-save">保存名称</button><button type="button" id="brand-cancel">取消</button></div><p id="brand-error" role="alert" hidden></p></fieldset></form>
    <nav aria-label="后台导航">
      <button class="nav-item active" data-panel="content" aria-current="page"><span aria-hidden="true">▧</span> 页面内容</button>
      <div class="mode-nav">
        <label class="mode-switch">
          <span><span id="display-mode-name">单/多品模式切换</span><small id="display-mode-value"><?= $modular ? '多产品展示' : '单品展示' ?></small></span>
          <input id="display_mode" type="checkbox" role="switch" aria-labelledby="display-mode-name" aria-describedby="display-mode-value" <?= $modular ? 'checked' : '' ?> disabled>
        </label>
        <?php if ($modular): ?>
        <div class="mode-submenu"><button class="nav-item" data-panel="products"><span aria-hidden="true">▤</span> 产品设置</button></div>
        <?php endif; ?>
      </div>
      <?php if ($modular): ?>
      <button class="nav-item" data-panel="languages"><span aria-hidden="true">◎</span> 多语种</button>
      <?php endif; ?>
      <button class="nav-item" data-panel="contact"><span aria-hidden="true">↗</span> 联系按钮</button>
      <button class="nav-item" data-panel="pixels"><span aria-hidden="true">◎</span> 广告像素</button>
      <button class="nav-item" data-panel="analytics"><span aria-hidden="true">▦</span> 数据魔方</button>
      <button class="nav-item" data-panel="admins" id="admin-nav" hidden><span aria-hidden="true">⚿</span> 管理员</button>
      <a class="nav-item" id="update-nav" href="/admin/update.php" hidden><span aria-hidden="true">⟳</span> 系统更新</a>
    </nav>
    <div class="sidebar-bottom"><span id="role-label">正在加载…</span><button class="text-button" data-panel="password">修改密码</button><button id="logout" class="text-button">退出登录</button></div>
  </aside>
  <main class="workspace">
    <header class="topbar"><div><span class="domain">ocpeptideus.org</span><span class="status-dot">网站设置</span></div><a href="/" target="_blank" rel="noreferrer">打开网站 ↗</a></header>
    <div class="page-heading"><div><h1 id="panel-title">页面内容</h1><p id="panel-description">调整访客第一眼看到的图片与文字。</p></div><div class="save-actions"><span id="save-state">正在加载…</span><button id="save" class="primary" disabled>保存并生效</button></div></div>
    <div id="message" class="dismissible-message" role="status" aria-live="polite" hidden><span id="message-content"></span><button type="button" id="dismiss-message" class="notice-close" aria-label="关闭提示" title="关闭提示">×</button></div>
    <div id="first-login-notice" class="first-login-notice" role="status" hidden><div><strong>首次登录，请修改默认密码</strong><p>账号名称可以保持不变。修改密码后即可使用后台其他功能。</p></div><button type="button" class="secondary" id="change-initial-credentials">修改密码</button></div>
    <div id="editor" aria-busy="true" inert>
      <section id="panel-content" class="panel">
        <div class="editor-grid"><div class="form-column">
          <section class="form-section"><div class="section-heading"><div><h2>头部图片</h2><p>1 张静态展示，多张自动轮播。用箭头调整显示顺序。</p></div><button class="secondary upload-label" id="upload-trigger" type="button">上传图片</button><input id="image-upload" type="file" accept="image/jpeg,image/png,image/webp,image/gif" multiple hidden></div>
            <p class="help"><?= $modular ? '建议上传 1600 × 900 像素的横图。' : '推荐尺寸：宽 800 × 高 1000 像素（4:5）。' ?>支持 JPG、PNG、WebP 或 GIF，每张最多 8MB，最多展示 12 张。替换时先上传新图，再删除旧图；至少保留 1 张头图。</p>
            <div id="image-list" class="image-list"></div>
            <div class="image-options"><span id="image-mode" class="pill">单张静态</span><label id="interval-field">轮播间隔 <input id="interval" type="number" min="2" max="15"> 秒</label><button class="text-button" id="show-library" type="button">从图片库选择</button></div>
            <div id="library" class="library" hidden></div>
          </section>
          <section class="form-section" <?= $modular ? 'hidden' : '' ?>><h2>标题与正文</h2>
            <label class="field">标题<input id="title" type="text" maxlength="200" required></label>
            <div class="type-controls"><label>字号 <span class="input-unit"><input id="title_size" type="number" min="16" max="80">px</span></label><label>字重<select id="title_weight"></select></label></div>
            <label class="field">标题对齐<select id="title_align"><option value="left">居左</option><option value="center">居中</option><option value="right">居右</option></select></label>
            <label class="field">正文<textarea id="body" rows="5" maxlength="3000" required></textarea></label>
            <div class="type-controls"><label>字号 <span class="input-unit"><input id="body_size" type="number" min="12" max="40">px</span></label><label>字重<select id="body_weight"></select></label></div>
          </section>
          <section class="form-section" <?= $modular ? 'hidden' : '' ?>><h2>底部文字</h2><label class="field">联系按钮下方的文字<input id="footer_text" type="text" maxlength="200" placeholder="例如 OCPEPTIDE · Research Use Only"></label><p class="help">显示在网站联系按钮的正下方，最多 200 个字符；留空可隐藏。</p></section>
          <?php if ($modular) { require __DIR__ . '/modular-admin.php'; } ?>
        </div>
        <?php if ($modular): ?>
        <aside class="preview-column form-section"><h2>真实页面预览</h2><p class="help">按当前编辑语言查看未保存的页面。可切换手机或桌面尺寸。</p><button type="button" class="secondary" data-open-preview="modular-language">预览当前修改</button></aside>
        <?php else: ?>
        <aside class="preview-column"><div class="preview-heading"><h2>内容预览</h2><span>尚未保存的修改</span></div><div class="phone-preview"><div class="preview-site"><div id="preview-hero" class="preview-hero"></div><div class="preview-content"><h3 id="preview-title"></h3><p id="preview-body"></p><?php if ($state['settings']['single_notice'] !== ''): ?><div class="preview-notice"><?= esc($state['settings']['single_notice']) ?></div><?php endif; ?><span class="preview-contact">◉ &nbsp; Contact us on WhatsApp</span><div class="preview-footer"></div></div></div></div><p class="help">发布后的完整页面请查看网站。</p></aside>
        <?php endif; ?>
        </div>
      </section>
      <?php if ($modular) { require __DIR__ . '/modular-settings.php'; } ?>
      <section id="panel-contact" class="panel" hidden><div class="contact-layout"><section class="form-section"><div class="section-heading"><div><h2>WhatsApp 号码</h2><p>按列表顺序依次分配，最后一个之后回到第一个，所有访客共用顺序。</p></div><span id="number-count" class="pill"></span></div><div id="number-list"></div><button id="add-number" class="secondary">＋ 添加号码</button><p class="help">启用联系跳转时填写 1–10 个号码，包含国家区号。留空表示尚未配置。修改号码或顺序并保存后，从第一个重新分配。</p><label class="field" <?= $modular ? 'hidden' : '' ?>>WhatsApp 预填消息<textarea id="message-text" rows="3" maxlength="1000"></textarea></label><?php if ($modular): ?><p class="help">在「页面内容」编辑各语言的按钮文案与预填消息。</p><?php endif; ?></section>
      <section class="form-section"><h2><?= $modular ? '按钮选择器' : '按钮文案与选择器' ?></h2><label class="field" <?= $modular ? 'hidden' : '' ?>>按钮文案<input id="button_text" type="text" maxlength="100" required placeholder="例如 Contact us on WhatsApp"></label><p class="help">方便广告追踪或外部脚本定位 Contact 按钮。</p><label class="field">ID<input id="button_id" maxlength="64" placeholder="例如 waButton"></label><label class="field">Class name<input id="button_class" maxlength="300" placeholder="例如 contact-button track-whatsapp"></label><p class="help">ID 仅填一个；多个 class 用空格分隔，不带 # 或 .。可留空。按钮基础样式会自动保留。</p><div class="selector-box"><span>可用选择器</span><code id="selector-preview"></code></div></section>
      <?php if ($modular): ?>
      <section class="form-section"><h2>其他联系方式</h2><p class="help">显示在前台联系区，留空可隐藏。</p>
        <label class="field">联系邮箱<input id="contact_email" type="email" maxlength="254" placeholder="sales@example.com"></label>
        <label class="field">手机／微信<input id="contact_phone" type="tel" maxlength="40" placeholder="带 + 和国家区号"></label>
      </section>
      <?php endif; ?>
      </div></section>
      <section id="panel-pixels" class="panel" hidden>
        <div class="contact-layout">
          <section class="form-section"><h2>TikTok Pixel</h2><p class="help">在 TikTok Events Manager 中复制 Pixel ID，填写后再启用。</p>
            <label class="pixel-toggle"><input type="checkbox" id="tiktok_enabled">启用 TikTok Pixel</label>
            <label class="field">Pixel ID<input id="tiktok_pixel_id" maxlength="20" spellcheck="false" autocomplete="off" placeholder="请输入 TikTok Pixel ID（20 位大写字母和数字）"></label>
            <label class="pixel-toggle"><input type="checkbox" id="tiktok_contact">点击 WhatsApp 时记录 Contact</label>
            <p class="help">启用后，访问首页自动记录 PageView。</p>
          </section>
          <section class="form-section"><h2>Facebook / Meta Pixel</h2><p class="help">在 Meta 事件管理工具中复制 Pixel ID。只需填写 ID，无需粘贴整段代码。</p>
            <label class="pixel-toggle"><input type="checkbox" id="meta_enabled">启用 Facebook / Meta Pixel</label>
            <label class="field">Pixel ID<input id="meta_pixel_id" maxlength="30" inputmode="numeric" spellcheck="false" autocomplete="off" placeholder="请输入 Facebook / Meta Pixel ID"></label>
            <label class="pixel-toggle"><input type="checkbox" id="meta_contact">点击 WhatsApp 时记录 Contact</label>
            <p class="help">启用后，访问首页自动记录 PageView。</p>
          </section>
          <section class="form-section"><h2>Google Ads</h2><p class="help">从 Google Ads 的网站转化设置复制转化 ID 和标签。</p>
            <label class="pixel-toggle"><input type="checkbox" id="google_ads_enabled">启用 Google Ads 标签</label>
            <label class="field">转化 ID<input id="google_ads_id" maxlength="23" spellcheck="false" autocomplete="off" placeholder="AW-123456789"></label>
            <label class="field">转化标签<input id="google_ads_label" maxlength="100" spellcheck="false" autocomplete="off"></label>
            <label class="pixel-toggle"><input type="checkbox" id="google_ads_contact">点击 WhatsApp 时记录转化</label>
          </section>
        </div>
        <section class="form-section"><h2>验证广告事件</h2><p class="help">保存后，使用 TikTok／Meta 的测试事件或 Google Tag Assistant 检查首页与 WhatsApp 点击。后台与编辑预览不加载标签。</p><p class="help">此转化只统计联系按钮点击。请避免在广告平台为同一个按钮重复配置事件。</p></section>
      </section>
      <section id="panel-admins" class="panel" hidden>
        <div class="admin-tabs"><button class="secondary selected" data-admin-view="users">管理员列表</button><button class="secondary" data-admin-view="logs">查看日志</button></div>
        <section id="users-view" class="form-section"><h2>管理员账号</h2><p class="help">两种角色都可编辑网站内容与广告像素。超级管理员还可管理账号、查看日志。每个账号使用自己的密码登录。</p>
          <form id="create-user" class="user-create"><label class="field">账号<input id="new-username" required minlength="3" maxlength="32" autocomplete="off" pattern="[a-zA-Z][a-zA-Z0-9_.-]{2,31}" placeholder="例如 editor01"></label><label class="field">初始密码<input id="new-user-password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label><label class="field">角色<select id="new-user-role"><option value="admin">普通管理员</option><option value="super">超级管理员</option></select></label><div class="create-buttons"><button type="button" class="secondary" id="generate-password">生成随机密码</button><button class="primary" type="submit">创建管理员</button></div></form>
          <div id="user-list"></div>
        </section>
        <section id="logs-view" class="form-section" hidden><h2>登录与操作日志</h2><p class="help">记录登录成功、登录失败、退出、网站设置保存、图片上传、账号管理与密码修改。保留最近 5000 条，不记录密码。日志时间按北京时间显示。</p><form id="log-filter" class="log-filter"><label class="field">按账号筛选<input id="log-username" maxlength="32" placeholder="留空查看全部"></label><button class="secondary" type="submit">查询日志</button></form><div id="log-list" aria-live="polite"></div><div class="log-pagination"><button class="secondary" id="logs-prev">上一页</button><span id="logs-page"></span><button class="secondary" id="logs-next">下一页</button></div></section>
      </section>
      <section id="panel-analytics" class="panel" hidden>
        <?php
        require_once __DIR__ . '/analytics.php';
        [$earliestDate, $reportToday] = analytics_custom_bounds(new DateTimeImmutable('now', new DateTimeZone('Asia/Shanghai')));
        $reportMin = $earliestDate->format('Y-m-d');
        $reportMax = $reportToday->format('Y-m-d');
        ?>
        <form id="analytics-filter" class="analytics-filter"><label class="field analytics-range-field">时间范围<select id="analytics-range">
          <option value="today">今天</option><option value="yesterday">昨天</option><option value="3d">近 3 天</option><option value="7d">近 7 天</option><option value="30d">近 30 天</option><option value="90d">近 3 个月</option><option value="custom">自定义时间段</option>
        </select></label>
        <div id="analytics-custom" class="analytics-custom" data-max-days="<?= ANALYTICS_CUSTOM_MAX_DAYS ?>" hidden>
          <label class="field">开始日期<input id="analytics-from" type="date" min="<?= esc($reportMin) ?>" max="<?= esc($reportMax) ?>" value="<?= esc($reportMax) ?>" disabled></label>
          <label class="field">结束日期<input id="analytics-to" type="date" min="<?= esc($reportMin) ?>" max="<?= esc($reportMax) ?>" value="<?= esc($reportMax) ?>" disabled></label>
        </div>
        <button type="submit" class="primary" id="analytics-apply">应用筛选</button><a class="secondary analytics-csv" id="analytics-csv" href="/admin/api.php?resource=analytics_csv&amp;range=today" download>下载 CSV</a></form>
        <p id="analytics-custom-help" class="help" hidden>北京时间。可查询最近 <?= ANALYTICS_CUSTOM_LOOKBACK_DAYS ?> 天，单次最多 <?= ANALYTICS_CUSTOM_MAX_DAYS ?> 天。</p>
        <p id="analytics-meta" class="help analytics-meta"></p>
        <div id="analytics-status" class="analytics-note" role="status" hidden></div>
        <div id="analytics-error" class="analytics-note analytics-error" role="alert" hidden></div>
        <div id="analytics-empty" class="analytics-empty" hidden>
          <h2>所选范围还没有数据</h2>
          <p>数据魔方已接入。访客打开网站或点击联系按钮后，数据会自动出现在这里。</p>
          <p>统计来自浏览器的同源上报：广告拦截、无 JavaScript 或上报失败的环境不会计入，可能少量少计；不存储 IP 与完整访问 URL，不用于鉴别真人或杜绝刷量。</p>
        </div>
        <div id="analytics-content" hidden>
          <div id="analytics-metrics" class="metric-grid"></div>
          <section class="form-section analytics-section" aria-labelledby="analytics-trend-heading">
            <h2 id="analytics-trend-heading">趋势</h2><p class="help" id="analytics-trend-note"></p>
            <ul class="analytics-trend-legend" aria-label="统计指标">
              <li class="trend-pv">页面浏览 PV</li><li class="trend-uv">独立访客 UV</li>
              <li class="trend-clicks">WhatsApp 点击</li><li class="trend-allocations">号码分配</li>
            </ul>
            <div class="analytics-trend-plot"><svg id="analytics-trend" role="img" tabindex="0" aria-labelledby="analytics-trend-heading" aria-describedby="analytics-trend-note analytics-trend-detail"></svg></div>
            <p id="analytics-trend-detail" class="analytics-trend-detail" aria-live="polite"></p>
          </section>
          <div class="analytics-duo">
            <section class="form-section analytics-section"><h2>来源</h2><p class="help">按访问首次来源归因；未关联到页面访问的联系点击单列，不计入转化率。</p><div class="table-wrap"><table class="data-table" id="analytics-sources"><caption class="sr-only">来源明细</caption><thead><tr><th scope="col">来源</th><th scope="col">访问</th><th scope="col">PV</th><th scope="col">联系点击</th><th scope="col">发生点击的访问</th><th scope="col">点击率</th></tr></thead><tbody></tbody></table></div></section>
            <section class="form-section analytics-section"><h2>UTM 广告活动</h2><p class="help">按 utm_campaign 归因访问与点击转化。</p><div class="table-wrap"><table class="data-table" id="analytics-campaigns"><caption class="sr-only">广告活动明细</caption><thead><tr><th scope="col">活动</th><th scope="col">访问</th><th scope="col">PV</th><th scope="col">发生点击的访问</th></tr></thead><tbody></tbody></table></div></section>
          </div>
          <div class="analytics-trio">
            <section class="form-section analytics-section"><h2>设备</h2><div class="table-wrap"><table class="data-table" id="analytics-devices"><caption class="sr-only">设备明细</caption><thead><tr><th scope="col">设备</th><th scope="col">访问</th><th scope="col">PV</th></tr></thead><tbody></tbody></table></div></section>
            <section class="form-section analytics-section"><h2>浏览器</h2><div class="table-wrap"><table class="data-table" id="analytics-browsers"><caption class="sr-only">浏览器明细</caption><thead><tr><th scope="col">浏览器</th><th scope="col">访问</th><th scope="col">PV</th></tr></thead><tbody></tbody></table></div></section>
            <section class="form-section analytics-section"><h2>操作系统</h2><div class="table-wrap"><table class="data-table" id="analytics-os"><caption class="sr-only">操作系统明细</caption><thead><tr><th scope="col">系统</th><th scope="col">访问</th><th scope="col">PV</th></tr></thead><tbody></tbody></table></div></section>
          </div>
          <section class="form-section analytics-section"><h2>号码分配（服务端 302）</h2><p class="help">每次成功 302 分配按当时号码记录，列表改序或更换号码不会混淆历史；未关联到页面访问的分配单列。国家/地区未接入。</p><div class="table-wrap"><table class="data-table" id="analytics-numbers"><caption class="sr-only">号码分配明细</caption><thead><tr><th scope="col">号码</th><th scope="col">分配次数</th><th scope="col">关联到访问</th></tr></thead><tbody></tbody></table></div></section>
        </div>
      </section>
      <section id="panel-password" class="panel" hidden><section class="form-section password-section"><h2>修改我的账号密码</h2><p class="help">修改时必须输入原密码，并两次输入相同的新密码。成功后所有设备上的旧会话都会失效，请使用新账号密码重新登录。</p><form id="change-password"><label class="field">账号名称（可不修改）<input id="new-own-username" required minlength="3" maxlength="32" autocomplete="username" pattern="[a-zA-Z][a-zA-Z0-9_.-]{2,31}"></label><label class="field">原密码<input id="old-password" type="password" required maxlength="72" autocomplete="current-password"></label><label class="field">新密码<input id="new-password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label><label class="field">再次输入新密码<input id="confirm-password" type="password" required minlength="8" maxlength="72" autocomplete="new-password"></label><p class="help">密码为 8–72 字节，建议使用随机英文字母、数字和符号。</p><button class="primary" type="submit">修改密码并重新登录</button></form></section></section>
    </div>
    <?php if ($modular): ?>
    <dialog id="modular-preview-dialog" aria-labelledby="modular-preview-heading">
      <div class="preview-dialog-bar">
        <h2 id="modular-preview-heading">页面预览</h2>
        <label class="field">尺寸<select id="modular-preview-size"><option value="mobile">手机 · 390 px</option><option value="desktop">桌面 · 1200 px</option></select></label>
        <form method="dialog"><button type="submit" class="secondary">关闭</button></form>
      </div>
      <p id="modular-preview-status" class="help" role="status"></p>
      <div class="preview-dialog-body"><iframe id="modular-preview-frame" title="网站页面预览" sandbox="allow-same-origin" referrerpolicy="no-referrer" data-size="mobile" hidden></iframe></div>
    </dialog>
    <?php endif; ?>
  </main>
</div><script src="/assets/accounts-ui.js?v=<?= urlencode(CODE_VERSION) ?>" defer></script><script src="/assets/admin.js?v=<?= urlencode(CODE_VERSION) ?>" defer></script><script src="/assets/analytics-ui.js?v=<?= urlencode(CODE_VERSION . '.' . (string)@filemtime(PUBLIC_DIR . '/assets/analytics-ui.js')) ?>" defer></script>
<?php endif; ?></body></html>
