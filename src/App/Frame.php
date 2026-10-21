<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Layout\LayoutSelfCheck;
use Drift\Layout\RenderBox;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Throwable;

/**
 * 一帧：flush 脏 Element → layout → paint → present（§5.2）。
 *
 * ★ **为什么这类东西必须住 `Drift\App`**：它同时要碰 `Element`（Widget）与
 *   `CommandRouter`（Commands），而 §4.3 禁 `Widget → Commands`。放进 Widget 层
 *   就得为它再开一个协议例外 —— 而例外是**按文件**登记的，多一个文件多一条缝。
 *
 * ★ 它**不拥有循环**（§5.2 裁决 1）：`pump()` 由平台阻塞等待，帧时钟由平台驱动。
 *   本类只是"被告知该跑一帧时"把三段按顺序做一遍 —— 于是测试可以**手动**驱动
 *   任意帧序列，gif/golden 也能喂固定时间戳（G3 的物质基础）。
 *
 * @api platform
 */
final class Frame
{
    public function __construct(
        private readonly RenderSurface $surface,
    ) {
    }

    /**
     * 跑一帧。
     *
     * @param BoxConstraints $constraints 表面给出的约束（通常 = 表面尺寸的紧约束）
     * @param float $timestampMs 帧时间戳，由 `FrameClock` 注入，**不读环境时钟**（§5.2 裁决 2）
     * @param Element|null $popup 浮层面板（O-20 第二棵根渲染树：菜单 / 模态）；`null` ⇒ 无浮层
     * @param float $popupX 浮层**盒**原点（客户区坐标，已由 `Application` 夹进客户区）
     * @param float $popupY 同上
     *
     * ★ **浮层的布局段不在这里**（O-20 差异表说"三段各加分支"，实际落点是：
     *   settle 与 paint 两段在本类，**layout 段在 `Application::placeMenu()`**）——
     *   因为浮层是"无界约束自尺寸 + 按锚点钉原点 + 夹进客户区"，三件事都要
     *   **锚点**与**客户区尺寸**，而锚点住 `FocusManager`（最近命中）。Frame 只拿到
     *   算好的原点，画的时候 `translate` 过去（绘制顺序即层叠，无 z-index）。
     *
     * @api
     */
    public function run(
        Element $root,
        BoxConstraints $constraints,
        float $timestampMs,
        ?Element $popup = null,
        float $popupX = 0.0,
        float $popupY = 0.0,
    ): void {
        // ① 帧末统一重建（§5.2 裁决 3）：派发中途的 setState 只标脏
        // ★ O-12 A′ 帧循环异常策略 S3 的"无边界"半边：异常从子树一路冒到这里
        //   （沿途没有 ErrorBoundary 捕获）⇒ Fatal 序列。**不吞**——吞掉就是
        //   窗口冻结在上一帧且不报错（G2 明令禁止的半静默）。
        // ★ 分段计时（SurfaceProfile，默认关）：M4 拆「其余(PHP)」黑箱用（`26` §7）。
        $prof = SurfaceProfile::$on;
        $t = $prof ? \hrtime(true) : 0;
        try {
            $root->settle();
            $popup?->settle(); // 第二棵树的帧末重建（O-20）
        } catch (Throwable $e) {
            Errors::report(sprintf(
                '帧循环：未捕获异常冒到帧末（沿途无错误边界）—— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
        if ($prof) {
            SurfaceProfile::add('settle', \hrtime(true) - $t);
        }

        // ② layout
        // ★ 必须用 `effectiveBox()` 而不是 `renderObject()`：应用根常是
        //   `StatelessWidget`（§A.2 的 `UsersRoot` 就是这个形态），它自己没有盒、
        //   只有它底下的 `Column` 有。用 `renderObject()` ⇒ 拿 null ⇒ 直接返回
        //   ⇒ **整屏空白且不报错**（C6 只查上界，不画任何东西也不算违反）。
        //   这正是 G2 要消灭的静默形态。
        $box = $root->effectiveBox();
        if ($box === null) {
            return; // 整棵子树没有任何可绘制内容（例如空页）⇒ 这一帧无事可做
        }

        $t = $prof ? \hrtime(true) : 0;
        try {
            $box->layout($constraints);
        } catch (Throwable $e) {
            Errors::report(sprintf(
                '帧循环：layout 阶段未捕获异常（沿途无错误边界）—— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
        if ($prof) {
            SurfaceProfile::add('layout', \hrtime(true) - $t);
        }

        // ②b 布局自校验（`04` §5；默认关，`build/layout.check` 存在时开）
        // ★ 必须放在 layout **之后**：它做的是"用同一约束再排一次"，
        //   排在前面就等于替真实帧排了两遍。
        // ★ **只覆盖主树**：浮层的 layout 在 `Application::placeMenu()`
        //   （要锚点与客户区尺寸），不在本类 ⇒ 自校验看不见它。
        LayoutSelfCheck::maybeCheck($box, $constraints);

        // ③ paint → ④ present
        // ★ 本段**含** beginFrame 与 endFrame（各自另有计时）⇒ 报告里要扣掉，
        //   剩下的才是「画」自己的（DisplayList 记录 + Skia 光栅）。
        $t = $prof ? \hrtime(true) : 0;
        try {
            $canvas = $this->surface->beginFrame($timestampMs);
            $box->paint($canvas);

            // 浮层画在主树**之后** —— 绘制顺序即层叠，不引入 z-index（O-20）
            $popupBox = $popup?->effectiveBox();
            if ($popupBox !== null && $popupBox->hasSize()) {
                $canvas->save();
                $canvas->translate($popupX, $popupY);
                $popupBox->paint($canvas);
                $canvas->restore();
            }

            $this->surface->endFrame();
        } catch (Throwable $e) {
            Errors::report(sprintf(
                '帧循环：paint 阶段未捕获异常（沿途无错误边界）—— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
        if ($prof) {
            SurfaceProfile::add('paint', \hrtime(true) - $t);
        }
    }
}
