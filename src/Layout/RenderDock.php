<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 外壳盒：五槽布局（§12.1 第 3 条 —— `Dock(top:, left:, right:, bottom:, center:)`）。
 *
 * top / bottom = 全宽横条（自然高），left / right = 中带全高竖轨（自然宽），
 * center = **紧约束填满**剩余矩形（内容区的语义）。偏移全由本盒算（C1：父定位子）。
 *
 * ★ **外壳持久**：五个槽都是普通子盒 —— 换页只换 center 槽的内容
 *   （`PageHost` 内部把旧页 `Offstage` 化），条与轨不重建（§12.1 第 3 条）。
 *
 * ★ **C5/W4**：外壳是会超的容器 ⇒ 溢出策略必填（`Report` 默认响亮失败，
 *   `Clip` 要应用显式选）。★ `Scroll` **不支持**（与 `ScrollView` 职责重叠，`04`
 *   §1058 否决）；`Wrap` 已从 `Overflow` 枚举删除（2026-10-25，类别错误）。
 *
 * @api
 */
final class RenderDock extends RenderBox
{
    private const array SLOTS = ['top', 'left', 'center', 'right', 'bottom'];

    /** @var array<string, RenderBox|null> */
    private array $slots = ['top' => null, 'left' => null, 'center' => null, 'right' => null, 'bottom' => null];

    public function __construct(private readonly Overflow $policy)
    {
    }

    public function setSlot(string $slot, RenderBox $box): void
    {
        $this->slots[$slot] = $box;
        $this->adoptChild($box);
    }

    /** @internal 测试 / devtools 读槽盒 */
    public function slot(string $slot): ?RenderBox
    {
        return $this->slots[$slot] ?? null;
    }

    #[\Override]
    public function overflow(): Overflow
    {
        return $this->policy;
    }

    /** 与 `RenderFlex` 同口径：声明了 `Clip` 就把槽位里超出的部分裁掉 */
    #[\Override]
    public function clipsOverflow(): bool
    {
        return $this->policy === Overflow::Clip;
    }

    public function visitChildren(callable $visitor): void
    {
        // 绘制序 = 访问序：center 先画、条轨后画（chrome 压内容）；
        // 命中测试反向 ⇒ 条轨优先（重叠时点条轨不点内容）
        foreach (['center', 'top', 'bottom', 'left', 'right'] as $name) {
            if ($this->slots[$name] !== null) {
                $visitor($this->slots[$name]);
            }
        }
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;

        $top = $this->slots['top'];
        $bottom = $this->slots['bottom'];
        $left = $this->slots['left'];
        $right = $this->slots['right'];
        $center = $this->slots['center'];

        // 横条：宽拉满（tight）、高自然（loose）—— §12.1 第 3 条"全宽横条"
        $barWidth = new BoxConstraints($maxW, $maxW, 0.0, $maxH);
        $topH = $top?->layout($barWidth)->height ?? 0.0;
        $bottomH = $bottom?->layout($barWidth)->height ?? 0.0;

        // ★★ 无界主轴 ⇒ 取**内容自然高**（§24.2 #18，2026-10-17 修）：
        //   `ScrollView` 给内容的约束就是"主轴无界"（先量你到底有多长）⇒
        //   过去直接把 INF 当自己的高往下传：bottom 槽 offsetY=INF、中带三槽
        //   高 INF ⇒ 绘制链出 INF 坐标（无声劣化）。对照 `RenderFlex` 同条件下
        //   返回内容自然尺寸 —— Dock 漏的是同一条回退，不是新语义。
        //   有界路径（is_infinite=false）逐字节不变。
        if (is_infinite($maxH)) {
            $midH = $this->naturalMidHeight();
            $selfH = $topH + $midH + $bottomH;
            $bottomY = $topH + $midH;
        } else {
            $midH = $maxH - $topH - $bottomH;
            if ($midH < 0.0) {
                $midH = $this->reject('top + bottom', '可用高', $topH + $bottomH, $maxH);
            }
            $selfH = $maxH;
            $bottomY = $maxH - $bottomH; // 有界语义：贴底（reject 夹 0 时与 top 重叠是既定行为）
        }

        // 竖轨：高铺满中带（tight）、宽自然（loose）—— "中带全高竖轨"
        $railHeight = new BoxConstraints(0.0, $maxW, $midH, $midH);
        $leftW = $left?->layout($railHeight)->width ?? 0.0;
        $rightW = $right?->layout($railHeight)->width ?? 0.0;

        $centerW = $maxW - $leftW - $rightW;
        if ($centerW < 0.0) {
            $centerW = $this->reject('left + right', '可用宽', $leftW + $rightW, $maxW);
        }

        $center?->layout(new BoxConstraints($centerW, $centerW, $midH, $midH));

        $top?->setOffset(0.0, 0.0);
        $bottom?->setOffset(0.0, $bottomY);
        $left?->setOffset(0.0, $topH);
        $right?->setOffset($maxW - $rightW, $topH);
        $center?->setOffset($leftW, $topH);

        return $constraints->constrain(new Size($maxW, $selfH));
    }

    /**
     * 中带三槽的自然高（无界主轴分支用）：left / center / right 的
     * `intrinsicHeight` 最大值 —— 与 `intrinsicHeight()` 的 `$mid` 口径一致
     * （那边是公开面，这边是布局执行面，两条读法互为印证）。
     */
    private function naturalMidHeight(): float
    {
        $mid = 0.0;
        foreach (['left', 'center', 'right'] as $name) {
            $box = $this->slots[$name];
            if ($box !== null) {
                $mid = max($mid, $box->intrinsicHeight(INF));
            }
        }

        return $mid;
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        $bars = 0.0;
        foreach (['top', 'bottom'] as $name) {
            $box = $this->slots[$name];
            if ($box !== null) {
                $bars = max($bars, $box->intrinsicWidth($height));
            }
        }

        $mid = $this->slots['center']?->intrinsicWidth($height) ?? 0.0;
        foreach (['left', 'right'] as $name) {
            $box = $this->slots[$name];
            if ($box !== null) {
                $mid += $box->intrinsicWidth($height);
            }
        }

        return max($bars, $mid);
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        $bars = 0.0;
        foreach (['top', 'bottom'] as $name) {
            $box = $this->slots[$name];
            if ($box !== null) {
                $bars += $box->intrinsicHeight($width);
            }
        }

        $mid = 0.0;
        foreach (['left', 'center', 'right'] as $name) {
            $box = $this->slots[$name];
            if ($box !== null) {
                $mid = max($mid, $box->intrinsicHeight($width));
            }
        }

        return $bars + $mid;
    }

    protected function paintContent(Canvas $canvas): void
    {
        foreach (['center', 'top', 'bottom', 'left', 'right'] as $name) {
            $box = $this->slots[$name];
            if ($box === null) {
                continue;
            }
            $this->paintChildAt($canvas, $box);
        }
    }

    /**
     * 溢出裁决：Report ⇒ Fatal；Clip ⇒ 夹到 0（条轨重叠，画面烂但不崩）；
     * `Scroll` ⇒ 响亮失败并指路 `ScrollView`（不支持，不是未实现）。
     *
     * ★ 消息写成三段（**谁**放不下 / **差多少** / **怎么修**）：
     *   · 槽名直说 `top + bottom` / `left + right` —— 不写"横向/纵向"：读者要的是
     *     "去哪改"，槽名就是他写代码时的那个名字。
     *   · 给差额（94 与 93 差 1）：这是"窗口被拖小了"与"内容真的超了"的分界线。
     *   · 两条修法都给：可裁的配 `Clip`，不可裁的去缩小槽内容 —— 只给 `Clip` 会把人
     *     引向"把溢出裁掉"（治标），只说缩内容会漏掉"窗口本就可被拖小"这个合法场景。
     *
     * @param string $slots 哪几个槽合计放不下（`top + bottom` / `left + right`）
     * @param string $available 被抢的维度（`可用高` / `可用宽`）
     */
    private function reject(string $slots, string $available, float $need, float $have): float
    {
        match ($this->policy) {
            Overflow::Clip => null,
            // ★★ 窗口帧（用户把窗口拖小）：裁剪 + 留痕，不中止 —— 同 `RenderFlex`。
            Overflow::Report => LayoutTolerance::$windowDriven
                ? LayoutTolerance::clipped(static::class . ':' . $slots, sprintf(
                    'Dock 放不下：%s 槽共需 %.1f，%s只有 %.1f（差 %.1f）—— 窗口比内容小，超出部分已裁掉。'
                        . "\n" . '这不是崩溃：把窗口调大即可恢复完整显示。',
                    $slots,
                    $need,
                    $available,
                    $have,
                    $need - $have,
                ))
                : Errors::report(sprintf(
                'Dock 放不下：%s 槽共需 %.1f，%s只有 %.1f（差 %.1f）。'
                    . "\n" . '修法：① 把窗口拖大，或缩小这些槽的内容；'
                    . '② 允许裁剪则给 Dock 声明 overflow(Overflow::Clip)（默认策略 Report 会中止）。',
                $slots,
                $need,
                $available,
                $have,
                $need - $have,
                $slots,
            )),
            // ★★ 同 `RenderFlex::handleOverflow()`（2026-10-25，I4）：容器上不支持 `Scroll`
            //   —— 不是"还没做"，是"别这么做"（与 `ScrollView` 职责重叠，`04` §1058 否决）。
            Overflow::Scroll => Errors::report(
                'Dock 不支持 overflow(Overflow::Scroll) —— 容器自己滚动与 ScrollView 职责重叠（已否决）。'
                    . "\n" . '修法：① 把内容包进 `ScrollView`；② 只想裁掉超出部分则用 `Overflow::Clip`。'
            ),
        };

        return 0.0;
    }
}
