<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

/**
 * 拖动重排入口的**静态槽**（§5.2 拖动路径）。
 *
 * ★ 为什么是静态槽而不是 `Application` 上的字段：壳调的是**具名函数**
 *   （见 `ResizeHook.php`），拿不到 Application —— 只能从进程级静态槽取
 *   "该谁跑帧"。组合根（Bootstrap）注入，`attach()` 之后才有效。
 *
 * ★ **槽是"按窗口分发的函数"，不是"一个闭包"**（2026-10-30，M1-b）：
 *   壳已经知道是哪个窗口在拖（`pumpResizeHook(HWND)`），把它传进来，
 *   由注入方按句柄找对应的应用 / 表面 / 视口。改动前签名是 `callable():void`
 *   ⇒ 多窗口下第二个窗口的拖动会去重排第一个窗口（**不报错、只画错窗**）。
 *
 * ★ 槽为空 ⇒ no-op：smoke / 离屏渲染从不注入，也就不存在重入问题。
 *
 * ★ `@api`：它和 `WindowHost::pushSemantics()` 一样是**组合根的注入点**
 *   （那条链路也是"框架不持平台 ⇒ 组合根接线"），不是内部实现细节。
 */
final class ResizeHookState
{
    /**
     * @var null|callable(int):void 注入的是"给**这个**窗口跑一帧"
     *                              （resize 表面 + frame + present）
     */
    public static $pump = null;

    /** @internal 由全局入口 `drift_resize_hook_pump()` 调 */
    public static function pump(int $windowHandle): void
    {
        $pump = self::$pump;
        if ($pump === null) {
            return;
        }
        $pump($windowHandle);
    }
}
