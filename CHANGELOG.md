# Changelog

本文件记录 Drift 的发布变更。**所有版本均为技术预览（preview）**，API 不保证向后兼容，
直至 1.0。

## 版本号规范

- 格式：`v<major>.<minor>.<patch>-preview.<N>`
  - `0.1.0` 主线；`preview.N` 预览序号（每次发预览递增 N）。
- **发布方式**：`git tag v0.1.0-preview.N` 并推送即发布（本仓当前无远程，本地 tag 亦可）。
- **破坏性变更**：preview 阶段允许；变更记入对应版本的「Breaking」小节，并在发版前于
  [`docs/DEBT-2026-11-02.md`](docs/DEBT-2026-11-02.md) 登记。
- 发版前验收：`drift-build --full` 绿 + `probe-window` 5/5（T2 全过）。

## v0.1.0-preview.1（2026-11-10）

初版技术预览。**能力面**对照 [`docs/RELEASE-READINESS-2026-11-10.md`](docs/RELEASE-READINESS-2026-11-10.md)。

### 已实现

- 声明式 UI 三层树（Widget / Element / RenderObject）+ 单向下行 + 组合根注入
- Skia 自绘 + Raster 软后端双渲染；高 DPI（Per-Monitor V2）/ 多屏 / 分数缩放
- 布局系统（单遍约束传递）、文本自排版 + IME
- 显式 `Equatable` 状态契约；`Subject` 响应式；`TaskHost` 三后端异步 + `Future` / `AsyncScope`
- 手势竞技场 + 焦点通道 + 键盘 / 滚轮 / 拖放
- 动画（`AnimatedValue` / `Curve` / `AnimationController` / 隐式动画）
- 多窗口（`WindowHost` / `WindowSet`）
- 深色主题（`ColorToken` + `SystemTheme` 跟随系统）
- Windows 无障碍满配（UIA provider + 树导航 + 结构 / 焦点事件）
- 控件面约 110 个类（覆盖日常 60–70%）
- 8 个应用样例（hello / tutorial / users / showcase / watermark / bench / dev / pacing）
- 工程质量：28 项闸门 + 变异四律 + 三态判据 + 金样确定性注入 + T0/T1/T2 分层 + 真机探针

### 明确不包含（preview 阶段）

- ❌ 多平台（仅 Windows 10/11 x64）
- ❌ i18n 框架
- ❌ 安装器 / 代码签名 / 自动更新
- ❌ 第三方组件生态 / 包管理
- ❌ 触控 / 笔 / 高对比度
- ❌ 远程崩溃上报

### Breaking

- 无（首个预览版）。

### 已知限制

- 布局为整树重排，无 `RelayoutBoundary`（12k 节点实测余量 4.8×，桌面工具类够用）。
- 壳 ↔ PHP ABI 无版本握手（改 C++ 壳或 PHP 接口须同步重编译）。
- AOT 增量构建缺 stale 判据（发版务必 `--full`，不要增量）。
