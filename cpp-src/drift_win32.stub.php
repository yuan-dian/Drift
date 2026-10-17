<?php

/**
 * Drift 的 Win32 桥接 ABI（§5.1 / §16.4）。
 *
 * ★ 本文件里每个函数都是**空体**，这是有意的：TypePHP 读它拿到签名并生成 C++
 *   声明头，真正的实现住在 `cpp-src/drift_win32.cc`，函数名加 `php_` 前缀
 *   （`drift_win_create` ⇄ `php_drift_win_create`）。
 *
 * ★ **改一边必须同步另一边**：签名不一致时 tpc 链接期会「静默失败」—— 不是编译错误，
 *   是运行期行为不对。这是本桥唯一的硬纪律。
 *
 * ★ 与 GUI 仓 `gui_win32.stub.php` / markdown-editor `win32.stub.php` 同一条边界纪律：
 *   ① **只传原语**（`int` / `float` / `string` / `bool` / `array`）—— 跨接口后不得
 *      解引用 OS 句柄（§5.1 纪律 1）；
 *   ② **PHP 侧不持有 Win32 常量、不做 `wParam` 位运算** ⇒ 泵返回的是**命名事件**，
 *      键码归一化成**可读键名**（`'A'` / `'F5'` / `'Tab'`），不是虚拟键码 ——
 *      后者是 `Platform` 层的私事（`KeyChord` 的 docblock 逐字如此）；
 *   ③ **零回调、零闭包**跨边界（AOT 红线）：WndProc 收下的东西一律进队列，
 *      由 `drift_win_pump()` 取出。
 *
 * ★ 与两个参考实现最大的差别：**本桥不建控件、不做布局、不画任何东西**。
 *   GUI 仓的 `gui_win32.cc` 要建工具栏 / 分隔条 / 状态栏，markdown-editor 的
 *   `win32.cc` 要建 RichEdit —— 那些是"原生控件当界面"。Drift 的界面**全部自绘**
 *   （§18：原生控件与自绘主题无法统一），所以壳只做四件事：
 *   **建窗 / 泵消息 / 贴一帧像素 / 定时器**。
 */

// ---- 窗口生命周期（"生"与"活"分开，§5.1 纪律 3） ----

/**
 * 建主窗口，返回**不透明句柄**；`0` = 失败。
 *
 * `$width` / `$height` 是**逻辑**像素的客户区尺寸，壳按当前 DPI 缩放后反推外框
 * （客户区 ≠ 窗口尺寸：标题栏与边框不算）。
 *
 * ★ **建窗会把本进程设成 Per-Monitor DPI Aware V2**（§6）：这是 Windows 的**进程级**
 *   属性，必须在**任何窗口 / DC 之前**设定，所以它落在建窗这一步（§24.1 #43）。
 *   不设成 per-monitor 的后果：跨屏时系统把内容**位图拉伸**（糊），且
 *   `WM_DPICHANGED` **不会发** ⇒ `Screen::scale` 停在系统 DPI、§6 的"跨屏 = 布局事件"落空。
 * ★ 外框换算走 `AdjustWindowRectExForDpi`：分数缩放下老的 `AdjustWindowRectEx` 按
 *   **系统** DPI 算边框厚度 ⇒ 客户区对不上请求的逻辑尺寸。
 */
function drift_win_create(string $title, int $width, int $height): int {}

/** 让窗口可见并激活（`ShowWindow(SW_SHOW)` + `UpdateWindow`） */
function drift_win_show(int $hwnd): void {}

/** 请求关闭：壳 `DestroyWindow` ⇒ `WM_DESTROY` ⇒ `PostQuitMessage` ⇒ 泵返回 `quit` */
function drift_win_close(int $hwnd): void {}

function drift_win_set_title(int $hwnd, string $title): void {}

/**
 * 客户区尺寸（**物理**像素，`['w'=>int,'h'=>int]`；失败为空数组）。
 *
 * ★ 返回**物理**像素而不是逻辑：逻辑↔物理的换算**只能发生一次**（§6.1），
 *   而那个点是 `Scale::quantizeRect()`，在 PHP 侧。壳多做一次换算就多一个
 *   取整点 ⇒ 分数缩放下会出现"客户区 801px、逻辑 640.8px、布局按 640 排"这类
 *   对不上的账。
 */
function drift_win_client_size(int $hwnd): array {}

function drift_win_is_maximized(int $hwnd): bool {}

// ---- 屏幕 / DPI ----

/**
 * 窗口所在**显示器** DPI（96 = 100%）。PHP 侧用 `Scale::fromDpi()` 换算成缩放比。
 *
 * ★ 给的是**每显示器** DPI（`GetDpiForWindow`）；老系统退化为**系统** DPI
 *   （`GetDeviceCaps`）—— 后者在多屏下不准确，是降级不是等价。
 * ★ 不返回"permille 缩放比"：DPI 是平台的**事实**，缩放比是框架的**解释**
 *   （96 是 Windows 的约定，不是普适真理）。解释留在 PHP 侧，换平台时才不用改口径。
 */
function drift_win_dpi(int $hwnd): int {}

/** 系统是否处于深色模式（§9.2；读 `AppsUseLightTheme`，读不到按浅色） */
function drift_win_system_dark(): bool {}

function drift_win_ime_caret(float $x, float $y, float $h): void {}

// ---- 消息泵 ----

/**
 * 阻塞等到**一条 Drift 关心的事件**，返回命名事件数组（§5.2 裁决 1）。
 *
 * 返回 `['type' => 名, ...]`，名与字段如下：
 *
 * | type | 字段 | 来源 |
 * |------|------|------|
 * | `quit` | — | `WM_QUIT` / 窗口销毁 ⇒ 调用方跳出循环 |
 * | `resize` | `w` `h`（**物理**像素） | `WM_SIZE` |
 * | `dpi` | `dpi` | `WM_DPICHANGED` |
 * | `timer` | `id` | `WM_TIMER`（id 由 PHP 给，见 `drift_win_set_timer`） |
 * | `pointer` | `x` `y`（**物理**像素，客户区原点） `button` `phase`（0=down 1=move 2=up 3=leave，与 `Drift\Input\PointerPhase` 同值） | `WM_{L,M,R}BUTTONDOWN` / `WM_MOUSEMOVE` / `WM_{L,M,R}BUTTONUP` / `WM_MOUSELEAVE`（`leave` 的坐标是**最后已知位置** —— 系统不再带坐标；`move` 的 `button` 恒 0，按住态由应用自己的拖动状态跟踪） |
 * | `key` | `key`（**键名**字符串） `ctrl` `alt` `shift` | `WM_KEYDOWN` |
 * | `char` | `text`（UTF-8） | `WM_CHAR`（`TranslateMessage` 合成） |
 * | `theme` | — | `WM_SETTINGCHANGE`（系统主题变更，载荷为空） |
 * | `wheel` | `x` `y`（**物理**像素，客户区原点） `delta`（WHEEL_DELTA=120 为一格，正=内容上移） | `WM_MOUSEWHEEL`（坐标经 `ScreenToClient` 换算） |
 *
 * ★ **壳不关心的事件就地派发后丢掉**（绘制、焦点切换…），绝不交给 PHP
 *   去忽略 —— GUI 仓的教训：把不关心的事件也回传，泵就成了"每帧几百次跨边界调用"。
 *   ★ 鼠标移动**是关心的**（2026-10-17 move/up/leave 通路：光标刷新 / 悬停派发 /
 *   拖放过程三消费者）—— 入队前经 `TrackMouseEvent(TME_LEAVE)` 请求离开跟踪。
 * ★ **`WM_SIZE` 不是队列消息**：它是 `DefWindowProc` 在窗口尺寸变化时**同步**调进
 *   WndProc 的，`PeekMessage` 取不到。壳在 WndProc 里把它转成一条自定义队列消息，
 *   泵才看得见（GUI 仓同名坑，注释写在 `gui_win32.cc` 第 540 行附近）。
 * ★ 泵**不做帧节流**：它只回答"下一条事件是什么"。帧率由 PHP 的循环结构决定
 *   （§5.2 裁决 1：循环归平台、派发归框架）。
 */
function drift_win_pump(): array {}

/**
 * **单调**毫秒时钟（`GetTickCount64`）。只做减法用，绝对原点无意义（§5.2 裁决 2）。
 *
 * ★ 不用 `microtime()`：AOT 标准库的 `time()` 粒度是秒且会被系统时间调整，
 *   而动画相位 = f(帧时间戳) ⇒ 时间倒流就是动画倒放。
 */
function drift_win_now_ms(): float {}

// ---- 定时器（帧时钟的物质载体，§5.2 裁决 1） ----

/**
 * 启动周期定时器。`$id` 由 **PHP** 指定（到点时 `timer` 事件带的就是它）——
 * 壳不分配 id：分配权在框架，否则"哪个定时器响了"要查表。
 *
 * @return bool 失败（`SetTimer` 返回 0）⇒ false
 */
function drift_win_set_timer(int $hwnd, int $id, int $ms): bool {}

/** 停表；**未起过的 id 不是错误**（幂等） */
function drift_win_kill_timer(int $hwnd, int $id): void {}

// ---- 失败路径（§1.2 标准中止序列第 ② 步） ----

/**
 * 弹一个**原生**错误框并阻塞到用户点掉。
 *
 * ★ 它**不依赖渲染栈** —— 渲染栈本身可能正是崩掉的那一环，所以不能用自绘对话框。
 * ★ 不用 `MessageBoxW` 的返回值（用户只有"确定"可点）：中止序列的下一步是
 *   `exit(3)`，不是"问用户要不要继续"。
 */
function drift_win_message_box(string $title, string $body): void {}

// ---- 呈现（每帧一次） ----

/**
 * 把一帧像素贴到窗口客户区。
 *
 * `$rgba` 是 `RasterCanvas::toRgba()` 的产物：**逐像素 RGBA、自上而下、无行填充**，
 * 长度必须 ≥ `width * height * 4`。长度不足 ⇒ **整帧丢弃**（不是画半张）：
 * 尺寸不符意味着上游算错了，画一半只会让"画面缺一块"看起来像布局 bug。
 *
 * ★ **为什么是"PHP 光栅 + 贴屏"而不是"把绘制命令交给壳"**：§7.3 的分期明确
 *   M1 = 位块传送；`RasterCanvas` 已经是**零 OS 依赖**的完整软光栅（§7.2 裁决 1），
 *   用它当 M1 的后端**不需要链 Skia**，而 `SurfaceProvider` 这层抽象本来就是为
 *   "后端可换"而存在的 —— Skia 后端（`SkiaCanvas` + `SkCanvas::MakeRasterDirectN32`）
 *   接同一个端口即可，应用侧一行不改。
 * ★ 壳**缓存**这一帧：`WM_PAINT` 时重贴缓存（窗口被遮住又露出来时系统会重发
 *   `WM_PAINT`，而那一刻 PHP 可能正阻塞在 `drift_win_pump()` 里等事件 ⇒
 *   不缓存就会白屏）。
 */
function drift_win_present(int $hwnd, string $rgba, int $width, int $height): void {}

// ---- 指针形状（§9.1 命中链的第 ③ 方） ----

/**
 * 设置鼠标指针形状。`$shape` 是 `Drift\Input\CursorShape` 的 `value`：
 * `0`=Default `1`=IBeam `2`=PointingHand `3`=SizeAll `4`=SizeNS `5`=SizeWE
 * `6`=Wait `7`=NotAllowed。
 *
 * ★ **为什么传 int 而不是形状名**：名字→`IDC_*` 的映射只该有一处（这里）。
 *   传名字会让壳做字符串比较，而 AOT 下字符串是 `php::Str`，每次移动光标都要
 *   过一次桥的解包 —— `int` 是原语（§5.1 纪律 1）。
 * ★ **壳必须缓存**并在 `WM_SETCURSOR` 时重贴：Windows 在鼠标移动前发
 *   `WM_SETCURSOR`，`DefWindowProc` 会把它重置回窗口类光标（`hCursor = NULL`
 *   ⇒ 默认箭头）⇒ 不缓存的话框架设的形状只显示到下一次移动，**且不报错**。
 * ★ 未知 `$shape` 一律退回 `IDC_ARROW`（**不抛**）：光标形状是**装饰**，
 *   为它中止整个应用与 §1.2 的中止序列不成比例。
 */
function drift_win_set_cursor(int $hwnd, int $shape): void {}
