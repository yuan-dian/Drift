<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 滚动夹具应用：视口里放一列比视口高的文本 ⇒ 真能滚（P1）。
 *
 * ★ 存在理由：`ScrollEvent → dispatchInput → scrollAt → ScrollViewState::scrollBy`
 *   这条链需要一棵"滚轮坐标命中 → 找到视口 → 偏移进盒"的树才能测 —— 缺任何一环
 *   都是静默的（事件被吞、盒偏移没变），与 padding 缺陷同族。
 *
 * @internal 测试夹具
 */
final class ScrollApplication extends Application
{
    /** 内容条数：远多于视口装得下的 ⇒ maxScroll 恒 > 0（与字号无关，不赌字模） */
    private const ROWS = 40;

    public function buildRoot(): Widget
    {
        $rows = [];
        for ($i = 0; $i < self::ROWS; $i++) {
            $rows[] = Text::make('row ' . $i);
        }

        return ScrollView::make(Column::make()->children($rows));
    }

    /** 夹具用：找出树里的滚动盒（断言偏移有没有进子盒 offset —— 坐标链判据） */
    public function scrollBox(): ?RenderScrollView
    {
        return $this->root() === null ? null : $this->findBox($this->root());
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
}
