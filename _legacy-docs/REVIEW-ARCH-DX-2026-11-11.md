# 综合 Review · 2026-11-11

> 基线 HEAD `700bd39`，工作区干净。**只读评审，未改任何代码 / 配置**。
> 方法：三路分面取证（核心运行时 / 状态异步输入 / 工程链）→ 逐条 grep 与 AOT 生成产物复核
> → 对比成熟 GUI → 区分「确认缺陷 / 有意裁决 / 假阳性」。
> 不照抄 [`REVIEW-ARCH-DX-2026-11-08.md`](REVIEW-ARCH-DX-2026-11-08.md)、
> [`PLAN-NEXT-STEPS-2026-11-11.md`](PLAN-NEXT-STEPS-2026-11-11.md) 与 DEBT 的清单：
> 本仓已多次证明清单会腐烂，本轮每条结论都重新落到代码或生成产物上。
> 定位一律用类名 / 方法名 / 命令，不写 `file:line`（本仓文档纪律）。
>
> **处置状态（2026-11-11 同日）**：本文件的 P0 两条 / P1 两条 / P2 五条已修，
> 一处复核结论被推翻（字体不是构建输入）。改动清单、验证读数与三处偏差见
> [`PLAN-REVIEW-FIX-2026-11-11.md`](PLAN-REVIEW-FIX-2026-11-11.md) §6。
> 本文件保留**评审当时的原样判断**，不回填结论 —— 保留"当时为什么这么判"比
> 改成一份正确的文档更有用。

---

## 0. 总评

**实测基线**：`php tools/gate.php --fast` → **25 项全过（46.6s）**，其中 `unit` 619 测试 /
1918 断言、`golden` 9 场景、10 组「判据 + 自证变异」成对全绿。规模：`src/` 399 个 PHP、
8 个 app、`cpp-src/` 三分平台目录、28 项闸门、31 条 lint 规则。

**一句话判断**：能力面与验证纪律仍然强于一般个人项目，11-08 评审的 P0 大多已闭环
（`StalePolicy` + `build-stale-check`、`abi-check` 静态契约、`errshape` 错误码贯通、
`Core` 纳入层表、CI 落地）。**本轮真正的高信号问题不在「缺能力」，而在
「纪律靠人肉枚举，而枚举每次都漏一处」**：

1. 框架自己写下的 AOT 回调 arity 铁律，自己的四个控件违反，且没有任何判据覆盖按键路径；
2. 刚补完 `cpp:` / `tpc:` 输入指纹，`app:` 侧的同一款洞还开着；
3. 闸门集合扩到 28 项，CI 白名单仍冻结在 11 项，**新增判据默认不进 CI**。

三者同族：**机制本身是对的，缺口都在「这一次有没有把新入口接进去」**。这类缺陷
在解释器 / lint / gate 全绿时依然成立，正好是本项目反复记录的「判据全绿但能力是假的」。

---

## 1. 五维评估

| 维度 | 现状 | 判断 |
|---|---|---|
| **结构** | `Core < Widget < App` 单向、BuildContext 只读快照、`cpp-src` 按平台三分、`Platform` 能力端口 | 分层方向正确且已机械化。但 `Application` 上帝类与 `Pipeline::current()` 进程级单例仍在，复杂度往静态中枢聚集；本轮 §3.2 就是「第 5 个帧外入口没切管线」 |
| **实现** | 28 项闸门 + 变异牙检 + golden 确定性 + T0/T1/T2 分层 | 判据密度罕见地高。弱点不是没有判据，而是**判据多为「接线清单」**：清单漏一项，闸门照绿。本轮 4 个发现里有 3 个属于此类 |
| **扩展性** | 接第二平台 = 新增 `cpp-src/<platform>/`；`TaskHost` 可换第四后端 | 落点清楚，但 `Platform` 接口仍在演进、帧记账与进程级 static 绑定多窗口，接平台前要先收口这两处 |
| **开发者体验** | dev 态改 PHP 秒级生效、`doctor` 逐项可操作、tutorial 完整、错误信息中文且带修法 | 上手体验是加分项。**最大摩擦仍是 AOT 专有的「三绿一炸」**：回调少一个参数、类未注册、其它解释器/AOT 语义差，解释器全绿、真机才炸且无行号（「常量求值成 0」已由上游修复，见 §5 与 [`PLAN-REVIEW-FIX-2026-11-11.md`](PLAN-REVIEW-FIX-2026-11-11.md) §2.2） |
| **维护性** | `DESIGN.md` 精简到 133 行、旧稿逐字节归档、docref 覆盖根级入口文档、项目名有判据 | 文档治理明显进步。但代码注释里的「判据性叙述」仍会过期（见 §5 假阳性），100+ 条口传铁律 vs 31 条 lint 规则的比例未变 |

---

## 2. P0 — 地基级

### 2.1 键盘回调 arity：自己定的 AOT 铁律，自己四个控件违反

**证据链（四条互相独立）**

1. 派发点固定传两个参数：`FocusManager` 的焦点链循环里 `$handler($event, $node)`；
   AOT 产物 `apps/users/build/src/App/FocusManager.cc` 生成
   `typephp_call_cached(handler, ..., php::VarList{event, node})`。
2. `WidgetInteraction::keyboard()` 的 docblock 明确声明签名是 `(KeyEvent, Element)`；
   而 `Slider`、`Select`、`ComboBox`、`CalendarView` 的 `keyboard()` 都只写
   `function (KeyEvent $event)`。生成产物里 `Slider.cc` 相应闭包是
   `{ php::ClosureParameter{"event", ...} }` —— **一个参数**；
   同一个文件的 `pointer()` 闭包是两个参数，说明差别不是编译器优化，是源码写的。
3. 本仓自己的事故记录：[`DESIGN-TABLE-2026-10-26.md`](DESIGN-TABLE-2026-10-26.md)
   「一条 AOT 与 PHP 解释器的行为差异」写着：解释器忽略多余实参，AOT 当场
   `Fatal: expects exactly 1 argument, 2 given`，被捕获期 try/catch 接住 ⇒
   **整页变成错误占位**，并定下纪律「给 `pointer()` / `hover()` / `keyboard()`
   写回调都按完整签名写」。[`docs/AOT-CONSTRAINTS.md`](AOT-CONSTRAINTS.md)
   与 `DESIGN-FOCUS-CHANNEL-2026-11-06.md` 重复了同一条。
4. `FocusManager` 里那句注释「老的单参 handler 在 PHP 里会忽略多余参数」，
   正是把解释器语义当成 AOT 语义。

**触发与影响**：任一上述控件获焦后按方向键 / Home / End / PageUp / PageDown，
AOT 产物里回调参数个数不匹配 ⇒ 该页变错误占位，且 `RenderErrorBoundary`
的 catch 把 `$e` 丢掉（§4 表），**日志里没有类型、消息、栈**，用户只看见一个红框。
单窗口应用与所有现有真机探针都不按方向键，所以 T2 5/5 与 gate 25/25 都不覆盖这条路径。

**为什么判据全绿**：`tools/lint.php --list` 的 31 条规则里没有任何一条守回调 arity；
现有 `await-progress` / `cancel-guard` 已证明「静态守 AOT 纪律」这个形状可行且低误报，
只是这一次没接。`probe-window` 只验窗口 / 尺寸 / 像素 / DPI / 退出码，`probe-press`
只验按下态，都不聚焦控件后发键。

**更优方案（三层，兼容性可破坏）**

- **立即（建议本轮做）**：补一条 lint 规则，扫 `->keyboard(` / `->pointer(` /
  `->hover(` 的闭包形参个数，按「1 规则 + 2 夹具 + 1 组断言」三件套，并做磁盘级变异
  （临时把 `Slider` 的闭包改成单参必须报红）。同时把四个控件改成双参。
- **判据先行**：加一条 T2 真机按键探针（聚焦 `Slider` → 发方向键 → 断言 stdout 零
  `Fatal` 且值真的变了），先确认能咬红，再谈修。项目纪律是「改了没咬红先怀疑变异打空」。
- **根治（结构上更优）**：把 `(KeyEvent, Element)` 合成**一个**不可变事件对象
  （WPF `RoutedEventArgs` 的形态；Flutter `Focus.onKey` 用固定签名 +
  `FocusNode` 由上下文取，也是单值入口）。派发点只传一个参数 ⇒
  **arity 这一整类 AOT 差异从类型层面消失**，以后加第三个上下文信息也不用再改所有
  回调。代价是所有回调签名破坏性变更，但本仓明确「API 不保证兼容」，且回调总数可控。

> 只补双参是「把清单补齐」，单值入口是「让这类清单不再需要」。推荐以第二条判据
> 验收第一条，再把根治排到下一个破坏性窗口。

### 2.2 输入指纹漏 `project.yml` / 图标 / 字体：同款坑只补了一处

`tools/drift-build.php` 的指纹行：

```php
hash_update($inputs, 'src:' . hashTree("{$root}/src") . "\n");
hash_update($inputs, 'app:' . hashTree($projectDir) . "\n");       // 默认只收 .php
hash_update($inputs, 'cpp:' . hashTree("{$root}/cpp-src", '') . "\n"); // 已补：全后缀
```

而 `hashTree(string $dir, string $suffix = '.php')` 默认只收 `.php`，且**没有忽略目录**。
`apps/<app>/` 下真实存在 `project.yml`（构建输入：`include` / `sources` / `ignore` /
`resource`）、`README.md`；`build/base.yml` 的 `resource.icon` 指向 `assets/*.ico`，
字体在 `cpp/fonts/`。`base:` 只哈希 `build/base.yml` 文件本身，不哈希它引用的资源。

**影响**：改 `sources` / `ignore`、换应用图标、换字体 ⇒ 指纹不变 ⇒ `StalePolicy`
判 SKIP ⇒ `distributeRuntime()` 后直接退出，**旧 exe 原样保留且无任何提示**。
这与 11-11 刚修的「拆完 UIA 重编却报输入未变」是同一个 bug 形状，只是换了个输入类别。
`tools/build-stale-check.php` 只校验 `'cpp:'` 那行和 tpc 两处，不守 `'app:'`。

**为什么判据全绿**：`build-stale-check` 自检 11 条全过，因为它是「接线对账」——
只查已知的几行，不查「输入清单全不全」。这是本项目第 N 次遇到「判据不覆盖输入面」。

**更优方案（有个陷阱，别踩）**：不能简单把 `app:` 改成 `hashTree($projectDir, '')`。
`apps/<app>/build/` 就在工程目录里，装的是每次构建都会变的生成物（`.cc` / `.res` /
`bin`），全收进指纹会让**每次构建后指纹都变 ⇒ 永远 FULL**，比 SKIP 更难发现。

正确形状是显式输入清单 + 忽略生成目录：

- `hashTree` 增加 `$excludeDirs` 参数（至少排除 `build/`、`bin/`）；
- `app:` 改收 `.php` + `.yml` + `.json`，并把 `project.yml` 单独 `hash_file`；
- 新增 `assets:` / `fonts:` 两行，按受控扩展名（`.ico` / `.png` / `.ttf` / `.otf`）收；
- `build-stale-check` 加第 12/13 条对账与变异：把 `app:` 退回默认后缀必须报红、
  删掉 `assets:` 行必须报红。借鉴 cargo / Bazel 的 **input manifest**：输入集合本身
  是受检对象，而不是散落在调用点的默认参数。

---

## 3. P1 — 结构性

### 3.1 T0 闸门被 `doctor` 卡死，CI 白名单与判据集脱节

- `tools/Doctor/Environment.php` 里 phprc / phpx / tpc / msvc / sdk 的 `affects`
  全是 `['build']`，`opcache` 是 `['gate']` —— 分级信息**已经存在**。
- 但 `tools/doctor.php` 判定 `$failed = $counts[Check::FAIL] > 0` 完全不读 `affects`；
  `affects` 只用于失败后打印「影响：build」。
- `tools/gate.php` 把 `doctor` 排**第一项**（非 slow，`--fast` 也跑），且失败即 `break`。
  ⇒ 一台只有 PHP 8.4 的机器按 [`README.md`](../README.md) / [`AGENTS.md`](../AGENTS.md)
  跑 `gate`，**一条 T0 纯 PHP 判据都跑不到**。
- `.github/workflows/ci.yml` 用硬编码 `--only=...`（11 项 / nightly 13 项）绕开 doctor，
  实测 `gate.php --list` 是 28 项 ⇒ `abi` / `abiself` / `cppbridge` / `cppbridgeself` /
  `dxdiag` / `dxself` / `stalepolicy` / `staleself` / `errshape` / `errself` 这 10 项
  **纯 PHP、亚秒级、且都是防静默失效的接线判据，永不进 CI**；以后新增项也不会自动进。

本机有完整工具链，所以 `gate --fast` 25/25 看不出这个缺陷；CI 的 `--only` 白名单
正是它的旁证。**判断**：T0 的定义是「无头解释器、可 CI」，把 AOT 工具链体检塞进
T0 第一项，等于把两条轨道绑在一起；`--only` 是 workaround，不是设计。

**建议**：`doctor` 加 `--scope=gate`，只把 `affects` 含 `gate` 的项作为失败依据
（`flutter doctor` 与 `flutter test` 本就是两条命令）；`gate` 的 doctor 项改调它；
CI 两个 job 改用 `--fast` / 全量 `--json`，让判据集合随 `gate` 自动增长。
配一条判据：临时把 `doctor` 的某项 `affects` 改成 `['build']` 且人为 FAIL，
`gate --fast` 仍须全绿（除 doctor 报 WARN）。

### 3.2 帧外 resize 未切 `Pipeline` ⇒ 多窗口记错账

`Pipeline::enter()` 在 `Application` 里有 5 处（attach / 跨档 resize / frame /
dispatchInput / 命令派发），`Frame::run()` 自己不切（类注释写明依赖调用方）。但
`repaintForResize()`（壳在 WM_SIZE 模态循环里同步调回）**没有 enter**；它先调
`adoptWindowSize()`，而后者在「档位没变」时早返回，早返回点正好在它自己的
`enter` **之前**。于是档内拖动 resize 时，`placeOverlays()` → `RenderBox::setBase()`
→ `Pipeline::current()` 落到「上一次跑帧那个窗口」上。

**影响**：≥2 窗时，本窗的 paintEpoch 记到别窗账上 ⇒ 本窗重绘门控误跳过、
别窗空转，**不报错**。`MEMORY.md` 已把「记账字段住 Pipeline，四入口都要切」列为铁律，
这是第 5 个入口漏了 —— 又是清单式纪律漏一项。

**建议**：短期把 `enter` 提到 `repaintForResize()` 入口（与另 5 处同形），并配一条
多窗口真机探针（两窗交替跑帧 → 拖 A 窗 → 断言 A 的 paintEpoch 变化、B 不变）。
长期对齐 Flutter `BuildOwner` / `PipelineOwner`：**显式注入到 Frame 与 RenderObject**，
而不是靠进程级 static 切换；这也是接第二平台前必须收口的一点。

### 3.3 异步契约跨后端不等价，默认后端恰好是能力最少的那个

| 能力 | `SyncTaskHost`（默认） | `ThreadTaskHost` | `ProcessTaskHost` |
|---|---|---|---|
| `TimedJob` 超时 | 不读 | 不读 | 唯一读取者（`sweepTimeouts()`） |
| 进度 | 无 | 无 | 唯一喂 `onProgress()` 的后端 |
| 取消 | 只丢弃已算完的结果 | 「不交付」但 worker 仍跑完 | 可杀进程 |

`TimedJob` 全仓生产读取点只有 `ProcessTaskHost` 一处；`AppScope` 未表态时默认
`new SyncTaskHost()`。`apps/watermark` 的两个批量 Job 都声明了 >120s / >300s 上限，
[`DESIGN.md`](../DESIGN.md) 也写「超时由任务自己声明上限」—— 开发者在默认后端
以为有超时保护，实际挂住就永远转圈。进度同理：`AsyncScope::await()` 第二参在
Sync/Thread 下恒不触发，`AsyncValue::hasProgress()` 恒 false，**不报错**。

这与 11-08 评审的「缺 `Future::timeout` 组合子」是同一处，但角度不同：
不是缺一个 API，是**同一段业务代码换后端就换语义**，且没有诊断。

**建议（更优方案）**：把 deadline / 超时下沉到 **Future 层**（对齐 Dart
`Future.timeout()`，与 executor 无关），后端只保留「能不能真强杀」这一项实现差异；
后端不支持强杀时至少 `Errors::report` 一条 DIAGNOSTIC，落实 G2「要么生效、要么
响亮失败」。同时把三后端的进度 / 取消语义写成一张受检表（表驱动，别靠注释），
让新增第 4 个后端时必须补齐。这一项与 §82 执行作用域裁决稿可以合并出稿。

---

## 4. P2 — 可搭车修的小缺口

| # | 问题 | 证据 / 影响 | 建议 |
|---|---|---|---|
| 4.1 | `RenderErrorBoundary` 吞异常且零留痕 | `performLayout()` / `paintContent()` 的 catch 只置 `paintBroken` 画红框，`$e` 丢弃，不调 `Errors::report`；与 §2.1 叠加时，用户只看到红框、日志无痕 | 保留「不自动复位」语义，但 catch 里报 DIAGNOSTIC 并带盒类型（Flutter 红屏带栈是同一目的） |
| 4.2 | `probe-audit` 漏掉自己点名的三个探针 | 它 `glob('tools/probe-*.py')` 只认连字符，实测 13 个；`probe_win.py` / `probe_check.py` / `probe_pixels.py` 三个下划线文件不在内，而 `probe_pixels.py` 正是 gate 的 `pixels` 项驱动 | glob 改 `probe[_-]*.py`，`--selftest` 报数应随之增加 |
| 4.3 | `gate.php` 头部注释自称「检查项清单」却漏 `cppbridge` 两项 | 注释 26 项 / `--list` 28 项，文件自己写着「项数以最后一行为准」 | 删掉注释里的清单，只留指向 `--list` 的一句 |
| 4.4 | 判据脚本硬编码本机绝对路径 | `tools/press-sequence.php` 顶部 `require 'D:/TypePHP/Drift2/tools/autoload.php'`；`tools/probe-raster-page.php` 默认输出路径同样绑本机。前者被 `AOT-CONSTRAINTS.md` 与 DEBT 当作正式对表命令 ⇒ 换机器 / 换目录即 Fatal，判据不可复现 | 改 `__DIR__ . '/autoload.php'`；默认输出落到相对仓库根的 `_build/` |
| 4.5 | 一批 `@api` 零生产消费者 | `ThemeData::withHighContrast()`、`AppScope::setTheme()`、`Future::race()` 在 `apps/` 零调用；`AsyncValue::code()` / `AsyncCommand::mapError()` 只见于测试；业务仍用 `message()` 拼串，主题跟随系统是每个 app 自己写一遍 | 要么补一个消费者（showcase 主题页），要么在 [`API-SURFACE.md`](API-SURFACE.md) 标「预留」，避免对外宣称「已支持自动换肤 / 错误分类」 |
| 4.6 | 静态工厂退化 | `IconButton::of()` 是有意裁决（PHP 静态方法也查 LSP，源码注释写明了），但 `IconButton::make('删除')` 会经 `Button::make()` 的 `new self` **静默返回一个普通 Button**，图标丢失 | 在 `IconButton` 覆写同名 `make()` 抛「请用 `of()`」，或把 `Button::make()` 改 `new static` 让它响亮失败（G2） |
| 4.7 | `fold()` 的校验与刷新路由 | 只用 `isset` 校验四支（AOT 下未验 `is_callable`，是有意取舍），写成 `null` / 数字时报错错位；「刷新中」硬编码走 `data` 支，页面须自己二次分支 | 校验信息带上具体键名；刷新路由提供显式开关（Riverpod `skipLoadingOnRefresh` 的形状），默认值不变 |
| 4.8 | 生命周期兜底仍不对称（11-08 已提，未修） | `State::setState()` 无 `mounted` 守卫；`Application::after()` 明明返回 id 且有 `cancelAfter()`，教程的 `AsyncStage` 却把回调包成 `void`、页面切走后仍对已卸载 State 写值 | 补 `mounted` 守卫 + 通用 disposers 袋（Flutter `State.mounted` / `Timer.cancel()` 是标准形态） |

> **2026-11-12 归档订正（不改 4.4 的裁决）**：该行点名的
> `tools/probe-raster-page.php` 已合并进 `tools/drift-preview.php --format=png`，
> 原件只读留存于 `tools/_archive/probe-raster-page.php`，默认输出不再绑本机绝对路径。
> `tools/press-sequence.php` 那半本轮未动 ⇒ 4.4 的判据继续有效；原记录保留划痕。

---

## 5. 复核后排除的假阳性（重要：别照着代理结论改代码）

| 初判 | 复核结果 |
|---|---|
| 「`RenderScrollView` / `RenderTable` 手写 `BoxConstraints::INFINITY`，AOT 下求值成 0 ⇒ 列表不可见」 | **不成立，且该缺陷已由上游修复**（2026-11-11 用户补充后复核）：`swoole/typephp` #184 批次的 `95e4f3e6` 与前置 `fce275b7` 修好标量常量的存储/折叠，本仓 10-28 记忆已记「不再复现」；当前产物 `apps/users/build/include/php_src_Geometry_BoxConstraints_arginfo.h` 把 `INFINITY` 注册为 `std::numeric_limits<double>::infinity()`，`RenderScrollView.cc` 走 `php::constant(...)` 取内建 `INF`。源码里的禁令只是**过期注释**，不是现存缺陷。本轮已订正 `MEMORY.md` 铁律、`PITFALLS` 与 7 处源码/测试注释；`capWidth()` 工厂与 Tooltip/Select 面板非零尺寸断言保留作回归守卫，不新增依赖生成产物的 T2 定值判据。**不要按旧注释去改布局** |
| 「`IconButton::of()` 命名混乱」 | 源码注释写明是 PHP 静态 LSP 约束下的有意裁决，不是缺陷（真问题见 4.6） |
| 「`fold()` 刷新中走 `data` 是四态覆盖不全」 | 源码与 showcase 页面注释写明是裁决：首屏才转圈、有旧值画内容。只作为 DX 可选项（4.7） |
| 「`select()` 对 `array` 放行是洞」 | 11-11 已复核为正确契约（数组 `===` 本身值语义），不再重复 |
| 「`docref --selftest` 的 `section-refs` 反例恒红」 | 已知遗留，且不进 gate；本轮实测仍是 3 ok / 1 FAIL，不计新发现 |
| 「根目录大量 exe / map / lib 污染仓库」 | `git ls-files` 与 `git check-ignore` 核对：全部被 `.gitignore` 覆盖，工作区干净 |

---

## 6. 与成熟框架的对照（借鉴与排坑）

| 框架 | 可借鉴 | 明确别学 / 排坑 |
|---|---|---|
| Flutter | `BuildOwner` / `PipelineOwner` 显式注入；debug 断言 + 带栈红屏；`State.mounted` + dispose 取消；`Future.timeout` 与 executor 解耦 | 不引自动依赖追踪（AOT 无反射，11-08 已判死）；不照搬 `InheritedWidget` 的 provider soup |
| WPF | `RoutedEventArgs` **单值入口**从根上消灭回调 arity 类别错误；tunnel/bubble + `Handled` 的结果链 | 不做原生控件包装、不引 XAML 层叠 |
| Compose / Riverpod | `derivedStateOf` 默认按值相等；`AsyncValue.when(skipLoadingOnRefresh:)` 把策略做成显式开关 | 快照自动追踪在本仓 AOT 下不成立 |
| cargo / Bazel | **input manifest**：输入集合本身受检，改了输入清单会触发重算 | 别把默认后缀 / 隐式目录当输入定义 |
| `flutter doctor` / `flutter test` | 工具链体检与测试闸门是两条命令，互不阻塞；`--fast` 自动继承新判据 | 别把体检塞进闸门第一项再靠 `--only` 绕开 |

---

## 7. 推荐执行顺序

排序沿用 DEBT 的三条判据：**①有消费者 ②错了是静默的 ③决定能不能交付**。

| 序 | 项 | 为什么排这里 | 验证档位 |
|---|---|---|---|
| 1 | §2.1 回调 arity：**先写 T2 按键判据并确认真红**，再补 lint + 四个控件双参 | 唯一「用户按一下就整页错误占位、且日志无痕」的路径；AOT 专有，解释器全绿 | **T2 真机**（按键探针）+ T0 lint 变异 |
| 2 | §2.2 输入指纹补 app / assets / fonts + 对账判据 | 改配置或换图标后产物静默过期，直接决定「交付的 exe 是不是这份源码」；与 11-11 已修项是同族，成本最低 | T0（`build-stale-check`） + `--dry` 决策对照；不必每次 T2 |
| 3 | §3.1 doctor 分级 + CI 改 `--fast` | 10 项防静默判据永不进 CI，且新增项自动漏；纯 PHP，改动小 | T0；CI 上验一次 |
| 4 | §3.2 `repaintForResize` 切管线 | 多窗口才触发，当前应用多为单窗；但漏的是既有铁律的第 5 个入口，早修成本低 | T1 dev 真机 + 多窗口探针 |
| 5 | §3.3 超时下沉 Future 层 + 三后端语义表 | 有真实消费者（watermark 批量 Job），但要先出裁决稿（可并入 §82） | 出稿 T0；落地 T1 |
| 6 | §4 小项 | 按「搭同一辆车」原则：动 lint 时带 4.6，动 docref / tools 时带 4.2/4.3/4.4，动渲染错误处理时带 4.1 | 各随主项 |

**不建议本轮做的**：`Application` 上帝类再拆一次（11-08 已判「再拆只是搬位置」，
缺的是边界形式化，不是切文件）；第二平台 / DevTools / 增量布局（DESIGN 边界章有
重裁触发条件）；`curl_multi` 探针（无消费者，见计划的排后表）。

---

## 8. 诚实边界

- **只读评审**：未改任何 `src/` / `tools/` / `cpp-src/` / 配置；只新增本报告。
- **未跑 T2 真机构建**：§2.1 的结论建立在 AOT 生成产物 + 本仓历史事故记录 +
  项目自定纪律之上，置信度高，但**按本仓纪律仍须一次真机按键复现才算实证**；
  报告里已把这条判据排在第 1 位，而不是当作已验证的 bug。
- **本机有完整工具链**：§3.1「无工具链机器 gate 跑不了」是代码推演 + CI 白名单旁证，
  未在纯净 PHP 环境实测。
- **未做像素级 / 性能回归**：本轮只跑 `gate --fast`（25/25，46.6s），未跑全量 28 项、
  未跑 golden 整页基线、未跑 `aot-equivalence.php`。
- **清单会腐烂**：所有类名、命令、读数均为 2026-11-11 复核结果。按
  [`AGENTS.md`](../AGENTS.md) 的纪律，开工前仍要重新 grep 一遍。
