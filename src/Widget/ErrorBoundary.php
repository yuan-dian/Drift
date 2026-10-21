<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderErrorBoundary;

/**
 * **错误边界容器**（§23.2 O-12 A′：反模式 A17 的容器侧）。
 *
 * ```php
 * ErrorBoundary::make(UsersPage::make($store))   // 页面级：一页崩不带走窗口
 * ```
 *
 * ★ **帧循环异常策略 S3**（O-12 已裁）：有边界 ⇒ 容器捕获，该子树换成占位；
 *   没边界 ⇒ 异常冒到 `Frame` 走 Fatal 序列。**没有"帧循环吞掉异常"那一路** ——
 *   窗口冻着不报错是 G2 明令禁止的半静默。
 *
 * ★ **容器本质 = 一个包装 Element + try/catch**（O-12 原话）⇒ 不依赖虚拟化 /
 *   脏矩形，归 M3。三层各管一段：
 *   ① `ErrorBoundaryElement::rebuild()` —— 构造期（widget 构造 / `createElement`
 *      / `mount` / `createRenderObject`）；
 *   ② `ErrorBoundaryElement::settle()` —— build 期（`State::build()` 冒出的异常）；
 *   ③ `RenderErrorBoundary::performLayout()/paint()` —— 渲染期兜底。
 *   父链上**最近**的边界先捕获（外层边界看到的是"子树已换成占位"的健康树）。
 *
 * ★ **为什么不是 `StatefulWidget`**：捕获后不走 `setState` 重建路径 —— 直接换
 *   子树（`updateChildren([ErrorPlaceholder])`，同一套 diff）。`State` 的存在意义
 *   是"跨帧保留的渲染态"，而这里要的恰恰是"这一帧立刻定型"。
 *
 * ★ **诚实边界**：捕获只写 `Errors::DIAGNOSTIC`（进环形缓冲，可被
 *   `recentDiagnostics()` 断言）。不用 `RECOVERABLE` —— 它在 debug 下会整局中止，
 *   与"只坏那一块"矛盾；持久化落盘等 M4 给 `Errors` 补"降级但留痕"的第四级。
 *   占位 UI 本身是持久可见的痕迹（消息原文显示给用户）。
 *
 * @api
 */
final class ErrorBoundary extends RenderBoxWidget
{
    public function __construct(private readonly Widget $kid)
    {
        parent::__construct();
    }

    public static function make(Widget $kid): self
    {
        return new self($kid);
    }

    public function child(): Widget
    {
        return $this->kid;
    }

    #[\Override]
    public function createElement(): Element
    {
        return new ErrorBoundaryElement($this);
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderErrorBoundary();
    }
}
