<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Input\KeyEvent;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Expanded;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 翻页键 → 滚动容器的夹具（`§56`）。
 *
 * 四个开关对应四条要分开的判据：
 * - `$horizontal`：翻页键走**容器自己的主轴**（横向容器里 PageDown 不该是空操作）。
 * - `$withButton`：焦点落在滚动内容里 —— 证明"焦点在内也能翻"。
 * - `$declares`：链上有 `keyboard()` 声明者 —— 框架整组让开，一个像素都不滚。
 * - `$twoPane`：窄侧栏也是滚动容器且在树序前面 —— 主视口只能按面积认。
 *
 * @internal 测试夹具
 */
final class ScrollKeyApplication extends Application
{
    /** 行数远多于视口 ⇒ maxScroll 恒 > 0（与字号无关，不赌字模） */
    private const ROWS = 40;

    private const ROW_EXTENT = 40.0;

    /** 侧栏宽（窄条 ⇒ 面积判据该把主视口判给内容区） */
    private const NAV_W = 80.0;

    public function __construct(
        private readonly bool $horizontal = false,
        private readonly bool $withButton = false,
        private readonly bool $declares = false,
        private readonly bool $twoPane = false,
    ) {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        if ($this->twoPane) {
            return $this->twoPaneRoot();
        }

        $rows = [];
        if ($this->withButton) {
            $rows[] = Button::make('焦点落点');
        }

        for ($i = 0; $i < self::ROWS; $i++) {
            $rows[] = $this->horizontal
                ? SizedBox::make()->width(self::ROW_EXTENT)->height(40.0)
                : Text::make('row ' . $i);
        }

        $content = $this->horizontal ? Row::make()->children($rows) : Column::make()->children($rows);
        $view = ScrollView::make($content, !$this->horizontal);

        if (!$this->declares) {
            return $view;
        }

        // 声明者只认领一个与翻页无关的键 ⇒ 它"没吃掉 PageDown"，但框架仍然让开。
        return Column::make()
            ->children([$view])
            ->keyboard(static function (KeyEvent $event): bool {
                return $event->chord->key === 'F13';
            });
    }

    /**
     * 两栏：**窄侧栏也是滚动容器**，且在树序里排在内容区前面 —— `showcase` 的形状。
     * 「树序第一个」会翻错面板，主视口只能按面积认。
     */
    private function twoPaneRoot(): Widget
    {
        return Row::make()->children([
            SizedBox::make(ScrollView::make($this->rows()))->width(self::NAV_W),
            Expanded::make(ScrollView::make($this->rows())),
        ]);
    }

    private function rows(): Widget
    {
        $rows = [];
        for ($i = 0; $i < self::ROWS; $i++) {
            $rows[] = SizedBox::make()->height(self::ROW_EXTENT);
        }

        return Column::make()->children($rows);
    }

    /** 树里的滚动盒（偏移进没进盒的唯一证据） */
    public function scrollBox(): ?RenderScrollView
    {
        $all = $this->scrollBoxes();

        return $all[0] ?? null;
    }

    /** @return list<RenderScrollView> 树序里的全部滚动盒（按身份去重：`effectiveBox()` 沿途每层都会拿到同一个盒） */
    public function scrollBoxes(): array
    {
        $found = [];
        $seen = [];
        if ($this->root !== null) {
            $this->collectBoxes($this->root, $found, $seen);
        }

        return $found;
    }

    /**
     * @param list<RenderScrollView> $found
     * @param array<int, true> $seen
     */
    private function collectBoxes(Element $element, array &$found, array &$seen): void
    {
        $box = $element->effectiveBox();
        if ($box instanceof RenderScrollView) {
            $id = \spl_object_id($box);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $found[] = $box;
            }
        }

        $element->visitChildren(function (Element $child) use (&$found, &$seen): void {
            $this->collectBoxes($child, $found, $seen);
        });
    }
}
