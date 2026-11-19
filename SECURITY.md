# 安全策略

## 请勿公开报告漏洞

如果发现了安全漏洞，**不要开公开 issue、不要在讨论区或 PR 里描述**。公开会放大风险，尤其在有用户已经用本框架打包分发的场景下。

## 如何报告

通过 GitHub 的私人漏洞报告渠道提交（链接见 `composer.json` 的 `support.security`，即仓库 Security 标签页的 "Report a vulnerability"）；若该渠道未启用，直接发邮件到 `composer.json` 的 `authors[].email`。

报告请尽量包含：

- 影响面：解释器 / dev 壳 / AOT exe，哪个形态受影响；
- 复现步骤与最小样例；
- 影响的版本（见 [CHANGELOG.md](CHANGELOG.md) 与 git tag，未打 tag 前说明 commit）；
- 你判断的严重程度与理由。

## 响应预期

技术预览阶段没有 SLA 承诺，但目标是：确认收到后尽快回复，确认漏洞后先修再披露，并给出受影响的版本范围。报告者会在修复发布前被征询披露时间。

## 支持范围

- 只有最新 git tag 对应的版本获得修复；未打 tag 期间以 HEAD 为准。
- 技术预览不保证向后兼容，安全修复可能伴随破坏性变更，见 [ROADMAP.md](ROADMAP.md)。

## 已知的外部风险面

本项目是 Windows 桌面 GUI 框架，以下边界值得安全研究者关注（也是已知的设计取舍，非"未修漏洞"）：

- **依赖包供应链**：Skia / 字体通过 `fetch-deps.php` 从 Release 获取，入库的 `deps.lock.json` 做 SHA256 校验；`SKIA_REVISION` 已 pin 到 Skia M148 的真实 commit，当前 `deps-v2` 双平台包已从该 commit 真编并从已发布资产回验，见[依赖与发布](docs/guides/dependencies-and-release.md)。
- **壳 ↔ PHP ABI**：无运行期握手，改 C++ 壳或 PHP 接口必须整树重编译；静态契约由 `php tools/abi-check.php` 守。
- **网络图片**：`Image::asset()` 接受 http(s) URL，走 `TaskHost` 在 worker 解码；未知 scheme 明确拒绝，单张解码有 2048 安全上限。
