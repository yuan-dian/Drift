<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;

/**
 * 窗口：生命周期 + 运行期控制（"生"与"活"分开）。
 * 句柄是"不透明 int"：跨接口后不得解引用（只允许在 `Drift\Platform\<Os>\` 内部）。
 * 指针形状与指针捕获也归这里（窗口级状态）。
 *
 * @api platform
 */
interface WindowHost
{
    /**
     * 建主窗口，返回不透明句柄（供 `Platform::surface()` 与同族方法使用）。宽高为客户区逻辑像素。
     */
    public function createWindow(string $title, int $width, int $height): int;

    /** 按平台"显示"语义让窗口可见（Win32：`ShowWindow(SW_SHOW)`） */
    public function show(int $windowHandle): void;

    /**
     * 请求关闭（Win32：`PostMessageW(WM_CLOSE)`）—— 与用户点 X 同一条路。
     * ★ 应用侧入口且可能被拒绝：带守卫的窗口变 `CloseRequestedEvent` 由应用决定；
     *   调用方不能假设"调完就没了"。执行走 {@see self::destroy()}。
     */
    public function close(int $windowHandle): void;

    /**
     * 执行关闭（`DestroyWindow`），不看守卫。
     * 仅框架可调用（收到 `CloseRequestedEvent` 且应用同意）。应用里出现它 = 绕过守卫。
     * 语义同步：返回时窗口已销毁。
     *
     * @internal 框架专用（见 {@see self::close()}）
     */
    public function destroy(int $windowHandle): void;

    /**
     * 这个句柄还是不是活窗口（`IsWindow`）。
     * ★ 不能用"客户区 0×0"代替：最小化也是 0×0（合法），必须显式存活查询，
     *   否则关窗之后那一轮会对着死句柄跑一帧。
     */
    public function isAlive(int $windowHandle): bool;

    /**
     * 声明关闭要不要先问应用（默认 `false`）。
     * ★ `false` ⇒ `WM_CLOSE` 直接销毁；`true` ⇒ 变 `CloseRequestedEvent`，返回 `false` 则什么都没发生。
     * 窗口级状态（按 HWND 记）。
     *
     * @api platform
     */
    public function setCloseGuard(int $windowHandle, bool $armed): void;

    public function setTitle(int $windowHandle, string $title): void;

    /**
     * 客户区最小尺寸（逻辑像素；0 = 不限制）。
     * 系统挡住"拖到比内容更小"（会让布局硬校验弹 Fatal，非编程错误）；每窗可不同。
     */
    public function setMinSize(int $windowHandle, int $width, int $height): void;

    /**
     * 一次应用窗口外观材质。
     * 收 `WindowMaterial` 值对象而非三个 setter（三者互斥/耦合）；像素归表面，这里只收"模式"。
     * 平台能力：不支持的旋钮 no-op，要问走 `materialCapabilities()`。
     */
    public function applyMaterial(int $windowHandle, WindowMaterial $material): void;

    /**
     * 当前平台对 `WindowMaterial` 各旋钮的实际支持（能力半边）。
     * 位掩码一次性探来、缓存无害；应用问它做自适应，不为报错。
     */
    public function materialCapabilities(int $windowHandle): WindowMaterialCapabilities;

    /**
     * 无边框模式。`$dragHeight` = 顶部拖动带高度（逻辑像素）。
     * 平台能力，可 no-op：Win32 走 `WM_NCCALCSIZE`（客户区=整窗）。去标题栏后原生按钮消失。
     */
    public function setFrameless(int $windowHandle, int $dragHeight): void;

    /**
     * 由应用内容发起窗口拖动（无边框窗无原生标题栏可抓）。
     * 消费者是 `AppBar`。平台能力，可 no-op。
     */
    public function beginDrag(int $windowHandle): void;

    /** 最小化（无边框窗自绘按钮接这里；非无边框等价原生按钮） */
    public function minimize(int $windowHandle): void;

    /** 最大化 ⇄ 还原（无边框窗自绘按钮接这里） */
    public function maximizeToggle(int $windowHandle): void;

    /** 客户区尺寸（逻辑像素；物理像素换算归 `Screen::scale()`） */
    public function clientSize(int $windowHandle): Size;

    /**
     * 客户区左上角屏幕坐标（逻辑像素；供 `WindowStateStore` 持久化）。
     * ★ 窗口已销毁 ⇒ 返回最后已知几何（非 null）：持久化在退出路径上跑，句柄已死，
     *   无回填则"记住窗口位置"永远写不出去（静默失败）。
     * 客户区原点而非外框原点（外框是平台装饰）；最小化 ⇒ `null`（窗口被挪到屏外）。
     *
     * @return Point|null 屏幕逻辑坐标；`null` = 当前位置不可信（最小化）
     */
    public function position(int $windowHandle): ?Point;

    /**
     * 一次原子还原窗口几何（`WindowStateStore::restore()` 的写向侧）。
     * 分两次会让窗口跳一下，且最大化语义夹中间没法表达。显示器夹取归平台；收逻辑像素。
     */
    public function place(int $windowHandle, Rect $rect, bool $maximized): void;

    /** 供 `WindowStateStore` 读回"上次是不是最大化" */
    public function isMaximized(int $windowHandle): bool;

    /**
     * 设置鼠标指针形状。
     * 收 `CursorShape` 而非 `int`（避免把"IBeam 是哪个数字"泄漏到调用点）。不触发重绘（输入态）。
     *
     * @api platform
     */
    public function setCursor(int $windowHandle, CursorShape $shape): void;

    /**
     * 推送语义树扁平节点给 UIA 无障碍。
     * 收扁平关联数组而非 `SemanticsTree` 对象（空数组 = 无障碍关闭）；UIA provider 挂 HWND，每窗一个。
     *
     * @param list<array<string,mixed>> $flatNodes 前序扁平节点（父在子前）
     *
     * @api platform
     */
    public function pushSemantics(int $windowHandle, array $flatNodes): void;
}
