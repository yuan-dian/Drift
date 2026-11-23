# Changelog

本文件记录 Drift 的发布变更。**0.x 均为技术预览**，API 不保证向后兼容；`1.0.0` 起
才是正式版并适用稳定版兼容承诺（见 [ROADMAP](ROADMAP.md)）。

## 版本号规范

**唯一真相源是根目录 [`VERSION`](VERSION) 与 [`DEPS_VERSION`](DEPS_VERSION)**，格式与
升级流程见 [README「版本号」](README.md)，派生副本由 `tools/gen-version.php` 生成、
`tools/version.php --check` 判据。本节只记约定，不另存一份版本值。

- 格式：`v<major>.<minor>.<patch>`，可带 `-preview.<N>` 后缀（`VERSION` 里不带 `v` 前缀）。
  `0.x` 是预览（带不带后缀都是），`preview.N` 只是历史预览序号；`1.0.0` 为首个正式版。
- **发布方式**：`git tag v<VERSION 内容>` 并推送即发布。
- **未打 tag = 未发布**：本文件里尚未打 tag 的版本只表示"下一个 tag 的内容"；
  `git tag -l` 为空时，任何版本号都不构成对外承诺。
- **破坏性变更**：预览阶段允许；变更记入对应版本的「Breaking」小节，并在发版前于
  在本文件对应版本的 Breaking 小节登记。
- 发版前验收：`drift-build --full` 绿 + `probe-window` 5/5（T2 全过）。

## Unreleased

### Breaking

- **`ScrollView::vertical()` 改名为 `isVertical()`**：与 `LazyList` / `Flex` 的同一判据同名
  （此前同一个问题有三个叫法，读代码要先确认是哪一种容器的哪个词）。唯一消费者是
  `ScrollViewState`，应用侧零改动。
- **取消能力从四档降为三档**：`CancelSupport::QueuedOnly` 删除（三个后端早已全部升到
  `Killable`，这档只剩枚举与文案在误导读者）；`Killable` 的 `value` 由 3 改为 2，
  按"能省多少活"的递增序仍成立。`revokesQueued()` 现在只认 `Killable`。
- **组合根入口改为实例**：`Bootstrap` 必须 `extends Drift\App\AppEntry`，`run()` 由基类
  继承且为 `final`，应用改写 `protected function start()`（后台应用再加
  `protected function jobs()`）；`main.php` 从 `\App\X\Bootstrap::run();` 改为
  `(new \App\X\Bootstrap())->run();`。`WorkerProcess::serveIf(array)` 删除，改为
  `isWorker()` + `serve(array)`，且不再是 `@api` —— 应用引用它会被 `internal-leak` 与
  `app-worker-role` 两道闸门报 error。
- **用户配置路径改为应用专属目录**：`Platform::configDir()` 只返回平台基础目录
  （Win32 `%APPDATA%` / X11 `$XDG_CONFIG_HOME`），不再写死 `Drift` 子目录——框架名不进
  用户目录，多个第三方 Drift 应用不会因同名状态文件互相覆盖。组合根向 `WindowSet`
  声明一次应用名，框架经 `AppPaths` 拼出 `<base>/<app>/window.state`，
  分隔符按平台自动处理（修掉 X11 下硬编码 `\` 生成怪文件的问题）。这个两层模型对齐
  Qt / Electron / Tauri。
- `Paths::userConfig()` 被 `AppPaths` 取代并删除；`WindowSet` 构造第二参数由完整路径
  字符串改为应用名（路径拼装内部化，`AppPaths` 不再是组合根要接触的类型）。旧位置（`%APPDATA%\Drift\<app>.window`）的状态文件不再读取，
  窗口几何会重置一次。
- 诊断工具的相应路径假设同步更新（`probe_win.py` / `probe-window.py` /
  `probe-two-windows.py` / `probe-hotreload-p15.php`）。

### 已实现

- **滚动读面单一实现**：新增 `Traits\ScrollViewHost`，`ScrollView` / `LazyList` 的
  `maxOffset` / `scrollTo` / `animateTo` / `scrollAxis` / `viewportExtent` /
  `scrollByAxis` / `glideByAxis` / `ensureVisible` 只有一份；两类各自只回答"主轴是哪个"
  "盒在哪""句柄是谁"。`scrollBy()` 仍分岔（懒列表要多一步换窗），留在各类。
  `TextArea` 不进来：它的盒是 `RenderTextArea`，没有 `revealDelta()`。
  新增结构判据：任一类把这八个方法抄回来就红。
- **日期选择器共用骨架**：新增 `abstract CalendarPickerState`，`DatePicker` /
  `DateTimePicker` 的"触发框 + 面板开合 + 翻月通知 + 关闭汇合 + 受控值签名"只有一份；
  两者只剩面板内容与"选完收不收"两处差异（`DatePicker` 收、`DateTimePicker` 不收）。
  此前 70 行逻辑各存一份，改一处漏一处不会报错。
- **删掉 `AppContext` 转发层**：P2 拆分留下的这个 11 方法纯转发壳只被 `Application`
  的默认实现用到，现在那四处（主题 / 度量 / 端口 / 提交）直接读 `AppScope`，
  壳不再持有上下文。原先守"上下文不收帧内方法"的反射断言迁到真正的服务容器
  `AppScope` 上——守卫对象比原先更实在。
- **输入链顺序单一真相**：键盘六环（Esc → 编辑 → 焦点 → 加速键 → 激活 → 上下文菜单键）
  的散落描述收敛到 `InputRouter::dispatch()` 的注释，`FocusManager` 不再抄一份顺序图，
  交互指南补上此前漏掉的 Esc 环。新增 `InputChainOrderTest` 按调用位置钉住六环顺序
  ——顺序写错不报错、只是行为错（Esc 被输入框吃掉、`Ctrl+W` 绕过对话框），
  这类纪律必须由工具守。
- **CI 架构判据纳入本地验证**：`assert-workflows.php` 一直是 `verify.yml` 的独立步骤、
  不在 `VerifyPlan` 里，A28 删 `ShowcaseNav`/`NavEntry` 后它引用已删文件，本地全绿而 CI 红。
  判据改用 A29 后的导航链（生成清单 → `PageRegistry` → `RouteDescriptor`）做锚点，并作为
  `workflows` static step 并入 `bin/drift verify`——同一份脚本，本地与 CI 不再漂移。
- **注释与文档跟上拆分后的类名**：`Application` 拆成 `AppShell` 与若干协作者之后，34 处
  注释仍写 `Application::pumpSemantics()` / `activateFocusedWidget()` / `placeOverlays()`
  等已迁走或已不存在的方法（含 5 篇指南），现改为真实归属（`AppShell` / `InputRouter` /
  `OverlayLayout` / `OverlayManager` / `PumpHost` / `ScrollInputRouter`）。同时清掉 5 处
  叠置的 docblock（旧说明紧贴新说明、旧的那份描述的是被替换前的实现）与文档里两处漂移
  （`ForEach` 应为 `Each`、`Bootstrap::run()` 应为实例调用）。
- **剪贴板单一真相**：`Environment` 的剪贴板读写原先直连 `AppScope` 端口，而编辑命令环
  走 `Application::clipboard()` 覆写点——应用覆写后两者指向不同端口且不报错。现门面经
  `AppShell` 注入的覆写点闭包访问，两条路径合一；覆写机制保留，与 `theme()`/`metrics()`
  一致。
- **示例台切页保活**：`ShowcaseRoot` 改用框架现成的 `PageHost` + `RouteTable`，34 页按
  稳定 id 注册、页面实例只造一次、换页走 Offstage 保活——表单草稿 / 滚动位置 / 异步
  结果切走再切回都还在（此前每次切页 `new` 新页，23 个 StatefulWidget 的 State 全丢）。
  新增 `PageHost::current()`（纯 UI 态直接给页名，不必再造 Subject）与 `expanded()`
  （内容区撑高），Offstage 加稳定 Key，侧栏顺序不再是身份。
- **`State::onActivate()` 首次可见钩子**：全常驻容器首帧就 mount 所有页，`initState()`
  不再等于"用户进过这页"。示例台的三个异步页把首次取数从 `initState()` 迁到这里。
- **隐藏页不再白烧 CPU**：`OffstageElement` 摘出可见树的子树从帧活跃表剔除，其环境
  变化（主题 / 明暗）也会补发给 parked 子树，切回来直接是新主题。
- **探针坐标锁解除**：6 个真机探针按导航项的无障碍名点击，不再记 `90 + N * 36` 的
  像素行号；插页 / 重排不会再让探针静默点错页。
- **组合根样板下沉**：后台装配收进框架——`AppScope::useBackgroundJobs()` 收掉取 exe →
  建进程池 → 预热 → 装端口四步；watermark / users / showcase 三份逐字相同的装配删除。
  `worker-argv` lint 禁止第四份开关字面量重抄。第二窗 / 无边框 / 材质 / 恢复 / 关闭守卫
  仍是应用语义，留在各自组合根，不塞进配置对象。
- **进程角色分流收进 `AppEntry`**：应用组合根继承基类，只声明 `jobs()`（后台任务表，可省）
  与 `start()`（UI 装配），看不见 `--drift-worker`、不 import `WorkerProcess`。`jobs()` 一处
  声明、两处消费（worker 干活 + `useBackgroundJobs()` 装池），不再各 `make()` 一遍。
  `app-worker-role` lint 守“角色判定不许搬回应用层”。裁决 A25。
- **`AsyncView::retryOn()`**：error 默认支持一行装"错误 + 重试按钮"，showcase 的异步表格与
  并发取数两页删掉重抄的 `Center(Column(ErrorPlaceholder + Button))`；显式 `error()` 仍优先
  （分页失败要保留旧行，LoadMore 页因此不迁）。新增裁决 A23 / A24。
- **UI 线程异步 HTTP（`AsyncHttp`）两条分流均有真机消费者**：门面按 body 大小 / multipart
  分流——小 JSON 走帧内 `curl_multi`，multipart / 大 body 走 `HttpRequestJob` 进程池，
  两条路共用 `Http::prepare()` / `finish()`。示例台 `DRIFT_SHOWCASE_HTTP=1` 时「异步表格」
  取数走前者、「上传示例」按钮带进度走后者；`probe-async.py` 在 AOT 产物上对两条支路各验
  在途→回贴。AOT 下帧内路径不调 `curl_close()`（对象属性 handle 传进去会原生崩溃，症状是
  请求成功却永久 loading）。裁决 N6。
- **取消在途任务真停 CPU**：`CancelSupport` 升到四档，`ProcessTaskHost` 取最高档 `Killable`
  ——取消一条在途任务会杀掉独占它的 worker、下帧补池（每 worker 在途 ≤1 ⇒ 不连坐），
  排队中的仍只是摘队列。取消不是失败：不投 `Failure` / 不 onError、`Future` 保持未结算。
  此前进程后端只能撤排队、在途只能"不交付"，后台照烧 CPU。裁决 N7。
- **表格 `Ctrl+C` 接上剪贴板（两个零消费者 token 落地）**：`Table::onCopy()` 钩子与
  `Environment::setClipboardText()` 此前都只有实现与单测、apps 零消费者。示例台表格页
  组合根注入 `Environment` 后声明 `onCopy`，把 `TableState` 算出的选区 TSV 交剪贴板；
  无头 / golden 默认构造不注入 ⇒ 不声明 `onCopy`、键交回应用，逐像素不变。`Ctrl+A` /
  `Ctrl+C` 键名大写（壳给 `A`–`Z` 大写），判据用真实键盘事件驱动而非直调回调。

## v0.1.0（2026-11-20 · 首个无后缀的预览版）

这一版把 preview.1 之后的能力补齐定为一个可下载的发布档位；**仍属技术预览**，
但去掉了 `-preview.N` 后缀——`0.x` 版本号本身即表明预览状态，`1.0.0` 才是正式版。
相比 `v0.1.0-preview.1`：

### 已实现

- **Linux x64 / X11 第二平台**：X11Platform 端到端门在公开 CI（`ubuntu-24.04`）真跑通——
  真窗口出现、关闭事件链、消息泵收工、干净退出；环境为 Xvfb + fluxbox。
- **X11 平台能力补全**：XSettings / 环境外观（暗色与高对比度跟随）、zenity / yad 对话框、
  yad 托盘图标、xclip `TARGETS` 剪贴板探测、XIM 插入符定位。Wayland / AT-SPI / 组合 IME /
  per-monitor DPI 仍不在能力面。
- **双 PHP 矩阵**：Windows 与 Linux 两条 CI 均按 PHP 8.4 / 8.5 ZTS 双版本验证；工具链统一
  使用 `swoole` 官方源（ref 钉死），失败日志按版本分存。
- **依赖分发**：`deps-v2` 双平台依赖包（Windows / Linux 各一资产，schema=2 单锁，Skia pin
  到 `13ffba25`）；dev shell 按 PHP 版本命名 `drift-dev-shell-win-x64-php8.4/8.5.zip`。
- **文档结构**：按 start / guides / concepts / reference / decisions / contributing 六区重组，
  统一 Windows + Linux 双平台口径。

### 明确不包含（预览阶段）

- ❌ 完整多平台对等（Wayland / AT-SPI / 完整 IME 组合输入 / per-monitor DPI 未接）
- ❌ i18n 框架 / 触控 / 笔 / 安装器 / 代码签名 / 自动更新 / 第三方组件生态与包管理
- ❌ 全匿名、零本机替代的端到端重跑（本机代理中断所阻，非产品缺陷；换网络可重跑）

## v0.1.0-preview.1（2026-11-10 条目 · 已打 tag）

初版技术预览。能力面见下方条目，边界见 [`框架概览`](docs/start/overview.md)。

### 已实现

- 声明式 UI 三层树（Widget / Element / RenderObject）+ 单向下行 + 组合根注入
- Skia 自绘 + Raster 软后端双渲染；高 DPI（Per-Monitor V2）/ 多屏 / 分数缩放
- 布局系统（单遍约束传递）、文本自排版 + IME
- 显式 `Equatable` 状态契约；`Subject` 响应式；`TaskHost` 三后端异步 + `Future` / `AsyncScope`
- 手势竞技场 + 焦点通道 + 键盘 / 滚轮 / 拖放
- 动画（`AnimatedValue` / `Curve` / `AnimationController` / 隐式动画）
- 多窗口（`WindowHost` / `WindowSet`）
- 深色主题（`ColorToken` + `SystemTheme` 跟随系统）+ 系统高对比度跟随
  （HC 独立轴 + `GetSysColor()` 用户选色）
- 主题扩展缝：`ThemeExtension` + `ThemeData::withExtension()`，应用侧可挂自定义 token
- Windows 无障碍满配（UIA provider + 树导航 + 结构 / 焦点事件）
- 控件面约 130 个类（含 8 个接口；精确清单以 `src/Widget/` 为准）
- 8 个应用样例（hello / tutorial / users / showcase / watermark / bench / dev / pacing）
- 工程质量：统一闸门（`composer checks` 是清单的唯一权威，项数以输出为准）+ 变异四律 + 三态判据 + 金样确定性注入 + T0/T1/T2 分层 + 真机探针
- X11 能力补全：XSettings / 环境兜底的暗色与高对比度跟随、xclip `TARGETS` 探测、
  zenity / yad 对话框（模态时间入账）、yad 托盘图标、XIM 插入符定位
- 双平台依赖分发（deps-v2 已双平台发布）：Windows / Linux 各一资产、共享 schema=2 单锁；Windows Skia 裁剪未使用模块，macOS 仅预留平台表
- 依赖链：Skia 预编译包与字体走 Release 资产（SHA256 入库、可断点续传），另有
  `SKIA_REVISION` + `tools/skia/` 构建输入支持从 pinned commit 源码重建；
  CI 两条流水线已入库（依赖包重建 / 产品 draft release），都跑在托管 `windows-2022` 上

### 明确不包含（preview 阶段）

- ❌ 完整多平台对等（preview 提供 Windows 完整桌面 + Linux x64/X11 能力子集；Wayland / AT-SPI / 完整 IME 组合输入 / per-monitor DPI 未接；X11 系统色无统一 API）
- ❌ i18n 框架
- ❌ 安装器 / 代码签名 / 自动更新
- ❌ 第三方组件生态 / 包管理
- ❌ 触控 / 笔
- ❌ 远程崩溃上报

### Breaking

- `Screen` 端口新增 `appearance(): SystemAppearance`（唯一实现 `Win32Screen` 已跟上）；
  `ThemeChangedEvent` 新增带默认值的 `appearance` 参数，无参构造仍可用。
- 壳 ↔ PHP 新增 `drift_win_high_contrast()` / `drift_win_system_color(int)` 两个桥函数，
  并接了 `WM_SYSCOLORCHANGE`。ABI 无运行期握手，**改 C++ 壳必须整树重编译**。

### 已知限制

- 布局为整树重排，无 `RelayoutBoundary`；12k 节点实测仍有余量（读数见
  读数见 git 历史），桌面工具类够用。
- 壳 ↔ PHP ABI **运行期无握手**：改 C++ 壳或 PHP 接口必须同步重编译。跨层契约不是没人守 ——
  静态契约由 `php tools/abi-check.php` 管（stub 为唯一事实源，`--json` 出 schemaHash）。
- AOT 增量构建**已有** stale 判据（`StalePolicy`，首次自动全量）。发版验收仍用 `--full`，
  理由是排除增量缓存的影响，不再是因为判据缺失。
- **源码树不自足：Skia 预编译包与字体不入库**。`/cpp/` 整个目录在 `.gitignore` 里，
  `git clone` 后 `cpp/skia/`（约 141 MB）与 `cpp/fonts/`（约 21 MB）**不存在**，
  缺了构建期直接链接失败。本仓提供 `php tools/pack-deps.php` 打成
  `dist/drift-deps-win-x64.zip`（Skia M148 + Noto Sans SC，约 50 MB）并输出 SHA256，
  包内随附 `cpp/licenses/`（11 份许可全文 + `THIRD-PARTY.md` 索引），并为 Skia 与 OFL 在
  `cpp/skia/LICENSE`、`cpp/fonts/OFL.txt` 留了就近副本 —— 九个静态库的许可不再只给链接；
  但**包本身不进 git 历史**：托管在 GitHub Releases，用 `php tools/fetch-deps.php` 取 ——
  下到 `dist/`、拿入库的 `deps.lock.json` 校验、解压后核对 lib/字体/许可数量，
  支持 `DRIFT_DEPS_URL` 镜像回退与断点续传。仓库公开后 Release 资产匿名可取
  （`deps-v1` 已发布）；落在私有镜像时带 token 走 API 解析；发布一侧由
  `php tools/release-deps.php` 完成，不需要 `gh`（tag 由 `DEPS_VERSION` 决定，
  发版前还会对账包内 `deps.lock.json` 的版本锚点）。
  `doctor` 新增「Skia 依赖包」一项，`drift-build` 缺包时直接给出取包命令，
  不再一路跑到链接期才报 LNK2019。`deps-v1` 已发布，`php tools/fetch-deps.php`
  匿名即可拉取；离线 / 内网场景仍可 `php tools/fetch-deps.php --url=<本机 zip 路径>`
  （或 `DRIFT_DEPS_URL=<目录>`），与在线那条共用同一套校验（lock → SHA256 → 解压后数
  lib / 字体 / 许可），流程见
  [`依赖与发布`](docs/guides/dependencies-and-release.md)。`SKIA_REVISION` 已 pin 到 Skia M148
  的真实 commit，lock 的 `skiaRevision` 与 `provenance: ci` 同步；`deps-v1` 已由
  `windows-deps.yml` 从这个 commit 重建并发布，来源声明与产物一致。入口见该文档的
  「CI 自动构建依赖包」一节。
  另：`PHPX` 与 `tpc` 源码树属
  TypePHP 编译器项目，同样不在本仓。搭建步骤见 [`README.md`](README.md) 的「构建环境」。
- 只用运行态时**不需要**上述工具链：`php bin/drift verify` 是纯 PHP 的，
  `composer install` 也不必跑（`tools/autoload.php` 直接读 psr-4）。
