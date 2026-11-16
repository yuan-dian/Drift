# 异步实现 Review · 对照成熟 GUI 框架的优劣势与开发者体验

> 日期：2026-11-16 · 类型：**只读评审，未改任何代码 / 配置**
> 基线：HEAD `0d30f6f`，工作区干净。
> 方法：逐文件精读框架侧异步链路（`Async/*`、`State/AsyncCommand`、`App/TaskHost` 三后端、
> `Wire`/`JobArgs`、`Image`/`ImageState`/`ImageSource`/`ImageAssets`/`FetchImageJob`/`CoreJobs`）
> 与应用侧真实用法（`watermark` 流水线、`showcase` Job），再对照 Flutter / SwiftUI /
> Kotlin 协程 / RTK Query / Coil·SDWebImage 的**一手资料**。
> 定位一律用类名 / 方法名，不写 `file:line`（本仓文档纪律）。
> 相关：[02-帧管线与异步](architecture/02-帧管线与异步.md)、
> [15-异步资源控件](architecture/15-异步资源控件.md)、
> [REVIEW-ARCH-DX-2026-11-11](REVIEW-ARCH-DX-2026-11-11.md)。

---

## 0. 总评

**一句话**：本仓的异步栈在「**静默失败的可观测性**」和「**跨进程参数/结果契约**」两点上，
做得比多数个人项目、甚至在若干细处比成熟框架的默认形态更严格；但在
「**声明式状态到界面的收口**」「**取消的结构化传播**」「**图片加载的可定制性**」三点上，
仍是命令式框架的老路，开发者要写的代码明显多于 SwiftUI / Flutter / RTK Query 的同类写法。

成熟框架把异步当成**声明式状态**，本仓把异步当成**可组合 Future + 命令式状态机**。
前者赢了「少写代码、少漏分支」，后者赢了「跨进程、可观测、对 AOT 友好」。
这不是谁对谁错，是两条路线的取舍 —— 下面逐功能摊开。

**结论先行（三个最值得记的判断）：**

1. **强项被低估**：`Wire`/`JobArgs` 的「一份名单两个方向 + 反射守契约」、
   `CancelSupport` 三档能力声明、稳定 `Failure code`、进度只喂根任务这四件，
   是把「跨进程线协议」这个本仓特有难题做成了框架能力，成熟 GUI 框架要么没有这层
   （SwiftUI/Flutter 进程内），要么把坑留给业务（自己定义 DTO 序列化）。**这是真优势。**
2. **弱项是真的**：图片控件 `ImageState`  owns 一个 `AsyncCommand` + 手写三态 + 占位 1×1，
   这套「加载态 → 界面」的桥接仍是**命令式样板**。SwiftUI 的 `AsyncImage` 一个
   `phase` 枚举 + `switch` 三支、RTK Query 一个 `useQuery()` 就返回
   `{data, isLoading, isError}`，本仓要写 `State` 子类 + `resolve()` + `apply()` +
   `setState()`。**这是 DX 差距的主战场。**
3. **最危险的取舍**：取消不是结构化的 —— `Future::cancel()` 沿派生链上溯会剪枝，
   「一条根 Future 只能有一个主人」是全仓最反直觉的契约。Kotlin 用结构化并发把取消
   做成父子树的确定传播，本仓用「不要复用 Future，要复用结果」的纪律来替代 ——
   纪律靠人，人总会忘。这是**唯一一处我建议补框架能力而非只补文档**的地方。

---

## 1. 五维评估

| 维度 | 现状 | 对照成熟框架 | 判断 |
|---|---|---|---|
| **结构** | `Async`（Future/Scope/Value/Failure）→ `State`（AsyncCommand）→ `App`（TaskHost/Job）→ `Widget`（ImageState）四层，依赖方向受 lint 约束（Widget 不依赖 App/Async） | Flutter 把加载态收进 `ImageProvider` + `ImageCache` 一个子系统；SwiftUI 收进 `AsyncImage` + `URLCache`；本仓分散在四层 | 分层干净、可替换后端是加分；但「一张图的加载」跨 4 层，`ImageSource` 端口为了守 lint 把返回类型写成 `mixed`，是**为架构纪律付的表达力代价** |
| **实现** | 手写 Promise（then/catch/all/allSettled/race + 摊平 + 进度 + 取消钩子）、Fiber 协程、三后端 TaskHost、双层缓存、反射契约判据 | Kotlin 用语言级 `suspend`/`Flow`/`CoroutineScope`，SwiftUI/Flutter 用框架级原语 | 在 PHP + AOT 约束下能做出这套完成度罕见；`AsyncScope::abort()`「一次注入 + 让出点闸门」对 Kotlin「CancellationException 必须重抛」的坑有独立解，**属于排坑成功** |
| **扩展性** | 换后端是组合根一行；加 scheme 改 `classify()` 一支；核心任务 `CoreJobs::register()` 两侧自动合并 | Flutter `ImageProvider` 可插拔任意来源；RTK Query `createApi` 端点声明式扩展 | 后端与任务扩展好；**图片来源扩展差** —— Flutter 的 `ImageProvider` 是开放接口，本仓 `classify()` 是封闭 if 链，加 `data:`/`blob:` 要改框架核心而非注入一个 provider |
| **开发者体验** | 加一个异步动作 = Job 类 + registry 一行；“给地址就显示”一张图一行 | SwiftUI `AsyncImage(url:)` + phase 三支；RTK Query 一个 hook 拿全状态 | **两极分化**：提交侧（参数/结果/登记）已经收敛得很省；**消费侧（加载态→界面）仍是最弱一环**，State 子类 + resolve/apply 的样板明显重于声明式框架 |
| **维护性** | 关键约束全部可观测：`progress/derived-chain` 诊断、Fatal 未接失败、`CancelSupport::describe()` 单一文案、反射守名单、双层判据 + 变异自检 | 成熟框架多靠约定与类型系统，少靠运行时诊断 | 可观测性是本仓的**护城河**；代价是诊断名/文案多，新增能力时必须同步补「谁触发、怎么验」，否则诊断本身会腐烂（历史 REVIEW 已多次记录） |

---

## 2. 逐功能对比：相同功能，谁的代码更少 / 谁更不容易写错

### 2.1 提交一个后台任务

| | 本仓 | Flutter | Kotlin | RTK Query |
|---|---|---|---|---|
| 写法 | `Job` 类（`NAME`+`FIELDS`+构造器+`run()`）+ registry 一行 + `host->submit()` | `compute(fn, arg)` 或 `Isolate.run()` | `viewModelScope.launch { withContext(IO){…} }` | `createApi` 里一个 endpoint，自动生成 hook |
| 应用侧样板 | **少**（2 文件 2 处，判据守漏登记/漏字段） | 中（函数即任务，但结果回主线程要自己 setState） | 少（语言级，但作用域/异常传播要懂规则） | **最少**（声明 endpoint，零手动接线） |
| 跨进程 | **支持且是默认能力**（`NamedJob` 名字+JSON） | 需自己搭 isolate 通信 | 同进程线程 | 不适用（HTTP 层） |

**判断**：本仓在「可跨进程 + 有契约判据」这个重量级场景里做到接近 RTK Query 的低样板，
是这几轮 DX 收敛的真正成果。轻场景（进程内一次性调用）用它是**杀鸡用牛刀** ——
`SyncTaskHost` 存在但没有把「一行提交」的轻路径做到 SwiftUI 那么薄。

### 2.2 多步流水线（签名→上传→轮询→取结果）

- **本仓**：`AsyncScope::task(fn($co){ $a=$co->await(...); $co->sleep(...); ... })`，
  顺序代码，5 个命令收成 1 个（`WatermarkStore::flow`）。
- **Kotlin**：`coroutineScope { val a = async{…}.await(); … }`，几乎同构。
- **本仓的独立解**：取消靠 `AsyncCancelled` 哨兵 + `guardCancelled()` 在每个让出点重投；
  Kotlin 靠「捕获 `CancellationException` 必须重抛」的纪律。
  一手资料印证 Kotlin 这条纪律每年都在咬人（多个最佳实践文档把「绝不吞掉
  CancellationException」列为 HIGH 检查点）。本仓的 `await()`/`sleep()` 入口闸门
  **把纪律变成了机制** —— 即使业务写了 `catch (\Throwable)` 吞掉取消，下一个让出点也会重新抛。
  **这一处本仓优于 Kotlin 的默认形态。**

### 2.3 加载态 → 界面（本仓最弱一环）

- **SwiftUI**：`AsyncImage(url:) { phase in switch phase { case .empty/.success/.failure } }` ——
  三态由框架给，**漏一支编译器都能提示**（`@unknown default`）。
- **RTK Query**：`const {data, isLoading, isError} = useGetXQuery(arg)` —— 状态是 hook 返回值，
  组件零状态字段。
- **本仓（图片）**：`ImageState` 继承 `State`，`initState()` 建 `AsyncCommand`，
  `resolve()` 手写来源三分类 + 磁盘命中 + 诊断 + 提交，`apply()` 里 `setState()` 换 handle。
  **一个控件为了「一张图」写了 5 个方法、约 100 行。**
- **本仓（页面）**：`SubjectBuilder::select()` + `AsyncValue::fold()` 四支 +
  `StatusMessage` 三件套，四态穷尽式 `fold()` 缺一支当场抛 —— 这个设计**本身很好**
  （比 RTK Query 的 `isLoading`/`isFetching`/`isSuccess`/`isError` 四布尔更容易防漏），
  但 `fold()` 之上的接线仍是命令式。

**判断**：差距不在「状态模型」（`AsyncValue` 四态 + `fold` 是成熟的），
差距在「**状态如何自动送到界面**」。SwiftUI/RTK Query 把这一步做进了框架，
本仓要业务自己搭 State 子类。若要继续优化 DX，这是唯一值得投入的方向 ——
但要注意回调 arity 的限制（调用方固定传 N 参、回调少声明参数在 AOT 炸，
已有 `callback-arity` lint 守；见 §5 判死条件），不能照搬 SwiftUI 的闭包 phase 形态。
★ 2026-11-16 订正：原句的「`?Obj` 限制」已过期 —— 上游 #183/#184 已解禁 `?Obj`
形参/返回值与链式（见 `AOT-CONSTRAINTS.md` §9）；字段层「非空+bool」约束不变，
但理由是 `?->` 静默坑而非类型退化。不影响本条结论，但影响 §4 若干条方案的成本估算，
详见 [`PLAN-异步优化方案-2026-11-16.md`](PLAN-异步优化方案-2026-11-16.md)。

### 2.4 网络图加载（本轮新增的异步资源控件）

| 能力 | 本仓 `Image::asset()` | Flutter `Image.network` | SwiftUI `AsyncImage` | Coil/SDWebImage |
|---|---|---|---|---|
| 入口 | **本地/网络同形**（一个 `asset()`） | 网络/本地/资源三个命名构造 | 只管网络 | 只管网络 |
| 解码尺寸 | 有 width→`ceil(w×2)`；无→2048 上限 + 诊断 | `cacheWidth/cacheHeight` **要业务自己算**（`×(devicePixelRatio)`） | `scale` 参数（默认 1） | 自动按 view 尺寸（Coil） |
| 磁盘缓存 | **内置**（`md5(url)` 命名，双层） | **无内置**，要加 `cached_network_image` | 依赖 `URLSession` 的 `URLCache` | **内置且成熟** |
| 内存缓存 | `ImageLoader::$cache[resolved@width]` | `ImageCache` 全局 LRU + `maximumSizeBytes` | `URLCache` memory 段 | 内存 + 磁盘双层 |
| 三态 | **内置**（1×1 占位 + DIAGNOSTIC，不闪 loading） | `loadingBuilder`/`errorBuilder` **要业务写** | `phase` 三支 **要业务写** | `placeholder`/`error` **要业务写** |
| 失败可观测 | `Errors::report(DIAGNOSTIC)`，不弹框 | 依赖业务 builder | phase.failure，依赖业务 | 依赖业务 |

**判断**：**这一功能本仓 DX 最好** —— 业务一行 `asset($url)` 就拿到下载 + 双层缓存 +
三态降级，比需要自己拼 `cached_network_image` + `memCacheWidth` + `placeholder` +
`errorWidget` 四件套的 Flutter 省得多，比 SwiftUI 的 phase switch 也省。
关键取舍是**「不给 width 用 2048 上限 + 诊断」而非拒绝或必填**：
一手资料证实 Flutter 社区的头号性能坑就是「不设 `cacheWidth` 导致 4000×3000 解出 33MB」，
本仓用安全上限替业务兜底，是**把成熟框架的坑提前填掉**，与 `Image` 类注释里的 OOM 前科一致。

**但要诚实标注差距**：
- 没有全局字节预算（Flutter `imageCache.maximumSizeBytes`、URLCache 的 disk/memory 上限）。
  2048 单张上限挡住了单图 OOM，挡不住「同屏 50 张网络图」的累积。
- 缓存键是 `md5(url)`，签名 URL / 带 token 的 URL 每次都 miss；
  Flutter `cached_network_image` 有独立 `cacheKey` 参数正对此。
- 来源扩展是 `ImageLoader::classify()` 的封闭 if 链，不是 `ImageProvider` 那种开放接口。
- 失败/占位不可定制（固定 1×1 透明）；成熟框架允许业务给 `errorWidget`。本仓的取舍是
  「不给业务选择负担」，代价是需要自定义错误图时得改框架。

### 2.5 取消

- **本仓**：三档能力声明 `CancelSupport`（`AlreadyDone`/`DetachedOnly`/`QueuedOnly`），
  `Future::cancel()` 沿链上溯剪枝，`CancelSupport::describe()` 给单一文案。
- **Kotlin**：结构化并发，取消沿父子树确定传播，`NonCancellable` 做清理。
- **Flutter**：`Isolate.kill()` / `CancelToken`（第三方），无统一抽象。

**判断**：`CancelSupport` 的**能力声明是本仓原创且正确** —— 把「cancel 到底能停到哪」
从文档变成编译期必答，比 Kotlin/Flutter 的默认形态更诚实（它们把「尽力而为」藏起来）。
但取消传播本身本仓**弱于 Kotlin**：没有父子作用域树，靠「一条根一个主人」的纪律，
一条根被两处扇出就会互相剪掉。这是我在 §0 说「建议补框架能力」的唯一处 ——
可考虑在 `AsyncScope` 外再给一个显式的作用域容器，让「同一批任务的取消」有确定边界，
而不是要求业务记住「复用结果、不复用 Future」。

### 2.6 失败分类

- **本仓**：`Failure` 四个稳定 code + `Failure::codeOf()` 唯一提取点 +
  `AsyncValue::code()` / `AsyncCommand::mapError()`（对齐 RTK Query 的
  `transformErrorResponse`）；跨进程 code 不丢。
- **RTK Query**：`error` 是序列化对象，`transformErrorResponse` 映射到业务语义。
- **Kotlin**：异常类型本身跨线程保留（同进程）。

**判断**：本仓在**跨进程不丢分类**这点上是必需的（`codeOf()` 是边界归一），
`mapError()` 的设计与 RTK Query 同思路，方向对。差距是四个 code 全是框架级
（超时/进程死/后端不可用/任务被拒），**业务级 code（404/鉴权/限流）要应用自己映射且自己定义字符串**，
没有像 RTK Query 那样成为 endpoint 声明的一部分。

### 2.7 进度

- **本仓**：`Future::reportProgress()` 只喂根任务；派生链挂 `onProgress()` 留
  `progress/derived-chain` 诊断；`AsyncScope::await($future, $onProgress)` 把订阅收进入参。
- **WPF/Qt/JavaFX**：进度是一等公民（`IProgress<T>`/`QFutureWatcher`/`Task`），沿任务公开。
- **Flutter/SwiftUI**：`loadingBuilder` 的 `LoadingProgress` / phase 不细分进度。

**判断**：本仓把「进度挂错链」从静默变成诊断，并把它收进 `await()` 入参让顺序正确 ——
这是一个**针对自身坑的精巧解**。但它本质是「用诊断管理一个限制」（进度不沿链传播），
不如 Kotlin `Flow` / WPF `IProgress` 那样让进度自然是一等流。属于**工程上够用、架构上未收口**。

---

## 3. 优势清单（做对了什么，成熟框架也没皆做到）

1. **跨进程参数/结果契约机械化**：`FIELDS` 一份名单驱动 `toArray()` 与 `fromArgs()`，
   `NamedJobContractTest` 拿反射比「名单 == 构造签名」，键名分叉/顺序错/漏字段全变红。
   Flutter/SwiftUI 进程内无此难题，其它跨进程框架多靠手写 DTO 或运行时反射 ——
   本仓在 AOT 下不用反射（反射元数据 Partial），选了 tpc 正规路径，**罕见地稳**。
2. **静默失败全部可观测**：未接 `catch` 的任务失败走 Fatal；进度挂错链留诊断；
   未知 scheme 明确拒绝而非当本地路径；无服务降级占位 + 诊断而非崩。
   这套「宁可吵也不静默」的纪律比多数框架的默认态更严。
3. **取消能力显式化**：`CancelSupport` 三档 + 单一 `describe()` 文案，新增后端编译期强制回答。
4. **取消异常不被吞**：`AsyncScope` 的让出点闸门把 Kotlin 的纪律变成机制。
5. **核心任务零登记**：`CoreJobs::register()` 两侧自动合并，图片下载进框架而应用 registry 零改动 ——
   「框架能力不该变成每个应用的税」这条做得比多数框架彻底。
6. **网络图 DX 领先**：一个 `asset()` + 安全上限 + 内置双层缓存 + 内置三态，
   提前填掉 Flutter 社区最常见的 `cacheWidth` OOM 坑。
7. **有态叶子控件路径**：`createRenderObjectFromState()` 让 Image 从无态变有态而外部 API 零改动，
   兼容口 `createRenderObject()` 保住既有测试 —— 破坏性改造的兼容性处理得很干净。

---

## 4. 劣势与可执行建议（诚实差距，按优先级）

> 本节只给方向与理由，不落代码。是否动手由裁决决定。

| # | 差距 | 对照 | 建议方向 | 优先级 |
|---|---|---|---|---|
| 1 | 加载态→界面仍是命令式样板（`ImageState`/`resolve`/`apply` + `SubjectBuilder`） | SwiftUI phase、RTK Query hook | 评估一个**声明式资源控件基类**：把「来源判定→缓存→提交→三态」收进框架，`State` 只留一个可覆写的 `buildFor(AsyncValue)`。受回调 arity 约束（非 `?Obj`），不能直接照搬 SwiftUI 闭包 | 高（DX 主战场） |
| 2 | 取消非结构化，靠「一根一主」纪律 | Kotlin 父子作用域树 | 给 `AsyncScope` 之外加显式批次作用域容器，让「同批取消」有确定边界；至少把这条契约 lint 化（同一 Future 被两个 AsyncCommand 持有 → 诊断） | 高（唯一建议补机制处） |
| 3 | 无全局图片字节预算；`ImageLoader::$cache` 只增不减 | Flutter `maximumSizeBytes` LRU、URLCache 双层上限 | 给内存缓存加条目数/字节上限 + LRU 淘汰；与 2048 单图上限形成两级防线（挡住「同屏 N 张」累积） | 中 |
| 4 | 缓存键 `md5(url)` 对签名 URL 失效 | `cached_network_image` 的 `cacheKey` | 在 `asset()` 加一个可选 `cacheKey()`，进 `FIELDS` 传给 worker；不给时仍用 url | 中 |
| 5 | 图片来源封闭（if 链），非开放 provider | Flutter `ImageProvider` | 把 `classify()` 的三分类升级为「来源解析器注册表」，允许注入新 scheme 的 resolver；保留内置三者为默认注册项 | 中（扩展性） |
| 6 | 失败/占位不可定制 | `errorWidget` / Coil `error` | 在守住了「不给选择负担」的前提下，给一个**可选**的 `placeholder(Widget)` / `errorBuilder`，默认仍是 1×1 透明 | 低 |
| 7 | 业务级失败 code 无框架位置 | RTK Query endpoint 级错误映射 | 允许 `NamedJob` 声明自己的 code 命名空间，随跨进程载荷带回来，无需应用另建字符串常量表 | 低 |
| 8 | 进度「不沿链」是限制而非能力 | Kotlin Flow / WPF IProgress | 长期可评估让进度成为一等流；短期维持诊断 + `await()` 收口即可 | 低 |

---

## 5. 判死条件与不该照搬的部分

- **不该照搬 SwiftUI 的 `@unknown default` / 闭包 phase**：回调 arity 是硬约束
  （调用方固定传参、回调少声明参数在 AOT 炸），历史 REVIEW 已有「四个控件回调少一参
  → 真机整页错误占位」的 P0，现有 `callback-arity` lint 守。声明式 phase 闭包
  若落到 PHP 回调，必须守住完整签名，不能只看解释器绿灯。
  ★ `?Obj` 已于 2026-11-11 解禁（上游 #183/#184，形参/返回值/链式），
  这一条**不再**是「不照搬」的理由；仍成立的是 arity 与 `?->` 静默坑（字段层）。
- **不该照搬 Kotlin 的结构化并发到 Fiber 之上**：本仓 Fiber 是用户态、跨帧推进、
  每帧由调度器唤醒，不是 Kotlin 那种有父子 Job 树的运行时。加作用域容器应建在
  `AsyncScope`/`AsyncCommand` 层，不要改 Fiber 语义。
- **不该为对齐 Flutter `cacheWidth` 而把 width 变必填**：这与「业务只给地址」的诉求冲突，
  2048 上限 + 诊断是当前约束下的正确取舍；要补的是**全局字节预算**，不是把负担返还业务。

---

## 6. 验证口径（本次评审的取证入口）

```
# 框架侧异步原语
grep -n "function " src/Async/Future.php src/Async/AsyncScope.php src/Async/AsyncValue.php
grep -n "function cancelSupport" src/App/TaskHost.php src/App/*TaskHost.php

# 名单驱动与反射守契约
grep -rn "private const FIELDS" src/App apps/*/src
php tools/phpunit-run.php --filter NamedJobContractTest

# 异步资源组件
grep -n "function " src/Rendering/ImageSource.php src/App/ImageAssets.php src/App/CoreJobs.php
php tools/phpunit-run.php --filter AsyncImageTest

# 应用侧真实 DX
grep -rn "AsyncScope::task\|->await(\|->sleep(" apps/watermark/src/Store/WatermarkStore.php
```

> 本文件是快照式评审，记录 2026-11-16 的判断，不随后续实现回填结论。
