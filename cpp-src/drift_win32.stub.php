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

/**
 * **请求**关闭：`PostMessageW(WM_CLOSE)` —— 与用户点标题栏 X 完全同路。
 *
 * ★ 2026-10-30 之前这里是 `DestroyWindow`，于是"关窗"有两条路：应用调 `close()`
 *   直接销毁、用户点 X 走 `WM_CLOSE`。两条路意味着**拦截点只能覆盖一半**
 *   （`setCloseGuard` 对前者无效）⇒ 合并成一条：请求走 `WM_CLOSE`，
 *   执行走 `drift_win_destroy()`。
 */
function drift_win_close(int $hwnd): void {}

/**
 * **执行**关闭：`DestroyWindow`，不看守卫（只有框架在应用同意之后该调它）。
 *
 * ★ 与 `drift_win_close()` 分开是"请求 / 执行"二分，不是一个功能的两个名字：
 *   前者可以被拒（窗口还在），后者不能（销毁 ⇒ `WM_DESTROY`）。
 */
function drift_win_destroy(int $hwnd): void {}

/**
 * 声明该窗口的关闭要不要**先问应用**（2026-10-30，可拦截关闭）。
 *
 * `true` ⇒ `WM_CLOSE` 不再销毁，改为入队一条 `close-requested`（帧边界由
 * `Application::onCloseRequested()` 决定，框架据此调 `drift_win_destroy`）。
 * `false`（默认）⇒ 与 2026-10-30 之前逐字节相同。
 */
function drift_win_set_close_guard(int $hwnd, bool $armed): void {}

function drift_win_set_title(int $hwnd, string $title): void {}

/**
 * 客户区**最小**尺寸（**物理**像素；0 = 不限制）。§5.4
 *
 * ★ 为什么需要：窗口能被拖到比内容更小 ⇒ 布局硬校验（C5/C6）会弹 Fatal，
 *   而"用户把窗口拖小"是**用户行为**、不是编程错误。最小尺寸是主流 GUI 的
 *   标配（Qt/WPF/Flutter 都有 minimumSize），比让每个容器在极小空间里优雅
 *   降级便宜得多。壳在 `WM_GETMINMAXINFO` 里换算成外框约束。
 */
function drift_win_set_min_size(int $hwnd, int $width, int $height): void {}

/**
 * 一次应用窗口外观材质（P1 收敛裁决，2026-10-26）。取代原先的三个独立入口
 * （`set_opacity` / `set_pixel_alpha` / `set_backdrop` —— 已删，签名不一致是
 * 链接期静默失败，改桥必须两边同步）。
 *
 * `$pixelAlpha` = 逐像素 alpha 上屏（present 走 `UpdateLayeredWindow`：画布像素
 * 自带的 alpha 生效，应用没画到的地方透出桌面）；`false` = GDI 贴屏。
 * `$opacity` 0..1 = 整窗不透明度（1 = 不透明）；`$backdropKind` 与
 * `Drift\Platform\Backdrop` 的 `value` 一一对应（`0` 关 / `1` 轻模糊 / `2` 磨砂）。
 *
 * ★★ **为什么收成一个函数**：三个旋钮**互斥/耦合** —— LWA 与 ULW 共用
 *   `WS_EX_LAYERED` 但互斥、逐像素要无边框（位图才与窗口矩形对齐）、背后材质
 *   只在带 alpha 底色时有观察通道。三个 setter 分开调，壳就得靠"调用顺序对了
 *   才对"来调和；一次拿到完整快照，调和变成按值的确定性逻辑。
 *   （"要无边框"那条的**校验**在 PHP 侧 `Win32WindowHost::applyMaterial()` ——
 *   壳看不见调用历史。）
 *
 * ★ `$pixelAlpha` 的语义是**声明**不是"每帧从像素里猜"：模式切换要
 *   `SetWindowLongPtr` + `SWP_FRAMECHANGED`（DWM 重建表面），是重操作；
 *   底色是应用级常量，声明一次就够。
 * ★★ **本函数不因 `$pixelAlpha` 动窗口样式**（老 `set_pixel_alpha` 的坑）：
 *   只置位标志，真正挂 `WS_EX_LAYERED` 推迟到**第一次 present**。挂着 LAYERED
 *   而 LWA / ULW 一个都没调过时窗口可能完全不显示 ⇒ 首帧前调它会闪一下。
 * ★ **整窗 alpha 的懒挂**：`$opacity < 1` 且非逐像素模式时挂 LWA（其余窗口保持
 *   纯 GDI 上屏）；逐像素模式下 `$opacity` 由每次 present 的
 *   `BLENDFUNCTION.SourceConstantAlpha` 带上 ⇒ **两种模式语义一致**。
 * ★ `$backdropKind` 走**未公开**的 `user32!SetWindowCompositionAttribute`
 *   （accent 策略）：`DWMWA_SYSTEMBACKDROP_TYPE` 要 Win11 22621+，本机是
 *   **Win10 19045**（实测该属性返回失败、画面零变化）⇒ 这是唯一一条路，
 *   `GetProcAddress` 动态取，取不到静默 no-op。
 *   ★ 真机实测（Win10 19045，`--screen --detail`，全透明底 + 无边框，窗口 900×560，
 *   判据 = 相对同一块矩形的桌面基准，读数逐位可复现）：基准 `detail=4.80`；
 *   对照组 `4.65`；磨砂 **1.57**（33%）；轻模糊 **0.87**（18%）；改尺寸后模糊仍在；
 *   与 `$opacity=0.6` 叠加两者同时生效。色调（`GradientColor`）四档 alpha 同背景
 *   连测 `detail` 一致 ⇒ **强度旋钮不发货**，固定 `0x99000000`；轻模糊传 `0`
 *   （alpha=0 时 `BLURBEHIND` 照样生效）。`0`（关）只验了"调用不出错"。
 * ★ 失败静默（外观增强，不是正确性）：返回 `void`，调用方不需要分支。
 */
function drift_win_apply_material(int $hwnd, bool $pixelAlpha, float $opacity, int $backdropKind): void {}

/**
 * 当前平台对窗口材质各旋钮的**实际支持**（位掩码；位值 = ABI，与
 * `Drift\Platform\WindowMaterialCapabilities::fromBits()` 一一对应）：
 *   · 位 1（`0x01`）  整窗不透明度（LWA，Win2000+，实际恒置位）
 *   · 位 2（`0x02`）  逐像素透明（`UpdateLayeredWindow`，XP+；**仍要求无边框**）
 *   · 位 4（`0x04`）  背后轻模糊（accent 策略入口探得到）
 *   · 位 8（`0x08`）  背后磨砂（同上；Win11 的 `DWMWA_SYSTEMBACKDROP_TYPE`
 *                     路线接入后并进这一位）
 *   · 位 16（`0x10`） 系统圆角（`DWMWCP_ROUND` 要 Win11 build 22000+；Win10 上
 *                     API 返回 S_OK 但**静默不裁角** —— 2026-10-26 真机证伪过
 *                     "分层窗照样裁角"的误判，所以这里按 OS 版本如实报 false）
 *
 * ★ 未知位置零 —— 前向兼容：新平台多出的位不该让旧应用崩。
 * ★ 结果进程内缓存：答案在进程生命周期内不变（OS 版本 / API 入口不会中途变）。
 */
function drift_win_material_caps(): int {}

/**
 * 无边框模式（P1-Stage 1b / §质感）。`$dragHeight` = 顶部拖动带高度（**物理**像素）。
 *
 * ★ 走 `WM_NCCALCSIZE`（客户区=整窗）而**不改窗口样式** ⇒ 贴边吸附 / 快照布局 /
 *   最大化动画全部由系统保留（`WS_POPUP` 会丢掉它们）。opt-in：调用才置位。
 * ★ 去标题栏后**原生最小化/最大化/关闭按钮一并消失** ⇒ 应用要在 AppBar 里自绘
 *   窗口控制按钮（`drift_win_minimize` / `drift_win_maximize_toggle` / `drift_win_close`）。
 */
function drift_win_set_frameless(int $hwnd, int $dragHeight): void {}

/**
 * 由应用内容发起窗口拖动（无边框窗没有原生标题栏可抓）。
 *
 * ★ 实现：`ReleaseCapture()` + `WM_NCLBUTTONDOWN/HTCAPTION` ⇒ 交给系统走原生
 *   标题栏拖动（贴边吸附随之生效）。消费者 = `RenderAppBar`（命中自己而非子按钮时）。
 */
function drift_win_begin_drag(int $hwnd): void {}

function drift_win_minimize(int $hwnd): void {}

function drift_win_maximize_toggle(int $hwnd): void {}

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

/**
 * 客户区左上角的屏幕坐标（**物理**像素，`['x'=>float,'y'=>float]`）。
 *
 * 空数组 = **当前位置不可信**：① 窗口最小化（`IsIconic` —— 那时 Windows 把它
 * 挪在屏幕外，照抄回来下次启动"窗口消失"）；② `GetClientRect`/`ClientToScreen`
 * 失败。⇒ PHP 侧译成 `null`（`WindowStateStore::position()`），由持久化方
 * 选择跳过。**这不是错误返回值**，是一个有语义的"别记"。
 *
 * ★ 为什么给**物理**而不是逻辑：`ClientToScreen` 的结果恒为物理（屏幕坐标没有
 *   DPI 感知一说），壳在这里做除法只会多一个取整点（§6.1）。逻辑化在
 *   `Win32WindowHost::position()` 按当前窗口 DPI 做一次。
 */
function drift_win_window_position(int $hwnd): array {}

/**
 * 一次原子还原窗口几何（读回上次退出时的位置 / 尺寸 / 最大化，§5.4）。
 *
 * 收**逻辑**像素（客户区原点 + 客户区尺寸，与 `drift_win_create` 同口径），
 * 壳负责剩下全部：外框换算（`AdjustWindowRectExForDpi`）、显示器夹取、
 * 最大化状态（`SetWindowPlacement` 的 `WINDOWPLACEMENT` 一次给全）。
 *
 * ★ **一次到位的理由**：分两次（先尺寸后位置）会让用户看到窗口跳一下，
 *   且"以最大化启动、还原后回到哪"这两件事在 Windows 里本来就是一起给的。
 * ★ **夹取归壳**：目标矩形与任何显示器工作区都不相交 ⇒ 落到最近显示器的工作区；
 *   部分越界 ⇒ 整体挪回来（保尺寸）。屏幕几何是平台知识，PHP 不该有。
 * ★ 尺寸非正（调用方传了垃圾）⇒ 直接返回不动窗口；**不报错**：调用方紧接下来
 *   会读 `clientSize()` 拿实际几何，对不对当下就知道。
 */
function drift_win_place_window(
    int $hwnd,
    float $x,
    float $y,
    float $w,
    float $h,
    bool $maximized
): void {}

/**
 * 用户级配置目录（`%APPDATA%\Drift`；失败 = 空字符串，§5.4）。
 *
 * ★ **只给路径，不创建目录**：问一次路径不该有副作用；创建归写入方。
 * ★ 空字符串 = 平台答不出来 ⇒ `WindowStateStore` 静默跳过持久化。
 * ★ 为什么 PHP 侧不能自己 `getenv('APPDATA')`：§16.7 的 `nano-hygiene`
 *   编译期就拒 `getenv` ⇒ "用户的文件放哪"是**平台能力**，只能由壳回答。
 */
function drift_win_config_dir(): string {}

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

/**
 * 报 IME 插入符位置（§8.4）—— x/y/h 是**设备像素**的客户区坐标，(-1,-1,0) = 无插入符。
 *
 * ★ **必须带 hwnd**（2026-10-30）：Windows 的插入符是**线程级**的，
 *   "该贴哪个窗口"只有调用方知道。用"最后建的窗"在多窗口下就是
 *   "窗口 A 打字的候选框贴在 B 上"。
 */
function drift_win_ime_caret(int $hwnd, float $x, float $y, float $h): void {}

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
 * | `wheel` | `x` `y`（**物理**像素，客户区原点） `delta`（WHEEL_DELTA=120 为一格；**壳已取负** ⇒ 正=回拉=内容上移=看到下面，与触控板"上划看到下面"同族） | `WM_MOUSEWHEEL`（坐标经 `ScreenToClient` 换算） |
 * | `files-dropped` | `x` `y`（**物理**像素，客户区原点 = `DragQueryPoint` 落点） `paths`（UTF-8，`'\n'` 分隔的多路径原始串；空串 = 零个文件） | `WM_DROPFILES`（`DragAcceptFiles` 开窗接受拖放） |
 * | `close-requested` | — | `WM_CLOSE`（**仅当该窗 `drift_win_set_close_guard(hwnd, true)`**；否则直接销毁、不出事件） |
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

/**
 * 当前线程 id（`GetCurrentThreadId`，读 TEB，无 syscall）。
 *
 * ★ 为什么必须有它：**`getmypid()` 区分不了线程**（同进程所有线程 pid 相同）⇒
 *   "UI 线程身份 / 跨线程断言"只能由壳给。它无状态、不碰任何 OS 句柄，
 *   是最小的一条桥（§17.4.2.1）。
 */
function drift_thread_id(): int {}

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

/**
 * 把 **Skia 画布**直接贴到窗口客户区 —— 不经过 PHP 的像素形态。
 *
 * ★★ 为什么要有它（而不是复用 `drift_win_present`）：旧路径每帧把同一批像素做
 *   两次**方向相反**的 swizzle，外加一次 4MB 跨界：
 *   ```
 *   drift_skia_read_pixels()   BGRA → RGBA  （为了喂 present 的入参格式）
 *   php_drift_win_present()    RGBA → BGRA  （为了喂 32bpp DIB）
 *   ```
 *   而 Skia 的 N32 在小端上**本来就是 BGRA**，32bpp `BI_RGB` DIB 要的也是 BGRA
 *   ⇒ 两次交换纯属白做。实测它们合计占端到端 **50%+**（`26` §5：
 *   toRgba 30% + present 20.5%）。本函数把这条链缩成一次 `memcpy`。
 *
 * ★ `$canvas` 是 `SkiaCanvas` 的**不透明句柄**（拿到它要 `SkiaCanvas::handle()`）。
 *   无效 / 已销毁 ⇒ 这一帧没贴（与 `drift_win_present` 的"整帧丢弃"同档，
 *   不是"应用该崩"）。
 *
 * ★ 壳仍然**缓存**这一帧（`WM_PAINT` 重贴）—— 语义与 `drift_win_present` 逐字相同，
 *   只是少了两步换算。窗口被遮住再露出来照样不会白屏。
 */
function drift_win_present_skia(int $hwnd, int $canvas): void {}

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

/**
 * 推送语义树扁平节点给 UIA 无障碍（§9 / M5，P2 数据流）。
 *
 * `$nodes` 是 `SemanticsTree::toArrays()['nodes']`：关联数组列表，每个含
 * `role` / `type` / `name` / ... 标量字段（**前序**，父在子前）。**空数组 =
 * 无障碍关闭**（清空 C 侧树）。
 *
 * ★ **只传标量**（§5.1 纪律 1）：节点已是扁平关联数组，无对象 / 无 OS 句柄跨边界。
 * ★ C 侧 provider 从这份数组应答 UIA 客户端（P2 只到"根属性可达"，树导航归 P3）。
 */
function drift_win_uia_push(int $hwnd, array $nodes): void {}

// ---- 剪贴板（§5.1 壳服务，M3 文本编辑三件套；只做纯文本 CF_UNICODETEXT） ----

/**
 * 读纯文本；剪贴板为空 / 不是文本 / 被别的进程占着（重试后仍打不开）⇒ 空串。
 *
 * ★ **空串不是错误**（端口契约）：调用方无法区分"真为空"与"读失败"——
 *   这个区分对上层没有价值（粘贴一个空串 = 什么都不做）。
 */
function drift_win_clipboard_read_text(): string {}

/** 写纯文本（覆盖现有内容）。失败静默 —— 端口契约是 `void`，见 .cc 注释 */
function drift_win_clipboard_write_text(string $text): void {}

/** 剪贴板上有没有纯文本 —— 让"粘贴"能先判可用性再改状态 */
function drift_win_clipboard_has_text(): bool {}

// ---- 原生对话框（§5.1 壳服务，A5-①；`Drift\Platform\Dialog` 的 Win32 实现） ----
//
// ★★ **这批函数一律阻塞**（`GetOpenFileNameW` / `MessageBoxW` 都是模态）：模态期间
//   系统跑自己的内层消息循环，PHP 侧**看不到一帧**（`Application::frame()` 不被调用）。
//   这是原生对话框的固有语义，不是缺陷 —— 但也意味着**不能在动画/滚动的回调里调**。
// ★ **`$owner` 是主窗口句柄**（`0` = 没有主窗口）：没有 owner 的模态框会跑到别的
//   程序后面去（`MB_TASKMODAL` 只挡住**本进程**的窗口，挡不住 z 序）。

/**
 * 选一个**已有**文件；用户取消 ⇒ 空串。`$owner` 见本节头注。
 *
 * `$filters` 是 `Win32Dialog::filterText()` 的产物：`显示名\t通配符` 每行一条，
 * 行间 `\n`。**分隔符是 `\t` / `\n` 而不是 NUL**：NUL 穿过 ABI 之后在调试器里
 * 全是一样的点，看不出来；而双 NUL 结尾是 `OPENFILENAMEW` 要的**最终形态**，
 * 由 C 侧按行拼出来（一次转换，PHP 侧不持有 Win32 编码细节）。
 *
 * ★ 空串 = **取消**。`CommDlgExtendedError()` 非 0 才是真失败，而端口
 *   （`Dialog::openFile(): ?string`）只能表达"取消 / 成功"两态 ⇒ 真失败由壳
 *   自己弹 MessageBox 喊出来，**不静默当成取消**（§1.2 的响亮失败）。
 */
function drift_win_open_file(int $owner, string $title, string $filters, string $defaultName): string {}

/** 选一个**保存**位置；取消 ⇒ 空串。`$defaultName` 只填文件名，不带目录 */
function drift_win_save_file(int $owner, string $title, string $filters, string $defaultName): string {}

/** 只有一个"确定"的提示框（阻塞到用户点掉） */
function drift_win_alert(int $owner, string $title, string $body): void {}

/**
 * 是 / 否（阻塞）；用户关框 ⇒ `false`。
 *
 * ★ 默认按钮是**"否"**（`MB_DEFBUTTON2`）：破坏性动作的默认不能是"执行"。
 */
function drift_win_confirm(int $owner, string $title, string $body): bool {}
