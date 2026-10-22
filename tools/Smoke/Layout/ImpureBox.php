<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * @internal 冒烟夹具：**故意不纯**的盒（`04` §12.2 #14 的反例自检）。
 *
 * `performLayout()` 读 `$this->size()` —— 上一帧留下的尺寸。纯函数里这不存在
 * （同样输入 ⇒ 同样输出），所以每重排一次宽就 +1 ⇒ `LayoutSelfCheck` 必然抓到。
 *
 * ★★ **为什么必须有这个夹具**：自校验本身也可能空转 —— 采样没落到这棵树、
 *    快照字段漏了一个、`layout()` 被缓存短路、`$on` 从来没被翻开……
 *    这些失败全是**静默**的（表现是"开关开着但什么都没发生"）。
 *    一条"该响会响"的反例，才能证明开关打开时它真的在守。
 *    ⇒ 与 `tools/selftest.php` 给每条 lint 规则配"该响/不该响"是同一个思路。
 *
 * ★ 与 `SolidBox` 的唯一差别就是那三行读 `$this->size()`；别为它加别的行为，
 *   否则反例触发的**原因**就不唯一了（分不清是"不纯"还是"别的问题"）。
 */
final class ImpureBox extends RenderBox
{
    protected function performLayout(BoxConstraints $constraints): Size
    {
        $prev = $this->size(); // ★ 读上一帧的尺寸 ⇒ 不纯
        $w = $prev === null ? 10.0 : $prev->width + 1.0;

        return new Size($w, 10.0);
    }

    protected function contentIntrinsicWidth(float $height): float
    {
        return 10.0;
    }

    protected function contentIntrinsicHeight(float $width): float
    {
        return 10.0;
    }

    public function visitChildren(callable $visitor): void
    {
    }

    protected function paintContent(Canvas $canvas): void
    {
        $canvas->drawRect(new Rect(0.0, 0.0, 10.0, 10.0), Paint::fill(Color::black()));
    }
}
