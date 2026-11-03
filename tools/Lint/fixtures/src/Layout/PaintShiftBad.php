<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Rendering\Canvas;

/**
 * 夹具：**该响**。手写了「平移后画子盒」那四行（收口前 10 处逐字重复的形状）。
 *
 * ★ 这条夹具存在的理由与规则本身一致：收口之后**它会再长回来** —— 下一个写
 *   容器的人（或下一个 AI）复制邻近容器的 `paintContent()` 是最自然的动作，
 *   而复制出来的东西看起来完全正确（`php -l` 过、测试过、画面也对，
 *   直到有人写反 `save`/`translate` 的顺序）。
 */
final class PaintShiftBad extends RenderBox
{
    /** @var list<RenderBox> */
    private array $kids = [];

    public function addChild(RenderBox $child): void
    {
        $this->kids[] = $child;
        $this->adoptChild($child);
    }

    public function visitChildren(callable $visitor): void
    {
        foreach ($this->kids as $kid) {
            $visitor($kid);
        }
    }

    protected function paintContent(Canvas $canvas): void
    {
        foreach ($this->kids as $kid) {
            $canvas->save();
            $canvas->translate($kid->offsetX(), $kid->offsetY());
            $kid->paintComposed($canvas);
            $canvas->restore();
        }
    }
}
