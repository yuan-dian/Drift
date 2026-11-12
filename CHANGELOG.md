# Changelog

本文件记录 Drift 的发布变更。**所有版本均为技术预览（preview）**，API 不保证向后兼容，
直至 1.0。

## 版本号规范

- 格式：`v<major>.<minor>.<patch>-preview.<N>`
  - `0.1.0` 主线；`preview.N` 预览序号（每次发预览递增 N）。
- **发布方式**：`git tag v0.1.0-preview.N` 并推送即发布（本仓当前无远程，本地 tag 亦可）。
- **未打 tag = 未发布**：本文件里的 `preview.N` 只表示"下一个 tag 的内容"；
  `git tag -l` 为空时，任何版本号都不构成对外承诺。
- **破坏性变更**：preview 阶段允许；变更记入对应版本的「Breaking」小节，并在发版前于
  [`docs/DEBT-2026-11-02.md`](docs/DEBT-2026-11-02.md) 登记。
- 发版前验收：`drift-build --full` 绿 + `probe-window` 5/5（T2 全过）。

## v0.1.0-preview.1（2026-11-10 条目 · 尚未打 tag）

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
- 控件面约 130 个类（含 8 个接口；精确清单以 `src/Widget/` 为准）
- 8 个应用样例（hello / tutorial / users / showcase / watermark / bench / dev / pacing）
- 工程质量：28 项闸门 + 变异四律 + 三态判据 + 金样确定性注入 + T0/T1/T2 分层 + 真机探针

### 明确不包含（preview 阶段）

- ❌ 多平台（仅 Windows 10/11 x64）
- ❌ i18n 框架
- ❌ 安装器 / 代码签名 / 自动更新
- ❌ 第三方组件生态 / 包管理
- ❌ 触控 / 笔
- ❌ **系统高对比度跟随**：配色映射本身已有（`ColorToken::highContrastValue()` /
  `ThemeData::withHighContrast()`），但未接 `SPI_GETHIGHCONTRAST` 信号、应用层无消费者
  ⇒ 用户在系统里打开高对比度模式时**不会自动切换**
- ❌ 远程崩溃上报

### Breaking

- 无（首个预览版）。

### 已知限制

- 布局为整树重排，无 `RelayoutBoundary`；12k 节点实测仍有余量（读数见
  [`docs/RELEASE-READINESS-2026-11-10.md`](docs/RELEASE-READINESS-2026-11-10.md)），桌面工具类够用。
- 壳 ↔ PHP ABI **运行期无握手**：改 C++ 壳或 PHP 接口必须同步重编译。跨层契约不是没人守 ——
  静态契约由 `php tools/abi-check.php` 管（stub 为唯一事实源，`--json` 出 schemaHash）。
- AOT 增量构建**已有** stale 判据（`StalePolicy`，首次自动全量）。发版验收仍用 `--full`，
  理由是排除增量缓存的影响，不再是因为判据缺失。
- **源码树不自足：Skia 预编译包与字体不入库**。`/cpp/` 整个目录在 `.gitignore` 里，
  `git clone` 后 `cpp/skia/`（约 141 MB）与 `cpp/fonts/`（约 21 MB）**不存在**，
  缺了构建期直接链接失败。本仓提供 `php tools/pack-deps.php` 打成
  `dist/drift-deps-win-x64.zip`（Skia M148 + Noto Sans SC，约 50 MB）并输出 SHA256，
  包内随附 `licenses/`（11 份许可全文 + `THIRD-PARTY.md` 索引），并为 Skia 与 OFL 在
  `cpp/skia/LICENSE`、`cpp/fonts/OFL.txt` 留了就近副本 —— 九个静态库的许可不再只给链接；
  但**包本身不进 git 历史**：托管在 GitHub Releases，用 `php tools/fetch-deps.php` 取 ——
  下到 `dist/`、拿入库的 `deps.lock.json` 校验、解压后核对 lib/字体/许可数量，
  支持 `DRIFT_DEPS_URL` 镜像回退与断点续传；首个 release 尚未发布，流程见
  [`docs/RELEASE-DEPS.md`](docs/RELEASE-DEPS.md)。
  另：`PHPX` 与 `tpc` 源码树属
  TypePHP 编译器项目，同样不在本仓。搭建步骤见 [`README.md`](README.md) 的「构建环境」。
- 只用运行态时**不需要**上述工具链：`php tools/gate.php --fast` 是纯 PHP 的，
  `composer install` 也不必跑（`tools/autoload.php` 直接读 psr-4）。
