# Review 修复工作计划 · 2026-11-11

> 输入：[`REVIEW-ARCH-DX-2026-11-11.md`](REVIEW-ARCH-DX-2026-11-11.md)（只读评审，271 行）。
> 基准：HEAD `700bd39`；工作区仅有该报告未提交；`gate --fast` 25/25（46.6s）、
> `gate --list` 28 项、lint 31 条规则。
> **本文件既是评估，也是实施记录**：§0–§3 是开工前的复核与裁决（写完即冻结），
> §4 是执行顺序，§5 是验证与诚实边界；实施结果在收尾时补 §6。
> 纪律：`AGENTS.md`（判据与实现不同源、变异必须真红、同一文件多处编辑串行）。

---

## 0. 复核方法

不照抄报告清单。本仓已多次证明清单会腐烂，本轮逐条重新落到代码 / 生成产物 /
工具输出上，并额外核对两处「报告自身可能不准」的地方：

| 动作 | 结果 |
|---|---|
| `grep -rn -- "->keyboard(" src apps tests tools` | **违规面比报告列的大**：单参不只 4 个控件，还有 3 处间接工厂、3 处箭头函数测试夹具、5 处 smoke 夹具 |
| `git log` 上游 typephp + 生成产物 arginfo | **常量求值已修复**（用户补充），报告 §5 的「注释已过期」判断正确，但归因可更精确 |
| `grep 'app:' drift-build` + `ls assets/ cpp/fonts/` | 指纹洞确认，且 `resource.icon` 指向**仓库根** `assets/`，不在 `apps/<app>/` 内 ⇒ 只修 `app:` 不够 |
| `doctor.php` / `Environment::probe()` / `ci.yml` | `affects` 分级确已存在但未参与判定，`--only` 白名单 11 项 vs 28 项 |
| `Application::repaintForResize()` | 确认缺 `Pipeline::enter()`，且 `adoptWindowSize()` 的档内早返回点在它自己的 `enter` 之前 |

⇒ 报告的**方向成立**（三条 P0/P1 同属「判据全绿但能力是假的」），但**两处取证要修正**，
否则修完仍会漏。

---

## 1. 逐条复核结论

| 报告项 | 复核 | 结论 |
|---|---|---|
| §2.1 键盘回调 arity | **确认，且范围扩大** | 派发点 `FocusManager::dispatchKey()` 固定 `$handler($event, $node)`；`WidgetInteraction::keyboard()` docblock 声明 `(KeyEvent, Element)`。违规见 §2.1 |
| §2.2 输入指纹漏非 PHP 输入 | **确认，且范围扩大** | `hashTree()` 默认 `.php` 且无排除目录；`base.yml` 的 `resource.icon` 在**仓库根** `assets/`，字体在 `cpp/fonts/`，两者都不在工程目录内 |
| §3.1 doctor 卡死 T0 / CI 白名单 | **确认** | `doctor.php` 的 `$failed` 不读 `affects`；`gate` 把它排第一且 `break`；CI 用 `--only` 绕开，10 项亚秒级接线判据不进 CI |
| §3.2 `repaintForResize` 未切管线 | **确认** | 档内拖动时 `placeOverlays()` → `RenderBox::setBase()` → `Pipeline::current()` 落到上次跑帧的窗口 |
| §3.3 异步跨后端语义不等价 | **确认，但本轮不落地** | `TimedJob` 全仓唯一生产读取点是 `ProcessTaskHost`；默认 `SyncTaskHost` 不认超时/进度。属「改语义」，按 `AGENTS.md` 先出裁决稿等拍板；报告自己也写「先出裁决稿」 |
| §4.1 吞异常零留痕 | **确认** | `RenderErrorBoundary` 两个 catch 都丢掉 `$e` |
| §4.2 `probe-audit` 漏下划线探针 | **确认** | `glob('tools/probe-*.py')` 只认连字符；`probe_win.py` / `probe_check.py` / `probe_pixels.py` 不在内 |
| §4.3 gate 头注释清单 | **确认** | 注释 26 项 / `--list` 28 项 |
| §4.4 硬编码绝对路径 | **确认** | `press-sequence.php` 的 `require 'D:/TypePHP/Drift2/tools/autoload.php'` |
| §4.5 零消费者 `@api` | **确认但不修** | 属「宣称 vs 预留」的产品口径，需裁决；本轮不动 |
| §4.6 `IconButton::make()` 静默退化 | **确认** | `Button::make()` 用 `new self` ⇒ 图标丢失且无诊断 |
| §4.7 / §4.8 `fold()` / `mounted` | **确认但不修** | 都改公开语义 / 生命周期契约，需单独裁决稿，不与本轮混做 |
| §5 「INFINITY 求值成 0」 | **排除（已修复）** | 详见 §2.2；本轮要做的是**同步事实**，不是改布局 |

---

## 2. 两处关键修正（本轮评估的增量）

### 2.1 arity 违规面不是 4 个控件，是 4 类形态

报告只点了 `Slider` / `Select` / `ComboBox` / `CalendarView`。实测 `->keyboard(` 全量：

| 形态 | 位置 | 参数 |
|---|---|---|
| 内联闭包，`src/Widget/` | `Slider` / `Select` / `ComboBox` / `CalendarView` | **1（违规）** |
| 内联闭包，`src/Widget/` | `Menu` / `RichText` / `Table` / `TreeView::rowKeyHandler()` | 2（正确） |
| 间接工厂返回 `\Closure` | `ComboBox::keyHandler()` / `Select::keyHandler()` | **1（违规）** —— 报告漏了 |
| 箭头函数，测试夹具 | `DirectionalFocusTest` 3 处 | **1（违规）** |
| 闭包，smoke 夹具 | `ContextMenuKeyApplication` / `KeyAncestorApplication` / `PressLabApplication` / `ScrollKeyApplication` | **1（违规）** |

⇒ **lint 规则必须同时覆盖「内联闭包」与「返回 `\Closure` 的间接工厂」**，
只扫调用点会放过 `ComboBox` / `Select` 的一半。这是报告「1 规则 + 2 夹具」方案
需要补的一层，也是本轮比「把清单补齐」更值钱的地方。
`pointer()`（2 参）/ `hover()`（1 参）现有写法全部合规，纳入规则是防复发。

**方案裁决**：

| 方案 | 成本 | 判断 |
|---|---|---|
| 只把 4 个控件改双参 | 4 行 | 否决。清单式修法，下次新控件照漏，且放过间接工厂 |
| **lint 规则 + 全量违规点补齐**（本轮） | 1 规则 + 2 夹具 + 约 15 处签名 | **采纳**。判据与实现不同源（期望值写死在规则里），静态、亚秒级、零误报 |
| 把 `(KeyEvent, Element)` 合成单值事件对象（WPF `RoutedEventArgs`） | 新类 + 改全部回调槽与派发点 + 20 处签名 + 需要 T2 全量 | **排到下一个破坏性窗口**。它从类型层面消灭整类差异，是更好的终局；但当前 tpc 仍 pin 在 `d4f8c3ca`（Windows 运行时尚未含 `typephp_call_closure`），破坏性改动应与「解开 pin」同批做，不与本轮修复混在一起。且 `pointer()` 仍是两参、`hover()` 一参，只改 `keyboard()` 也谈不上「统一单值入口」——真要做应三个槽一起 |

### 2.2 「外部类常量求值成 0」已修复，是**文档债**不是代码缺陷

用户补充「常量求值应该已经修复了，#184 上应该有」。核实后判断成立，且证据链比
「#184 里有」更完整：

1. **上游**：`swoole/typephp` 的 `95e4f3e6`
   `fix(types): retain scalar constant storage and lowered types`（2026-11-10，随 12:45
   那次合并进入 #184 批次）保留标量常量的存储与降级类型；同家族还有
   `fce275b7 fold native class scalar constant expressions`（10-27，常量折叠根因）、
   `47c8215d`（#172，表达式常量回落 Zend 查表）、`1497c731`（#178，数组常量符号冲突）。
   ⇒ #184 批次 + 前置修复合起来终结了这条 AOT 差异。
2. **本仓观察其实更早**：`.workbuddy-ai/memory/2026-10-28.md` 已写
   「当前编译器已不再复现（保留为回归守卫）：外部类常量=0」——**观察到了，但铁律与注释没同步**。
3. **当前产物实证**：`apps/users/build/include/php_src_Geometry_BoxConstraints_arginfo.h`
   把 `INFINITY` 注册为 `std::numeric_limits<double>::infinity()`；
   `RenderScrollView.cc` 里 `BoxConstraints::INFINITY` 编成
   `php::constant(...)` 取内建 `INF`，不是 0。

⇒ 上一轮代理把它判成 P0，根因是**代码注释里写着"求值成 0"的判据性叙述过期**
（与报告 §5 结论一致）。本轮处理：

- **订正，不删守卫**：`BoxConstraints::capWidth()` 工厂、`TooltipTest` 的面板非零尺寸
  断言继续保留 —— 它们是「INF 不为 0」的现成回归守卫，删了反而少一张网；只把
  「AOT 那行是坏的」的理由改成「上游已修复，留作回归守卫 + 单一出口」。
- **同步清单**：`MEMORY.md` 第 21 条铁律、`PITFALLS.md` 两节、
  `BoxConstraints` / `OverlayLayout` / `Palette` / `StatusMessage` / `DialogPage` /
  `TooltipTest` / `SelectTest` 共 7 处源码与测试注释、
  `REVIEW-ARCH-DX-2026-11-11.md` §1/§5、`REVIEW-TPC-DEVIRT-2026-11-11.md` 补一节。
- **不改历史正文**：记忆日志（10-23 / 10-24 / 10-25）是当时的实测记录，按 DESIGN
  治理保留；`docs/arch-v2/` 历史稿同样不逐字改写。
- **不新增 T2 定值判据**：报告 §5 建议「补一条 T2 定值判据后再删过期注释」；
  复核后认为现有守卫已等价（Tooltip 面板尺寸断言 + golden 整页基线能咬住
  `w=h=0`），再新增一条依赖「生成产物不进 git」的判据只会多一个维护面。

---

## 3. 各修复项方案裁决

| 项 | 备选 | 采纳 | 理由 |
|---|---|---|---|
| §2.2 指纹 | `app:` 直接传 `''` | **否决** | `apps/<app>/build/` 是每次都变的生成物，全收 ⇒ 永远 FULL，比 SKIP 更难发现 |
| §2.2 指纹 | 只补 `project.yml` 一个文件 | **否决** | 图标 / 字体仍漏；且 `resource.icon` 在仓库根 |
| §2.2 指纹 | **显式输入清单 + 排除目录 + 受控后缀**（cargo input manifest 形状） | **采纳** | `hashTree` 加 `$excludeDirs`；`app:` 收 `.php/.yml/.json`；新增 `assets:` / `fonts:`；`build-stale-check` 对账输入清单本身 |
| §3.1 doctor | 把 doctor 从 gate 拿掉 | **否决** | 会丢掉 `opcache` 这类 gate 相关的真检查 |
| §3.1 doctor | `doctor --scope=gate` 按 `affects` 过滤失败依据 | **采纳** | 与 `flutter doctor` / `flutter test` 两条命令同形；`affects` 已有，只差被读 |
| §3.1 CI | 手工把 10 项补进 `--only` | **否决** | 白名单正是病根，下次新增项照样漏 |
| §3.1 CI | 改 `--fast` / 全量 `--json` | **采纳** | 判据集合随 `gate` 自动增长 |
| §3.3 超时 | 下沉 Future 层落地 | **推后** | 改语义需先出裁决稿（可并入 §82），本轮只把结论与触发条件写进计划 |
| §4.6 `make` | `Button::make()` 改 `new static` | **否决** | 会让所有子类静态工厂行为一起变，影响面大于一个控件 |
| §4.6 `make` | `IconButton` 覆写 `make()` 抛可操作错误 | **采纳** | G2「要么生效、要么响亮失败」，改动局部 |

---

## 4. 执行顺序

沿用 DEBT 三条判据：**①有消费者 ②错了是静默的 ③决定能不能交付**。

| 序 | 项 | 为什么排这里 | 验证档位 |
|---|---|---|---|
| 1 | **§2.2 常量求值事实同步**（用户点名） | 纯文档/注释，零风险，且它是「后续别改坏布局」的前提 | T0 `docref` + `php -l` |
| 2 | **P0-1 回调 arity**：lint 规则 → 全量违规点 → 变异 | 唯一「用户按一下就整页错误占位、日志无痕」的路径 | T0 lint 变异真红 + T1 `drift-codegen` 产物对照 |
| 3 | **P0-2 输入指纹**：清单 + 排除目录 + 对账判据 | 改配置/换图标后产物静默过期，决定「交付的 exe 是不是这份源码」 | T0 `build-stale-check` + `--dry` 决策对照 |
| 4 | **P1-1 doctor 分级 + CI** | 10 项防静默判据永不进 CI、新增项自动漏；纯 PHP | T0 + 变异（人为 FAIL 的 build 项不得阻断 gate） |
| 5 | **P1-2 `repaintForResize` 切管线** | 多窗口才触发，但漏的是既有铁律的入口，早修成本低 | T1 dev 真机（`probe-two-windows.py` 若可用） |
| 6 | **P2 搭车**：4.1 / 4.2 / 4.3 / 4.4 / 4.6 | 动 tools / 渲染错误处理时顺手，不单独开车 | 各随主项 |
| 7 | 全量验证 + 记忆日志 | — | `gate --fast` + 全量 28 项 + `docref` |

**本轮明确不做**：`Application` 上帝类再拆（11-08 已判「再拆只是搬位置」）；
单值事件对象根治（等解开 tpc pin 的破坏性窗口）；`Future.timeout` 下沉（先出 §82
裁决稿）；`fold()` 刷新开关 / `State::mounted` / disposers（改公开语义，单独裁决）；
零消费者 `@api` 的口径（产品决策）；`curl_multi` 探针（无消费者，§80 仍推后）；
T2 真机按键探针（见 §5 诚实边界）。

---

## 5. 验证清单与诚实边界

**必须验的**：

- 新增 lint 规则的 `--selftest` 正反夹具全绿；**磁盘级变异**：临时把某处
  `Slider` 的 `keyboard()` 闭包改回单参 ⇒ `lint.php` exit 1 且精确报该处；恢复即绿。
- `drift-codegen`（只走翻译层，~35s）后 `grep` 生成体 `Slider.cc` / `Select.cc` /
  `ComboBox.cc` / `CalendarView.cc` 的 `newClosureWithParameters`，确认形参从 1 变 2。
- `build-stale-check --selftest` 新增对账与变异全绿；`--dry` 对照：改
  `apps/<app>/project.yml` 一个字节 ⇒ 决策从 SKIP 变 FULL；连跑两次仍 SKIP。
- `doctor --scope=gate` 的变异：把某个 `affects=['build']` 项人为判 FAIL ⇒
  `gate --fast` 仍全绿（doctor 报 WARN 而非 FAIL）。
- `probe-audit --selftest` 报数随 glob 修正增加且全绿。
- 收口：`docref` 0 FAIL、`gate --fast`、全量 28 项。

**诚实边界（先写下来，避免收尾时给自己放水）**：

- **T2 真机按键探针未做**：arity 结论建立在生成产物 + 本仓历史事故记录 +
  项目自定纪律之上，置信度高；按本仓纪律仍须一次「聚焦控件 → 发方向键 →
  断言 stdout 零 Fatal 且值真的变了」才算实证。本轮用 `drift-codegen` 产物对照
  作为更便宜的 AOT 侧证据，不冒充 T2。
- 未跑 AOT 全量重建（`drift-build`）；tpc 仍 pin 在 `d4f8c3ca`。
- 多窗口 resize 只做代码层修复 + 既有探针，未新增两窗交替拖动专用探针。
- 所有读数均为 2026-11-11 复核结果，开工前仍要重新 grep。

---

## 6. 实施结果

### 6.1 改动清单（36 个已跟踪文件 + 5 个新增）

| 组 | 文件 | 内容 |
|---|---|---|
| 常量求值订正 | `MEMORY.md`、`PITFALLS.md`×2、`BoxConstraints.php`、`OverlayLayout.php`、`Palette.php`、`StatusMessage.php`、`DialogPage.php`、`TooltipTest.php`、`SelectTest.php`、`arch-v2/11`、`REVIEW-ARCH-DX`、`REVIEW-TPC-DEVIRT`、`PLAN-NEXT-STEPS` | 把「外部类常量在 AOT 下求值成 0」从**现行判据**降级为**已修复的历史事故 + 回归守卫**；`capWidth()` 工厂与「面板非零尺寸」断言保留 |
| P0-1 arity | 新增 `tools/Lint/Rules/CallbackArity.php` + 2 夹具；`Linter.php`、`selftest.php`；`Slider` / `Select` / `ComboBox` / `CalendarView`、`FocusManager`、`WidgetInteraction`、`DirectionalFocusTest`、4 个 smoke 夹具 | 新 lint 规则（内联闭包 + 返回 `\Closure` 的工厂两种形态）；13 处真实违规全部补齐两参签名 |
| P0-2 指纹 | `drift-build.php`（`hashTree` 加后缀数组 + 排除目录；`app:` 收 `.php/.yml/.json` 并排除 `build/bin/tests`；新增 `assets:` 行）、`build-stale-check.php`（+3 条对账，9→14 条自检） | 改 `project.yml` / 换图标不再 SKIP |
| P1-1 doctor/CI | `doctor.php`（`--scope`，`affects` 参与判定）、`gate.php`（doctor 带 `--scope=gate`）、`ci.yml`（`--fast` / 全量）、`CONTRIBUTING.md` | 缺 MSVC 的机器不再一条 T0 都跑不到；CI 判据集随 `$checks` 自动增长 |
| P1-2 resize | `Application.php::repaintForResize()` | 补 `Pipeline::enter()`（第 5 个入口） |
| P2 搭车 | `RenderErrorBoundary.php`、`probe-audit.php`、`press-sequence.php`、`IconButton.php`、`TooltipPanel.php`、`Doctor/Environment.php`、`gate.php` 头注释 | 见 6.3 |

### 6.2 验证读数（2026-11-11 本机实测）

| 判据 | 结果 |
|---|---|
| `lint` | 1072 文件 · 0 error · 0 warn（规则 31→32 条） |
| `lint --selftest` | `callback-arity` 该响 4 / 不该响 0 |
| **arity 磁盘变异** | Slider 改回单参 ⇒ `lint` exit 1 且精确报 `src/Widget/Slider.php:243`；恢复后复绿 |
| **arity AOT 证据** | `drift-codegen`（pin 编译器）后 `Slider.cc` / `Select.cc` / `ComboBox.cc` / `CalendarView.cc` 的 `keyboard` 闭包从 `{event}` 变 `{event, self}`；重新 `drift-build users` 后产物里仍是两参 |
| `build-stale-check --selftest` | 11→14 条全过 |
| **指纹敏感性** | 同输入 `--dry`⇒**skip**（`0f1f801f9b1a`==`0f1f801f9b1a`）；改 `apps/hello/project.yml` 一个字节 ⇒ 指纹变 `f879c3bb3703`（FULL）；还原 ⇒ 回到 `8054c57ff156`（确定性 + 敏感性双向成立） |
| **doctor 分级变异** | `DRIFT_PHPX_HOME=D:/nope`：`--scope=gate` ⇒ 打 `✗ PHPX_HOME（不影响 gate）` 但 **exit 0**；不带 scope ⇒ exit 1 |
| `probe-audit` | 13 个探针 · 0 error；selftest 6 条双向都对 |
| `unit` | 619 tests / 1918 assertions OK |
| `feature` | 900 tests / 3504 assertions OK |
| `gate --fast` | **25/25 全过（44.9s）** |
| `gate`（全量） | **28/28 全过（157.5s）** —— 含 feature 900 / pages / smoke |
| T2 真机 | `drift-build users` 13.3 MB / 200.7s；`probe-window users` **5/5**（标题、尺寸、非白 8.6%、DPI V2、退出码 0） |
| `docref` | 116 ok / 0 FAIL |

### 6.3 与计划的偏差（三处，都是复核后改的）

1. **字体不进指纹**（计划 §3 写了 `fonts:`）。复核发现 `FontLocator::find()` /
   `drift_skia.cc::ensureFont()` 都是**运行期**从 exe 旁的 `fonts/` 或 `cpp/fonts/`
   加载，换 ttf 不需要重编 ⇒ 收进指纹只会制造每次 200s 的假 FULL。改为只收
   `assets:`（图标是构建期由 tpc 嵌入的，且在仓库根、不在工程目录内）。
2. **`app:` 不传 `''`、也不解析 `ignore:`**。`''` 会收进 `build/`（每次都变 ⇒ 永远
   FULL，比 SKIP 更难发现）；解析 `project.yml` 的 `ignore:` 需要一个迷你 YAML
   读取器，而 8 个应用里只有 2 个声明了它 ⇒ 改成显式排除 `build`/`bin`/`tests`，
   方向取"宁可多 FULL，不可漏 SKIP"。代价写在这里：改 `apps/<x>/tests/` 会触发一次
   全量（安全方向的误报）。
3. **`probe-audit` 不是简单把 glob 放宽**。三个共享件（`probe_win` / `probe_check` /
   `probe_pixels`）如果直接进扫描集，会被自己的 `duplicate-*` 规则全数命中 ——
   它们**就是**那"唯一实现"。改成：共享件列入白名单并**核对存在性**，其余
   `probe_*.py` 照常审计。真正补上的是「以后新建的下划线命名探针会被审到」。

### 6.4 遗留与诚实边界

- **T2 按键探针仍未做**：arity 的实证链是「生成产物闭包参数 + AOT 产物闭包参数 +
  历史事故记录」，`probe-window` 只证明 exe 起窗/退出正常，**不证明按下方向键不
  Fatal**。要彻底关上这条，需要一次「聚焦 `Select` → 发 `Down` → 断言 stdout 零
  Fatal 且值变了」的真机探针（`probe-press.py` 是现成骨架）。
- tpc 仍 pin 在 `D:/TypePHP/tpc-pin`（上游 Windows PHPX 未补 `typephp_call_closure`）；
  本轮所有构建都用 `DRIFT_TPC_ENTRY` 指它。
- `TimedJob` 跨后端语义（报告 §3.3）按裁决**未落地**，只把结论留在这里等 §82 裁决稿。
- 未修：`fold()` 刷新开关、`State::mounted`、零消费者 `@api` 口径（都改公开语义）。
- `docref --selftest` 的 `section-refs` 反例恒红（既有遗留，不进 gate）。

