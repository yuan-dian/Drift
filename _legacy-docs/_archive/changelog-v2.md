> 本文件从 `DESIGN.md` 拆分而来（2026-10-21 文档瘦身），主文档见 `DESIGN.md`。

### 24.1 v2 的 8 处文档内不一致修正（语义不变）

| # | 位置 | 原文 | v2 |
|---|------|------|-----|
| 1 | §16.6.3 | 正文"九条"/"八条"、表 10 行、§19"8 条"、§23.10"9 条" | **统一 17 条** |
| 2 | §3.1 | 分层图无 `Drift\Commands` | 补入 |
| 3 | §16.4 | `ignore: src/platform/macos` | `../../src/platform/macos` |
| 4 | §11.1 | "命令 / 局部闭包"无界线 | 补**业务动作 vs 纯 UI 回调**界线表 |
| 5 | §21 A2 | 正确做法写 `null` | 指向**哨兵三档定则** |
| 6 | §19 M1 | 链式探针门仍列待验 | 标 **✅ 已过** |
| 7 | §12.1 | 路由 `match` 有 `default` 却无解释 | 补**开放域 / 封闭域**判据 |
| 8 | §12.3 / §15.4 / §12.2 | Element 身份未定 / 枚举大小写不一 / Root 判据会误伤 | 分别补说明 / 统一 PascalCase / 精确化判据 |

（另有 §13.1 C5、§7.2 W8、§14.2 三处"补边界"计入 §23.1 的第 9 / 12 / 13 项。）

★ **第三轮复核追加（2026-10-14）**：§4.2 目录清单里的 `SelectedBuilder` 是**孤儿名字** —— 全项目只出现 1 次、任何地方都没有定义、v1.0 归档里连名字都没有 ⇒ **已删**（见 §23.2 O-05 复核发现 ⑤）。同一类问题：§11.3.3 原写的"更好的做法是用**类型系统**强制"被实测证伪 ⇒ 已改为诚实表述（见 §23.2 O-05 复核发现 ②/③）。

★ **第四轮追加（2026-10-14 晚，随 O-16 / O-17 裁决）**：
- §4.2 目录清单**缺 `Color`** —— 它被 §11.5（`color(ColorToken $t): Color`）与 §15.6（`Color::fromRgb`）引用，但**目录里没有定义** ⇒ **已补进 `Drift\Rendering`**（文档遗漏，语义不变）。
- §16.4 的 **`ControlStyle` 是孤儿名字**（全文无定义、§4.2 也没有）⇒ **已删**，该格改指向 `BoxStyle`（见 §23.2 O-17 复核发现 ⑤）。
- §19 里"**错误边界**"**同时在 M3 和 M5**（名字还不一致）⇒ 统一为"**错误边界容器**"、**归 M3**，M5 那处已删（见 §23.2 O-12 复核发现 ①）。

★ **第五轮追加（2026-10-15，M0 落地时）** —— 两处**会挡住正确实现**的文档缺口（都是"已有裁决没写全"，语义不变）：

| # | 位置 | 症状 | 修正 |
|---|------|------|------|
| 9 | **§4.1** | `composer.json` 把 `apps/<app>/bin/` **笼统挂一个前缀**，但 §4.5 的组合根在 `bin/<platform>/Bootstrap.php` 里声明 `namespace App\Hello;`（§A.1）⇒ 按 PSR-4 该声明应落在"前缀根"上，笼统挂 `bin/` 会要求命名空间是 `App\Hello\win32` ⇒ **§A.1 的例子在 PSR-4 下不成立**（`namespace-mismatch` 会报它） | 改为**按平台逐条列出** `bin/win32/` 与 `bin/macos/`；两个平台同时列出无害（非当前平台目录查不到） |
| 10 | **§4.3** | `Theme` 行的允许集**漏了 `Rendering`**，而 §4.2 把 `Color` 放在 `Drift\Rendering`、§15.7 又要求 `ThemeData` 是主题 token 的**取值来源** ⇒ `Theme → Rendering` 是**必需边**，缺了它 `dependency-direction` lint 会误报 | Theme 行补 `Rendering` |
| 11 | **§4.3** | `Text` 行的允许集只有 `Geometry`、**漏了 `Errors`**，但 §8.3 血账 1 已经裁决"`FontManager` 初始化失败走 `Errors::report` 而不是崩"，§8.2 坑 6 也要 Text 层响亮失败 ⇒ 缺这条会让"Text 层不许报错"与"G2 绝不静默"直接冲突 | Text 行补 `Errors` |
| 12 | **§8.1 / §7.1** | 缓存键钉死为 `(text, style, maxWidth, scale)`，但排版结果**还受**对齐 / 行数上限 / 省略号影响 ⇒ 若把它们做成 `layout()` 的参数就会**同键不同结果**（缓存撒谎） | 裁决：三者**进 `TextStyle`**（§15.7 说 widget 层的角色方法落成 `TextStyle` 的值）⇒ 方案给定的键**保持字面成立** |
| 13 | **§A.3.3** | `overrideTokens([ColorToken::Border => …])` **不是合法 PHP** —— 枚举实例是对象，**不能作数组键**（实测 `Fatal error: Cannot access offset of type ColorToken on array`，纯枚举与 backed 枚举都一样）。⇒ 这行示例照抄必然编译不过 | 改为值对象的 `withX()` 形态（与 §15.7 `BoxStyle::withX()` 同源）：**点覆盖** `withColor(ColorToken, Color)` / `withMetric(MetricToken, float)`；**面覆盖** `withHighContrast()`。**公共面无字符串键**（§16.4 否决字符串注册表；内部用 `->name` 作数组键只是因为 PHP 数组键不能是对象） |
| 14 | **§4.2** | 目录清单的 `Drift\Theme` 行**缺 `SystemTheme`**，但它被 §9.2 / §A.2.7 / §A.3.3 三处引用（`SystemTheme::Light` / `::Dark`）⇒ 与第 9 处（缺 `Color`）同类 | 补 `SystemTheme` |
| 15 | **§4.3** | `Widget` 行的不许依赖列写着 `Theme`，但 §3.4（2026-10-14 才定义）要求 `Drift\Widget\BuildContext` 有 `theme(): ThemeData` / `color(ColorToken)` / `metric(MetricToken)` ⇒ **两条裁决直接冲突**（`dependency-direction` 会报它，而删掉 `theme()` 又让 §3.4 的能力面少一半） | 照 `Input` 行的既有先例加**协议例外**：三个类型**仅限 `BuildContext` 的类型签名**可引用。★ 边界要说清：`ThemeData` 是"解析后的值"，`BuildContext` 只是**把它递给 `build()`**，不构成 Widget → Theme 的架构依赖，`ThemeScope`（真·主题节点）仍归 `Drift\Theme` 侧 |
| 16 | **§4.3 / §4.2** | `TextRole`（§15.2 定义、§11.5 要用）**不在 §4.2 目录清单里**，且"角色 → `TextStyle`"的解析该放哪一层方案没说 | 补进 `Drift\Text`（不是 `Drift\Widget`）：解析落 `ThemeData::textSize/textColor`，而 §4.3 只允许 `Theme → Text`、**不允许 `Theme → Widget`**。放 Widget 会让 Theme 依赖 Widget，直接撞 lint。另：**§15.7 纪律 3 因此有了第二个落点实例**（合并只发生一次） |
| 18 | **§4.2 / §4.3** | **§4.3 的 `Widget → Theme` 禁令与"控件要在造盒时把主题解析成值"直接冲突**。`Button::createRenderObject()` 必须引用 `ColorToken` / `MetricToken` 才能把变体解析成颜色 —— 而 lint 只放行了 `BuildContext.php` 一个文件的协议例外，扩例外会让它扩散（今天允许一个文件，明天整层都能依赖 Theme） | **`ColorToken` / `MetricToken` 从 `src/Theme/` 移到 `src/Rendering/`**。依赖方向天然成立：`Widget → Rendering` ✓、`Theme → Rendering` ✓、`Rendering` 自己不依赖 Widget/Theme ✓。**语义没说谎**：token 是"渲染资源的语义键"，与 `Color` 同层比与 `ThemeData` 同层更实。★ 解析仍只发生一次（§15.7 纪律 3），落点从 `RenderButton`（Layout，不许碰 Theme）移到 `Button::createRenderObject()`（Widget，经 `BuildContext`） |
| 17 | **§15.2 / §16.6.1 / §19 / §A.1 / §A.2** | **`ForEach` 不是合法 PHP 类名** —— `foreach` 是保留字，且 PHP 类名**大小写不敏感** ⇒ `class ForEach` 直接 `PHP Parse error: unexpected token "foreach"`（实测）。方案全文 6 处 `ForEach::make(...)` 照抄必然编译不过 | 类名改 **`Each`**（`Each::make(keyFn)->items(...)`）。★ 这是第 9 处"文档挡住实现"的同类，但**改了公共面** ⇒ 名字选择请你确认；若你更想叫 `Items` / `ListOf` / `EachItem`，改回来只是一次重命名（语义与 key 必填裁决都不变） |
| 22 | **§4.2** | `Drift\Routing` 行列了 **`Page`**，但全文对 `Page` 的唯一定义在 **§A.2.1 的 `App\Users\Store\Page`**（`fetchPage()` 返回的 DTO，三个字段 `ok/items/error`）—— 它是**应用私有值对象**，不是框架类型。这与第 10 处"目录表与定义对不上"同类，只是这次错在**层级**：照 §4.2 建 `src/Routing/Page.php` 会得到一个框架里没人用的空壳 | 从 `Drift\Routing` 行删掉 `Page`。★ **2026-10-15 更正（§24.1 #37）**：本行写的"真实成员是 `RouteTable` · `PageHost` · `Offstage` + `NoPage`"**其中 `Offstage` 是错的** —— 它住 `Drift\Widget`（结构节点），该行真实成员是 `RouteTable` · `PageHost` · `NoPage` 三个。★ 应用私有 DTO 归 `apps/<app>/store/`，不进框架 —— §12.2 的"每页可独立预览"正要求页面只依赖自己的 store 类型 |
| 23 | **§A.1** | 样例的三处照抄会编译不过：① `protected function title()/buildRoot()` —— 基类 `Application` 把它们定义成 public（`commands()` 要能被 devtools 枚举），子类**收窄**可见性 ⇒ `PHP Fatal: Access level must be public`；② `use` 列表里漏了 `Center`（正文用了 `Center::make()` 却没 import），多了一个没用的 `BuildContext`；③ `Bootstrap` 调 `$app->run()`，而 `Application` **没有** `run()` —— §5.2 裁决 1 明确"循环归平台，`Frame` 不拥有它" | ① 改 `public`；② 补 `Center`、删 `BuildContext`；③ 改成平台驱动形态（`attach()` + `pump()` 循环里调 `frame()`）。★ `main.php` 与 `bin/<platform>/Bootstrap.php` **本轮不建**：它们要 `Drift\Platform\Win32\Win32Platform`（M1 的 Skia/Win32 桥），建出来只会是引用不存在类型的空壳（A15）。`apps/hello/src/HelloApplication.php` 已建且可无头预览（`--app`） |
| 21 | **§4.3** | `Routing` 行的允许集写着"Widget / Commands"，但 §12.1 第 7 条要求 `RouteTable` 的工厂**返回 `Widget`** ⇒ 签名必须引用 `Drift\Widget\Widget`。这正是 §4.2 目录表"Routing → Widget"那一列本来的意思，**依赖表漏抄了它**（与第 10 处"Theme 漏 Rendering"同类：允许集和目录表对不上） | `Routing` 行补 `Widget`。★ 顺带确认方向只有一条：`RouteTable` **不认 `Element`**、不挂树，它只造 Widget —— 挂载与保活住 `Drift\App` 与 `Drift\Widget\Offstage`，那样 Routing 才保持可单测（必须在浏览器外能跑） |
| 19 | **§11.3.1** | 样例写 `private Cell $hovered = new Cell(-1);` + `$this->hovered->setIfChanged($index)`，并注明"内部：=== 不等 ⇒ setState 标脏"。**PHP 的属性初始化器拿不到 `$this`**（`new Cell(-1)` 里没有 State 可引用）⇒ 照抄能得到一个"设了值但没人重建"的 `Cell`，正是 G2 要消灭的静默形态 | 绑定必须显式发生：`State::cell($initial)` 是**唯一创建入口**，`initState()` 里 `$this->hovered = $this->cell(-1);`。`Cell` 未绑定就 `setIfChanged()` ⇒ **Fatal**（不留下"设了没用"的值）。★ 顺带：`State` 的样例因此从"属性初始化器"变成"`initState()` 里赋值"，与 §11.3 RT-1 的"只 initState 订"是同一个时机 |
| 20 | **§4.3** | `Widget` 行的不许依赖列写着 `Commands`，但 §11.4.3 的派发链第 ①② 环要求 `Drift\Widget\State::handleCommand(string $name, CommandArg $arg): bool` —— 签名要碰 `Drift\Commands\CommandArg` ⇒ **与 §11.4.3 直接冲突** | 照 `BuildContext`/`Theme` 的既有先例加**协议例外**，且**只登记 `src/Widget/State.php` 一个文件**（层级放行会让"任何 Widget 都去依赖 Commands"合法化）。★ 边界：Widget 侧只碰这一个**参数类型**；链的其余部分住 `Drift\App`（那里 `Widget → Commands` 天然合法）。夹具 `src/Widget/UsesCommands.php` 守"例外不扩散" |

★ **第六轮追加（2026-10-15，补 lint 规则告一段落后转看核心能力时）** —— 全部是“已有裁决/已有实现没写全”，语义不变：

| # | 位置 | 症状 | 修正 |
|---|------|------|------|
| 24 | **§4.2** | 目录表**没有“现状”一列**，读起来像 73 个类型都已存在。按 `src/` 核验：**46 个已实现、27 个连文件都没有**（`Platform` 9 + `Win32` 1 + `Input` 9 + `App` 异步半 6 + 孤儿 `Theme` 1；★ 2026-10-16 更新：`SkiaCanvas` 与 `Win32RasterSurface` 已落地 ⇒ 由 45/28 变为 46/27） | 加**状态列**（✅/⏳ + 缺哪个）—— 路线图写“要做”不算数，`src/` 里有没有才算 |
| 25 | **§4.2** `Drift\Routing` 行 | 第 22 项写“`Page` 已删”，但**表里 `Page` 还在**、真正的成员 `NoPage` 反而没列 ⇒ 上一轮的“已落地”是自述不是事实 | 删 `Page`、补 `NoPage`（与 #22 结论一致） |
| 26 | **§4.2** `Drift\Theme` 行 | `Theme` 是**孤儿名**：全文无定义、无引用（`Theme::Light` 只在引用旧正文时出现一次），`src/` 里也没有 | 删（同 #22 `Page` / O-17 `ControlStyle` 的处置） |
| 27 | **§12.1** 第 2 条 | 判据说“封闭域（enum）禁止 `default`”，但**全文没有枚举清单** ⇒ 判据不可逐条核验；且 `UnhandledMatchError` 被 5 处当作“框架的守卫”引用，从没人说它是 **PHP 内置类**（8.5.11 实测：`match` 无臂无 `default` ⇒ 抛 `\UnhandledMatchError`） | 补**框架封闭域 enum 清单**（11 个：住哪层 / 取值 / 谁消费）+ 一句“纪律由语言保证，Drift 只需别写 `default`” |
| 28 | **§11.3.3** | `SubjectBuilder` 这个 `@api` 契约原语**只有用法、没有成员表**（§23.2 O-05 复核发现 ⑥ 的挂账项，原定“随 M3 订阅侧一并补”）⇒ 照 §A.2.11 手写时不知道该实现哪些方法 | 补**类形状表**（`make`/`select`/`builder` + 三个 `@internal` 读取器 + `createState`）与三条调用约定 |
| 29 | **§8.1 / §8.2** | `TextAlign` / `FontWeight` / `FontSlant` / `PaintStyle` 四个 `@api` 封闭域 enum 在全文**出现 0 次**，而 §8.2 坑 6 说“凡是影响排版结果的输入都在 `TextStyle` 里（对齐…亦然）” | 随 #27 的清单一并命名（公共面不再靠读者翻 `src/` 发现） |

★ **第七轮追加（2026-10-15，O-18 / O-19 按推荐 A 裁决并落地）** —— 两条都属“文档挡住实现”同型：

| # | 位置 | 症状 | 修正 |
|---|------|------|------|
| 30 | **§A.2 / §5.1 / §9.1 / §11.4.3 / §A.3.1 / §A.3.2 / §A.3.8 / §A.3.9** | 旗舰样板**不可照拉**：`protected` 覆写 + `boot()` + `commands(): CommandRouter` + `accelerators()` + `taskHost()` 在已实现的 `Application` 上一个都不存在（#23 只修了 §A.1，§A.2 三枪全留着） | 9 处全部改成已实现 API：`public` 覆写、`commands(): iterable` 字典、依赖装配在构造点（O-15 E）、Bootstrap 改平台驱动（`attach()`+`pump()`）；三处不存在的钩子按 A15 **不预挂**，形状以注释留存 |
| 31 | **§19** | 路线图六个里程碑**没有“当前状态”一列**，M1 出口判据读起来像已达成（实际平台层 11 个类型 0 文件） | 加状态列，与 §4.2 同口径、**同步维护**；归属 `drift-doctor`（O-10）自动输出 |

★ **第八轮追加（2026-10-15，A 方向：把剩余 §A 样板对 `src/` 实况核验）** —— 两条同型：**目录表（§4.2）漏登记正在被依赖的类型**：

| # | 位置 | 症状 | 修正 |
|---|------|------|------|
| 32 | **§4.2** `Drift\Widget` 行 | **`TextField` 全文 29 处引用、`src/` 里 0 文件**，而 §4.2 只写“另有 11 个控件”（那 11 个是 `Button`/`Center`/`Column`/`Each`/`Expanded`/`Flex`/`Offstage`/`Padding`/`Row`/`Spacer`/`Text`，**不含**它）⇒ O-16 全部论据、§11.4 官方订阅形态、§A.2.9 旗舰表单都建在一个**既无代码、又无里程碑归属**的控件上。`Spinner` 同类（3 处引用） | Widget 行补 ⏳ `TextField` · `Spinner`；§19 M2 状态列同步标“0 文件”；§A.2.9 加实况边界横幅。★ **这是能力缺口，不是文档缺口** —— 输入控件本该是 M2“首批控件”收的，repo 至今没写。★ **2026-10-15 已落地** —— `TextField` + 输入层最小集（`RenderTextField` · `TextFieldBox` · `TextFieldState`）已写，`tools/Preview/FormPage.php` 示例页 + 24 条 smoke 断言在；`Spinner` 仍 0 文件（★ **2026-10-16 已补** —— `Spinner` + `SpinnerState` + `SpinnerBox` + `RenderSpinner` 四件 + smoke 14 条断言，§4.2 / §19 M2 / 本节横幅三处已同步） |
| 33 | **§4.2** | `Drift\Widget\Drag\{Draggable,DragTarget}` 4+2 处引用、§A.3.5 整节建在它上面、§12.4 与 §19 M3 都点了名，但 **§4.2 连目录行都没有**（§19 只写“应用内拖放”四个字，无文件可数） | 新增 `src/Widget/Drag/` 行（⏳ 缺 2，M3），与 §19 M3“应用内拖放 0 文件”对上账 |

★ **第九轮追加（2026-10-15，A 方向 15 处 use 失配审计收尾）** —— 三条同型：**名字对、位置/归属错**：

| # | 位置 | 症状 | 修正 |
|---|------|------|------|
| 34 | **§A.2.6** · **§12.2** · **§13.1** | `Overflow` 三处引用错：① §A.2.6 从 `Drift\Widget\{… Overflow …}` 导入，而它声明在 `src/Layout/Overflow.php` ⇒ 照抄即 `Class "Drift\Widget\Overflow" not found`；② §12.2 样板写 `Overflow::clip`（小写）—— `src/` 里 case 是 PascalCase 的 `Clip/Scroll/Wrap/Report` ⇒ `Undefined constant`；③ §13.1 边界注里的默认值同理 | ① 拆成 `use Drift\Widget\{Dock, AppBar, NavButton}` + `use Drift\Layout\Overflow`（方向合法：§4.3 允许 Widget → Layout）；②③ 改 `Overflow::Clip`。★ ②③ 让 §12.1 自称的“枚举 case 命名已统一为 PascalCase”**从假变真** —— 此前 §12.2 自己就留着反例 |
| 35 | **§4.2** | `Dock` / `AppBar` / `NavButton`（多页外壳三件）+ `Drift\Widget\Menu\{MenuBar, Menu, MenuItem, ContextMenu}` + `Drift\App\Job` 共 8 个类型全文被依赖（§12.2 外壳样板、§A.2.6 `UsersRoot`、§17.2 `TaskHost::submit(Job)`），**§4.2 一个都没登记**，`src/` 全 0 文件 | Widget 行补 ⏳ 三件外壳 + 新增 `src/Widget/Menu/` 行（缺 4）+ App 行补 ⏳ `Job`；§19 M3/M4 状态列同步（TaskHost 系 6 → 7 个） |
| 36 | **§13.1 C5 / §16 W4** | 两张契约表都把“外壳类控件（**AppBar/StatusBar**）”并列，但 `StatusBar` 按 §4.2 目录注释与 §A.2.11 是**应用私有**控件（`apps/users/src/widgets/StatusBar.php`），不是框架类 ⇒ “必须有溢出策略”这条契约看起来在约束一个框架外的例子 | 澄清为**类别判据**而非类清单：会超约束的**外壳类**容器必须显式声明；`AppBar` 是框架控件、`StatusBar` 只是示范这个类别的应用私有样本。**契约对象是类别，不是那张名单** |

★ **第十轮追加（2026-10-15）** —— **#25/#26 的同类错误第二次出现**，方法论见下：

| # | 位置 | 症状 | 修正 |
|---|------|------|------|
| 37 | **§4.2** `Drift\Routing` 行 · **§3.1 分层图** | `Offstage` 被挂在 `Drift\Routing` 并标 ✅ 4/4，而它声明在 `src/Widget/Offstage.php`（结构节点，`Drift\Widget`）—— 全表**唯一一个"标 ✅ 却没有文件"的矛盾**。同图 `Drift\Routing` 行还留着已删的孤儿 `Page`、`Drift\Theme` 行还留着已删的孤儿 `Theme` | Routing 行删 `Offstage` → ✅ 3/3 + 注明归属；分层图两行分别改成 `RouteTable · PageHost · NoPage` 与 `ThemeData · ThemeScope · SystemTheme · token`；#22 的"真实成员"加更正。★ **方法论（第二次同类 ⇒ 顺找模型问题）**：#25/#26 删孤儿名时**只改了 §4.2 表格**，没有全文扫一遍同名孤儿 —— "已删"因此再次是自述。⇒ 以后删一个名字，**必须全文 grep 该名字的所有出现形态**（表格 / 分层图 / 术语表 / 正文叙述），而不是只改被点名那一处 |

★ **第十一轮追加（2026-10-15，输入层最小集落地时撞出的两个"静默"盒挂接缺陷）** —— 两条**同根**（"新盒造出来后必须重新挂接进父容器"），且都是**能力缺陷**而非文档缺陷（`php -l` / lint / 全部断言全绿时缺陷照样活得很好）：

| # | 位置 | 症状 | 修正 |
|---|------|------|------|
| 38 | **§13.2 + C1** · `Element::settle()` | **帧末重建后父容器仍握旧盒**：脏的 `StatefulElement` 重建 ⇒ `syncBox()` 造出新盒，但 `Element::settle()` 只重建脏节点、**不重新挂接** ⇒ 祖先容器（`RenderFlex` / `RenderProxyBox`）仍指向**旧盒对象**，`layout()/paint()` 走的是重建前那棵树 ⇒ **画面纹丝不动、也不报错**。首个暴露者：`TextField`（第一个"重建后盒内容会变"的 `StatefulWidget`）—— `drift-preview --focus` 曾是**空操作**（插入符画不出来，输出与不聚焦时**逐字节相同**，md5 一致） | `Element::settle()` 末尾调 `adoptBoxes()`（**后序**：先 `visitChildren` 再自己挂）。容器覆写幂等（先 `clearChildren()` 再重挂）⇒ 每帧 O(子数) 的指针重连，相对 `layout()` 的 O(整树) 可忽略（§13.3：Render 层本就无脏边界）。smoke 断言「帧末重建后父容器换上了新盒」——去掉该行必变红 |
| 39 | **§13.2** · `Element::applyContext()` | **换肤时容器的新盒是空的**：`applyContext()` 先 `rebuild()`（其 `adoptBoxes()` 把子盒挂进**旧盒**）、后 `syncBox()`（用**空**新盒换掉旧盒）⇒ 容器整棵子树在"换表"到"下一帧 `settle()`"之间**静默消失**（`FlexElement` 实测 `kids=2 → 0 → 2`）。**叶子控件不受影响**（自己就是盒）⇒ 此前 4 条主题用例全绿，因为**它们只测了叶子** | 把 `syncBox()` 提到 `rebuild()` **之前**（§13.2「盒按 ctx 算」⇒ 先换盒、再挂子）。smoke 补「容器的盒立即重算**且子盒已挂上**」（与叶子那条**不重复**：一个测叶子、一个测容器） |

★ **方法论（第二次同类 ⇒ 顺找模型问题）**：第 38 条修完后按「同类错误重复出现 ⇒ 顺着找第二次」把**所有会改盒的路径**扫了一遍（`mount` / `update` / `applyContext` / `settle`），在 `applyContext` 找到第 39 条。**判据记下来**：任何"造新盒"的动作都必须配一次"父层重新挂接"；`adoptBoxes()` 是那条**唯一**出口，缺它即静默。
★ **#38 的连带**（本轮同批落地）：§9.1 补"落地现状"（`FocusManager` 兑现的 ③ 焦点链 / 命中测试 / 命中→焦点**父链上溯**）；§4.2 `src/Input/` 行从「缺 9」改「缺 5」、App 行登记 `FocusManager.php`（`@internal`）；§19 M2 状态列把 `FocusNode` 改标已落地；`Widget::focusOrder()` 的 docblock **撤回**"⇒ lint 报错"——**该守卫并未落地**（`--list` 15 条里没有它），属本轮自己写下的过度宣称。

★ 附带确认：`main.php` / `tools/*.php` 这类**入口脚本**（无类型声明）不受 `namespace-mismatch` 管 —— PSR-4 管的是类文件。

**#40（2026-10-15，端口层落地时暴露）**

| # | 位置 | 问题 | 处理 |
|---|------|------|------|
| 40 | **§4.3** `Platform` 行 · **§5.2** 消息泵归属 · **§A.1** 样板 | **三处互相矛盾，且都把 `Platform` 的"出口"写成了"入口"**：<br>① §4.3 的 `Platform` 行只允许 `Geometry` / `Errors`，而 §5.1 + §7.1 的签名要求 `SurfaceProvider::surface(): RenderSurface`、`Screen::systemTheme(): SystemTheme`，§9.1 的路由链图又把平台输出逐字写成 `PointerEvent / KeyEvent` ⇒ **三条指向"上层"的边被表漏掉**；<br>② §5.2 的图写 `WindowHost::pump`，§A.1 的可执行样板写 `$platform->pump()` ⇒ **同一个泵两个归属**；<br>③ §A.1 把 `$platform->surface($hwnd)`（`?SurfaceProvider`）直接当 `attach()` 的第一参（要 `RenderSurface`）⇒ **照抄编译不过**（与 §24.1 #23 同型：§A 章是"可照抄的代码"，却含 3 处不可照抄的写法）。 | ① §4.3 补三条边并**说清为什么是层放行而非按文件例外**（三条都是**输出类型**，没有更低的层能拥有；按文件放行会扩散到 `Platform\<Os>\` 每个文件）；`DependencyDirection` 的 `ALLOWED['Platform']` 同步；<br>② §5.2 改成 `Platform::pump`（**语义未变**：泵仍归平台层、仍阻塞、框架仍不自建循环）；<br>③ **✅ 2026-10-15 已回填**（P0-3 真机跑通后）—— 含同一处错的是**四处**：§A.1 / §5.1 / §A.2.4 / **§A.3.8**（★ 本条原写"§A.3.9"，**记错了** —— §A.3.9 只有"换后端一行"，含 `attach()` 的是 §A.3.8 的窗口状态样板；**同型错误第五次**，见下）。四处都换成**真机验证过的形态**：`createWindow()` → **`show($handle)`** → `surface($handle)` 收窄 `null` → `$provider->surface()` → **首帧前 `resize()`** → `attach($surface, $viewport)`。<br>★ 回填顺带补上三处原先缺的东西：① `show()` 调用；② `fatalBox` 的安装（§1.2 中止序列第 ② 步 —— 引导阶段崩溃时没有它就只剩闪退）；③ "`attach()` 内部就会跑一帧 ⇒ 尺寸为 0 那一帧被丢弃"（症状：**画面到第二次 resize 才出现，不报错**）。 |

★ **方法论（第三次同型 ⇒ 模型确实不成立）**：#23（§A.1 三处照抄不过）、#37（"标 ✅ 却无文件"）、#40（§A.1 又两处 + §4.3 漏边）**都是"文档自述与可执行事实不一致"**。⇒ 判据升级：**§A 章的每一段代码都要真的能编译/运行过**才算"可照抄"；`drift-conformance`（M4 建）落地前，§A 的代码只能靠人肉核 —— 这本身就是它该进 P0 的理由（见 §19 M2 判据依赖 M4 工具的顺序矛盾）。

**#41（2026-10-15，P0-3 真机构建时撞出）**

| # | 位置 | 问题 | 处理 |
|---|------|------|------|
| 41 | **上游 tpc codegen**（首个暴露者 `src/Text/DeterministicFontData.php`） | **类常量与同类方法同名 ⇒ 生成的 C++ 报 `error C2872: 不明确的符号`，整个应用编译不过**。根因：tpc 把类常量提升成**文件级静态变量** `__<类名>__<常量名小写>`，把方法翻译成**同名函数** ⇒ `private const LATIN` 与 `public static function latin()` 生成的两个符号都叫 `php_drift__text__deterministicfontdata__latin`；而生成的声明头末尾有 `using namespace typephp_project_hello;` ⇒ 调用点歧义。**判据（全仓可复用，已扫过 —— 只此一处）**：类常量名**小写后**与**同类任一方法名**相同即踩雷。 | ① 常量改名 `LATIN_GLYPHS` / `CJK_GLYPHS`（**语义零变化**，纯命名规避；同文件风格下 `= ButtonVariant::Default`（enum case 整体）**能过** ⇒ 禁的只是"取 `->value`"这一形态，见 §24.1 前文）；② ★ **`--dry` 门抓不到**（只生成不编译）⇒ `drift-codegen` 的覆盖面在**翻译层**止步，这类缺陷要**真编译**才现形。★ 与 §16.3 那条实测举证（codegen 抓到 lint / preview 都放过的 `Enum::Case->value`）合起来看：**三道门抓的是三批不同的东西**，不是冗余；但也说明 `drift-codegen` **不是** AOT 缺陷的完备门 —— 补法是让 `drift-build` 进 T2 档（已就位），或给 codegen 门加一步单文件 `cl /c`。<br>★ ③ 同轮还发现一处**覆盖缺口**：`drift-codegen` 的 `sources` 只有 `[../src, ../apps/<app>/src]`（单一出处 = `tools/drift-codegen.php`）⇒ **`apps/<app>/main.php` 与 `bin/<os>/Bootstrap.php` 不在门内**—— 而这两个恰恰是「入口 + 组合根」、平台相关代码最集中的文件（本轮 M1 的真机构建才第一次覆盖它们）。收口方向同上：把 `main.php` 与 `bin/<os>` 纳入 codegen 的 `sources`。 |

| 42 | **§17.2 后端 A 的"【实测】可用"是过度声称**（暴露者 `probes/taskhost_tsrm/`） | §17.2 曾在 4 处（§2 表第 15 行 / §2 能力表 / §11.x 第 2 条 / §17.2 架构图）声称"线程池后端 **【实测】可用**"，依据是探针 E。★ **但探针 E 只测了纯标量 + 字符串分配**（`thr_php_add` / `thr_php_sum` / `thr_php_concat`），**从没碰过类 / 对象 / 属性**。探针 K（6 mode × 7 组合，`threads=1` 关键对照）证明：**碰类/对象/属性的 PHP 在单个 worker 线程上就 SIGSEGV**，根因是生成的扩展里 `static THREAD_LOCAL php_request_cache` **只在 RINIT 里被 `new`**，而 worker 没跑过 RINIT。⇒ "可用"成立的范围**比文档声称的小得多，且小到没有工程意义**（`Job` 就是类）。 | ① 4 处"【实测】可用"全部收窄为"**只对纯标量/字符串子集成立**"并指向 §17.2.2；② §17.2.2 标题加"⛔ 当前 tpc 下不可实现"、"仍未验"改为"已验（结论负面）"并**精确写出 tpc 需要补什么接口**；③ §19 M4 行的"TaskHost 线程池后端"标注被卡住 ⇒ 该里程碑"后台 IO 不卡帧"这条出口判据**当前不可达**；④ §24.2 #1 收口（转为对 tpc 的能力请求）、#7 定案（**只上后端 B**）。★ **教训**：探针的"✅"只覆盖它**实际跑到的那一面**；把"探针 E 过"读成"线程池可用"，缺的正是"**探针没测什么**"那一列。 |

| 43 | **"跨屏无差别"是错误声称**（暴露者 `cpp-src/win32/drift_win32.cc` 的注释 / `tools/probe-window.py` 的 DPI 断言） | `php_drift_win_dpi` 的注释写着"用 `GetDeviceCaps` 只拿到**系统** DPI —— **对 M1 无差别**，因为跨屏拖动时系统会发 `WM_DPICHANGED`"，并据此把进程设成 `SetProcessDPIAware()`（**V1**）。★ **两句都错**：① `WM_DPICHANGED` **只发给 per-monitor 感知的进程**，V1 进程**收不到** ⇒ `g_dpi` 永远停在系统 DPI、§6 的"跨屏 = 布局事件"整条落空；② V1 进程跨屏时系统把内容**位图拉伸** ⇒ 那正是 §19 M1 那句"分数缩放下不糊"要消灭的形态（**糊**）。⇒ 连"为一个 DPI 查询不值得抬 `WINVER`"这个成本判断也站不住：正解是 `GetProcAddress` **动态取**，根本不碰编译宏。★ **它能在开发机上"过"的原因**：单屏 + 100% DPI 时 V1 与 V2 的**可观测行为完全相同**（系统 DPI == 显示器 DPI，且没有"跨屏"这个动作）。 | ① 进程感知升到 **Per-Monitor V2**（三级回退 V2 → shcore per-monitor → V1；**必须在建窗 / 取 DC 之前**设）；② `drift_win_dpi` 改 `GetDpiForWindow`（每显示器，退 `GetDeviceCaps` 是**降级**不是等价）；③ 外框改 `AdjustWindowRectExForDpi`（老的按系统 DPI 算边框 ⇒ 客户区对不上逻辑尺寸）；④ 建窗后按 `GetDpiForWindow` 纠正（`CW_USEDEFAULT` 可能落在另一块 DPI 的屏上）；⑤ 探针加**带反例**的感知级别断言；⑥ 修掉 `pump()` 里"relayout 由 `viewport()` 变化驱动"的错误注释（跨屏时逻辑尺寸不变）。★ **教训**：**"平台会替我处理"是最贵的一类假设** —— 它把"我没做"伪装成"不需要做"，且在**单屏 100% DPI 的开发机上永远暴露不出来**。⇒ 这类判据必须**直接断言配置本身**（感知级别），而不是断言它的后果。 |
★ **方法论（第六次同型，模型确实不成立 —— 与 §24.1 #40 同一条判据）**：#23 / #37 / #40 / #41 / #42 / #43 全是"**文档自述（或门禁自述）与可执行事实不一致**"。⇒ 判据再升级：**"门通过"只能证明门覆盖到的那一层**；每加一道门，必须同时写下它**覆盖不到什么**（§16.3 已为 codegen 记了一条，本条补第二条：**只生成不编译**）。★ #43 给出一个更强的特例：**"开发机的环境恰好掩盖了缺陷"** —— 单屏 100% DPI 下 V1 与 V2 表现**完全相同**，故"本机跑得好"对这类缺陷**零信息量**；判据必须**直接断言配置本身**，不是断言它的后果。

