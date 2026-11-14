<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;

/**
 * **窗口帧**的布局宽容：空间不够时裁剪，不中止进程。
 *
 * ## 为什么存在（对齐成熟框架）
 *
 * 窗口的客户区是**用户的东西**，不是程序的东西。用户可以把窗口拖到任意大小，
 * 而"内容比窗口宽"是**合法状态**，不是编程错误。成熟框架一律这么处理：
 *
 * | 框架 | 容器装不下内容时 |
 * |---|---|
 * | Flutter | debug 画黄黑条纹 + 打印错误，**release 静默裁剪**，进程从不中止 |
 * | Qt | 布局给出 `minimumSizeHint`，比它小就是裁剪；没有"中止"这条路 |
 * | WPF / Win32 原生 | 没有这个错误 —— 子元素超出父边界就是画到边界外、被裁 |
 * | Android View / iOS Auto Layout | 裁剪；Auto Layout 冲突时**删一条约束并打日志** |
 * | CSS | `overflow: visible` 溢出可见 / `auto` 出滚动条，页面不会因此崩 |
 *
 * 共同点：**"装不下"是呈现问题，只有"程序性不变式被破坏"才配中止。**
 *
 * ## 所以严格性放哪
 *
 * 分界线是**这一层有没有窗口**：
 *
 * | 层 | 约束从哪来 | 装不下时 |
 * |---|---|---|
 * | `Frame::run()` / `RenderBox::layout()`（纯布局） | 调用方给的 | **中止**（真溢出要有人管） |
 * | `Application::frame()` / `repaintForResize()`（窗口帧） | 窗口客户区 | **裁剪 + 留痕**（用户拖小是合法的） |
 *
 * 测试与快照（`tools/Inspect/Snapshot.php` 的 `ofPage()`、golden）都走**纯布局**那一侧
 * ⇒ 开发者写错布局照样红。牙齿没有被拔掉，只是从"杀用户的进程"挪到了"红测试"。
 *
 * ## 裁剪不静默
 *
 * 每次裁剪都调 {@see clipped()} 留一条 DIAGNOSTIC（**同类只报一次**，否则拖动时
 * 每个像素一条、日志会被刷满）。测试断言"发生了什么"，用户看日志知道"窗口太小"。
 *
 * @internal 只由 `Application` 的两个帧入口在 try/finally 里翻转
 */
final class LayoutTolerance
{
    /**
     * 当前这次布局的可用空间是否来自**窗口客户区**。
     *
     * `true` ⇒ `RenderFlex` / `RenderDock` 的 `Overflow::Report` 与 `RenderBox`
     * 的 C6 校验一律降级为裁剪（并留一条 diagnostic）。
     *
     * ★ 为什么是进程级静态开关而不是参数：`RenderBox::layout()` 是全树入口，
     *   参数要逐层透传（每个容器的每个子盒），而校验点在最内层；调用链上没有任何
     *   一处天然持有"这是不是窗口帧"。`Errors` 本身就是进程级语义，这里同构。
     */
    public static bool $windowDriven = false;

    /**
     * 尺寸比较的浮点容差（**百分之一像素**）。
     *
     * ★ 为什么必须有：主轴等分 N 份再求和会累积浮点误差 —— 实测 7 列等分
     *   344px 得 `344.00000000000006`，裸 `>` 判定就成了"子项共需 344.0，
     *   可用只有 344.0（差 0.0）"的 Fatal（2026-10-29，`CalendarView` 的 7 列网格）。
     *   这种量级在任何渲染管线上都不可见，而真溢出（≥ 0.5px）照样报。
     */
    public const float EPSILON = 0.01;

    /** @var array<string, true> 已留痕的裁剪（键 = 触发者类名） */
    private static array $noted = [];

    /**
     * 在"窗口帧"语义下跑一段布局（**唯一**的置位入口）。
     *
     * @template T
     *
     * @param \Closure():T $layout
     *
     * @return T
     */
    public static function withinWindow(\Closure $layout): mixed
    {
        $previous = self::$windowDriven;
        self::$windowDriven = true;

        try {
            return $layout();
        } finally {
            self::$windowDriven = $previous;
        }
    }

    /**
     * 记一次"因窗口太小而裁剪"。
     *
     * @param string $scope 触发者（类名）
     * @param string $message 人话：**发生了什么 / 这不是崩溃 / 怎么恢复 / 若想换行该怎么改**
     */
    public static function clipped(string $scope, string $message): void
    {
        // ★ 去重键是 **`类名 + 消息`**，不是只有类名（2026-10-30 改）。
        //   只按类名去重会在**第一处**溢出后把同类的其余全部吞掉 —— 而一条消息里带着
        //   "共需多少 / 可用多少 / 子项各多宽"，不同位置的消息本来就不同，去重不会失效。
        //   实证：示例台窄窗审计一开始每页只报一条，改完才发现每页其实有 2~3 处。
        //   ★ 同一个位置每帧都会走到这里，消息逐字相同 ⇒ 仍然只报一次，拖动不刷屏。
        $key = $scope . "\0" . $message;
        if (isset(self::$noted[$key])) {
            return;
        }

        self::$noted[$key] = true;

        Errors::report($message . "\n（同一处裁剪只报告一次，避免拖动时每像素一条把日志刷满）", Errors::DIAGNOSTIC);
    }

    /** @internal 测试清场（每条用例都要从"没报过"开始，否则第二条恒真） */
    public static function reset(): void
    {
        self::$noted = [];
        self::$windowDriven = false;
    }
}
