<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Rendering\Canvas;

/**
 * 多子盒的实现面：`kids` 的增删、遍历与绘制序。
 *
 * 四个盒的布局算法毫无共同点，故只复用容器层；类型识别走 `ChildContainer` 接口，
 * 不换父类（D2 裁决）。Flutter 的 `ContainerRenderObjectMixin` 同理。
 *
 * 宿主声明 `private array $kids = []` 即可；`adoptChild` / `paintChildAt` 来自基类。
 */
trait MultiChildBox
{
    public function addChild(RenderBox $child): void
    {
        $this->kids[] = $child;
        $this->adoptChild($child);
    }

    /** @internal 重建前清空 —— 否则同一次 rebuild 会把子盒追加两遍 */
    public function clearChildren(): void
    {
        $this->kids = [];
    }

    /** @return list<RenderBox> */
    public function children(): array
    {
        return $this->kids;
    }

    public function visitChildren(callable $visitor): void
    {
        foreach ($this->kids as $kid) {
            $visitor($kid);
        }
    }

    protected function paintContent(Canvas $canvas): void
    {
        // 绘制序 = children 顺序：后加的压在上面
        foreach ($this->kids as $kid) {
            $this->paintChildAt($canvas, $kid);
        }
    }
}
