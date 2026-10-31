# B2B广告询盘单页系统 — Hostinger 部署说明

当前版本已改为账号密码登录，旧 token 日常登录停用。

请阅读 [完整部署与升级说明](DEPLOY-ACCOUNTS.md)。

- 首次安装：delivery/packages/hostinger-first-install.zip。
- 超级管理员账号：admin，初始密码见 delivery/credentials/admin-credentials.txt，不要上传此 TXT。首次登录只强制修改密码，账号名称可继续使用 admin。
- 已有站点升级：delivery/packages/hostinger-update.zip，不覆盖线上 data 和 uploads。
- 首次接入系统更新、信任配置、cURL/缓存要求及失败恢复，请读 [系统更新说明](docs/UPDATE.md)。升级包也保留客户 hero.webp。
- 从 token 版升级：打开 /admin/，用原主管理员 token 完成一次性身份验证，设置新的账号密码。
- 会话按最近一次有效操作续期七天；连续十次输错后暂停登录十五分钟。

Hostinger 解压后必须核对最终 private/data/state.php 和原图片实际保留；若出现带数字后缀的旧目录，请按 docs/UPDATE.md 合并验收，不能仅凭解压成功恢复访问。
