# 平台能力端口

> 本篇解决：框架怎样通过 `Platform` 端口集合拿到窗口、表面、事件、DPI、主题与壳服务，Win32 与 X11 两侧壳在边界上各承担什么，以及哪些"没有"必须被显式处理。

`Platform` 是**能力端口的集合**，不是上帝接口：平台名只出现在组合根，`src/` 其余部分只依赖端口。窗口级能力一律用不透明句柄参数化，有两套"指定窗口"的写法能力相同，多一层状态对象只会增加歧义；有状态的服务由平台按句柄缓存，句柄就是状态 key。

## 平台现状一览

| 平台 | 状态 | T2 入口 | 已知缺口 |
|---|---|---|---|
| Win32 x64 | 完整桌面实现 | `drift-build --full` + `probe-window` | 触控 / 笔未做 |
| Linux x64（X11） | 已过公开 CI 端到端门，能力子集 | `bash probes/linux_x11/build.sh`（Xvfb + fluxbox + xclip + Linux Skia `.a`） | Wayland / AT-SPI / IME / 高对比度跟随未接；`dialog()` / `tray()` 返回 `null`；剪贴板只做 UTF-8 纯文本 |
| macOS | 未接 | —— | 仅在 deps 平台表预留 `macos-arm64` |

## 端口集合与"没有"的三种写法

每平台必有的五类子端口：

- `screen($windowHandle)`：`Screen`，给该窗口的 DPI / 视口 / 系统主题，每次现读、不缓存。
- `window()`：`WindowHost`，管窗口"生"与"活"——`createWindow()` 给句柄，`close()` 是请求（`PostMessageW(WM_CLOSE)`，可被应用拒绝），`destroy()` 才是执行。
- `surface($windowHandle)`：`?SurfaceProvider`，渲染表面提供者。
- `input($windowHandle)`：`InputSource`，该窗口的原始事件队列。
- `fatalBox($title, $body)`：不依赖渲染栈的原生错误框（渲染栈本身可能正是崩掉的那一环）。

循环三入口：`waitEvents($timeoutMs)` 只取不派发，`pumpEvents()` 把这次唤醒带来的事件归一化投给 `input()`，`now()` 是单调毫秒时钟（帧时间戳唯一来源，绝不能用会被系统调时的 `microtime()`）。

"没有"在这里有三种互不相同的写法，调用方必须分清：

| 写法 | 语义 | 调用方该做什么 |
|---|---|---|
| 壳服务返回 `null` | `dialog()` / `tray()` 在未实现的平台返回它；`clipboard()` 在 Win32 与 X11 都已实现 | 必须处理，不能当空操作吞掉 |
| `surface()` 返回 `null` | 平台**没有渲染能力** | 与"provider 在、但表面还没 resize"分开；后者是第二种状态 |
| 值域里的哨兵 | `configDir()` 空串 = 没有这个概念；`position()` 为 `null` = 最小化、位置不可信；`imeCaret()` 为 `null` = 无插入符；`waitEvents` 的 `-1 / 0 / >0` = 无限等 / 只探 / 等到点 | 消费者跳过对应能力，不要报成错误 |

`SurfaceProvider::surface()` 对同一 provider 反复调用返回**同一个** `RenderSurface` 实例；应用持 surface，每帧 `beginFrame()` / `endFrame()`。**渲染对象树不认识 surface**：测试里手搓一个 `RasterCanvas` 就能跑完整布局 + 绘制。这层间接是为了让"换绘制后端"不必先改平台接口。

## 壳的职责：Win32 与 X11

壳（C++ 侧）负责 OS 侧那一段：建窗与销毁、消息泵与唤醒窗、原生剪贴板 / 对话框 / 托盘、DPI 或缩放信号、系统主题信号，以及把桥函数暴露成稳定的 ABI。PHP 侧只通过 `Platform` 端口发命令、收事件。两侧的实现边界不同，但端口形状不变：

- **Win32**：`PostMessageW` 唤醒、`HWND` 句柄、`WM_SETTINGCHANGE` 主题、`PER_MONITOR_AWARE_V2` DPI、UIA 语义树；对话框与托盘是原生通道，剪贴板走系统 API。
- **X11**：`XConnectionNumber` + `poll()` 唤醒、窗口句柄为 `Window`、X11 selection 由独立 `xclip` 进程持有（桥内自实现会在同一 connection 上自死锁）；只做 `UTF8_STRING` 纯文本三件套，读写各有界超时与 `waitpid` 回收。Wayland / AT-SPI / IME、对话框与托盘未接，返回 `null` 或 no-op；最大化 / 最小化依赖 WM 处理。

以下几条是 Win32 专属的实现纪律：

- 消息泵阻塞等待由 `waitEvents()` 完成，超时用高精度可等待定时器（约毫秒级），不用 `SetTimer` 的系统 tick——后者会把短等待量化到下个 tick。
- `needsPolling()` 为 `false` 的后端靠 worker 的 `PostMessageW` 叫醒 UI；"说能叫醒却叫不醒"就是挂死，所以这是后端的能力声明，不是实现细节。
- 原生模态期间用 `consumeModalBlockedMs()` 读一次、读后即清，交给 `FrameClock::advance()` 从帧间隔里剔除；不清会把同一段模态时间反复剔除，连真卡顿也剔没。
- IME 光标由 `EventLoop` 调 `imeCaret($windowHandle, $rect)` 上报，`null` 表示无插入符；框架永远给逻辑像素，量化（× DPI）在平台侧做。插入符是线程级资源，"贴哪个窗口"必须由调用方指定。
- 窗口级能力（`applyMaterial()` / `setFrameless()` / `beginDrag()` / `setCursor()`）可 `no-op`：不支持的旋钮不报错，要问走 `materialCapabilities()` 的能力位，应用据此自适应（例如亚克力不支持就退回模糊）。能力位是 ABI，改位值必须与 C++ 实现同步。

## 表面与 surface 选择

`Platform::surface($windowHandle)` 由组合根拿到 provider，再交给 `Application::attach()` 持住 `RenderSurface`。同一进程多窗口时句柄不同、表面不同；按窗口取，不要在改动前拿"最后建的窗"的表面。

`WindowMaterial` 是**值对象**：一次应用窗口外观材质，三个互斥 / 耦合的旋钮收在一个值里，而不是三个 setter。像素归表面，窗口端口只收"模式"。

**显示时机（Win32）**：组合根先 `attach()`（内含首帧，帧进 `WindowSlot::frame` 缓存）再 `show()`，`show()` 里 `InvalidateRect` + `UpdateWindow` 把缓存那一帧贴出来。先 show 后 attach ⇒ 显示时缓存还是空的，`WM_PAINT` 无内容可贴，客户区停在**未绘制**状态（自绘窗口的 `WM_ERASEBKGND` 返回 1，不擦背景）—— 这就是"黑窗闪一下再出内容"。两半都要：缓存要有内容，显示时要真贴一次。X11 走 `X11SkiaSurface` 的呈现路径，没有 `WM_PAINT` 这一段。

## DPI 与缩放

`Screen::scale()` 是 DPR（1.0 = 96dpi，125% → 1.25），分数缩放**全程保留小数**；量化只在 `Scale::quantizeRect()` / `RenderSurface::resize()` 一处发生。取 DPR 全仓只有一个入口（`Win32\Dpr::of()`）：句柄无效或桥读不到时兜底为 `1.0`，避免 `0.0` 传进除法后变成 NaN 铺满整棵布局且不报错。它不缓存——跨屏拖动时 DPI 会变。

`Screen::viewport()` 是根约束的**唯一出处**：窗口客户区的逻辑尺寸、紧约束。物理像素的换算只发生在表面 resize 里。`scale()` 变化会产生 `DpiChanged` 事件，触发**全树重建**（不是 relayout）——它改的是 `build()` 的输入，字号与间距 token 都会变。

Win32 启动阶段的 DPI 感知级别必须是 `PER_MONITOR_AWARE_V2`：旧式进程级感知跨屏会被系统位图拉伸、画面发糊，而且收不到 `WM_DPICHANGED`。这条只能在真机上验（见[测试](./testing.md)）。

## 深色主题跟随

`Screen::systemTheme()` 给明暗，`appearance()` 给完整快照（明暗 + 高对比度 + 用户选的系统色）。Win32 来源是 `WM_SETTINGCHANGE`（`lParam == "ImmersiveColorSet"`）或启动时读注册表，复用现有事件通道，不新增端口方法。`appearance()` 每次现读、不缓存：明暗与高对比度都是运行期事件，缓存就得配失效逻辑。新代码用 `appearance()`，别让高对比度状态散在第二个通道里。

## 剪贴板、对话框、托盘、IME

三个壳服务按平台不同：Win32 的剪贴板与对话框懒建具体实现、托盘返回 `null`；X11 的剪贴板由 `xclip` 子进程实现 UTF-8 纯文本三件套，对话框与托盘返回 `null`。调用方必须处理 `null`——"没有剪贴板"不是"复制了个空串"，静默当空操作只会让用户以为操作成功了。

Win32 的原生对话框会阻塞事件循环，`consumeModalBlockedMs()` 负责把这段时间从卡顿统计里剔除。关闭请求是窗口级生命周期：应用可以拒绝（`setCloseGuard()` + `CloseRequestedEvent`），同意后框架才 `destroy()`；`isAlive()` 必须显式查 `IsWindow`，不能用"客户区 0×0"代替（最小化也是 0×0）。

窗口位置持久化用客户区左上角屏幕坐标，窗口销毁后 `position()` 返回**最后已知几何**（非 `null`），否则退出路径上写不回"记住窗口位置"；`place()` 一次原子还原几何与最大化态。

## 多窗口状态归属

`WindowSet` 管活窗口表；事件按事件自带的 hwnd 投递、`EventLoop` 按窗口抽干，输入队列也按窗口取——单队列时两个应用会重复消费同一条事件。Win32 的语义树 UIA provider 挂 HWND，每窗一份；`pushSemantics()` 收扁平节点数组，空数组表示无障碍关闭。

`AppScope` 每进程一份、多窗口共享：后台端口、图片服务这类长命服务与任务端口同生命期，不随窗口建毁重建；窗口级状态（标题、最小尺寸、材质、光标、关闭守卫、位置几何）才归各窗口。

## ABI 契约与构建期校验

跨层桥函数的 stub（`.stub.php`）是**唯一事实源**：tpc 直接拿它生成声明，C++ 实现与解释器替身都是被校验方。不另立 IDL / 生成清单——stub 本身就带契约文档，多一份清单就多一个同步点。

校验只看 ABI 实际关心的维度：函数名、参数个数、顺序、类型、返回类型；参数名与默认值不进 ABI。**校验只在构建期做**（`abi-check.php`，与 C++ 桥接结构对账一起挂在验证的 static 层），运行期不做握手。校验口径有 schema 版本，变化时抬版本，避免新旧指纹长得一样。

## 相关

- [帧管线](../concepts/frame-pipeline.md)
- [语义与无障碍](./semantics.md)
- [诊断与错误码](../reference/diagnostics.md)
- [环境搭建](../contributing/setup.md)
