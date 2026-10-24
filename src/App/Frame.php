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
use Drift\Widget\State;
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
    /**
     * 上一帧的约束（`05` §5.4 第三步的判据之一）。
     *
     * ★ **四个 float + 一个 bool，而不是一个 `?BoxConstraints`**：可选对象字段在
     *   AOT 下退化成 `php::Var`（`08` 的类型退化表）⇒ 比较与取值都变慢且类型
     *   不确定。这里只做相等判断，摊平存储没有代价。
     */
    private bool $laidOut = false;

    private float $lastMinWidth = 0.0;

    private float $lastMaxWidth = 0.0;

    private float $lastMinHeight = 0.0;

    private float $lastMaxHeight = 0.0;

    /**
     * 上一帧排版时看到的**盒换代序号**（`State::boxEpoch()`）。
     *
     * ★★ 存在的理由是一条真实缺陷（2026-10-24 定位）：`$didRebuild` 来自
     *   `settle()` 的**帧内**重建差，而 `Element::update()` / `applyContext()`
     *   这些**帧外**路径会换盒却不在 settle 里计数 ⇒ 判成"本帧没变" ⇒ 跳过
     *   layout ⇒ 新盒 `size()` 恒 `null` ⇒ **整棵子树命中测试落空**，而 overlay
     *   计数、焦点、声明看起来全对。
     *
     * ★ 判据从"本帧重建过吗"改成"**盒换过代吗**"：`Element::syncBox()` 是唯一的
     *   盒创建点，任何来路的换盒都会让序号变 ⇒ 这条缝在结构上不可能再漏。
     *   而"没重建 ⇒ 序号不变 ⇒ 照旧跳过 layout"的原优化（`05` §5.4 第三步）保留。
     */
    private int $lastBoxEpoch = -1;

    public function __construct(
        private readonly RenderSurface $surface,
    ) {
    }

    /** 约束与上一帧是否不同（没排过 ⇒ 一定不同 ⇒ 必须排） */
    private function constraintsChanged(BoxConstraints $constraints): bool
    {
        return !$this->laidOut
            || $this->lastMinWidth !== $constraints->minWidth
            || $this->lastMaxWidth !== $constraints->maxWidth
            || $this->lastMinHeight !== $constraints->minHeight
            || $this->lastMaxHeight !== $constraints->maxHeight;
    }

    /**
     * 跑一帧。
     *
     * @param BoxConstraints $constraints 表面给出的约束（通常 = 表面尺寸的紧约束）
     * @param float $timestampMs 帧时间戳，由 `FrameClock` 注入，**不读环境时钟**（§5.2 裁决 2）
     * @param list<\Drift\App\OverlayEntry> $overlays 已由 `Application::placeOverlays()` 算好 x/y
     *
     * ★ **浮层的布局段不在这里**：settle 与 paint 两段在本类，**layout 段在
     *   `Application::placeOverlays()`** —— 因为浮层是"自尺寸 + 按锚点钉原点 +
     *   夹进客户区"，三件事都要**锚点**与**客户区尺寸**，而锚点住 `FocusManager`。
     *   Frame 只拿到算好的 `$e->x/$e->y`，画的时候 `translate` 过去。
     *
     * @api
     */
    public function run(
        Element $root,
        BoxConstraints $constraints,
        float $timestampMs,
        array $overlays = [],
    ): void {
        // ① 帧末统一重建（§5.2 裁决 3）：派发中途的 setState 只标脏
        // ★ O-12 A′ 帧循环异常策略 S3 的"无边界"半边：异常从子树一路冒到这里
        //   （沿途没有 ErrorBoundary 捕获）⇒ Fatal 序列。**不吞**——吞掉就是
        //   窗口冻结在上一帧且不报错（G2 明令禁止的半静默）。
        // ★ 分段计时（SurfaceProfile，默认关）：M4 拆「其余(PHP)」黑箱用（`26` §7）。
        $prof = SurfaceProfile::$on;
        $t = $prof ? \hrtime(true) : 0;
        $didRebuild = false;
        try {
            $didRebuild = $root->settle();
            foreach ($overlays as $e) {
                // 浮层各自的帧末重建（W3）；任一浮层重建 ⇒ 本帧不能跳过 layout
                $didRebuild = ($e->element?->settle() ?? false) || $didRebuild;
            }
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
        // ★ **纯动画帧跳过 layout**（arch-v2 `05` §5.4 第三步，W4-L3-5）：
        //   "没 rebuild + 约束没变 ⇒ 盒的 size 与子 offset 与上一帧逐位相同"
        //   —— 前提是 `layout()` 是纯函数，这一点由 `LayoutSelfCheck`（W0）在
        //   真机上守（`04` §5.2：先证明纯，才有资格说不缓存）。
        //   ★ 自校验必须先于本步上线：没有它，跳过 layout 会把"布局不纯"的
        //     后果从**漂移**放大成**完全不更新**（`05` §13 的推翻条件）。
        //
        //   ★★ **第三条判据 `$boxEpoch`**（2026-10-24）：`$didRebuild` 只覆盖
        //     "settle 期间重建过"。帧**外**换盒的路径（直调 `Element::update()`、
        //     `applyContext()` 切主题、`OffstageElement` 放回停放子树）换盒却不
        //     进 settle 计数 ⇒ 只靠前两条会判成"没变"而跳过 layout ⇒ 新盒没有
        //     `size` ⇒ 命中测试全落空（静默）。序号来自唯一盒创建点
        //     `Element::syncBox()`，所以这条判据**不可能漏**。
        //   ★ 读序号的位置必须在 settle **之后**（这样本帧重建造成的换盒也算进来）。
        $boxEpoch = State::boxEpoch();
        if ($didRebuild || $boxEpoch !== $this->lastBoxEpoch || $this->constraintsChanged($constraints)) {
            $this->laidOut = true;
            $this->lastBoxEpoch = $boxEpoch;
            $this->lastMinWidth = $constraints->minWidth;
            $this->lastMaxWidth = $constraints->maxWidth;
            $this->lastMinHeight = $constraints->minHeight;
            $this->lastMaxHeight = $constraints->maxHeight;

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
        }
        if ($prof) {
            SurfaceProfile::add('layout', \hrtime(true) - $t);
        }

        // ②b 布局自校验（`04` §5；默认关，`build/layout.check` 存在时开）
        // ★ 必须放在 layout **之后**：它做的是"用同一约束再排一次"，
        //   排在前面就等于替真实帧排了两遍。
        // ★ **只覆盖主树**：浮层的 layout 在 `Application::placeOverlays()`
        //   （要锚点与客户区尺寸），不在本类 ⇒ 自校验看不见它。
        LayoutSelfCheck::maybeCheck($box, $constraints);

        // ③ paint → ④ present
        // ★ 本段**含** beginFrame 与 endFrame（各自另有计时）⇒ 报告里要扣掉，
        //   剩下的才是「画」自己的（DisplayList 记录 + Skia 光栅）。
        $t = $prof ? \hrtime(true) : 0;
        try {
            $canvas = $this->surface->beginFrame($timestampMs);
            $box->paintComposed($canvas);

            // 浮层画在主树**之后**，按栈顺序逐层 —— 绘制顺序即层叠，不引入 z-index。
            // ★ scrim 不在这里画：模态的遮罩由 `ModalHost`（RenderModalHost）自绘，
            //   再画一次会双绘（`01` §5 的理想形态与本仓现状的差异）。
            foreach ($overlays as $e) {
                $popupBox = $e->element?->effectiveBox();
                if ($popupBox !== null && $popupBox->hasSize()) {
                    $canvas->save();
                    $canvas->translate($e->x, $e->y);
                    $popupBox->paintComposed($canvas);
                    $canvas->restore();
                }
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
