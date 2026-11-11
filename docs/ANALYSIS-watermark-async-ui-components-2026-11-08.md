# watermark 的「心智负担」是不是因为缺异步 UI 组件

> 2026-11-08 · 基准 `d2fc77a` · 只读分析，未改任何产品代码、未跑新真机
>
> 用户追问：「之前说的 watermark 心智负担比较大，一个是异步的实现，剩下的是不是因为
> 缺少异步 UI 组件（图片 / 音频 / 视频的异步加载组件），如果有了这些是不是就不用自行实现了，
> 结合成熟 GUI 的做法详细分析」。
>
> 方法：**对当前代码，不对上一棒的结论**。本仓事实回查 `apps/watermark/`、`src/`、
> `docs/DEBT-2026-11-02.md`；成熟 GUI 事实取官方文档与权威实现描述。
> 与 `docs/ANALYSIS-watermark-async3-borrow-2026-11-07.md` §10 是同一条追问链，
> 但问的不是「换异步范式」，而是「补一类组件」。

---

## 0. 结论（四层，别压成一句）

1. **直觉抓对了位置，没抓对比例。** watermark 里确实有一整块是「网络资源没被组件化」逼出来的：
   `DownloadResultsJob` 156 行 + Store 下载段约 100 行 + `ResultCard::imagesSection()` 里手写的
   四态分支约 45 行。它们存在的唯一理由，`DownloadResultsJob` 的类注释自己写得很清楚：
   「`Image` 控件只认本地路径或内存句柄 —— 它不会发 HTTP」。
2. **但这块只占约 8%，而且「业务不写了」不等于「代码与心智负担消失了」。**
   全量 3717 行里，它最多覆盖约 300 行；框架侧要补的通用组件、缓存淘汰、尺寸决策、
   跨进程协议、生命周期取消，只会更多不会更少。§23 那句「净代码量只减约 5 行，交付的是
   整类静默 bug 消失」是同一条纪律：**把代码换个目录不算减负**。
3. **「音频 / 视频」是错的类比。** watermark 是图片批处理，音视频零消费者；框架也没有
   媒体栈。更关键的是成熟 GUI 里音视频从来不是「异步加载组件」，而是播放器状态机 +
   硬件解码 + 时钟同步，与图片组件不同类。用户是把「不阻塞 UI 线程」这个唯一共同点
   当成了同一类能力。
4. **本仓在 2026-11-03 §23 已经把 `Image::url()` 异步缓存判死**，四条路（向上查找 /
   全局作用域 / 全局静态 host / 每调用点传 host）全堵，外加跨进程两道税；
   §30 / §33 / §66 三次复核都明写「不重开 `Image::url()`」。所以这不是「没想到」，是
   **想到了、验过、否了，且否的理由至今没变**。

⇒ 一句话：**缺的是「资源加载这一层的契约」，不是「三个控件」；而契约在本仓已有
`AsyncValue` 四态承担，真正便宜的减负是把它接到下载段，不是造 `NetworkImage`。**

---

## 1. 先把「心智负担」拆成可计量的东西

上一棒的 §8.4 列过 8 条隐性规则，还量化过「加一个业务动作要动约 8 处、没有一处会提醒你漏了」。
把那些规则按**来源**归一下，才是回答「剩下的是什么」的正确切法：

| # | 来源 | 本轮可核对的读数 | 一个「异步 UI 组件」能碰到吗 |
|---|---|---|---|
| A | **隐性契约**（错了静默） | §8.4 的 8 条：`catch (AsyncCancelled)` 顺序 / 闭包恰好 1 参 / `time()*1000` / `sleep()` 返回 false / `duplicated()` 只认 `===` / `localPaths` 键序 / 进度是 `await()` 第二参 / 三条消息无类型保证 | ❌ 完全碰不到 |
| B | **状态广播样板** | `SubjectBuilder` 29 行、`notifyChanged()` 29 次、Store 25 个 getter；`WatermarkStore` 833 → 现 837 行里 :155–:293 全是 getter | ❌ 碰不到（§69 的 `Equatable` 只修了值相等门控，没减行数） |
| C | **跨进程序列化样板** | `NamedJob` 13 / `toArray` 9 / `fromArray` 8 / Registry 5 项登记 | ❌ 反而会**增加**（新组件要登记自己的 Job） |
| D | **命令编排** | `Flow` 3 入口 + `Phase` 6 态 + `pollLoop()` + 上界退出 + 取消守卫 + 三条消息分派 | ❌ 碰不到。这是 mutation，不是资源 |
| E | **网络资源落地** | `DownloadResultsJob` 156 + Store 下载段约 100 + `ResultCard` 手写四态约 45 | ✅ **只有这一块** |
| F | **静态控件树** | `CardShell` 72 / `Palette` 136 / `UploadCard` 222 / `TaskCard` 148 / `MessageLine` 81 / `StatusBar` 98，卡片结构、文案、颜色、拖放区、按钮门控 | ❌ 与异步无关 |

⇒ **A–D 才是「心智负担」，E 是「代码量」的一小块，F 是业务固有。**
用户把两者混成「剩下的是不是缺组件」，正是这个错位：A 的特征是**错了不报、静默走偏**，
补一个控件不会让 `catch` 顺序、进度第二参、键序突然变得有判据。

---

## 2. 精确测量：一个「网络图片异步组件」到底能消多少

### 2.1 本轮口径

```
基准 d2fc77a，find apps/watermark -name '*.php' = 28 文件 / 3717 行
  Api       716   Jobs      477   Store    1057
  Widgets  1126   宿主/根   341
```

与 §10 的 3714 行差 3 行，正是 §69 的净增（`StatusMessage` +4 / `StatusBar` -1）；
§10 记 29 文件，本轮只数 `*.php`，口径差异不影响分组占比。

### 2.2 可覆盖清单（乐观上界）

| 文件 / 段落 | 行数 | 组件化后 |
|---|---:|---|
| `apps/watermark/src/Jobs/DownloadResultsJob.php` | 156 | 整文件可搬进框架 |
| `WatermarkStore.php` 下载字段 + 构造（:103–:115, :130） | ~12 | 由组件内部状态取代 |
| `WatermarkStore.php` 下载 getter（:244–:273） | ~30 | `localPaths/failedDownloads/isDownloading/downloadProgress/downloadMessage` 五个合并 |
| `WatermarkStore.php::downloadResults()`（:716–:778） | 63 | 编排搬进组件 |
| `ResultCard::imagesSection()`（:151–:230 共 80 行）中的 pending / failed / 总进度分支 | ~45 | 换成四态槽 |
| `JobRegistry.php` 中 download 一行 | 1 | 换成框架自带 Job 的登记 |
| **合计（上界）** | **~307** | **3717 行的 8.3%** |

### 2.3 这 307 行里，有多少是「组件不该替业务决定」的

`DownloadResultsJob` 不是「`Http::get` 一下」这么简单，它写满了业务策略：

- **一张失败不拖累别的**：返回 `['paths'=>…, 'failed'=>…]`，注释写明「原文是一张都不显示，
  因为一张失败就抛」——这是产品决策。
- **一批而非 N 个任务**：注释写明「N 张图起 N 个任务的话，UI 侧要自己维护 N 条在途 Future 的
  簿记 —— 那是本仓明令不要的第二套异步状态机」。
- **总进度**：每张图 `JobProgress::report(done/total)`，Store 订到 `await()` 第二参，界面画一条
  整批的 `Progress`。
- **超时分级**：结果图 `connectTimeout=10 / timeout=120`（「4K 图可能几 MB」），
  而 `Http` 默认是 5/10。
- **落盘键**：`md5(url)` 覆盖而不是堆积；扩展名先 URL 后 `Content-Type` 兜底 `jpg`。
- **展示顺序**：页面按 `lastResult()->downloadUrls` 取，不遍历 `localPaths` 的键序
  （`WatermarkStore.php:244–248` 注释：「键序是下载完成顺序，而用户要看的是服务端给的顺序」）。

⇒ 通用组件若吃掉这些，就成了「什么都管的胖组件」；若不吃，业务还得写，只是换个地方。
成熟 GUI 的选择是一致的：**组件只给契约与生命周期，策略留在业务层**（见 §4.7）。

### 2.4 框架侧新增成本（不是零）

按本仓现有纪律，一个能上生产的 `NetworkImage` 至少要补：

| 必补项 | 为什么绕不开 |
|---|---|
| 组件 + provider + 四态 | 现有 `Widget\Image` 是 `RenderBoxWidget` 叶子，`createRenderObject()` **每帧**调 `ImageLoader::load()` |
| 缓存淘汰 + 字节上限 | `ImageLoader::$cache` 只增不减（只有 `clearCache()`）；一张 1792×2390 RGBA = **17MB**，128M 上限真机崩过（`ImageDecoder` 类注释） |
| 尺寸决策 | 现有 `Image::decodeWidth()` 靠调用方给的 `->width()` 乘 `SUPERSAMPLE`；网络组件要在布局约束出来后才知道目标宽（Drift3 `20 §5.6` 也卡在这里：没有 layout pass 可取 `targetWidth`） |
| 代次 / 取消 / 离屏回收 | 复用 URL 换图、滚出视口、停止轮询时要撤在途请求 |
| 跨进程协议 | worker 下载 → 落临时文件 → **只回路径**（见 §5.2） |
| 每应用 `NamedJob` 登记 | 漏登记 = 真机静默失败 |
| 判据 / 变异 / T2 AOT | 新增框架类按 `AGENTS.md` 必须 T2；四态、取消、缓存、内存各要独立判据 |

⇒ 粗估框架侧 **300–500 行 + 一整套真机判据**，且要动 `src/`（T2 独立成棒）。
**净账不是「-307 行」，而是「业务 -307 / 框架 +300~500，风险从应用层搬到框架层」。**

---

## 3. 音频 / 视频：零消费者，且不是同一类组件

机械事实（本轮 grep）：

```
src/ 下 *audio* / *video* / *media* 文件：0
src/Widget、src/Rendering 里 Audio|Video|MediaPlayer 命中：0
apps/watermark 里 audio|video|mp3|mp4|wav 命中：0
```

watermark 是「选图 → 上传 → 提交水印任务 → 轮询 → 下载结果图」，全程只有图片。
`Http.php` 的 MIME 表里虽列了 `mp4/mov`，但那是通用 multipart 映射，不是媒体能力。

更值得纠的是**分类本身**：成熟 GUI 里音视频组件与图片组件不是「同一类的三个实例」——

| | 图片异步组件 | 音视频组件 |
|---|---|---|
| 本质 | 一次性资源获取（query） | 有播放状态机的时间连续媒体 |
| 状态 | 4 态：空/加载/成功/失败 | 播放/暂停/缓冲/seek/结束/错误 + 时钟 |
| 关心 | 缓存、尺寸、占位、失败降级 | 硬件解码、音画同步、音频会话、后台播放、焦点抢占 |
| 代表 | `Image.network` / `AsyncImage` / `AsyncImagePhase` | ExoPlayer / AVPlayer / MediaFoundation pipeline |

两者唯一的共同点是「不阻塞 UI 线程」。用户列举的三件套是**按名词枚举**，不是按负担归因。
对 watermark 而言，音视频组件的减负是 **0 行 / 0 条隐性规则**。

---

## 4. 成熟 GUI 怎么做（以及明确「不做什么」）

### 4.1 Flutter：`Image.network` 只是薄壳，能力在 provider/cache 层

`Image` 是 `StatefulWidget`，真正干活的是 `ImageProvider` → `ImageStream` → `ImageCache`：
按配置生成 cache key → 命中缓存直接通知 `_ImageState` 刷新 → 未命中起异步 `loadAsync()` →
回来更新缓存再通知。**网络下载与解码都在 `NetworkImage._loadAsync()`，不是渲染路径。**

- 四态由 `frameBuilder` / `loadingBuilder` / `errorBuilder` / `FadeInImage` 暴露，**穷尽性靠框架**。
- `ResizeImage` / `ScrollAwareImageProvider` 负责「按布局约束决定解码尺寸」与「快速滚动时推迟加载」。
- `ImageCache` 默认**不淘汰离屏图**，官方实践要自己在 `deactivate/dispose` 里 `evict()`、
  并按机型调 `maximumSizeBytes`；**磁盘缓存不在核心**，要 `cached_network_image`。
- 命令编排完全不归它：`FutureProvider` / `AsyncNotifier` / Riverpod 的 `.when(data/loading/error)`、
  `Bloc` 才是那一层。

### 4.2 Jetpack Compose / Coil：`AsyncImage` 的四态 + SizeResolver

`AsyncImage` 是 composable，内部走 `rememberAsyncImagePainter`；
`AsyncImagePainter.State` 是 `Empty / Loading / Success / Error` 四态（比 SwiftUI 多一个 Empty）。
`placeholder / error / fallback` painter 与 `onLoading/onSuccess/onError` 回调是槽位；
`crossfade` 是内置过渡。

★ 关键设计：**`AsyncImage` 会按 composable 的 constraints 与 `ContentScale` 决定加载尺寸**
（`ConstraintsSizeResolver`），而底层 `rememberAsyncImagePainter` 不检测尺寸 ⇒ 默认按原图加载。
官方明写的坑：首帧 `State.Empty` 即使内存缓存已命中；要首帧状态正确得用
`SubcomposeAsyncImage`，而它「用 subcomposition，更慢，不适合 LazyList」。

⇒ 连 Coil 都要在「状态准确」与「组合性能」之间取舍，还要业务自己选 API 档位 —— 不是「有了就不用管」。

### 4.3 SwiftUI：`AsyncImagePhase` 与 `.task(id:)` 分属两层

`AsyncImage` 的 phase 是 `empty / success(Image) / failure(Error)`，视图离开层级时
`URLSession` data task 自动取消，缓存走 `URLSession` 默认 memory+disk。
**命令**（发起任务、轮询、取消）在 `.task { }`，视图消失自动取消 task。

官方/实践明确的两个边界：
- `AsyncImage` **不做批量与限流**：`LazyVGrid` 几百格会同时发几十个请求，
  要自己设 `httpMaximumConnectionsPerHost` 或换 Nuke / Kingfisher。
- 不产生隐式 `accessibilityLabel`，每个调用点要自己加。

⇒ 「一张图一个组件」在列表/批量场景里，节流、顺序、聚合仍归业务。

### 4.4 WPF / WinUI：最接近 Drift 现状的一面镜子

`BitmapImage.IsAsync = true` 的语义常被误读：它让**解码**不阻塞 UI 线程，
`DecodePixelWidth` 在解码期就按目标尺寸缩；`ImageFailed` / `DownloadProgress` 给失败与进度。
但**下载、缓存策略、重试、取消、生命周期仍要业务/服务层管**，WPF 没有「任何元素都能发请求」的白嫖。

这正好对上 Drift 已有的两件：
- `Image::decodeWidth()` 的 `SUPERSAMPLE=2.0` == `DecodePixelWidth` 的尺寸决策；
- `ImageDecoder`（WIC 优先 / `PngDecoder` 兜底）== 原生解码避 PHP 堆 OOM。

⇒ **Drift 已经把 WPF 那条「异步解码 + 尺寸决策」做完了**；没做的是「谁来下载」，
而 WPF 也把这件事留给了业务层。

### 4.5 Web / React：query 与 mutation 显式分家

`<img loading="lazy" decoding="async">` 与 `<Suspense>` 管资源呈现；
React Query 用 `useQuery`（幂等、可缓存、可去重）对 `useMutation`（有副作用、要手动失效缓存）
把两类能力分开命名。这个区分是本问最该借的一条。

### 4.6 Qt

`QQuickAsyncImageProvider` 把图片请求交给线程池，`QNetworkAccessManager` 发信号回主线程；
provider 是**引擎级注册**的能力，不是某个 QML 元素自己去发请求 —— 与本仓 §18
「业务数据只能从组合点注入」同构。

### 4.7 共同结构：一张表看清边界

| 能力 | 成熟 GUI 的异步资源组件 | 归谁 |
|---|---|---|
| URL → 四态（空/加载/成功/失败） | ✅ 组件内 | 框架 |
| 占位 / 失败 / 淡入槽位 | ✅ 组件内 | 框架 |
| 内存 / 磁盘缓存 + 按尺寸 key | ✅ 组件内（Flutter 磁盘缓存仍在生态层） | 框架 |
| 按布局约束决定解码尺寸 | ✅ 组件内（Coil `SizeResolver` / Flutter `ResizeImage` / WPF `DecodePixelWidth`） | 框架 |
| 离屏 / 销毁时取消在途请求 | ✅ 组件内 | 框架 |
| **一批 N 个资源、总进度、部分失败** | ❌ **从不提供** | 业务 |
| **按服务端顺序展示** | ❌ | 业务 |
| **轮询 / 重试退避 / 停止条件** | ❌ | 业务 |
| **跨请求事务性取消、代次** | ❌（只管自己那条） | 业务 |
| **业务错误文案、状态栏汇总** | ❌ | 业务 |
| **命令编排（mutation）** | ❌ 交给 ViewModel / `AsyncNotifier` / `useMutation` / `.task` | 业务 |

⇒ 用户问的「有了这些是不是就不用自行实现了」，在成熟框架里的答案是：
**单张图的加载不用自己写；批量策略、命令编排、状态汇总从来都要自己写。**
而且连「单张图」都常要引第三方库补磁盘缓存与限流。

### 4.8 排坑：组件自己也会带来新的心智负担

- Coil：首帧 `Empty` 的三种处理策略（乐观 / ZStack / 悲观）+ `SubcomposeAsyncImage` 更慢。
- SwiftUI：`AsyncImage` 不批量不限流、无隐式无障碍标签；自定义 `urlSession` 要 iOS 17+。
- Flutter：`ImageCache` 不淘汰离屏图，磁盘缓存靠生态；`Image` 每帧 inflate 要靠路径缓存兜住。
- 通用：**组件引入的是「生命周期 + 代次 + 缓存键」这组新规则**。本仓 §8.4 的痛正是
  「规则靠人记且错了静默」，加组件若不同时把新规则 lint 化，等于换一批隐性规则。

---

## 5. 本仓为什么不能照搬：§23 判死复核（逐条对当前代码）

`docs/DEBT-2026-11-02.md` §23.2「P0-2 为什么必须停下来：widget 拿不到 `TaskHost`」
原文：「`Image::url()` 要成立，缺的是一句『谁来下载』。四条路都堵」。本轮逐条复核：

| 路 | §23 的理由 | 本轮现状 |
|---|---|---|
| ① `BuildContext` 向上查找 | §18 明文否决「无 `of()` / `find()` / `maybeOf()`」 | `src/Widget/BuildContext.php` 契约未变；§18 那条正结论仍在 |
| ② 全局 `AppScope` | 2026-10-30 已撤回服务定位推荐 | `WatermarkApplication` 仍是手写组合根，把 `TaskHost` 放进 `AppScope` 递给 Store，不进 Widget |
| ③ 全局静态 `TaskHost` | 等于第 39 个静态字段，D3 刚把 38 个搬进 `Pipeline` | `ImageLoader` 的 `static $cache` / `$baseDir` 是少数例外，且正是 §2.4 的内存债来源 |
| ④ 每调用点传 host | `Image::network($url, $host)` 比现在手写下载更啰嗦 | `ResultCard` 目前连 `store` 都由构造注入，再塞一个 host 会让 Widget 依赖 App 层 |

原话仍然是本轮最好的总结：

> 「**某个控件自己去要数据**这件事，本仓在结构上是禁掉的」——而这不是疏忽，是 §18 的
> 正结论：业务数据只能从组合点注入。网页的 `<img src>` 白嫖能力，代价正是「任何元素
> 都能发起网络请求」。

### 5.1 跨进程两道税，今天仍成立

```
NamedJob 要在每个应用的 JobRegistry 登记（漏登记 = 真机静默失败）
图片字节不能过管道：2.35 MB → base64 3.1 MB → json_decode
只能由 worker 落临时文件、回传路径
```

`DownloadResultsJob::fetchOne()` 现在就是这么做的：`Http::get` → `file_put_contents` → 返回路径，
`run()` 返回 `['paths'=>…, 'failed'=>…]` 这个纯标量 map。
`WatermarkStore::exec()` 走 `JobRunner::exec($host, $job)`，`TaskHost` 是常驻子进程池。

### 5.2 每帧 inflate + UI 线程解码

`Image::createRenderObject()` 每帧调 `ImageLoader::load()`（靠解析后绝对路径缓存才没打穿帧率）。
`ImageDecoder` 走壳里的 WIC，注释写明「纯 PHP 解码 1792×2390 实测 6.6 秒 / 峰值 92MB，
跑在 UI 线程的 `createRenderObject()` 里 ⇒ OOM ⇒ 崩在 `php_request_shutdown`，
看起来与图片毫无关系」。

⇒ 渲染路径不做网络，这条约束是**用一次真机崩溃换来的**，不是风格偏好。
`DownloadResultsJob` 类注释把它写成了显式裁决：「为什么不给 `Image` 加 URL 支持」。

### 5.3 最讽刺的一点：最自然的落地形态 == 现有代码

如果明天一定要做 `NetworkImage`，在本仓进程模型下唯一可行的形状是：

```
Widget 声明 URL → 组合点注入的 resource provider 收到请求
  → 派一个 NamedJob 到 worker → worker 下载、落临时文件、回传路径
  → 主进程按路径走 ImageLoader(WIC) 解码 → ImageHandle → RenderImage
```

**这正是 `DownloadResultsJob` + `Image::asset($local)` 现在的样子。**
差别只在「这段编排写在 `apps/watermark` 还是 `src/`」。
所以「不用自行实现」这个判断成立，但它是**能力下沉**（本仓 §8.1 说的「机制下沉 / 业务词汇上层」），
不是「负担消失」；而且下沉后框架要为所有应用承担通用性、缓存淘汰、代次与内存上限。

### 5.4 后续三次复核都没重开

`docs/DEBT-2026-11-02.md` 的 §30 / §33 / §66 三处**逐字**写着
「不重开 `bind()` / 自动依赖收集 / `pollUntil` / codegen / `Image::url()` / 增量布局」。
§23 给的重裁前置是「**给不给 Element 树一个可继承的作用域**（Flutter `InheritedWidget` 同形）」，
要推翻 §18 的第 ③ 条，且「得先有『为什么这次值得』的证据 —— 现在没有」。

⇒ 用户这次的假设**踩在同一批约束上**。若要重裁，得先回答 §23 留下的那句前置，
而不是重新论证「有组件会更好」。

---

## 6. 反直觉：N 个异步资源组件反而可能更糟

watermark 的下载不是「N 张独立的图」，是**一个批量作业**：

- 一条整批 `Progress`（`done/total`），不是每张一个 spinner；
- 部分失败要汇总成一句「有 N 张结果图没能下载」写进状态栏；
- 展示顺序按 `downloadUrls`，不按完成顺序；
- 「停止轮询」要能连带撤掉整批下载；
- URL 是 `TaskResult` 的元信息，还要原样显示给用户复制。

把它换成 N 个 `NetworkImage` 后，组件各自只暴露自己的 loading/success/error，
**总进度、失败汇总、顺序、统一取消都得跨 N 个实例聚合** —— 状态机从 `WatermarkStore`
一处散进 Widget 树。这与 §Phase 那次踩坑同形：迁移前五个 `AsyncCommand` 各报 `isRunning()`，
界面拼「在忙」，结果「它们互不排斥」⇒ 轮询中「提交任务」按钮仍是活的，
点一下起第二条轮询、两条 `absorbPoll` 互相覆盖 `lastResult`。
`Phase.php` 的类注释把结论写死了：「**『在忙』本来就是一个状态，所以它就该是一个值**」。

§4.8 的框架证据也指向同一处：SwiftUI `AsyncImage` 不批量不限流、
Coil 要 `SubcomposeAsyncImage` 才换得到准确首帧且更慢、
Flutter 靠 `cached_network_image` 补磁盘缓存。**「一张图一个组件」在批量场景下不是免费午餐。**

---

## 7. 真正便宜的减负点（按「减负 / 成本」排序）

### 7.1 首选：把下载段收成一个 `AsyncValue`（不新增命令、不动 §18、不动 `src/`）

本仓**已经有**成熟 GUI 那一层的原语：`Drift\Async\AsyncValue` 四态
（`idle/loading/data/error` + 进度作为第五维度 + `carriedFrom()` + `fold()` 穷尽四支），
`AsyncCommand` 提供 `value/isRunning/run/append/retry/cancel`。
`apps/users/src/Store/UserStore.php` 的类注释记着一次同构收敛：

> 「之前本类是 `AsyncCommand` 的同款逻辑**手写第二遍**：`LoadStatus` 枚举 + …
> ⇒ `LoadStatus` 随之删除（四态已经是 `AsyncValue`），页面改读 `AsyncValue::fold()`」

watermark 现在的下载段恰好就是「手写第二遍」：

```
localPaths / failedDownloads / downloadFraction / downloadMessage / isDownloading
                                   ↓ 合成一个不可变值
ResultCard::imagesSection() 里的 if(local) / if(failed) / Spinner / Progress
                                   ↓
AsyncValue::fold(['idle'=>…, 'loading'=>…, 'data'=>…, 'error'=>…])
```

收益直接命中 **A 类负担**：`fold()` 漏写一支当场抛（把「界面空白」从运行期挪到开发期），
而不是继续靠人记「这五个字段要一起改、`notifyChanged()` 别漏」。

★ **实施约束（必须写死，否则会重开旧裁决）**：
`WatermarkStore` 用**一个** `AsyncCommand` 包整条流水线（`Flow::Submit` → 建任务 → 轮询 → 下载），
这是 `Phase` 六态刻意收敛的结果（见 §6）。所以**不能把下载拆成第二个 `AsyncCommand`**，
只能在现有单 Flow 内把 `AsyncValue` 当作**值**（而非命令）使用；进度仍走
`await()` 第二参，不改成 `AsyncCommand` 的 `onProgress`。
§69 的 `Equatable` / `select-purity` 对 `AsyncValue` 已有 `IDENTITY_STABLE` 例外登记，
`SubjectBuilder::select` 门控这条路 lint 上是通的。

成本：Store + `ResultCard` 两个文件、约 100 行改写，零框架改动、零 T2 风险、
判据可复用 `tests/Unit/Async/AsyncValueTest.php` 那一层。

### 7.2 剩余隐性规则 lint 化

§10.5 第 1 条、§8.4 都点过：A 类 8 条里 §68 只收了两条（进度订阅、取消守卫）。
本仓有 30 条 lint 规则的现成通道，逐条「1 规则类 + 2 夹具 + 1 组 selftest 断言」。
这是唯一「不改语义就能减心智负担」的一件。

### 7.3 状态广播 / Widget 样板

§10.5 第 2 条：1126 行 Widgets + 29 行 `SubjectBuilder` + 29 次 `notifyChanged` 才是
代码量最大单一来源。§69 开了头（`Equatable` 让默认门控按值），但没减行数。

### 7.4 明确不做

- 为 watermark 造 `NetworkImage` / Audio / Video 三件套；
- 重开 §18 的「可继承作用域」；
- 把批量下载拆成 N 个独立资源组件；
- 在 `src/` 加静态全局 host 或让 Widget 拿 `TaskHost`。

---

## 8. 重裁触发条件（写下来，避免把「现在不做」读成「永远不做」）

要重开 `Image::url()` 这类能力下沉，**至少**满足：

1. **结构前置**：先裁决「Element 树要不要可继承作用域」，并给出推翻 §18 第 ③ 条的证据
   （`docs/DEBT-2026-11-02.md` §23.2 的原话）；
2. **跨进程前置**：图片字节能不经管道进主进程，或框架提供统一的
   「worker 落盘 + 路径回传 + 生命周期回收」协议，且 `NamedJob` 登记不再靠每个应用手写；
3. **内存前置**：`ImageLoader` 缓存有淘汰与字节上限判据，解决 17MB/张 × N 与 128M 上限；
4. **尺寸前置**：有 layout pass 能把目标宽传给解码器（Drift3 `20 §5.6` 卡的就是这条）；
5. **消费者前置**：出现**第二个**真实业务需要网络资源组件（现在只有 watermark 一处，
   单点抽象按本仓纪律不成立）；
6. **判据前置**：四态 / 取消 / 代次 / 缓存 / 内存各有独立判据，且变异能咬红；
   新增框架类按 `AGENTS.md` 必须 T2 AOT 真机。

在此之前，§7 的三件是更短、更可验、且不搬风险的路径。

---

## 9. 诚实边界

- 本轮**只读**：未改 `src/` / `apps/` 任何产品代码，未跑新真机、未提交；
  git 工作区只多出本稿。行数与 grep 读数均基于 `d2fc77a`。
- §2.2 的 307 行是**乐观上界**，来自逐段 `wc -l` 与人工分界，未经实现验证；
  §2.4 的 300–500 行是粗估，不是承诺。
- 成熟 GUI 事实取：Apple `AsyncImagePhase` 官方文档、Coil 官方 Compose 文档、
  Flutter `ImageProvider`/`ImageCache` 实现描述、Riverpod `AsyncNotifier` 文档与
  相关工程实践；**未在本机跑过这些框架**，只用于结构与边界对照，不引用其性能数字做承诺。
- 与 Drift3 的关系：其 `AsyncResource::images()` 仍是「不下载、不解码、不碰 IO」的
  惰性句柄（`slotId=0`），`20 §5.6` 自记「没有 layout pass 可供取尺寸」⇒
  换个仓库也没有现成答案。**不继承** `docs/ANALYSIS-watermark-async3-borrow-2026-11-07.md`
  §10 的「不迁」结论来回答本问，本问是独立复核。
- §7.1 是**方向建议**，未做实现前分析、未出方案稿、未验证它在 `Phase` 六态下不产生回归；
  真要落地须另开一棒，先写方案 + 变异清单。

---

## 10. 一句话回答

> 「异步实现」那一半，§68/§69 已经在收（lint 化 + 值相等门控）；
> 「剩下的」不是缺控件，而是 **8 条错了不报的隐性规则 + 29 次状态广播样板 +
> 6 态命令编排 + 跨进程序列化**。图片异步组件能碰到的只有约 307 行（8.3%），
> 音视频是零消费者，而且 `Image::url()` 在 §23 已按四条堵死的路径判死、三次复核未重开。
> 成熟 GUI 给的答案是「资源与命令分层、组件只管四态/缓存/尺寸/取消」——
> 这一层的四态本仓 `AsyncValue` 已经有了，**先把它接到下载段，比造组件便宜一个数量级**。
