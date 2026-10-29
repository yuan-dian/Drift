<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Layout\RenderBox;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Stack;
use Drift\Widget\Widget;

/**
 * `Widget::disabled()` 夹具（2026-10-29）。
 *
 * 主树五块，每块钉一条不同的判断：
 *
 * | # | 内容 | 钉什么 |
 * |---|---|---|
 * | 0 | `enabled` 普通按钮 | 基线：同一条点击链在**没禁用**时确实会触发（否则下面几条的"没反应"是假绿） |
 * | 1 | `off` 禁用按钮 | 点它不触发 |
 * | 2 | `back` `->disabled(false)` | 显式恢复：等价于没声明，不是"声明过就永久禁用" |
 * | 3 | 禁用 `Column` 套一个 `inner` 按钮 | 禁用是**子树级**，不是只挡自己 |
 * | 4 | `Stack[under(普通), over(禁用)]` | 事件被**吞**而不是穿透 —— 点到 `over` 不能落到它下面的 `under` |
 *
 * ★ 第 4 块必须在 `Stack` 里重叠：不重叠时"没穿透"和"根本没命中那一层"看不出区别。
 * ★ 全部走真指针（`PointerEvent::down/up`）而不是 `activate()`：直接激活会绕过
 *   命中测试，而"点不动"这条判据的**全部**价值就在命中测试里。
 *
 * @internal 测试夹具
 */
final class DisabledApplication extends Application
{
    /** @var list<string> 触发过 `onPressed` 的标签（按发生顺序） */
    public array $pressed = [];

    public function buildRoot(): Widget
    {
        return Column::make()->spacing(10.0)->children([
            $this->button('enabled'),
            $this->button('off')->disabled(),
            $this->button('back')->disabled(false),
            Column::make()->disabled()->children([
                $this->button('inner'),
            ]),
            Stack::make()->children([
                $this->button('under'),
                $this->button('over')->disabled(),
            ]),
        ]);
    }

    private function button(string $label): Button
    {
        return Button::make($label)->onPressed(function () use ($label): void {
            $this->pressed[] = $label;
        });
    }

    /** 标签为 `$label` 的按钮元素（文档序第一个） */
    public function elementOf(string $label): ?Element
    {
        $found = null;

        $walk = function (Element $el) use (&$walk, &$found, $label): void {
            if ($found !== null) {
                return;
            }

            $widget = $el->widget();
            if ($widget instanceof Button && $widget->label() === $label) {
                $found = $el;

                return;
            }

            $el->visitChildren($walk);
        };

        $root = $this->root();
        if ($root !== null) {
            $walk($root);
        }

        return $found;
    }

    public function boxOf(string $label): ?RenderBox
    {
        return $this->elementOf($label)?->effectiveBox();
    }

    /** @return array{0: float, 1: float}|null 该按钮中心的**窗口**坐标 */
    public function centerOf(string $label): ?array
    {
        $box = $this->boxOf($label);
        $size = $box?->size();

        if ($box === null || $size === null) {
            return null;
        }

        [$x, $y] = RenderBox::absoluteOrigin($box);

        return [$x + $size->width / 2.0, $y + $size->height / 2.0];
    }
}
