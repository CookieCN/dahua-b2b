# OCPEPTIDE 网站更新发行

当前版本：2.0.1。公开保存签名更新包及对应源码；不包含客户账号数据、设置、图片、会话或发行私钥。

## 网站更新配置

适用于已手动接入2.0.0及以上固定引导的网站。更新源：

`https://raw.githubusercontent.com/CookieCN/dahua-b2b/main/channel`

将本仓库 `release-public.pem` 完整公钥填入网站私有 `private/update/trust.php`，配置网站自己的HTTPS根地址为probe_base_url。首次建立信任应由维护者核对公钥摘要；运行时不得从更新源自动替换信任公钥。客户不需要GitHub令牌或私钥。

后台“系统更新”仅供完成首次改密的超级管理员使用。首次接入、备份、解压目录合并及恢复见 [部署说明](source/2.0.1/docs/UPDATE.md)。

## 目录

- channel/releases.json：单个签名封装的发行频道，含版本授权、兼容性与撤回状态。
- channel/ocpeptide-admin-2.0.1.zip：24个业务/静态更新文件及签名清单，不改固定PHP引导或客户数据。
- source/2.0.1：本版本对应程序源码、部署说明及发布工具。
- release-public.pem：发行公钥；私钥仅由维护者在发布端保管。
- SHA256SUMS.txt：发行文件摘要，不能代替签名信任。

已公开ZIP不可覆盖；撤回只更新签名频道。更新器会先备份代码，验证失败恢复原代码；需要的备份缺失时保持维护并转人工处理。维护者不得将私钥上传仓库或客户服务器。
