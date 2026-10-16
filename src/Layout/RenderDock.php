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
 *   `Clip` 要应用显式选）。`Scroll` / `Wrap` 未实现（M3 只有 Clip / Report）。
 *
 * @api
 */
final class RenderDock extends RenderBox
{
    private const SLOTS = ['top', 'left', 'center', 'right', 'bottom'];

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

    public function overflow(): Overflow
    {
        return $this->policy;
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

        $midH = $maxH - $topH - $bottomH;
        if ($midH < 0.0) {
            $midH = $this->reject('纵向', $topH + $bottomH, $maxH);
        }

        // 竖轨：高铺满中带（tight）、宽自然（loose）—— "中带全高竖轨"
        $railHeight = new BoxConstraints(0.0, $maxW, $midH, $midH);
        $leftW = $left?->layout($railHeight)->width ?? 0.0;
        $rightW = $right?->layout($railHeight)->width ?? 0.0;

        $centerW = $maxW - $leftW - $rightW;
        if ($centerW < 0.0) {
            $centerW = $this->reject('横向', $leftW + $rightW, $maxW);
        }

        $center?->layout(new BoxConstraints($centerW, $centerW, $midH, $midH));

        $top?->setOffset(0.0, 0.0);
        $bottom?->setOffset(0.0, $maxH - $bottomH);
        $left?->setOffset(0.0, $topH);
        $right?->setOffset($maxW - $rightW, $topH);
        $center?->setOffset($leftW, $topH);

        return $constraints->constrain(new Size($maxW, $maxH));
    }

    public function intrinsicWidth(float $height): float
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

    public function intrinsicHeight(float $width): float
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

    public function paint(Canvas $canvas): void
    {
        foreach (['center', 'top', 'bottom', 'left', 'right'] as $name) {
            $box = $this->slots[$name];
            if ($box === null) {
                continue;
            }
            $canvas->save();
            $canvas->translate($box->offsetX(), $box->offsetY());
            $box->paint($canvas);
            $canvas->restore();
        }
    }

    /** 溢出裁决：Report ⇒ Fatal；Clip ⇒ 夹到 0（条轨重叠，画面烂但不崩）；其余 ⇒ 未实现 */
    private function reject(string $axis, float $need, float $have): float
    {
        match ($this->policy) {
            Overflow::Clip => null,
            Overflow::Report => Errors::report(sprintf(
                'Dock %s超出：条轨需要 %.6g，可用 %.6g。C5 要求外壳类容器声明策略 —— Report 即响亮失败；若要允许重叠请改用 Clip',
                $axis,
                $need,
                $have,
            )),
            Overflow::Scroll, Overflow::Wrap => Errors::report(sprintf(
                'Dock 的溢出策略 %s 尚未实现（M3 只有 Clip / Report）',
                $this->policy->name,
            )),
        };

        return 0.0;
    }
}
