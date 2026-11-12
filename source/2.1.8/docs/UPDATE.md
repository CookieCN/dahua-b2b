# B2B广告询盘单页系统 — 系统更新、首次接入与恢复

此文说明更新器的部署要求，不表示线上站点已经安装或验收。正式发行源地址、签名密钥和 Hostinger 环境需由维护人员确认；示例配置不会自动启用更新。

## 首次接入已运行的网站

1. 先在测试站演练。确认 PHP 8.2+、json、openssl、zip、fileinfo、session 和 cURL 可用；更新传输使用 libcurl 7.59+。确认 HTTPS、PHP 可写程序目录和私有 `private/update/`，并留出程序快照、暂存与备份的空间。
2. 通过主机面板备份完整 `public_html` 与 `private`，包括现有 `hero.webp`、uploads、data 和原信任配置。保留离线副本；不要用首次安装包重置已有客户。
3. 暂时在主机侧停止网站访问，再解压 `hostinger-update.zip`。此包包含完整程序、固定入口、代码基线和当前保护引擎；不包含客户 data、hero 或上传图片，也不包含运行中的 trust、任务、nonce、会话和备份。不要先删除原目录；保持 `private` 与 `public_html` 同级。

   **Hostinger 解压后的必查项**：文件管理器可能保留 `private.2396`、`public_html.2348` 等旧目录并新建空目录，不能只看“解压成功”。恢复访问前确认当前 `private/data/state.php` 存在且与升级前摘要一致、原会话和上传图片已保留到最终目录。出现新旧并存时先备份两套，在维护窗口内合并原数据与图片；有同名现存数据时停止覆盖并排查。不得运行 prepare 或使用全新安装 state 重置已有账号。
4. 当前请求必须能够关闭 OPcache，且不能预加载业务 PHP；否则固定入口会安全返回 503。手工接入后按主机方式重启 PHP/清理代码缓存，再验收。CLI 通过不能替代 Hostinger/FPM 验证。
5. 在主机面板填写 `UPDATE-TRUST.example.php` 的副本，保存到 `private/update/trust.php`。只填发行公钥；私钥只留在发布端。已有正确的 trust.php 应原样保留。
6. 恢复访问并检查首页、联系按钮、真实登录、内容保存、上传与系统更新页。核对该站原账号、内容、图片和号码顺序仍保持；确认后再移走主机里的 ZIP/说明。失败时恢复整套旧程序，保留客户数据。

首次全新安装使用 first-install 包：它只带模板引用的图片和独立新建的 admin，初始密码来自本地 credentials 配置，首次登录必须改密。

## 信任配置与更新源

- `public_key`：与发布端签名私钥配对的完整 PEM 公钥。不要放密码、PAT 或私钥。
- `source_url`：可直接下载 `releases.json` 和版本 ZIP 的 HTTPS 静态目录。仓库网页不是下载源；重定向会被拒绝。客户不需要 GitHub PAT。
- `probe_base_url`：这个网站自身的 HTTPS 根地址，不从访问者请求头推断。
- `allow_loopback_source`：生产必须为 false；true 仅用于一次性 localhost 测试。
- `backup_retention`：1–10，默认 3。清理保留近期备份、截止时间并列组和当前安装版本的回滚备份。未知/损坏归属会保留或阻止清理。

源使用单个签名封装 `releases.json`（`private/updater-engine.php` 的 `CHANNEL_ENVELOPE_FORMAT` 常量），不是 JSON 与 `.sig` 分开替换。后台安装和上传备用入口都要核对签名发行清单、撤回状态、版本、兼容条件、ZIP 大小及 SHA256。上传用于服务器下载大包受限，不提供离线绕过撤回校验。

维护人员核对实际 PHP/Build Date、cURL 版本和缓存行为后再部署。主机“已应用安全修复”的提示不能证明已切到最新 PHP；本地测试版本也不能代表线上版本。

## 发布端

正式私钥和发行配置未获批准前，不执行真实发版。工具已有以下命令；密钥保存在仓库外安全位置，ZIP/清单先在本地验证，再由维护者批准上传公开发行仓库。

```text
php scripts/release.php keys <key-directory>
php scripts/release.php build <clean-source-root> --key <release-private.pem> --out <release-directory> --notes-file <notes.txt>
php scripts/release.php verify <package.zip> --key <release-public.pem>
php scripts/release.php withdraw --out <release-directory> --key <release-private.pem> --version <x.y.z>
```

先提升 `private/version.php` 的代码版本，再构建。已发布 ZIP 不覆盖；撤回只改签名清单。根目录的原始数据、凭据和备份不应为了通过构建而删除：改用干净代码副本准备发布。固定公开 PHP 入口和 `private/request-entry.php` 不由在线包改写；引导变更需另走手工接入与验收。

发行工具的本地进程中断自动恢复目前延期。若它在产物提交之间中断，停止分发并保留目录和日志，人工核对签名清单及不可变 ZIP；不要猜测缺失元数据或覆盖旧包。

## 客户更新和中断

只有完成首次改密的超级管理员能安装、回滚或清理；危险操作需原密码、CSRF 和一次性操作凭证。此页不提交内容编辑器的其他草稿。

备份期间旧站仍可访问。安装进程中断后，可重新进入系统更新页或访问 `/update-recovery.php`：未完成备份会取消，完整备份或已开始的任务按日志恢复。替换期间普通请求返回 503，不消耗号码；检查仅在绑定任务与完整代码证明的短窗口内允许四个只读入口。安装失败而旧版恢复成功仍显示失败及原始原因。

公开恢复页只显示概况，不替代主机面板。不要手动删除 `maintenance.json`、任务或备份来消除 503。

## 必须人工恢复时

1. 保持主机侧停站/维护，备份现场的全部程序、任务日志、备份和标记。不把现场文件发到公开发行仓库。
2. 先从有来源和完整哈希的部署包或整站代码快照恢复程序。若使用某次任务备份，应按任务的完整操作记录恢复所有改写/删除项，并移除该次新增的代码；不能只补一个首页文件。
3. 对照对应代码基线核对全部程序与删除项，并从同一已验证版本恢复 `private/update/protected/updater-engine.php`。基线自身必须与可信包/任务固定摘要一致，不能仅信任现场可能损坏的基线。
4. 保留当前客户 data、uploads、hero 和信任配置。代码回滚不盲目回滚客户 state，避免丢掉更新后的账号或内容操作。
5. 由维护人员确认版本、完整性和健康检查后再解除更新维护标记，并在恢复访问前验证真实登录与联系路由。无法确认时继续保持维护，从整套已验证快照恢复或升级人工处理。

自动恢复无法修复被人工删掉的必要备份或未知归属日志；遇到这些情况保持围栏是预期行为。
