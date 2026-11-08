# 评审 · Drift3 蓝图 13「DX 收口与异步 UI 资源接口」对 Drift2 的可借鉴性

> 日期：2026-11-08 · 评审基准：Drift2 `d2fc77a`（§71）、Drift3 工作区当日快照
> 被评审：`D:/TypePHP/Drift3/docs/blueprint/13-DX收口与异步资源接口.md`（334 行，2026-11-06 11:41）
> **方法：对代码，不对分析稿。** 文档对 Drift2 的每条事实回查当前源码与本仓实测裁决；
> 文档自身的落地程度回查 Drift3 的 `src/` 与探针 RESULT；未跑 Drift3 探针、未改任何一仓代码、
> 未代 Drift3 拍板。
> 本文与 `docs/REVIEW-drift3-blueprint21-render-arch-2026-11-08.md` 是同一套评审口径，
> 与 `docs/ANALYSIS-watermark-async-ui-components-2026-11-08.md` 是同一个问题的续问。

---

## 0. 结论（先答「有没有借鉴的点」）

**有，但不是它主推的那几件，而且没有一行代码可以直接搬。**

| 层 | 结论 |
|---|---|
| 事实质量 | **这批蓝图里对 Drift2 引用最准的一份**。§1.2 的 8 条断言逐条回查：7 条属实、1 条措辞需收窄；`DownloadResultsJob.php` 精确 156 行、缓存键 `resolved@maxWidth` 一字不差。与 21 号「13 条里 6 条已过时」形成反差——13 号是「对代码不对稿子」写出来的 |
| 可迁移代码 | **0 行**。接口规格（`ResourceSource` / `ResourceHandle` / `AsyncResource`）在 Drift3 自己都还是惰性骨架，无控件消费、未过 AOT |
| 可迁移设计 | **1 条新论证路径**（不是设计本身）：§3 的「单一入口 + 框架解析来源」提示，重裁 §23.2 未必需要推翻 §18，也可以走「框架内资源层 + 组合根 + 帧泵回注」 |
| 可迁移工程纪律 | **2 条**：① 把「按原图尺寸解码」做成可机械检查的门禁（本仓唯一真空白）；② 「同名不同义」的命名登记纪律 |
| 反向价值 | **1 条权威旁证**：文档 §1.4 用 Flutter `ResizeImageKey` 确认本仓 `ImageLoader` 的 `resolved@maxWidth` 是业界同款、「不是土办法」——这给本仓已有做法补了外部依据，不是新能力 |

**一句话**：它是一份写得很扎实的「契约层设计稿」，但它的核心承诺「业务写法一个字不改、心智负担 0」在 Drift3 自己都还没有控件层来兑现；对 Drift2 而言，值得拿回的是**一条预防性门禁**和**一条重裁思路**，不是任何组件或接口。

---

## 1. 文档定位与证据时效

### 1.1 它在链路里的位置

13 号是「第七轮配套」，自称**不是新事实源**：设计以 08、能力面以 11、签名以 12、未决项以 09 为准；本文只做两件事——给 CAP-9/6 首版做 DX 缺陷对账，回答「异步 UI 组件要不要预留接口、留成什么形状」。§7（第九轮）、§8（第十轮）是后追加的轮次记录。

它主推的裁决是 §0 / §3：

> **预留要做，但只留框架侧的资源来源与句柄形态，一律不新建 UI 组件类、不新增组件语法。**
> 业务写法保持 `Image::make()->asset($src)->width(...)` 不变 —— 本地路径与网络 URL 走同一个入口，
> 由框架解析来源。音频/视频只在资源种类里留常量位，不设计播放器 API。

这条正好落在上一棒的问题上：用户问「watermark 心智负担大，剩下的是不是因为缺少异步 UI 组件，有了是不是就不用自行实现了」。13 号给出的答案是「不用造组件，只要把 `asset()` 的入参放宽到 URL，框架自己解析」。本评审要判的就是：**这个答案对 Drift2 成不成立**。

### 1.2 文档自身的四处腐烂（借鉴时必须交叉最新版）

13 号是「多轮叠加的活文档」，前半的「现状 / 挂账」栏没随后续轮次回写：

| # | 文档里的说法 | 当日实际 | 影响 |
|---|---|---|---|
| R1 | §4.3 来源判定表：`http(s)` 路由到 `drift/net`，现状栏写「**未建立，挂账**」 | §8 自己记「第十轮首次建立 `src/Drift/Net/`」；当日 `src/Net/` 有 13 个文件（`CurlIoSource` / `QueryCache` / `RequestTransport` 等） | 同一份文档内两处「现状」互斥 |
| R2 | §5 挂账 1：`Cell` 已删、`Reactive` 不存在 ⇒ `AsyncValue` 依赖表接线挂账，「**不得宣称可观察单元已闭合**」 | `probe/cap-landing/RESULT.md` §17.1（第二十一轮）已裁决：**`Cell` 不重建，`AsyncValue` 自己实现 `ObservableSource`**，并用 `revision()` 做相等门控（同值 settle 不涨 revision） | 挂账已被后续轮次闭合，文档未回写 |
| R3 | §5 挂账 2：`when()` 闭包 vs 命名构建函数「两处口径待拍板，本轮不实现」 | RESULT §17.2 已裁决命名构建函数；`src/Ui/AsyncStateView.php` / `When.php` 已落地，`When::render()` 四态 `match` 无 `default` | 同上 |
| R4 | §6 证据「30/30 PASS」、§7「38/38」、§8「45/45」 | RESULT 已到第三十八轮 `[SUMMARY] pass=135 fail=0 overall=PASS`、`lint 79 files / 0 errors` | 数字是 11-06 快照，不是当日状态 |

**但 §5 挂账 6 / 7 仍成立**：RESULT §20.5 记「P-D / P-G 仍 `NOT RUN`；63 个类未过 `tpc --dry`」，最新几轮反复重申。⇒ 这篇文档的「诚实边界」部分没有腐烂，腐烂的是「现状描述」部分。

> 这与 21 号评审发现的病症同型（证据快照过期），但方向相反：21 号是**拿 Drift2 的旧坑做排除法**，13 号是**拿自己的旧状态做现状栏**。借鉴 13 号时不能只引 §3–§5，必须交叉 `RESULT.md`。

---

## 2. 事实核查：§1.2 对 Drift2 的 8 条断言

对当前 HEAD `d2fc77a` 逐条回查：

| # | 13 号断言 | 核查结果 | 证据 |
|---:|---|---|---|
| 1 | `Image` 有两个源：`asset(path)` / `image(ImageHandle)` | ✅ 属实 | `src/Widget/Image.php` 类 docblock 与两个方法 |
| 2 | 解码在 `createRenderObject()` 同步做，靠「解析后绝对路径 + `@maxWidth`」缓存 | ✅ 属实 | `Image.php` 的 `createRenderObject()`；`src/Rendering/ImageLoader.php` 的 `load()` 里 `$key = $maxWidth > 0 ? $resolved . '@' . $maxWidth : $resolved` |
| 3 | `maxWidth = ceil(逻辑宽 × 2.0)`（200% DPI） | ✅ 属实 | `Image.php` 的 `SUPERSAMPLE = 2.0` 与 `decodeWidth()` |
| 4 | 纯 PHP 解 1792×2390 实测 6.6s / 92MB、UI 线程 OOM 崩在 shutdown | ✅ 属实 | `src/Rendering/ImageDecoder.php` 类 docblock 原文（含「任务完成后程序自己消失、图没显示」的症状） |
| 5 | 失败降级 = 1×1 透明占位，不抛异常 | ✅ 属实 | `ImageLoader::placeholder()`；`Image` 构造器用非空初值避免 `?ImageHandle` 在 AOT 下退化 |
| 6 | `ImageHandle` 持有整张 RGBA 字符串 | ✅ 属实 | `src/Rendering/ImageHandle.php` 的 `private readonly string $rgba` + `rgba()` |
| 7 | 没有网络图；`downloadUrls` 必须先下到临时文件；`DownloadResultsJob.php` 156 行 | ✅ 属实 | `wc -l` 精确 156 行；该 Job 类注释自陈「`Image` 控件只认本地路径或内存句柄」 |
| 8 | 没有 Audio / Video 控件（全仓 grep 零命中） | 🟡 **结论成立、措辞过宽** | `src/` 与 `apps/watermark` 确无控件文件，但 `src/App/Io/Http.php` 的 MIME 表有 `'mp4' => 'video/mp4'` / `'mov' => 'video/quicktime'`。应写成「无媒体控件与播放状态机」，而非「零命中」 |

⇒ **7 属实 / 1 收窄 / 0 过时**。这一点值得明确肯定：13 号 §1.2 的表头写着「Drift2 真实实现（已过 AOT）」，每条都给得出位置，甚至反过来用 Drift2 的实测数字给自己的设计做止损依据（§1.4 排坑表）。这是本仓「对代码，不对分析稿」纪律的同款做法。

---

## 3. 核心碰撞：§3「单一入口」对上本仓 §23.2 判死

这是本次评审最该说清的一段，因为它直接决定「用户上一棒的猜想」能不能被 13 号救活。

### 3.1 上一棒已经判过的东西

`docs/ANALYSIS-watermark-async-ui-components-2026-11-08.md` §5 复核了 `docs/DEBT-2026-11-02.md` §23.2（2026-11-03）：`Image::url()` 异步缓存**已判死**，四条路全堵——

```text
① BuildContext 向上查找   —— §18 明文否决（无 of()/find()/maybeOf()）
② 全局 AppScope           —— 2026-10-30 已撤回"服务定位"推荐
③ 全局 TaskHost 静态      —— 等于第 39 个静态字段
④ 每个调用点传 host       —— 比现在手写下载更啰嗦
```

外加两道跨进程税（`NamedJob` 要在每个应用登记；图片字节不能过管道，只能 worker 落临时文件回传路径）。§30 / §33 / §66 三次复核逐字写「不重开 `Image::url()`」。上一棒的结论是：**在本仓进程模型下，组件化唯一可行的形状恰好等于现有的 `DownloadResultsJob`**，差别只是代码写在 `apps/` 还是 `src/`。

### 3.2 13 号确实给出了「不用推翻 §18」的另一条路

§23.2 写下的重裁前置是「**给不给 Element 树一个可继承的作用域**（Flutter `InheritedWidget`）」，那要推翻 §18 ③。

13 号走的是完全不同的方向：它**不让 Widget 自己去要数据**。

- `ResourceSource::parse()` 是 `@drift-internal`，`origin` 由框架按 `locator` 前缀判定，**业务不传**；
- `AsyncRuntime` 是显式组合根，构造里一次 `new` 齐 20+ 个内部组件（`FrameStats` / `CompletionQueue` / `SingleFlight` / `Poller` / `FramePump` …），业务拿不到内部对象；
- 分层图（§4.1）是 `createRenderObject()` **只读 `AsyncValue` 四态**，就绪由帧泵「③ drain 唯一回注」，网络层只能投 `Pending`、不得直写 `AsyncValue`；
- §2 D6 把 `register(..., bool $external)` 判为缺陷，理由正是「开发者不选执行体」。

⇒ 也就是说，「谁来下载」这个把 §23.2 卡死的问题，13 号的答案是**框架内部资源层 + 组合根 + 帧泵回注**，压根不需要 `BuildContext` 向上查找、不需要全局静态、不需要每个调用点传 host。**四条堵点里前三条被绕开了，不是被推翻。**

这条论证对本仓是有价值的新信息，应当写进重裁触发条件：将来若要重开「网络图」，前置不必是「推翻 §18」，也可以是「先具备框架内资源层 + 组合根 + 回注通道」。

### 3.3 但药方仍不可直接搬（四条硬约束）

| # | 约束 | 本仓现状 |
|---:|---|---|
| 1 | 组合根要能触达 `Image` | `Image` 是 `RenderBoxWidget`，`createRenderObject()` 是纯函数、无 State、无订阅。要读四态就得给它加 State + 订阅 + `markDirty()`，等价于把业务层 Store / `SubjectBuilder` 那套搬进 `src/` |
| 2 | 要有网络层 | `drift/net` 在 Drift3 自己也是 §8 才建骨架、`QueryCache` 签名张力还挂着；本仓只有 `Job` + `ProcessTaskHost` + `NamedJob` 跨进程序列化 |
| 3 | 跨进程图片字节 | §23.2 第二道税不变：字节不能过管道，仍得 worker 落临时文件回传路径 ⇒ 仍然退化成 `DownloadResultsJob` |
| 4 | 回注要驱动重绘 | 本仓 `AsyncValue` 是值对象（§69 给它登记了 `IDENTITY_STABLE`，供 `select-purity` 用），不是可观察源；Drift3 的 `AsyncValue` 是 `ObservableSource` + `revision()`。要在控件里用，得先有依赖接线 |

### 3.4 而且 13 号自己也没兑现「心智负担 0」

这是最该防的一点：**§3 的承诺目前是纸面的**。

- `src/Ui/` 当日只有 3 个文件：`View.php`（**空标记接口**，注释写明「渲染层 M2 没落地，任何成员都会把未验证的形状钉进事实源」）、`When.php`、`AsyncStateView.php`。**没有 `Image` 控件，没有渲染层。**
- `ResourceSource` / `AsyncResource` 的消费者只有 `AsyncRuntime` / `ResourceHandle` / `Net/QueryKey` 与两个探针（`cap-landing/smoke.php`、`l0-coverage/main.php`）——**没有任何控件层**来把 `asset($src)` 解析成 `ResourceSource`。
- `AsyncResource::images()` 类注释直白写着：「**本轮只做声明式惰性句柄工厂**……**不下载、不解码、不碰 IO、不建 UI 控件**」；返回 `ResourceHandle(0, $requests)`，`slotId = 0`，注释说「本阶段不预分配槽位」。
- 更自洽但更说明问题的一点：它产出的每个 `ResourceSource` 都是 `targetWidth = 0`，即 `isSized() === false`（受禁默认）；类注释说「控件层必须 `withTargetWidth()` 补上由 `->width()` 推导的尺寸才允许加载」——**而那个控件层不存在**。§4.2 说 `ResourceHandle` 立即返回 `status=LOADING`、就绪靠 drain 翻 `READY`，同样没有消费者。

⇒ 所以「业务写法一个字不改、来源由框架解析、心智负担 0」是**契约层的设计意图**，从没被任何 Widget 调用过，更没上过真机。上一棒对 Drift3 整体的判断（零行代码可迁移、只借工程纪律）在资源接口这一块得到具体印证。

### 3.5 重裁触发条件（更新上一棒 §8）

旧条件写的是「给不给 Element 树可继承作用域（推翻 §18 ③）」。补一条并列路径与三条门槛：

```text
路径 B（不推翻 §18）：框架内资源层 + 显式组合根 + 帧泵回注通道
门槛 ① Drift3 侧出现真实 Image 控件并跑通 ③ drain 回注（不是惰性句柄）
门槛 ② 该控件过 tpc --dry / AOT 真机，P-D、P-G 不再是 NOT RUN
门槛 ③ 跨进程图片字节方案有真机判据（不能只有"落临时文件"的口头结论）
且：仍先用小业务试，不拿 watermark 当首个样本。
```

---

## 4. 逐项可迁移性裁决（结构 / 实现 / 扩展性 / DX / 维护性）

按「本仓有没有、能不能直接用」分四组。这一节刻意先查了全部 33 条 lint 规则、`Pipeline`、`JobProgress`、`gate.php` 与 `smoke.php` 相关判据——上一棒评审 21 号就是漏查 `smoke.php` / `Pipeline.php` 才把已闭环的 `pairChecks` 误判成「唯一真缺口」。

### 4.1 A 组：本仓已有，不借代码，但拿到外部旁证

| 项 | 本仓现状 | 价值 |
|---|---|---|
| §1.4 `cacheKey()` 含 `targetWidth`（Flutter `ResizeImageKey`） | `ImageLoader::load()` 的 `resolved . '@' . maxWidth`；注释明写「它进缓存键 —— 同一文件按不同宽度解出尺寸不同的 `ImageHandle`，共用一个键会拿到错的那份」 | 13 号 §1.4 原文认可这是「业界同款，不是土办法」，给本仓已有做法补权威依据。**属于信心，不属于能力** |
| §1.4 缓存命中当场 `READY`、不闪一帧 loading（Coil 首帧 `Empty` 坑） | 同步解码 + 命中即返回 handle，压根没有 loading 态 | 本仓天然免疫；不借 |
| §1.4 请求对象每次重建 ⇒ 重发请求（Coil `ImageRequest` 未 remember） | `Image::createRenderObject()` 每帧调 `load()`，靠 `resolved@maxWidth` 兜住；`tests/Feature/Rendering/ImageLoaderTest.php` 用 `clearCache()` + `cacheSize() === 1` 钉住「第二次不再解码」 | 同族坑已有判据；不借 |
| §4.3 按前缀/首字符判定来源 | `ImageLoader::isAbsolute()` 判 `/`、`\`、`X:` 三类，`resolve()` 对绝对路径原样用、不进候选拼接 | 同族做法已有；不借 |
| §4.5 失败走 1×1 占位 + 诊断留痕 | `ImageLoader::load()` 找不到 / 解不出都 `Errors::report(..., DIAGNOSTIC)` 并返回 `null`，控件用 `placeholder()` 顶上 | 已有；不借 |

### 4.2 B 组：本仓已有，特别标注以防重复造（上一棒的教训）

| 13 号条目 | 本仓对应 | 判定 |
|---|---|---|
| §7 **「末件必落地（否则永远停在 99%）」** | `src/App/JobProgress.php` 的 `report()` 注释原文：「**`1.0` 永远不被节流吞掉**……吞掉它，界面上进度条就永远停在 99% —— 一个只在『最后一步很短』的任务上出现的 bug」；`MIN_STEP = 0.01` 节流、倒退/同值吞掉、`bind()` 重置节流态。另有 `tools/Lint/Rules/AwaitProgress.php`：会 `report()` 的 Job 被 `await()` 时必须传第二参订进度 | **2026-11-02 已实现，理由与 13 号同文**。不借 |
| §2 D2 计数「只由框架产生、可审计」⇒ private + `note*()` | `src/Core/Pipeline.php` 的计数全是 `private int` + `bump*()` + 只读 getter；§23 记「D3 把 38 个静态字段搬进 Pipeline」 | 已有。顺带一提：Drift3 落地后的 `FrameStats` 字段确已 private，但 `note*()` **仍是 public**，业务照样能调 ⇒ 它其实只是命名约定收口，不比本仓更强 |
| §2 D3 「使用方自己 new 11 个对象手工接线」⇒ 组装根 | `WatermarkApplication` / `Application` 已是组合根；本仓无此缺陷 | 已有 |
| §2 D1 `generation` 禁词；整文件 `@drift-framework` 绕过 lint ≠ 业务合法 | 本仓业务面无 `generation`；豁免走 `@internal-use-allowed: 理由` / 行内 `@...-allowed:` 显式带理由并**降为 warn**，`tools/Lint/Rules/NonBoxDecoration.php` 明写「豁免必须写清为什么这是有意的，否则规则会被注释淹没」 | 本仓纪律**更严**；不借 |
| §2 D7 四态 `AsyncValue` | `src/Async/AsyncValue.php` 有 `idle/loading/data/error` + `fold()` 穷尽四支 + `carriedFrom()`；`apps/users` 已用 `fold()` 删掉手写 `LoadStatus` | 已有；上一棒推荐把下载段五字段收成一个 `AsyncValue` 值 |
| §8.2 「已接探针 ≠ 已进构建链」 | `tools/gate.php` 真接 `lint.php` 与 `lint.php --selftest` 两项（18 项闸门中的第 2、3 项） | 已闭合 |
| §5 七条诚实挂账 | 与本仓「诚实边界」同纪律 | 已具备 |

### 4.3 C 组：诊断对、药方不可用

| 项 | 诊断 | 为什么药方不能搬 |
|---|---|---|
| §1.4 **全局单例缓存**排坑（缓存挂 `AsyncRuntime` 组合根） | 本仓 `ImageLoader::$cache` 确是 `private static array`，**只有 `clearCache()` 全清、无淘汰无上限**。watermark `ResultCard` 的 `PREVIEW_WIDTH = 560` ⇒ `maxWidth = 1120`，一张 1120×1494 RGBA ≈ 6.7MB；批量结果图累积顶 128M 上限，正是 `ImageDecoder` 记过的血账族 | 药方要求「有组合根可挂」，而本仓 `Image` 是纯函数控件、无 State、无向上查找（§18）。要治得自己定上限 / LRU + 落判据，不能引 Drift3 的形状 |
| §1.4 `ImageHandle` 改 `int` 槽位（不持 RGBA） | 本仓确持整串 RGBA | Drift3 自己 `ResourceHandle` 的 `slotId = 0`「本阶段不预分配槽位」；本仓改需 C++ SkImage 管理 + AOT 验证，重活且无消费者 |
| §1.4 `scopeId` / `ScrollAwareImageProvider` 离屏取消 | 本仓无作用域树、无 viewport scope | 与 §18 同族，引入成本高；且 Drift3 §5 挂账 4 明写「作用域树 / Guard 传播 / `ViewportScope` / 递归取消未实现」 |
| §1.5 PHP 8.6 `Io\Poll` RFC 的「底层 IO 唤醒」 | 分层判断写得准（`epoll_wait` 家族 ≠ 业务周期轮询） | 能力不可用：8.6 未发布、`class_exists('Io\Poll') === false`、AOT 运行时随发行版。本仓 `IoPoller` 已用「IO 先非阻塞 + 每帧 `select(0)`」解决同一问题（实测 Swoole `Event::dispatch()` 单帧 57ms、Fiber 不让出阻塞 IO） |
| §4.4 AUDIO / VIDEO 只留常量位 | 与本仓「零消费者不发明机制」同纪律 | 本仓 `src/App/Io/Http.php` 已有 video MIME，但无控件；留常量位对本仓没有落点（`ResourceKind` 本身就不存在） |

### 4.4 D 组：真正值得拿回的两件

只有两件，且都不是 13 号主推的接口规格：

1. **把「按原图尺寸解码」做成可机械检查的门禁**（§5 展开）。
2. **「同名不同义」的命名登记纪律**（§6 展开）。

---

## 5. 唯一值得落地的一件：无尺寸解码门禁

### 5.1 这不是「已有但漏查」

先按上一棒的教训把清单扫全：

- `tools/Lint/Rules/` 当日共 33 条规则，**没有任何一条**引用 `Image::` / `asset(` / `ImageLoader` / `ImageDecoder`；
- `src/Rendering/ImageLoader.php` 的 `load()` 在 `$maxWidth = 0` 时**没有任何诊断**，直接走 WIC 原尺寸解码；
- `src/Widget/Image.php` 的 `decodeWidth()` 在 `width <= 0` 时**静默 `return 0`**；
- `tests/Feature/Widget/ImageTest.php` 只钉住自然尺寸与比例缩放，没有「缺 width」的形状断言。

⇒ 确实没有这道防线。

### 5.2 但不能照搬 `isSized()` 的「禁令」

13 号 §1.4 / §4.2 把 `targetWidth = 0` 定为**受禁默认**，「`0 = 原尺寸` 是明确禁止的默认，不得作为省事路径」，并用 `isSized()` 供诊断/lint 判定。

**直接搬会破坏本仓既有的布局语义**：

- `Image` 的 `width` / `height` 都允许 `<= 0`，语义是「用原图尺寸 / 只给宽则高按比例推」；
- `ImageTest::testNaturalSizeAndProportionalScaling()` 明确钉住「自然尺寸 = 原图像素尺寸（64×64）」与「只给宽 48 ⇒ 高按比例 = 12」；
- 注释写明「`width <= 0` ⇒ 尺寸由原图决定 ⇒ **不能在解码期缩**」。

⇒ 在 Drift2 里 `0` 不是「省事路径」，是**按内容定尺寸**的合法语义。要借的是「**可机械检查**」这个手法，不是「0 非法」这个结论。

### 5.3 建议形状（方向，**未实现、未验证**）

```text
① 静态侧：lint 识别 `Image::make()->asset(...)` 链上没有 `width()` 的调用点，
   对非测试夹具给 warn（或至少进 DIAGNOSTIC），理由写「大图按原尺寸解码有真机 OOM 血账」。
② 运行期侧：`ImageLoader::load($path, 0)` 入口留一条 DIAGNOSTIC，
   让"谁没给宽度"在真机日志里可追溯（现有两条 DIAGNOSTIC 都是"找不到/解不出"，
   没有"解了但按原图"）。
③ 不禁止 0，不改 `decodeWidth()`，不动 `ImageTest` 的自然尺寸语义。
```

**当前无真实违规**：全仓 `asset()` 调用点逐个看过——showcase 5 处全带 `width()`/`height()`，watermark 两处是跨行链式（`ImageThumb` 的 `SIDE = 110`、`ResultCard` 的 `PREVIEW_WIDTH = 560`），测试 2 处（其中一处故意取不存在的图测占位）。所以这是**预防未来**，不是修现有 bug，优先级低于 §70.5 列表里的欠账。

**判死条件（本仓变异四律，必须先证明）**：写一条变异——把某个 `asset()` 链上的 `width()` 删掉——必须真响；恢复必须绿；判据不能与实现同源。找不到能咬红的变异就不上仓，否则又是一条恒绿空壳。

---

## 6. 命名纪律：同名不同义（近零成本）

13 号 §1.5 有一条容易被忽略但很实用的纪律：

> `Drift\Async::poll`（业务周期原语）与 `Io\Poll\Context`（内核 IO 就绪）**同名不同义**。
> 文档、lint 与 `api-surface.json` 里必须靠命名空间区分，不得把两者写成同一个「poll 能力」。

本仓**真实存在这个碰撞**：

| 名 | 语义 | 位置 |
|---|---|---|
| `IoPoller::poll()` | 每帧 `select(0)`，问「流就绪没」，UI 线程帧内执行 | `src/App/Io/IoPoller.php` |
| `WatermarkStore::pollLoop()` | 业务周期轮询：每 10s 查服务端任务状态、`while(true)` + 上界退出 | `apps/watermark/src/Store/WatermarkStore.php` |
| `InputSource::poll()` / `Win32InputSource::poll()` | 输入事件出队 | `src/Platform/` |
| `pollConstraintDrift()` | 布局约束漂移检查 | 三个 State 类 |

`docs/API-SURFACE.md` 里只登记了 `IoPoller` 类与 `watch()` / `unwatch()`，`poll()` 甚至没进公共面。⇒ 建议在架构文档（或 `IoPoller` 类 docblock 已有的「三者分工」那段）补一句显式区分「IO 就绪 / 业务周期 / 输入出队」，成本近零，能防「看到 `poll` 就以为是同一个能力」这类误判。§68 给 `Config` 加的 `@design-param` 口径标注是同族做法。

**明确不借的部分**：`Io\Poll` 能力本身（PHP 8.6 未发布、AOT 运行时随发行版）、`Backend::Auto` 三档自动选（与 `IoPoller` 的非阻塞 + 每帧轮询不是一层）、`Watcher::getData(): mixed`（本仓 AOT 下 `mixed` 擦除）。

---

## 7. 诚实边界

- 本文**只读**：未改任何产品代码、未跑 Drift3 探针、未跑本仓 gate / 真机；因为没改代码，也没有新的回归证据可给。
- Drift3 侧只核了 `src/` 文件清单、`ResourceSource` / `AsyncResource` / `FrameStats` / `AsyncRuntime` 的源码与 `RESULT.md` 的章节标题，**没有复跑 135 条断言**，也没有核 `tpc --dry`；引用其「PASS / NOT RUN」是转述文档记录。
- 对 Drift2 的事实核查基于 HEAD `d2fc77a`（2026-11-08），§5 的「无门禁」结论基于当日 33 条 lint 规则清单；若后续新增规则需重核。
- §5、§6 是**方向建议**，未做实现前分析、未做变异验证、未评估 T2 影响，不等于已排期。若真要落地，按本仓纪律另开一棒：先出方案稿 + 判死条件，再实现。
- 不代 Drift3 拍板：§3 / §4 的接口规格是它自己的裁决范围，本文只判「对 Drift2 能不能借」。
- 与上一棒的关系：`docs/ANALYSIS-watermark-async-ui-components-2026-11-08.md` 回答「组件能不能减负」，本文回答「13 号蓝图有没有可借的」；两者对 §23.2 的判死结论一致，本文只**补充了一条新的重裁论证路径**（§3.5），不推翻原结论。

---

## 8. 一句话回答

13 号是一份**事实扎实、纪律干净、但核心承诺尚未兑现**的契约层设计稿：它对 Drift2 的 8 条引用几乎全对，还反过来给本仓的 `resolved@maxWidth` 缓存键补了 Flutter 的权威旁证；但它主张的「单一入口、框架解析 URL、心智负担 0」在 Drift3 自己都还没有 `Image` 控件来消费，对 Drift2 更撞在 §23.2 的结构前置上。**值得拿回的只有两件小的**——把「不给宽度就按原图解码」做成可机械检查的门禁（本仓唯一真空白，且必须证明变异能咬红），以及把 `IoPoller::poll()` 与 `pollLoop()` 这类同名不同义显式登记；其余四态原语、末件落地、计数私有化、组合根、缓存键含尺寸，本仓早已有或早已判死。
