# 裁决索引

> 一张表回答"这件事现在定成什么样、什么时候该重新讨论"。只登记已经拍板的结论；待办、进度与讨论过程不进这张表。

新裁决必须同时写清**失效条件**：写不出失效条件，说明这条结论还没有真正想清楚。失效条件被触发时，先重开裁决、再改代码，不要直接改实现。旧的过程稿与战报不是裁决依据，需要考古时用 git 历史。

## 架构与表达

| # | 主题 | 现行结论 | 失效条件 |
|---|---|---|---|
| A1 | 三棵树与组合根 | Widget / Element / RenderObject 三树 + 单向下行 + 组合根注入；`BuildContext` 是只读环境快照，无 `of()` 式向上查找。 | 出现无法逐层传值的深层场景，且实测组合成本超过帧预算门槛。 |
| A2 | 读点响应式 | **读自动、写显式**：`build()` 期读 `Cell` / `Computed` 自动登记依赖，重建边界 = 读点所在 `State` 的子树（`Bind` / `Text::of` / `LiveText` 逐级收窄）；写入永远是显式调用（`Cell::set/update` 或 `setState`）。**不做**可变对象的透明代理（Vue `reactive()` 式"改属性即追踪"）。原表述"不做自动依赖追踪"已在 11-21 R0 落地后被推翻。 | 出现读点自动登记表达不了的依赖形状；或显式写入在真实应用里成为交付瓶颈，且有可验证的代理方案（含 AOT 生成物形状）。 |
| A3 | 双向绑定 | 不做；控件受控，点击只回调新值，值由应用改。 | 表单类应用出现无法用受控模型表达的交互契约。 |
| A4 | 原生控件包装 | 不做；界面全自绘。 | 无障碍或输入法出现自绘无法满足的硬性采购/平台要求。 |
| A5 | CSS 层叠与流式布局 | 不做；单遍约束布局。 | 出现需要文档流排版的真实产品形态，且成本可承受。 |
| A6 | 增量布局与 damage rect | 不做；整树重排，布局为纯函数。 | 以 `FrameTaxMeasure` 在目标节点规模复量，重排占用超过帧预算的一半。 |
| A7 | 命令标识 | 用字符串名 + `CommandRouter` + Element 冒泡链，不用整数命令 ID。 | 命令派发成为可测量的性能热点，且命名方案无法优化。 |
| A8 | 结构性重写 | D1–D7 剩余项不打包重写；按真实消费者逐项推进。 | 出现新的 P0 结构缺陷，或现有债务触发其重裁条件。 |
| A9 | 盒 chrome 单点 | 盒的"底 → 边框（含虚线）→ 圆角"只有一个实现 `BoxChrome::paint()`；`Modifier` 与 5 个自绘 chrome 盒共用。阴影**不在内**（Button 的 hover 阴影是动态、PopupPanel 有 fallback，语义不同）。 | 出现需要不同 chrome 语义的盒（例如逐边不同色/不同圆角），`BoxChrome` 无法用一个签名表达。 |
| A10 | 单轴滚动宿主 trait | 滚动 State 的共用面分两层。`SingleAxisScrollHost`：`lane()` + `tickFrame()`，三个单轴宿主（ScrollView/LazyList/TextArea）共用，`$lane` 字段留各类。`ScrollViewHost`（内嵌上一层）：`Scrollable` / `ScrollMetrics` 的八个读方法（`maxOffset`/`scrollTo`/`animateTo`/`scrollAxis`/`viewportExtent`/`scrollByAxis`/`glideByAxis`/`ensureVisible`）只给查 `RenderScrollView` 的两个宿主，各类只回答 `mainAxis()` / `scrollBox()` / `scrollController()` 三个事实。`scrollBy()` 仍各自实现（懒列表多一步换窗），`TextArea` 不进第二层（盒是 `RenderTextArea`，没有 `revealDelta()`）。 | 出现第 4 个单轴滚动宿主，或其 `tickFrame` 需要不同帧语义（如惯性刹车只作用于某类）；或 `TextArea` 的盒也长出 `revealDelta()` 一致的几何契约（届时两层合并）。 |
| A11 | 多子盒实现面 trait | 四个多子盒（Flex/Wrap/Stack/SegmentedControl）的 `addChild`/`clearChildren`/`children`/`visitChildren`/`paintContent` 收进 `MultiChildBox` trait；类型识别仍走 `ChildContainer` 接口，**不换父类**（D2 裁决：四盒布局算法无共同点，Flutter 亦为 mixin）。叶子的 `contentIntrinsic*` 不抽（各返回自身字段，是假重合）。 | 某个盒的 kids 增删 / 遍历序需要不同语义（如按 z-order 插入、跳过隐藏子）。 |
| A12 | 文本输入配置面 trait | `TextField`/`TextArea` 共有的 value/label/placeholder/两回调 + 五个 getter + `setSemanticsValue` 收进 `TextInputConfig` trait；字段留宿主、各自 `make()`/`createState()`/构造不进，单行独有（error/helper/maxLength/obscure/formatters）与多行独有（minLines/lines）不进。 | 某个控件的受控纪律分叉（值不再唯一来源 / 写回不再走 onChanged），或回调签名各异。 |
| A13 | 路由声明与状态管理解耦 | `#[Route(id,title,icon)]` 是**纯导航声明**，标在页面类上（`Page`；reducer 例外页为 `Widget`）；`RouteCatalog` 启动期反射成描述符，是 id/title/icon/pageClass 的唯一真相源，导航项与建页全从它派生。四段式 `ReducerView`+`ReducerState` 降级为**复杂页可选**的内部状态管理（需纯函数可测 `update()` 才用），不进注解、不被迫全量。 | 导航元数据出现注解之外的新真相源。（reducer 触发阈值由 A14 独占。） |
| A14 | 导航与 reducer 命名空间分离 | `Drift\Route\` 只放导航三件套（`Route`/`RouteDescriptor`/`RouteCatalog`）；页面状态机六件套迁到 `Drift\Reducer\`（`ReducerView`/`ReducerState`/`ReducerModel`/`ReducerEvent`/`ReducerEffect`/`ReducerUpdate`）。只对**纯状态转移**页值得（实测仅 Snackbar）；Dialog（同步模态取返回值）、Feedback（fire-and-forget）、AsyncTable（`AsyncCommand` 生命周期）调用形状不是 reduce，**不铺开、不造中间档**（中间档服务假想客户，反增范式）。对标 Flutter `Route` vs `Bloc`、Compose `NavController` vs `ViewModel`、SwiftUI `NavigationStack` vs `@State`。★ A28 订正：`Route`/`Routing` 的分层依据是「声明元数据 / 装配建页」而非旧注释里的「那是命令路由」（命令路由住 `Drift\Commands\`）。A14 本身只守 `Route` ↔ `Reducer`，从未约束 `Route` ↔ `Routing`；方向由 A28 定为 `Routing → Route` 单向，`Route` 不反向 import `Routing`（声明不依赖装配）。 | 出现 3 页以上真实「事件→纯更新→effect」形状且可接受各页值类样板；或导航元数据与状态机真的需要互相引用。 |
| A15 | 页面单元形态 | 页面 = `Drift\Widget\Page`（`State` 子类）**一个类**：字段 / `build()` / 事件处理器同处，不再有"Widget 壳 + State"两件套。`Page::host()` 是唯一装配入口，交出 `@internal` 的 `PageShell`；`#[Route]` 标页面类。三条判据：`RouteCatalog` 只收 `Page`/`Widget`、一个 `State` 只能绑一个 `Element`（同一实例同时挂两处、或卸载后重挂，均 Fatal）、`Page` 子类禁用 `$this->widget()`（它返回宿主而非页面）。showcase 全部页面已迁移：除 `SnackbarPage` 外都是单类，那个例外保留薄 `StatefulWidget` 壳（状态机委托 `ReducerState`，必须经 `createState()` 挂树，为的是 `update()` 可纯函数单测）；`RouteCatalog`/`PageRegistry` 的 `Widget` 分支就是为这个例外留的，不是迁移期临时代码。**不强制所有应用**：无路由的单根应用（watermark）继续用 `StatelessWidget` 组合根，长生命周期业务流住 `Store` 而不是页面。 | 页面普遍需要"描述不可变 + 每帧换新描述"的重建能力；或单类页面里 UI 态与业务态普遍混住且无法用 `Store` 隔离。 |

| A16 | 受控参数的两种形态 | 控件参数可以是**值**（`selection(TableSelection)`）或**通道**（`selectionFrom(Readable)`）：值形态的读点在页面 `build()`，变了要应用 `setState` 整页重建；通道形态的读点在控件自己的 `State`，变了只重建控件子树。判据是“变了之后谁该跟着重建”，不是“哪个 API 更新”。双向绑定仍归应用（`onSelectionChange` 写回同一个格子），页面上其它跟着变的地方用 `Bind` 开自己的读点。`Table` 是唯一同时提供两种形态的控件；其余控件只有值形态，**不为“可能有用”预先加通道**。 | 出现第二个需要通道形态的控件（届时把 `xxxFrom(Readable)` 的命名与门控要求一起定），或控件能自行收窄到行级、通道形态的收益消失。 |
| A17 | 环境能力契约 | `Drift\Widget\Environment`（**接口**）是控件与 Store 唯一的能力入口，实现是 `Drift\App\AppEnvironment`（`Application::environment()`）。契约住 Widget 层，因为实现住 App 层 —— 反过来就是 `Widget → App` 成环，而那等于让控件 import 帧循环。端口在门面上**投影成值参数**（`openFile(string, array): ?string`、`clipboardText(): ?string`），`Dialog` / `Clipboard` 的类名不进 Widget 层，那条 `Widget → Platform` 的边因此保持为 0。来源只有两类：浮层 / 定时器来自本窗（跨窗弹错窗口），对话框 / 剪贴板来自 `AppScope` 的端口槽（进程一份）。缺端口不是异常：空值 + **一条** Diagnostic。窗口拖动用 `supportsWindowDrag()` + `startWindowDrag()` 一对，"无边框"这个事实的两个后果同源。应用侧只收一个 `Environment`，不再各写一种闭包递送形状。 | 框架**控件自己**需要弹对话框 / 读剪贴板（届时把端口接口下沉成独立层，而不是把 `Dialog` 引进 Widget）；或门面长到"什么都做"（按能力族拆接口，但仍只保留一个注入点）。 |
| A18 | 状态形状 | **只有 `Cell`**：界面会读的每一块状态是一个格子，写进去当场通知（没有 `notifyChanged()` 可漏），读点交给 `Bind` 的 builder，依赖由框架采集给出。★ **2026-11-24 例外条款退役**：`extends Subject` + `notifyChanged()` + 切片订阅那条路（`SubjectBuilder::select` + `SubscribingWidget` 标记）没有实现者、也没有 `apps/` 使用者，整套删掉 —— 留着它等于允许同一件事两种写法，而 lint 只守得住其中一种。`Subject` 保留为**基座**（`Cell` / `AsyncCommand` 继承它，控件内部信号如 `SelectPanelSignal` 用它），但"多字段同频一致快照"目前**没有原语**。两条纪律不变：不给每个字段写格子之后又整块广播（两套机制一起用，每次白通知一遍）；不因为"想少写几个格子"把一个 Store 的字段合并成数组切片。粒度代价是公开的：订阅粒度 = 格子粒度，读 `phase` 这种枚举格的段会在无关档位切换时白重建一次，一次业务流 6~8 次，与进度 tick 的百次量级不同 —— 值得换"漏写依赖不存在"。 | 出现"读方必须跨字段一致快照"成为多数场景的证据（届时把切片做成 `Cell` 的**组合原语** —— 是新原语，不是回退整块广播）；或单 Store 的格子数增长到难以导航（那时拆 Store，不是换回 `Subject`）。 |
| A19 | 可观察值的读法 | 界面要跟着变的东西一律实现 `Core\Readable`：`Cell` / `Computed` / `AsyncCommand` 都是，读点即订阅，由 `State` 的采集栈统一建边（`Tracker::record`）。控件参数只收**值**、不收 `Subject` + 切片函数 —— `PageHost::from(Readable)` 取代了 `source(Subject)` + `select(callable)`，那套手写订阅 + 手工同值门控是采集栈的重复实现。连带两条：Store 不再承担"把命令的变化转发一次广播"的角色（`AsyncCommand` 自己可读）；`Routing` 因此不再依赖 `State`（依赖矩阵里那条边删掉）。 | 出现"读一次要付昂贵求值"的通道（那时需要显式的拉取/缓存契约，而不是把切片函数加回来）；或某个 `Readable` 实现必须在 `get()` 里写状态（那它就不是可观察值，是命令）。 |
| A20 | 状态色 | 状态色以 `ColorToken` 的**前景 + 容器底**成对提供：`Success`/`Warning`/`Info`/`Danger` 与 `*Surface`。业务档位经 `Rendering\StatusTone` 映射成一对 token，`Loading` 与 `Warning` 共用橙色系但保留独立语义。 控件在 `build(ctx)` 里按当前主题解析，应用侧不再写 `kind → Color` 的 if 链或状态色 hex（`Palette` 只留品牌色）。 | 出现“同一状态在不同产品要完全不同色系”的真实需求；或某对颜色在暗色 / 高对比下实测越过可读性下界 —— 那时加新 token 对，不回应用里散写 hex。 |

| A21 | 异步视图 | 控件层用 `Widget\AsyncView` 消费 `AsyncValue`：源是 `Readable`，`data` 支必填，`idle` / `loading` / `error` 有**非空白默认**；四态路由仍由 `AsyncValue::fold()` 独占。为它重新放行 `Widget → Async` 这条单向边（`Async` 不反向依赖 `Widget`，靠 `Readable` 解耦）。 | 出现更通用的“状态 → 子树”抽象能同时覆盖非异步四态，且不需要 Widget 依赖 Async；或默认支开始携带业务语义（那时把它拆回页面显式写）。 |

| A22 | 状态条控件 | **不抽**通用 `StatusLine`。users 状态栏是无 tone 的一行文本，watermark 是带语义点的 `StatusMessage`；真正重复的只有 `Row + Text + Spacer`，而语义色已经由 `StatusTone` + `ColorToken` 收口。再抽一层会把“业务 kind → tone”映射推回调用点，抽象成本高于省下的排版。 | 第三个应用也出现“消息 + 语义点 + 容器底”且读写接口稳定，届时抽的是完整状态消息控件，不是只包 `Row` 的壳。 |
| A23 | 组合根样板 | 后台装配收成原语：`AppScope::useBackgroundJobs()` 收掉 exe → 池 → 预热 → 装端口四步；`worker-argv` lint 禁止第四份开关字面量。★ 角色判定那半由 A25 再收进 `AppEntry`，`WorkerProcess` 只剩 `isWorker()` / `serve()` 两个原语。**不**做“大而全配置对象”：第二窗 / 无边框 / 材质 / 恢复 / 关闭守卫是应用语义，留在组合根；`Bootstrap.php` 必须保留 `new WindowSet(`（`app-bootstrap-loop` 判据）。 | 出现同一份装配在 5 个以上应用里逐字重复、或平台侧提出“一个配置文件描述所有窗口”的真实消费者；届时先给 `EventLoop` 加钩子，不复制循环。 |
| A24 | 重试默认支 | `AsyncView::retryOn(\Closure)` 给**默认** error 支装“错误 + 重试按钮”，省掉 showcase 异步表格与并发取数两页重抄 `Center(Column(ErrorPlaceholder + Button))`；显式 `error()` 优先于 `retryOn()`（LoadMore 页分页失败要保留旧行的形状只有页面知道，故不迁）。重试动作是 0 参闭包；`AsyncCommand::retry()` 有意不去重（在途点它要打断重来），按钮不自带 enabled，安全性来自 error 支显示时命令已不在途。 | 第四个应用需要带自定义文案 / 门控的重试支，届时扩 `retryOn()` 参数而不是造新控件；或 error 态真的会持续 running，才把 enabled 收进框架。 |
| A25 | 组合根角色分流 | 把“同 exe 双角色”从应用侧彻底收进框架：`abstract AppEntry` 的 `final run()` 判 `WorkerProcess::isWorker()`，worker 走 `serve($this->jobs())`、UI 走 `start()`；应用只写 `jobs()`（可省）与 `start()`，不 import `WorkerProcess`。`app-worker-role` lint 守“角色判定不许搬回应用层”。**不用静态模板方法**：PHP 的 `abstract static` 是 Fatal，且 worker 进程会把 warning 吐进 stdout 污染行协议（真机表现为退出码 3），实例方法才是合法形态，入口固定 `(new Bootstrap())->run()`。**不做**双 exe（worker 仍要链全部应用代码，产物与构建翻倍）与“基类接管 AppScope”（`maxWorkers` / `prewarm` 是应用语义，收进去就得再加钩子）。 | 出现第三种进程角色（如 CLI 子命令）时在基类加**带默认实现**的钩子（`cli()` / `onWorkerStart()`）。用抽象基类而不是 interface 就为这一步：interface 加方法会强制所有实现同步改，基类加带默认实现的钩子只影响真正要用的那个应用。 |

| A26 | 焦点环输入模态 | `:focus-visible` 只由**导航键**开启：`KeyEvent::isFocusNavigation()` 收口 Tab / 方向键 / PageUp / PageDown / Home / End（含 Shift 组合）；文本、Enter、Backspace、Delete、Ctrl+A/C/V 等编辑键不切模态，指针与滚轮仍关闭。应用只消费这个谓词，不另写一份键表。 | 出现"编辑键也必须提示焦点位置"的真实无障碍需求，并能给出明示规则；或新增不属于这份键表、却要切模态的导航语义。 |
| A27 | 多行输入滚动坐标 | `RenderTextArea::caretRect()` / `selectionRects()` 返回**已扣滚动偏移**的盒内视口坐标；绘制时只把段落上移，不再对整张画布做第二次 `translate`。`offsetAt()` 反向加回偏移，几何 / 命中 / 绘制共用一份契约。 | 改成"光标几何保留内容坐标"的另一套契约，或滚动引入旋转 / 缩放等非平移变换。 |
| A28 | 导航描述符唯一 | `RouteDescriptor` 是 id/title/icon/pageClass 的唯一运行时描述符，**不再投影成第二份 `NavEntry`**：`PageRegistry` 直接吃 `list<RouteDescriptor>` + 工厂表，侧栏与 AutomationId 都读 descriptor。理由：`NavEntry` 四字段与 descriptor 1:1，投影层零增值且让契约测试钉在错误的类型上（GoRouter `GoRoute` / Compose `composable` / React Router `<Route element>` 都是单一描述符同时带身份与建页）。`Route`（声明）与 `Routing`（装配）仍是两个命名空间，但依赖方向明确为 **`Routing → Route` 单向**（装配依赖声明是自然方向；`Route` 不反向 import `Routing`，"命令路由住 `Drift\Commands\`"的订正仍成立）。兼容可破坏：`NavEntry` 删除，`PageRegistry` 构造参数改为 descriptors，`lint dependency-direction` 的 `Routing` 白名单加 `Route`。 | 出现一个应用需要"导航项 ≠ 路由 descriptor"（例如同一页多个入口带不同 label/icon），届时恢复投影层但显式命名为 `NavShortcut`，不复用 `NavEntry` 这个名字。 |
| A29 | 示例应用判据边界 | 核心框架判据只认 `src/` 与 `tools/` 的公开契约；showcase 页面集合由生成 manifest、`PageScenes`、golden 基线与 bench 五向封闭（`scene-set-check` 守），`apps/` 里不放演示页的业务单测或桩。新增页只写页面与 `#[Route]`，再跑生成器补 manifest。 | 页面类只为演示契约服务，却需要专属单测 / 桩来维持；或 `scene-set-check` 发现不了 manifest 与磁盘漂移。 |
| A30 | 表格类不按职责拆分 | `TableState` 2700 行保留为单一类，**不**拆成编辑 / 指针 / 表头等协作者：编辑会话与焦点、滚动、几何双向交织（`beginEdit()` 调 `revealFocus()`、`editKey()` 的 Enter/↑/↓ 直接 `moveFocusTo()`、`commitIfUserScrolled()` 读 `revealScrollInFlight`），外置只是把内聚换成耦合。外置只适用于**无外部读者/写者**的纯逻辑，它们本来就在外面（`TableGeometry` / `TableSelection` / `TableGridPicker` / `ColumnWidths` / `ScrollLane`，各有专项测试）。类内用分区标记导航（滚动 / 焦点键盘 / 表头键盘 / 选区 / 编辑 / `EditableText` 转发 / 指针 / 构建）。 | 出现第三个表格类消费者（如 TreeTable）需要共用渲染层，届时按"渲染 / 编排"两分，而不是按交互职责切细。 |
| A31 | 选择器骨架用继承而非 trait | `DatePicker` / `DateTimePicker` 的 State 共用 `abstract CalendarPickerState`：要共享的是**私有态 + 生命周期**（`$month` / `$dismiss` / 面板信号，`initState()` 建订阅端），trait 表达不了字段与初始化时序，而 A11/A12 那种"纯方法面"才该用 trait。子类只剩面板内容与提交语义（选完收 / 不收）。先例是 `EditableTextState`（`TextField` / `TextArea` 的基类）。 | 第三个带月历的控件出现（如 `DateRangePicker`）且它与两者只是"面板内容不同"，届时把 `panelContent()` 收成一个参数；或子类差异长到抽象方法超过八个，说明该改回组合。 |

## 编译器与代码纪律

| # | 主题 | 现行结论 | 失效条件 |
|---|---|---|---|
| C1 | `switch` 控制流 | 热路径禁用，用 `int $kind` + `===` 比较链。 | 编译器生成物经系统复测证明安全，且大规模迁移有可测收益。 |
| C2 | `?Obj` 可空对象 | 形参、返回值、链式已解禁；字段层仍用"非空 + `bool`"或带内哨兵，避开 `?->`。 | 编译器属性表生成形状变化，或 `?->` 的静默读空被机制拦住。 |
| C3 | `readonly` | 保留并继续用于不可变值对象，不迁移。 | 生成形状再次变化，实测退化越过帧预算门槛，且去掉后仍能保留不可变性。 |
| C4 | 闭包 | 事件回调保持闭包，不换成接口对象；两层闭包保留。 | 回调变成每帧热路径，或 AOT 下调用成本实测超过阈值。 |
| C5 | AOT 判据 | lint 管结构性纪律，运行时与编译器层用诊断和真机判据兜底。 | 静态分析能力足以覆盖运行时所有权与生成物形状。 |
| C6 | ABI 运行期握手 | 不做；stub 是唯一事实源，构建期三向对签名。 | 出现静态契约覆盖不住的真实错配路径，且缓存 stale 无法解释。 |
| C7 | `--nano` 后端 | 不做。 | 编译器支持且出现要求极小体积的真实部署场景。 |
| C8 | 属性钩子 | **禁用**。PHP 8.4 property hook 在 AOT 下能编过（`users` 实测），但两条代价盖过那点短：① 它让"这一行在读一个会变的值"从源码里消失 —— `grep ->get()` 找不到读点，而 A19 的判据正是读点可审计；② 每字段 4 行钩子比省下的 `notifyChanged()` 还长，多字段之间还会撕裂读（2026-11-07 实测 16 字段净收益为负）。读用 `x()`、写用 `setX()`，通知由格子承担。 | 出现能静态列出钩子读点的量具（lint 像检查 `->get()` 那样把 hook 读纳入依赖判据），且实测钩子不产生额外间接调用。 |

## 异步与资源

| # | 主题 | 现行结论 | 失效条件 |
|---|---|---|---|
| N1 | 任务单元 | 任务必须是 `Job` 类，不是闭包；参数走 `FIELDS` 一份名单。 | 编译器能安全跨线程传递闭包且反射契约被替代。 |
| N2 | 异步资源基类 | 不建 `AsyncResourceState`；`AsyncValue::fold()` 四态穷尽是优势。 | 出现第二个真实资源消费者，且四态无法表达其状态。 |
| N3 | 取消风险 | 扇出取消用运行时诊断 `future/fanout-cancel`，不升级为持有即校验的 ownership。 | 出现第二个真实踩坑，诊断已不足以定位。 |
| N4 | 图片缓存 | 内存层用 LRU + 字节/条目双上限（默认 48 MiB / 256 条，命中移 MRU，单张超限不缓存）；磁盘层由组合根的可选 `cacheKey` 回调收敛签名 URL，写盘临时文件原子改名，损坏缓存自愈。不做同 URL 在途合并与磁盘容量淘汰。 | 并发同 URL 下载造成可测的带宽浪费，或磁盘目录在长跑中实际堆积告警，或内容失效无法用 `cacheKey` 表达。 |
| N5 | 编程式滚动动画 | `ScrollController::animateTo()` 是定时长 + 曲线的一条独立入口；`scrollTo` / `ensureVisible` / thumb 拖动保持"调完就到"。每帧位移走 State 的 `scrollBy`，用户一动即让位，不返回完成回调。论证见 `_legacy-docs` 下当日设计稿与工作日志。 | 出现需要不同中断语义的消费者，或两条入口互斥导致可复现的交互缺陷。 |
| N6 | UI 线程异步 HTTP | `AsyncHttp` 门面按 body 大小分流：小请求走帧内 curl_multi（`CurlMultiTransport`，每帧 `exec + select(0)`），multipart / 大 body 走进程池 `HttpRequestJob`；两条路共用 `Http::prepare()` / `finish()`，语义只一份。传输后端是 `HttpTransport` 接口，可换。这个分流替掉的是"所有 HTTP 都占 worker"和"为每个小请求配池大小"。 | 出现需要流式双向（WebSocket）或超大并发长连接的真实场景，帧内推进顶帧；或编译器支持 AOT 线程入口后 Swoole 后端能在真机跑通。 |
| N7 | 取消能真停 CPU | `CancelSupport` 四档按"能省多少活"递增，`ProcessTaskHost` 取最高档 `Killable`：在途任务的取消 = 杀掉独占它的 worker + 下帧补池。可行性来自 `pickIdle()` 保证每 worker 在途 ≤1 ⇒ 不连坐；复用超时清扫的 `dropWorker()`，不另造协议。取消不是失败（不投 `Failure` / 不 onError / Future 保持未结算）。**不做**任务内轮询取消标志的 cooperative 检查点：那要改行协议 + 每个 `NamedJob` 配合，而"杀执行单元"用现成设施就够。借鉴 Electron `utilityProcess.kill()` / JavaFX `Future.cancel(true)`。 | 出现"取消必须在任务内部优雅收尾（如回滚半写入的文件）"的真实需求 —— 那时才需要 cooperative 检查点；或一个 worker 允许并发多条在途，"杀 worker 不连坐"的前提不再成立。 |

## 平台与能力

| # | 主题 | 现行结论 | 失效条件 |
|---|---|---|---|
| P1 | 第二平台 | `Platform` 是能力端口集合，新平台加壳与适配器，框架层不动；第二平台 Linux x64（X11）已过公开 CI 的 T2 端到端门，桌面窗口能力（光标 / 无边框拖拽 / 高 DPI / 主题事件）已补齐，A11y 未接。 | 出现明确的第三平台交付目标，或 X11 退化到过不了 T2 真机门。 |
| P2 | 触控与笔 | 不做；无对应输入通道。 | 触控场景落地，与 fling / 长按一次性评估。 |
| P3 | `ITextProvider` | 推迟；只做树导航与结构/焦点事件。 | 读屏或政企无障碍采购成为硬阻断。 |
| P4 | `WM_POINTER` | 推迟；当前指针通道够用。 | 触控 / 笔输入场景出现。 |
| P5 | i18n 与 RTL | 不做。 | 真实多语言交付要求出现。 |
| P6 | UIA 富 Pattern 的推进顺序 | 按"数据面成本 × 可验证性"逐项推进：`IScrollProvider` 已实现（`ScrollMetrics` 单轴 → UIA 两轴视图 + 写回）；`ITextProvider` 见 P3 继续推迟；`IGridProvider` 推迟——需 cell 语义节点与行列索引，与"叶子不给角色"的角色裁决冲突，硬做会产出可 `GetCurrentPattern` 但方法全空的空壳。 | 读屏在表格 / 文档场景成为硬阻断，或语义树引入可声明的 cell 角色。 |

## 工程与知识

| # | 主题 | 现行结论 | 失效条件 |
|---|---|---|---|
| E1 | 验证入口 | `php bin/drift verify` 是唯一入口，判据清单只在 `--list`；dev 绿不等于 AOT 绿。 | 出现能覆盖 AOT 生成物的纯 PHP 判据，真机层可被替代。 |
| E2 | 判据可信度 | 判据与实现不同源，每条新约束要能回答"什么变异让它报红"。 | 缺陷题库能自动推导变异，人工设计不再是瓶颈。 |
| E3 | API 面 | `@api` / `@internal` 独立标注行，`lint --api` 是唯一事实源，不留手抄快照。 | 离线发布要求静态快照，且有机制保证其新鲜度。 |
| E4 | 文档引用检查 | 旧的 docref 不恢复；链接、结构、裁决表由 `tools/doccheck.php` 手工承接，**不在 V2 `steps()` 内**。 | 新检查覆盖不到跨仓库引用且出现真实死链；或 `doccheck` 被要求进 `VerifyPlan` 成为第六个结果层。 |
| E5 | 应用目录 | 保持 `apps/`，不改名 `examples/`；示例不合并。 | 外部贡献者因命名惯例找不到入口，且迁移收益超过成本。 |
