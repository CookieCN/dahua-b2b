'use strict';
const $u = id => document.getElementById(id);
let csrf = '', nonces = {upload: null, source: null, rollback: null, cleanup: null}, latest = null, checkedFromVersion = null, busy = false, pollTimer = null, currentTaskId = null;
function notify(text, error = false) {
  $u('message-content').textContent = text;
  $u('message').className = error ? 'dismissible-message error' : 'dismissible-message';
  $u('message').hidden = false;
}
$u('dismiss-message').onclick = () => { $u('message').hidden = true; };
async function api(path, options = {}) {
  const headers = { ...(options.headers || {}) };
  if (csrf) headers['X-CSRF-Token'] = csrf;
  let body = options.body;
  if (body && !(body instanceof FormData)) { headers['Content-Type'] = 'application/json'; body = JSON.stringify(body); }
  const response = await fetch('/admin/update-api.php' + path, {...options, headers, body, credentials: 'same-origin', cache: 'no-store'});
  let result;
  try { result = await response.json(); } catch { throw new Error('服务器返回异常，请重试。'); }
  if (!response.ok) throw Object.assign(new Error(result.error || '请求失败。'), {data: result});
  return result;
}
function setBusy(state) {
  busy = state;
  for (const id of ['check-update', 'install-source', 'install-upload', 'rollback', 'verify-update', 'cleanup-backups']) {
    const missingGrant = id === 'install-source' ? !latest || !nonces.source
      : id === 'install-upload' ? !nonces.upload : id === 'rollback' ? !nonces.rollback
      : id === 'cleanup-backups' ? !nonces.cleanup : false;
    $u(id).disabled = state || $u(id).hidden || missingGrant;
  }
}
const stageCopy = {
  prepared: '已准备（等待安装）', backing_up: '正在备份现有代码…', backed_up: '备份完成，等待替换…',
  applying: '正在替换文件…', applied: '安装完成，待验证', verified: '已安装并通过健康检查',
  rolling_back: '正在回滚…', rolled_back: '已回滚到上一版本', aborted: '已取消（未修改任何文件）', failed: '失败',
};
function renderEnv(status) {
  $u('update-version').textContent = '版本 ' + status.version;
  if (latest && checkedFromVersion !== status.version) {
    latest = null;
    checkedFromVersion = null;
    nonces.source = null;
    $u('install-source-form').hidden = true;
    $u('update-latest').textContent = '网站版本已变化，请重新检查更新。';
  }
  const env = [];
  env.push(['PHP ' + status.php, true]);
  for (const [name, loaded] of Object.entries(status.extensions || {})) env.push(['扩展 ' + name, loaded]);
  if (status.baseline) env.push(['代码基线 ' + status.baseline.files + ' 个文件', true]);
  else env.push(['缺少代码基线', false]);
  if (status.disk_free !== null) env.push(['磁盘可用 ' + Math.round(status.disk_free / 1048576) + 'MB', status.disk_free > 50 * 1048576]);
  $u('update-env').replaceChildren(...env.map(([label, ok]) => {
    const li = document.createElement('li');
    li.textContent = (ok ? '✓ ' : '✗ ') + label;
    if (!ok) li.className = 'update-problem';
    return li;
  }));
  $u('update-trust').textContent = !status.updater_enabled
    ? '更新器未配置信任密钥（private/update/trust.php），更新功能已禁用。请联系部署人员配置。'
    : status.extensions?.curl === false
      ? '安装功能需要 PHP cURL 扩展，请联系主机管理员启用后重试。'
    : status.source_configured
      ? '更新源：' + status.source_host + '（HTTPS 签名源）'
      : '更新源未配置，安装已禁用：请联系部署人员配置更新源。上传包也需核对发行与撤回状态。';
  if (status.maintenance) notify('网站正处于更新维护状态，访问者会看到短暂维护提示。', true);
  nonces.upload = status.install_nonce;
  nonces.rollback = status.rollback_nonce;
  nonces.cleanup = status.cleanup_nonce;
  $u('role-label').textContent = status.admin ? status.admin.username + ' · 超级管理员' : '';
  $u('update-backups').replaceChildren(...(status.backups || []).map(backup => {
    const li = document.createElement('li');
    li.textContent = backup.dir + ' · ' + Math.round(backup.size / 1024) + 'KB · ' + new Date(backup.created).toLocaleString();
    return li;
  }) || [Object.assign(document.createElement('li'), {textContent: '暂无备份。'})]);
  renderTask(status.last_task);
  setBusy(busy);
}
function renderTask(task) {
  const box = $u('update-task');
  if (!task) { currentTaskId = null; box.textContent = '还没有更新任务。'; $u('rollback-form').hidden = true; $u('verify-update').hidden = true; return; }
  currentTaskId = task.id;
  const lines = [];
  lines.push('任务 ' + task.id.slice(0, 8) + '：' + (stageCopy[task.stage] || task.stage));
  lines.push(task.from_version + ' → ' + task.to_version + (task.mode === 'upload' ? '（上传安装）' : '（在线安装）'));
  if (task.ops_total) lines.push('文件进度 ' + Math.min(task.ops_done, task.ops_total) + ' / ' + task.ops_total);
  if (task.error) lines.push('错误：' + task.error);
  if (Array.isArray(task.checks)) {
    lines.push('健康检查：');
    for (const checkItem of task.checks) {
      lines.push((checkItem.ok === true ? '✓' : checkItem.ok === false ? '✗' : '–') + ' ' + checkItem.label + '（' + checkItem.detail + '）');
    }
  }
  box.replaceChildren(...lines.map(text => Object.assign(document.createElement('p'), {textContent: text})));
  $u('verify-update').hidden = !['applied', 'verified', 'failed'].includes(task.stage);
  $u('rollback-form').hidden = !['applied', 'verified', 'failed'].includes(task.stage);
}
function pollTask(id) {
  clearTimeout(pollTimer);
  pollTimer = setTimeout(async () => {
    try {
      const task = await api('?resource=task&id=' + encodeURIComponent(id));
      renderTask(task);
      if (['prepared', 'backing_up', 'backed_up', 'applying', 'rolling_back'].includes(task.stage)) pollTask(id);
      else { await refreshStatus(); setBusy(false); }
    } catch (error) { notify(error.message, true); setBusy(false); }
  }, 1000);
}
async function refreshStatus() {
  const status = await api('?resource=status');
  csrf = status.csrf;
  renderEnv(status);
  if (status.active_task && ['prepared', 'backing_up', 'backed_up', 'applying', 'rolling_back'].includes(status.active_task.stage)) {
    pollTask(status.active_task.id);
  }
  return status;
}
async function run(fn) {
  if (busy) return;
  setBusy(true);
  try { await fn(); }
  catch (error) { notify(error.message, true); }
  finally { setBusy(false); }
}
$u('check-update').onclick = () => run(async () => {
  const result = await api('?resource=check');
  latest = result.latest_compatible;
  checkedFromVersion = latest ? result.current_version : null;
  const box = $u('update-latest');
  box.replaceChildren();
  if (!latest) {
    nonces.source = null;
    box.append(Object.assign(document.createElement('p'), {textContent: '已检查（' + result.generated_at + '）：当前已是最新可安装版本。'}));
    $u('install-source-form').hidden = true;
    return;
  }
  box.append(Object.assign(document.createElement('p'), {textContent: '发现新版本 ' + latest.version + '（发布于 ' + (latest.published_at || '未知时间') + '）。'}));
  if (latest.notes) box.append(Object.assign(document.createElement('p'), {textContent: '更新说明：' + latest.notes}));
  nonces.source = result.install_nonce || null;
  $u('install-source-notes').textContent = '将安装 ' + latest.version + '，从 ' + result.current_version + ' 升级。';
  $u('install-source-form').hidden = false;
});
$u('install-source-form').onsubmit = event => {
  event.preventDefault();
  if (!latest || !nonces.source) return notify('请先重新检查更新。', true);
  if (!confirm('开始安装 ' + (latest ? latest.version : '') + '？安装期间网站短暂维护。')) return;
  run(async () => {
    try {
      const result = await api('?action=install_source', {method: 'POST', body: {
        version: latest.version, password: $u('install-password').value, nonce: nonces.source}});
      renderTask(result.task);
      notify('安装完成，请查看健康检查结果。');
    } finally {
      nonces.source = null;
      $u('install-password').value = '';
      await refreshStatus().catch(() => {});
    }
  });
};
$u('install-upload-form').onsubmit = event => {
  event.preventDefault();
  const file = $u('package-upload').files[0];
  if (!file) return notify('请选择签名更新包。', true);
  if (!nonces.upload) return notify('操作凭证缺失，请刷新页面。', true);
  if (!confirm('上传并安装 ' + file.name + '？安装期间网站短暂维护。')) return;
  run(async () => {
    const data = new FormData();
    data.append('package', file);
    data.append('password', $u('upload-password').value);
    data.append('nonce', nonces.upload);
    try {
      const result = await api('?action=install_upload', {method: 'POST', body: data});
      $u('package-upload').value = '';
      renderTask(result.task);
      notify('安装完成，请查看健康检查结果。');
    } finally {
      nonces.upload = null;
      $u('upload-password').value = '';
      await refreshStatus().catch(() => {});
    }
  });
};
$u('verify-update').onclick = () => run(async () => {
  const result = await api('?action=verify', {method: 'POST', body: {task_id: currentTaskId}});
  renderTask(result.task);
  notify(result.verification.passed ? '健康检查全部通过。' : '健康检查存在失败项，可回滚或排查后重试。', !result.verification.passed);
});
$u('rollback-form').onsubmit = event => {
  event.preventDefault();
  if (!nonces.rollback) return notify('操作凭证缺失，请刷新页面。', true);
  if (!confirm('回滚到上一版本？仅恢复程序代码，不改动内容、图片和账号。')) return;
  run(async () => {
    try {
      const result = await api('?action=rollback', {method: 'POST', body: {
        task_id: currentTaskId, password: $u('rollback-password').value, nonce: nonces.rollback}});
      renderTask(result.task);
      notify('已回滚到 ' + (result.task.from_version || '上一版本') + '。');
    } finally {
      nonces.rollback = null;
      $u('rollback-password').value = '';
      await refreshStatus().catch(() => {});
    }
  });
};
$u('cleanup-form').onsubmit = event => {
  event.preventDefault();
  if (!nonces.cleanup) return notify('操作凭证缺失，请刷新页面。', true);
  if (!confirm('清理过期备份？删除后无法恢复，当前版本的回滚备份会保留。')) return;
  run(async () => {
    try {
      const result = await api('?action=cleanup', {method: 'POST', body: {
        password: $u('cleanup-password').value, nonce: nonces.cleanup}});
      notify('已清理 ' + (result.removed.backups || 0) + ' 个过期备份；保留待核对目录 ' + (result.removed.skipped || 0) + ' 个。');
    } finally {
      nonces.cleanup = null;
      $u('cleanup-password').value = '';
      await refreshStatus().catch(() => {});
    }
  });
};
(async () => {
  try {
    await refreshStatus();
    $u('update-editor').setAttribute('aria-busy', 'false');
  } catch (error) { notify(error.message, true); }
})();
