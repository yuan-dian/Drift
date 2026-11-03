<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Rendering\Canvas;

/**
 * 夹具：**不该响**。四件"看着像但不是":
 *
 * ① 走 `paintChildAt()` —— 规范写法，四行在基类里；
 * ② 平移量是**普通数值**（`$dx` / `$dy`）不是子盒的 `offsetX()/offsetY()` ——
 *    `RenderButton` / `RenderMenuItem` 就是这样把子盒往上抬一个 padding；
 * ③ 两个参数来自**不同**变量（`$a->offsetX()` + `$b->offsetY()`）—— 那不是在
 *    画某个子盒，规则用 `\1` 反向引用判"同一个变量"，所以不会误伤；
 * ④ 子盒 offset 恒 `(0,0)` 时直接 `paintComposed()` —— 不需要平移，
 *    推着它改成 `paintChildAt()` 只是多两次 `save/restore`。
 */
final class PaintShiftGood extends RenderBox
{
    private ?RenderBox $child = null;
    private float $dx = 0.0;
    private float $dy = 0.0;

    public function setChild(RenderBox $child): void
    {
        $this->child = $child;
        $this->adoptChild($child);
    }

    public function visitChildren(callable $visitor): void
    {
        $kid = $this->child;
        if ($kid !== null) {
            $visitor($kid);
        }
    }

    protected function paintContent(Canvas $canvas): void
    {
        $kid = $this->child;
        if ($kid === null) {
            return;
        }

        // ① 规范写法
        $this->paintChildAt($canvas, $kid);

        // ② 普通数值平移
        $canvas->save();
        $canvas->translate($this->dx, $this->dy);
        $kid->paintComposed($canvas);
        $canvas->restore();

        // ③ 两个参数不同源
        $other = $kid;
        $canvas->save();
        $canvas->translate($kid->offsetX(), $other->offsetY());
        $kid->paintComposed($canvas);
        $canvas->restore();

        // ④ offset 恒 0 ⇒ 不需要平移
        $kid->paintComposed($canvas);
    }
}
