<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Element;
use Drift\Widget\LazyColumn;
use Drift\Widget\LazyListState;
use Drift\Widget\LazyRow;
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 虚拟列表夹具应用：500 行、每行 20 高、视口 200 ⇒ 只造约 10 行（M5）。
 *
 * ★ 存在理由：`ScrollEvent → dispatchInput → scrollAt → Scrollable::scrollBy`
 *   这条链在 `LazyColumn` 上是**新的一环**（路由从写死 `ScrollViewState` 改成
 *   接口）。不测它，接口就是"写了但没被走通过"的分支 —— §24.2 #25 的教训。
 *
 * ★ 行高 20 + 500 行：`sliceOrigin` 与 `offset` 不相等（48 ⇒ 窗起点 40），
 *   于是"窗该挪多少"这个减法的正负号能被夹具钉住（恒置 0 会差 2 行）。
 *
 * @internal 测试夹具
 */
final class LazyApplication extends Application
{
    public const ROWS = 500;
    public const EXTENT = 20.0;

    /**
     * @param bool $vertical true = `LazyColumn`（纵向）/ false = `LazyRow`（横向）
     *
     * ★ 两个轴共用一套夹具的理由：滚轮路由（`Scrollable`）与轴无关 —— 它只递
     *   一个标量 Δ。所以"横向也能滚"这件事**必须**在这条链上被走通一次，否则
     *   `LazyRow` 的滚动就只是"代码看起来对"。
     */
    public function __construct(private readonly bool $vertical = true)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        $rows = [];
        for ($i = 0; $i < self::ROWS; $i++) {
            $rows[] = $i;
        }

        if ($this->vertical) {
            return LazyColumn::make(self::EXTENT)->items(
                $rows,
                static fn(int $i): Widget => Text::make('row ' . $i),
            );
        }

        return LazyRow::make(self::EXTENT)->items(
            $rows,
            static fn(int $i): Widget => Text::make('col ' . $i),
        );
    }

    /** 夹具用：树里的滚动盒（断言窗的起点有没有进子盒 offset） */
    public function scrollBox(): ?RenderScrollView
    {
        return $this->root() === null ? null : $this->findBox($this->root());
    }

    /** 夹具用：列表的 State（断言偏移真的被滚轮改了） */
    public function lazyState(): ?LazyListState
    {
        return $this->root() === null ? null : $this->findState($this->root());
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

    private function findState(Element $element): ?LazyListState
    {
        if ($element instanceof StatefulElement) {
            $state = $element->state();
            if ($state instanceof LazyListState) {
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
