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
| A10 | 单轴滚动宿主 trait | 三个单轴滚动 State（ScrollView/LazyList/TextArea）的 `lane()` + `tickFrame()` 收进 `SingleAxisScrollHost` trait；`$lane` 字段留在各类（trait 读写它）。`offset()`/`viewportExtent()` 不进（只 2 份且硬编码 `RenderScrollView`，TextArea 用 `RenderTextArea`）。 | 出现第 4 个单轴滚动宿主，或其 `tickFrame` 需要不同帧语义（如惯性刹车只作用于某类）。 |
| A11 | 多子盒实现面 trait | 四个多子盒（Flex/Wrap/Stack/SegmentedControl）的 `addChild`/`clearChildren`/`children`/`visitChildren`/`paintContent` 收进 `MultiChildBox` trait；类型识别仍走 `ChildContainer` 接口，**不换父类**（D2 裁决：四盒布局算法无共同点，Flutter 亦为 mixin）。叶子的 `contentIntrinsic*` 不抽（各返回自身字段，是假重合）。 | 某个盒的 kids 增删 / 遍历序需要不同语义（如按 z-order 插入、跳过隐藏子）。 |
| A12 | 文本输入配置面 trait | `TextField`/`TextArea` 共有的 value/label/placeholder/两回调 + 五个 getter + `setSemanticsValue` 收进 `TextInputConfig` trait；字段留宿主、各自 `make()`/`createState()`/构造不进，单行独有（error/helper/maxLength/obscure/formatters）与多行独有（minLines/lines）不进。 | 某个控件的受控纪律分叉（值不再唯一来源 / 写回不再走 onChanged），或回调签名各异。 |
| A13 | 路由声明与状态管理解耦 | `#[Route(id,title,icon)]` 是**纯导航声明**，标在页面类上（`Page`；reducer 例外页为 `Widget`）；`RouteCatalog` 启动期反射成描述符，是 id/title/icon/pageClass 的唯一真相源，导航项与建页全从它派生。四段式 `ReducerView`+`ReducerState` 降级为**复杂页可选**的内部状态管理（需纯函数可测 `update()` 才用），不进注解、不被迫全量。 | 出现多数页面都需要纯函数可测 reducer 的真实证据，且实测全量四段式的代码量/帧税被接受；或导航元数据出现注解之外的新真相源。 |
| A14 | 导航与 reducer 命名空间分离 | `Drift\Route\` 只放导航三件套（`Route`/`RouteDescriptor`/`RouteCatalog`）；页面状态机六件套迁到 `Drift\Reducer\`（`ReducerView`/`ReducerState`/`ReducerModel`/`ReducerEvent`/`ReducerEffect`/`ReducerUpdate`）。只对**纯状态转移**页值得（实测仅 Snackbar）；Dialog（同步模态取返回值）、Feedback（fire-and-forget）、AsyncTable（`AsyncCommand` 生命周期）调用形状不是 reduce，**不铺开、不造中间档**（中间档服务假想客户，反增范式）。对标 Flutter `Route` vs `Bloc`、Compose `NavController` vs `ViewModel`、SwiftUI `NavigationStack` vs `@State`。 | 出现 3 页以上真实「事件→纯更新→effect」形状且可接受各页值类样板；或导航元数据与状态机真的需要互相引用。 |
| A15 | 页面单元形态 | 页面 = `Drift\Widget\Page`（`State` 子类）**一个类**：字段 / `build()` / 事件处理器同处，不再有"Widget 壳 + State"两件套。`Page::host()` 是唯一装配入口，交出 `@internal` 的 `PageShell`；`#[Route]` 标页面类。三条判据：`RouteCatalog` 只收 `Page`/`Widget`、一个 `State` 只能绑一个 `Element`（同一实例同时挂两处、或卸载后重挂，均 Fatal）、`Page` 子类禁用 `$this->widget()`（它返回宿主而非页面）。showcase 全部页面已迁移：除 `SnackbarPage` 外都是单类，那个例外保留薄 `StatefulWidget` 壳（状态机委托 `ReducerState`，必须经 `createState()` 挂树，为的是 `update()` 可纯函数单测）；`RouteCatalog`/`NavEntry`/golden 的 `Widget` 分支就是为这个例外留的，不是迁移期临时代码。**不强制所有应用**：无路由的单根应用（watermark）继续用 `StatelessWidget` 组合根，长生命周期业务流住 `Store` 而不是页面。 | 页面普遍需要"描述不可变 + 每帧换新描述"的重建能力；或单类页面里 UI 态与业务态普遍混住且无法用 `Store` 隔离。 |

| A16 | 受控参数的两种形态 | 控件参数可以是**值**（`selection(TableSelection)`）或**通道**（`selectionFrom(Readable)`）：值形态的读点在页面 `build()`，变了要应用 `setState` 整页重建；通道形态的读点在控件自己的 `State`，变了只重建控件子树。判据是“变了之后谁该跟着重建”，不是“哪个 API 更新”。双向绑定仍归应用（`onSelectionChange` 写回同一个格子），页面上其它跟着变的地方用 `Bind` 开自己的读点。`Table` 是唯一同时提供两种形态的控件；其余控件只有值形态，**不为“可能有用”预先加通道**。 | 出现第二个需要通道形态的控件（届时把 `xxxFrom(Readable)` 的命名与门控要求一起定），或控件能自行收窄到行级、通道形态的收益消失。 |

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

## 异步与资源

| # | 主题 | 现行结论 | 失效条件 |
|---|---|---|---|
| N1 | 任务单元 | 任务必须是 `Job` 类，不是闭包；参数走 `FIELDS` 一份名单。 | 编译器能安全跨线程传递闭包且反射契约被替代。 |
| N2 | 异步资源基类 | 不建 `AsyncResourceState`；`AsyncValue::fold()` 四态穷尽是优势。 | 出现第二个真实资源消费者，且四态无法表达其状态。 |
| N3 | 取消风险 | 扇出取消用运行时诊断 `future/fanout-cancel`，不升级为持有即校验的 ownership。 | 出现第二个真实踩坑，诊断已不足以定位。 |
| N4 | 图片缓存 | 内存层用 LRU + 字节/条目双上限（默认 48 MiB / 256 条，命中移 MRU，单张超限不缓存）；磁盘层由组合根的可选 `cacheKey` 回调收敛签名 URL，写盘临时文件原子改名，损坏缓存自愈。不做同 URL 在途合并与磁盘容量淘汰。 | 并发同 URL 下载造成可测的带宽浪费，或磁盘目录在长跑中实际堆积告警，或内容失效无法用 `cacheKey` 表达。 |
| N5 | 编程式滚动动画 | `ScrollController::animateTo()` 是定时长 + 曲线的一条独立入口；`scrollTo` / `ensureVisible` / thumb 拖动保持"调完就到"。每帧位移走 State 的 `scrollBy`，用户一动即让位，不返回完成回调。论证见 `_legacy-docs` 下当日设计稿与工作日志。 | 出现需要不同中断语义的消费者，或两条入口互斥导致可复现的交互缺陷。 |

## 平台与能力

| # | 主题 | 现行结论 | 失效条件 |
|---|---|---|---|
| P1 | 第二平台 | `Platform` 是能力端口集合，新平台加壳与适配器，框架层不动；第二平台 Linux x64（X11）已过公开 CI 的 T2 端到端门，但仍是能力子集。 | 出现明确的第三平台交付目标，或 X11 退化到过不了 T2 真机门。 |
| P2 | 触控与笔 | 不做；无对应输入通道。 | 触控场景落地，与 fling / 长按一次性评估。 |
| P3 | `ITextProvider` | 推迟；只做树导航与结构/焦点事件。 | 读屏或政企无障碍采购成为硬阻断。 |
| P4 | `WM_POINTER` | 推迟；当前指针通道够用。 | 触控 / 笔输入场景出现。 |
| P5 | i18n 与 RTL | 不做。 | 真实多语言交付要求出现。 |

## 工程与知识

| # | 主题 | 现行结论 | 失效条件 |
|---|---|---|---|
| E1 | 验证入口 | `php bin/drift verify` 是唯一入口，判据清单只在 `--list`；dev 绿不等于 AOT 绿。 | 出现能覆盖 AOT 生成物的纯 PHP 判据，真机层可被替代。 |
| E2 | 判据可信度 | 判据与实现不同源，每条新约束要能回答"什么变异让它报红"。 | 缺陷题库能自动推导变异，人工设计不再是瓶颈。 |
| E3 | API 面 | `@api` / `@internal` 独立标注行，`lint --api` 是唯一事实源，不留手抄快照。 | 离线发布要求静态快照，且有机制保证其新鲜度。 |
| E4 | 文档引用检查 | 旧的 docref 不恢复；链接、结构、裁决表由 `tools/doccheck.php` 承接。 | 新检查覆盖不到跨仓库引用且出现真实死链。 |
| E5 | 应用目录 | 保持 `apps/`，不改名 `examples/`；示例不合并。 | 外部贡献者因命名惯例找不到入口，且迁移收益超过成本。 |
