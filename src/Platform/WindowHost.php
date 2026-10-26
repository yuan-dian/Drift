<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;

/**
 * 窗口：生命周期 + 运行期控制（§5.1 纪律 3："生"与"活"分开）。
 *
 * ★ **句柄是"不透明 int"**（§5.1 纪律 1）：端口方法签名里只出现原语与 Drift 值对象，
 *   OS 句柄（HWND / NSWindow…）**跨接口后不得解引用** —— 解引用只允许发生在
 *   `Drift\Platform\<Os>\` 内部（`Win32Platform::surface()` 里
 *   `reinterpret_cast<HWND>($windowHandle)`）。
 *
 * ★ **指针形状与指针捕获也归这里**（不是新端口）：它们是**窗口级**状态
 *   （`SetCursor` / `SetCapture` 作用于 HWND），与"标题 / 位置 / 尺寸"同族。
 *   ★ **`setCursor()` 2026-10-15 落地** —— `CursorShape` 同日落地 ⇒ 消费者到位，
 *     这正是当初"不预挂"的解除条件。**带 `$windowHandle`**：原记的形状没带，
 *     但同族方法都带，且多窗口时"设哪个窗口的光标"必须有答案。
 *   ★ **`setCapture()` 仍不声明**：它的消费者是 `GestureArena`（§9.1"按下即捕获"，
 *     归 M3）⇒ 继续按 A15 不预挂。形状已定：`setCapture(int, bool): bool`。
 *
 * @api platform
 */
interface WindowHost
{
    /**
     * 建主窗口，返回**不透明句柄**（供 `Platform::surface()` 与同族方法使用）。
     *
     * @param int $width  客户区宽度（**逻辑**像素）
     * @param int $height 客户区高度（**逻辑**像素）
     */
    public function createWindow(string $title, int $width, int $height): int;

    /** 按平台的"显示"语义让窗口可见（Win32：`ShowWindow(SW_SHOW)`） */
    public function show(int $windowHandle): void;

    /**
     * 请求关闭（Win32：`WM_CLOSE`）。
     *
     * ★ 这是**应用唯一的正常退出路径**：关闭 ⇒ `WM_QUIT` ⇒ `Platform::pump()`
     *   返回 `false` ⇒ 组合根的 `while` 跳出。`Platform` 上**不另设 `quit()`**
     *   —— 那会变成第二条等价的退出路径（同一件事两个入口 = 必有一个是死的）。
     */
    public function close(int $windowHandle): void;

    public function setTitle(int $windowHandle, string $title): void;

    /**
     * 客户区**最小**尺寸（**逻辑**像素；0 = 不限制）。§5.4
     *
     * ★ 系统据此挡住"拖到比内容更小"——那种状态会让布局硬校验弹 Fatal，
     *   而它不是编程错误。平台侧负责换算到物理像素与**外框**（标题栏/边框）。
     */
    public function setMinSize(int $width, int $height): void;

    /**
     * 整窗不透明度（0..1；1 = 不透明）。P1 §质感。
     *
     * ★ **平台能力，不是正确性**：不支持的平台可 no-op —— 应用不该因为
     *   "这个平台不能半透明"就报错。Win32 实现走 `WS_EX_LAYERED`（首次 <1 懒挂）
     *   + `SetLayeredWindowAttributes`（**全局** alpha，与既有 GDI 上屏共存）。
     * ★ 逐像素 alpha 是**另一条路**（`RenderSurface::setBackground()` 带 alpha 底色
     *   ⇒ 壳切 `UpdateLayeredWindow`），两者共用 `WS_EX_LAYERED` 但**互斥**；
     *   逐像素模式下本方法的 alpha 由 `BLENDFUNCTION.SourceConstantAlpha` 带上，
     *   语义一致。
     */
    public function setOpacity(int $windowHandle, float $opacity): void;

    /**
     * 窗口背后的材质（`null` = 关）。P1 §质感 / Stage 3。
     *
     * ★ **平台能力，可 no-op**。Win32（Win10 19045 实测）：走未公开的
     *   `user32!SetWindowCompositionAttribute` 的 accent 策略 —— `Blur` 轻模糊、
     *   `Acrylic` 磨砂。Win11 有 `DWMWA_SYSTEMBACKDROP_TYPE`（本机验不了，未接）。
     * ★★ **只在"应用没画到"的地方可见** ⇒ 通常要配合 `RenderSurface::setBackground()`
     *   给带 alpha 的底色，否则模糊被应用内容整块盖住（不是没生效，是看不见）。
     * ★ 与本框架的应用内毛玻璃（`BackdropFilter`）分工：那个模糊**应用自己的内容**
     *   （浮层压在主界面上），这个模糊**窗口背后的桌面**（整窗透出）。
     */
    public function setBackdrop(int $windowHandle, ?Backdrop $backdrop): void;

    /**
     * 无边框模式（P1-Stage 1b / §质感）。`$dragHeight` = 顶部拖动带高度（**逻辑**像素）。
     *
     * ★ **平台能力，可 no-op**。Win32 走 `WM_NCCALCSIZE`（客户区=整窗，样式不变）
     *   ⇒ 原生贴边吸附 / 快照布局 / 最大化动画保留。
     * ★ 去标题栏会**一并去掉原生最小化/最大化/关闭按钮** ⇒ 应用要在 AppBar 里自绘，
     *   接 `minimize()` / `maximizeToggle()` / `close()`。
     */
    public function setFrameless(int $windowHandle, int $dragHeight): void;

    /**
     * 由应用内容发起窗口拖动（无边框窗没有原生标题栏可抓）。
     *
     * ★ 消费者是 `AppBar`（命中自己而非子按钮时调）。平台能力，可 no-op。
     */
    public function beginDrag(int $windowHandle): void;

    /** 最小化（无边框窗自绘的窗口按钮接这里；非无边框时等价原生按钮） */
    public function minimize(int $windowHandle): void;

    /** 最大化 ⇄ 还原（无边框窗自绘的窗口按钮接这里） */
    public function maximizeToggle(int $windowHandle): void;

    /**
     * 客户区尺寸（**逻辑**像素；物理像素的换算归 `Screen::scale()`） */
    public function clientSize(int $windowHandle): Size;

    /**
     * 客户区左上角的**屏幕坐标**（**逻辑**像素；供 `WindowStateStore` 持久化，§5.4）。
     *
     * ★ **客户区原点而不是外框原点**：应用认的永远是"能画的那块"，外框厚度
     *   （标题栏 / 边框）是平台装饰。存外框原点 ⇒ 换主题 / 换 DPI / 换 Windows
     *   版本后还原位置偏几个像素，且偏的是**用户能看见**的那种。
     * ★ **最小化 ⇒ `null`**：那时窗口被挪到屏幕外（Windows 惯例
     *   (-32000,-32000)），照抄会让下次启动"窗口消失"⇒ 平台侧直接说
     *   "当前位置没有意义"，由持久化方选择跳过（保留上一份状态）。
     * ★ **窗口已销毁 ⇒ 最后已知几何**（不是 `null`）：`WindowStateStore::persist()`
     *   跑在退出路径上，而 WM_CLOSE 里壳同步销毁窗口 ⇒ 等它来问时句柄已经死了。
     *   对死句柄问 `GetClientRect` 必然失败 ⇒ 若无此回填，"记住窗口位置"
     *   永远写不出去（静默失败，2026-10-18 真机实测）。实现侧见
     *   `drift_win32.cc` 的 `g_lastGeom*` 缓存（活窗口查询顺手刷 + WM_DESTROY
     *   抓最后一下）。
     *
     * @return Point|null 屏幕逻辑坐标；`null` = 当前位置不可信（最小化）
     */
    public function position(int $windowHandle): ?Point;

    /**
     * 一次原子还原窗口几何（`WindowStateStore::restore()` 的写向侧，§5.4）。
     *
     * ★ **一次到位的理由**：分两次（先尺寸后位置）会让用户看到窗口跳一下，
     *   且最大化语义夹在中间没法表达 —— "以最大化启动、还原后回到哪"是
     *   Windows 的 `WINDOWPLACEMENT` 一起给的两件事，拆开就丢一半。
     * ★ **显示器夹取归平台**：目标矩形与任何显示器的可见工作区都不相交时
     *   （显示器拔了 / 分辨率变小了），由实现决定落点 —— 屏幕几何是平台知识，
     *   `Drift\App` 不该有。实现必须保证"还原后至少有一部分在屏幕上"。
     * ★ 收**逻辑**像素（与 `createWindow()` / `clientSize()` 同口径）；
     *   逻辑↔物理换算在平台侧一次做完（§6.1）。
     */
    public function place(int $windowHandle, Rect $rect, bool $maximized): void;

    /** 供 `WindowStateStore`（M5，§5.4）读回"上次是不是最大化" */
    public function isMaximized(int $windowHandle): bool;

    /**
     * 设置鼠标指针形状（§9.1「一次命中，三方消费」的第 ③ 方）。
     *
     * ★ **收 `CursorShape` 而不是 `int`**：§5.1 纪律 1 允许 Drift 值对象跨端口，
     *   而 `int` 会把"哪个数字是 IBeam"这条知识泄漏到每个调用点。平台只需把它
     *   翻成自己的资源（Win32：`LoadCursorW(IDC_*)`）。
     * ★ **不触发重绘**：光标是**输入态**，不是渲染态 ⇒ 它不需要一帧。
     *
     * @api platform
     */
    public function setCursor(int $windowHandle, CursorShape $shape): void;

    /**
     * 推送语义树扁平节点给 UIA 无障碍（§9 / M5）。
     *
     * ★ **收扁平关联数组而非 `SemanticsTree` 对象**（§5.1 纪律 1：跨端口只原语 +
     *   Drift 值对象，且 `SemanticsTree` 属 `Drift\Input`、不是端口层词汇）——
     *   形状即 `SemanticsTree::toArrays()['nodes']`。**空数组 = 无障碍关闭**。
     * ★ **窗口级能力**（与 `setCursor` / `setTitle` 同族）：UIA provider 挂在 HWND 上。
     *
     * @param list<array<string,mixed>> $flatNodes 前序扁平节点（父在子前）
     *
     * @api platform
     */
    public function pushSemantics(array $flatNodes): void;
}
