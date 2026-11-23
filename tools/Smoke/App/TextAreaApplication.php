<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Layout\RenderTextArea;
use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\States;
use Drift\Widget\TextArea;
use Drift\Widget\TextAreaState;
use Drift\Widget\Widget;

/**
 * 多行输入框夹具：一段比视口高的文本 ⇒ 真能滚、真有滚动条。
 *
 * ★ 存在理由与 `ScrollApplication` 同源：`ScrollInputRouter` 的 thumb 命中
 *   走"沿父链找最近 `Scrollable` → 问它的 `ScrollBarHost`"。`TextArea` 是
 *   第三个 `ScrollBarHost` 实现，而**这条链上任何一处认死具体盒类都是静默
 *   失效**（条画得出来、抓不住）。要测它就得有一棵真树。
 *
 * @internal 测试夹具
 */
final class TextAreaApplication extends Application
{
    /** 每行 40 个 CJK：一定折满可用宽（判据依赖它） */
    private const PER_LINE = 40;

    /** 行数：远多于视口装得下的 */
    private const ROWS = 20;

    public function buildRoot(): Widget
    {
        return TextArea::make()
            ->value(str_repeat(str_repeat('中', self::PER_LINE) . "\n", self::ROWS))
            ->minLines(4);
    }

    /** 夹具用：树里的多行输入盒 */
    public function areaBox(): ?RenderTextArea
    {
        return $this->root() === null ? null : $this->findBox($this->root());
    }

    /** 夹具用：多行输入的 State（读选区 / 偏移用） */
    public function areaState(): ?TextAreaState
    {
        $state = $this->root() === null ? null : $this->findState($this->root());

        return $state instanceof TextAreaState ? $state : null;
    }

    private function findBox(Element $element): ?RenderTextArea
    {
        $box = $element->effectiveBox();
        if ($box instanceof RenderTextArea) {
            return $box;
        }

        $found = null;
        $element->visitChildren(function (Element $child) use (&$found): void {
            if ($found === null) {
                $found = $this->findBox($child);
            }
        });

        return $found;
    }

    private function findState(Element $element): ?State
    {
        $state = States::of($element);
        if ($state instanceof TextAreaState) {
            return $state;
        }

        $found = null;
        $element->visitChildren(function (Element $child) use (&$found): void {
            if ($found === null) {
                $found = $this->findState($child);
            }
        });

        return $found;
    }
}
