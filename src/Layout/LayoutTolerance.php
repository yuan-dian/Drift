<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * 布局期的**宽容模式**（进程级开关）。
 *
 * ★ 语义：`true` = "当前这一次布局是**预览**，不可以因为空间不够而中断"。
 *   置位期间所有"布局硬约束校验"降级为**裁剪**：
 *     · `RenderFlex` 的 `Overflow::Report`（主轴溢出）
 *     · `RenderDock` 的条轨溢出
 *     · `RenderBox::layout()` 的 C6 校验（子盒报的尺寸超出父给的上界）
 *
 * ★ 为什么要有它：用户拖窗口边缘时，中间尺寸是**瞬时态**，而"窗口比内容窄/
 *   矮"本身是**合法状态**（用户可以自由把窗口拖小）。这两件事都不该弹 Fatal
 *   打断拖动 —— 成熟框架（Flutter release）遇到 Row 溢出也是裁剪，从不中止。
 *   松手后的正式帧会把开关复位，真溢出仍会响亮失败（C5/C6 的守卫不失效）。
 *
 * ★ 为什么是静态开关而不是构造参数：`RenderBox::layout()` 是**全树**入口，
 *   参数要逐层透传（每个容器的每个子盒），而校验点在最内层；调用链上没有任何
 *   一处天然持有"是不是预览"这个上下文。`Errors` 本身就是进程级语义，这里同构。
 *
 * @internal 只由 `Application::repaintForResize()` 在 try/finally 里翻转
 */
final class LayoutTolerance
{
    /** 预览期（拖动中的瞬时重排）—— `true` 时硬约束校验降级为裁剪 */
    public static bool $previewing = false;
}
