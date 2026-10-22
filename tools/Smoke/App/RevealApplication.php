<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\ScrollView;
use Drift\Widget\ScrollViewState;
use Drift\Widget\SizedBox;
use Drift\Widget\StatefulElement;
use Drift\Widget\Widget;

/**
 * 键盘可达性夹具：**视口装不下的焦点链**（`04` §6.3 的 `ensureVisible`）。
 *
 * ★ 存在理由：`ensureVisible` 的"真消费"是"Tab 换焦点 ⇒ 把新焦点滚进视口"。
 *   不测这条，`revealDelta()` 的算术再对也只是"写了但没被走通过"的分支
 *   （§24.2 #25 的教训）。夹具必须真的按 Tab、真的看偏移变。
 *
 * ★ 行高 40 × 20 行 = 内容 800，视口 200（`attach()` 给 tight(320,200)）：
 *   - 第 6 次 Tab 落到 `b5`（y 200..240，正好在视口下沿之外）⇒ 期望 offset 48
 *     （`240 + 8 − 200`），不是 40、也不是 200 —— 三个候选值互不相等，能钉住公式；
 *   - 第 5 次 Tab 落到 `b4`（y 160..200，下沿贴边）⇒ 期望 offset 8（`200 + 8 − 200`）。
 *   ⇒ 两次之间的增量 40 正好等于一行，**"每次只补差量"** 与 "每次整屏对齐"
 *     在这两个值上分得开。
 *
 * @internal 测试夹具
 */
final class RevealApplication extends Application
{
    public const ROWS = 20;
    public const ROW_H = 40.0;

    public function buildRoot(): Widget
    {
        $rows = [];
        for ($i = 0; $i < self::ROWS; $i++) {
            $rows[] = SizedBox::make(Button::make('b' . $i))->height(self::ROW_H);
        }

        return ScrollView::make(Column::make()->children($rows));
    }

    /** 夹具用：树里的滚动盒（断言 `revealDelta()` 的几何基准） */
    public function scrollBox(): ?RenderScrollView
    {
        return $this->root === null ? null : $this->findBox($this->root);
    }

    /** 夹具用：滚动偏移的持有者（断言 Tab 之后偏移真的变了） */
    public function scrollState(): ?ScrollViewState
    {
        return $this->root === null ? null : $this->findState($this->root);
    }

    /** 夹具用：当前焦点按钮的标签（断言 Tab 真的走到了预期那一行） */
    public function focusedLabel(): ?string
    {
        $widget = $this->focus?->focused()?->widget();

        return $widget instanceof Button ? $widget->label() : null;
    }

    private function findBox(Element $element): ?RenderScrollView
    {
        $box = $element->effectiveBox();
        if ($box instanceof RenderScrollView) {
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

    private function findState(Element $element): ?ScrollViewState
    {
        if ($element instanceof StatefulElement) {
            $state = $element->state();
            if ($state instanceof ScrollViewState) {
                return $state;
            }
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
