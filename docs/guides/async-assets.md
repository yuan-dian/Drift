# 异步资源：图片的加载与缓存

> 本篇解决：用 `Image::make()->asset($src)` 一个入口加载本地与网络图片，并理解三态画法、两层缓存和尺寸上限，避免"图不显示却不报错"。

图片是应用里最常见的异步资源。Drift 把来源判定、下载、解码、缓存、失败降级都收在图片端口里，业务只给一个字符串路径。

## 唯一入口 asset()

`Image::make()->asset($src)` 是唯一字符串入口：本地路径与 http(s) URL 同形，业务不用判断来源。

```php
Image::make()->asset('assets/logo.png')->width(120.0)->fit(ImageFit::Cover);  // 本地
Image::make()->asset('https://cdn.example.com/a.png')->width(300.0);          // 网络
```

`image(ImageHandle $handle)` 仍收内存句柄（自绘 / 测试用），给了它就忽略 `asset()` 的路径，不参与来源判定。

`ImageLoader::classify($src)` 做三分类，盘符判断排在 scheme 之前（否则 `C:\x` 会被当成 `C:` scheme，让 Windows 上所有绝对路径失效）：

| 来源 | 判定 | 行为 |
|---|---|---|
| 相对路径、`/x`、`C:\x`、`\\server\x` | `local` | 同步解析 + 解码 |
| `http://` / `https://` | `network` | 磁盘缓存命中则同步解码，未命中提交 `FetchImageJob` |
| 其它 scheme（`file://`、`data:`…） | `reject` | 报一条诊断 + 降级为占位，不静默当本地路径 |

## 三态画法与占位

图片状态住在 `ImageState` 里，不在 Widget 上：Widget 每次重建都换新，在途请求与已解码的 handle 必须跨 build 存活。画法按三态组织：

- **READY**（本地解码成功，或两层缓存之一命中）：拿真 handle 画 `RenderImage`，**不产生 loading 帧**。
- **LOADING / IDLE**：用 1×1 透明占位，尺寸由 `width` / `height` 撑住。
- **ERROR**：仍是 1×1 占位 + `Errors::report(..., Errors::DIAGNOSTIC)`，不抛、不弹框。

资源缺失用 1×1 透明占位顶上，是为了让 `RenderImage` 的字段恒为非空 `ImageHandle`；可能缺图时**显式给 `width` / `height`**，否则布局会缩到 1×1，看起来像"整块图没了"。`Image` 不给语义角色：要可读名字就放在外层交互控件的 `semName`。

`ImageState` 的命令只在 `initState()` 里建一次，`didUpdateWidget()` 里用 `src|kind|width` 作键判断是否需要重新解析——同一张图、同一目标宽度的重建不重发请求。换 src 时由 `AsyncCommand` 取消旧的那条。

## 装配链：Widget 不接触 Future

网络图的下载、解码、诊断全在 App 侧完成，Widget 只收一个 handle；`Future` 不穿 Widget 层。组合根一次装配：

`AppScope::setTaskHost($host)` → `ImageAssets::install($host, $cacheDir)` → `Image::setSource($source)` → `ImageState` 取端口 → `ImageAssets::fetch()`（内部 `TaskHost::submit()` + `Future`）→ `FetchImageJob`。

`FetchImageJob` 是框架自带的 `NamedJob` + `TimedJob`，由 `CoreJobs::register()` 在 `ProcessTaskHost` 与 worker 两侧各自合并，应用注册表不用改。它只依赖构造器参数（`url` / `cacheDir`），不读任何静态服务，所以 worker 侧无须装配。单图 HTTP 上限比 Host 默认墙钟长，避免慢图被兜底超时掐死。

没有后台端口时，网络图返回 `rejected` 并报诊断、降级为占位；不是崩溃。

## 两层缓存与失效

- **磁盘层**：`FetchImageJob::cachePath()` 把 URL 算成本地文件（`md5(url)` + 从 URL 推断的扩展名），同一 URL 覆盖而非堆积。UI 侧判命中与 worker 侧写盘**共用这个算法**——两边各算一遍会在扩展名上分叉，命中永远失败。缓存目录没给时用系统临时目录。
- **内存层**：`ImageLoader::$cache[resolved@maxWidth]` 存解码结果。命中走快路径：同步返回 handle，不提交 Job、不闪一帧 loading。

两层是串联的：网络图先查内存 handle、再查磁盘文件，都没有才下载。解码结果以"解析后的绝对路径 + 目标宽度"为键，同一文件按不同宽度解出的 handle 不共用键。

失效注意点：

- `ImageLoader::clearCache()` 清内存层；磁盘层按 URL 键覆盖。换了内容但 URL 不变的网络图不会自动刷新，要么换 URL，要么先清缓存路径。
- 诊断只在"没装配服务"、"未识别协议"、"没给 width"等事实发生时报，同一个 key 只报一次。
- 缓存条目数只用于测试（证明"第二次不再解码"），不作为运行期指标。

## 解码尺寸上限

解码上限按来源与是否给了显示宽度决定：

- 给了 `width`：`decodeWidthFor()` 按 `ceil(width × 2.0)` 解码，覆盖到 200% DPI 缩放，避免高分屏下发虚。
- 网络图没给 `width`：用安全上限 2048 解码，并报一条诊断。原尺寸解码有撑爆内存的前科。
- 本地图没给 `width`：按原尺寸（`0`）解码。

解码顺序是先用壳里的 WIC（原生解码，省 PHP 堆），失败再退回纯 PHP 的 `PngDecoder`；顺序不能反，大尺寸 / 高位深图走纯 PHP 会在 UI 线程吃光内存。分派按文件签名而非扩展名。找不到或解不出都返回 `null` 并附诊断，由调用方降级为占位。

本地相对路径的候选目录按顺序是：显式基目录（`ImageLoader::setBaseDir()`）→ 工作目录下的 `assets` → 工作目录下的 `images` → 仓库根 `assets`。绝对路径原样使用，不进候选拼接（否则在 Windows 上会拼出永不存在的路径且不报错）。

## 常见静默坑

- 网络图没给 `width`：按 2048 上限解码，显著费内存且只留一条诊断。
- 缺图又没给 `width` / `height`：布局缩到 1×1，看着像整块消失。
- 把 `file://` / `data:` 塞进 `asset()`：被判 `reject` 降级为占位，不会当本地文件读。
- 两侧各算缓存路径：扩展名分叉导致每次都下载。
- 以为 `image($handle)` 也会走来源判定：它直接覆盖 handle，`asset()` 路径被清空。
- 把 JPG 之外的网络图当成总是 `jpg` 缓存：扩展名只从 URL 推断，推断不出时按 `jpg` 命名，命中仍按 URL 键走。

## 相关

- [异步开发实战](./async.md)
- [用 Widget 组合界面](./widgets.md)
- [诊断与错误码](../reference/diagnostics.md)
- [命令速查](../reference/cli.md)
