<?php
declare(strict_types=1);

namespace Drift\DevTools\Inspect;

use Drift\App\Application;
use Drift\Debug\Session\Recorder;
use Drift\DevTools\Session\History;
use Drift\Geometry\Rect;
use Drift\Layout\RenderBox;
use Drift\Rendering\Canvas;
use Drift\Rendering\Paint;
use Drift\Theme\ThemeData;
use Drift\Widget\Element;

/**
 * 真机侧的检查器叠加：布局边界层与盒模型四层。
 *
 * ## 只做 Web 给不了的三件事
 *
 * 实时、不切窗口、多窗空间关系。树、面板、搜索、请求列表都不在这里 —— 那些在
 * Web 上做得更好，重复一遍只会得到两份会漂的实现（`evidence-model.md` 边界表）。
 *
 * ## 为什么读活树而不是读几何记录
 *
 * 记录只留最近 60 帧有变化的节点：live 宿主空闲时帧号照常推进，几秒后叠加要问的
 * 节点就"落在窗口外"，画面上会突然什么都不剩。而叠加层的边际价值恰恰是"现在"。
 * 代价是它与 `boxModel()` 不是同一次读取 —— 所以 `BoxLayers` 的字段名对齐
 * `boxModel()`，判据用"同一帧两边逐值相同"把它们钉在一起，而不是靠注释约定。
 *
 * ## 记账
 *
 * `paint()` 返回"这一帧真的画了东西吗"。开着但一个盒都不在视口里、或 `box-model`
 * 开着却没有选中节点，都如实返回 `false` —— 让 `overlayDrawn('inspect')` 停在
 * 上一次成功那一帧，与高亮同一套语义（请求了 ≠ 上屏了）。
 */
final class Inspector
{
    /** 支持的层；顺序即绘制顺序（先淡后浓） */
    public const array LAYERS = ['bounds', 'box-model'];

    /** @var array<string, bool> */
    private array $layers = ['bounds' => false, 'box-model' => false];

    private ?Application $app = null;

    private ?Recorder $recorder = null;

    private ?History $history = null;

    /**
     * 装上叠加层。装的一刻会排一帧（叠加层绕过 `setState`，不排就永远等下一次输入）。
     *
     * ★ 闭包捕获 `$this`：`src` 只持一个 `\Closure`，不认识 `Inspector` —— 结构断言
     *   "`src` 不得引用 `Drift\DevTools\`"因此仍然成立。
     */
    public function attach(Application $app, Recorder $recorder): void
    {
        $this->app = $app;
        $this->recorder = $recorder;
        $this->history = new History($recorder);
        $app->setDebugOverlay(fn(Canvas $canvas): bool => $this->paint($canvas));
    }

    public function detach(): void
    {
        $app = $this->app;
        $this->app = null;
        $this->recorder = null;
        $this->history = null;
        // 摘也要请求一帧：否则屏幕上那层会一直留到下一次输入（叠加层不标脏）。
        $app?->setDebugOverlay(null);
    }

    public function attached(): bool
    {
        return $this->app !== null;
    }

    /**
     * 覆盖式设置开关（`[]` = 全关）。
     *
     * @param list<string> $layers
     * @return list<string> 实际生效的层
     * @throws \InvalidArgumentException 出现不认识的层名
     */
    public function setLayers(array $layers): array
    {
        $unknown = \array_values(\array_diff($layers, self::LAYERS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(\sprintf(
                '未知的叠加层：%s（可用：%s）',
                \implode(', ', $unknown),
                \implode(', ', self::LAYERS),
            ));
        }

        $next = \array_fill_keys(self::LAYERS, false);
        foreach ($layers as $layer) {
            $next[$layer] = true;
        }
        $this->layers = $next;
        // 开关变了要重画：叠加层绕过 `setState`，静止的树不会自己再画一遍。
        $this->app?->requestOverlayRepaint();

        return $this->enabled();
    }

    /** @return list<string> */
    public function enabled(): array
    {
        $on = [];
        foreach (self::LAYERS as $layer) {
            if ($this->layers[$layer]) {
                $on[] = $layer;
            }
        }

        return $on;
    }

    public function paint(Canvas $canvas): bool
    {
        $app = $this->app;
        $recorder = $this->recorder;
        if ($app === null || $recorder === null) {
            return false;
        }

        $roots = $app->treeRoots();
        if ($roots === []) {
            return false;
        }

        $viewport = $this->viewport($roots);
        $theme = $app->theme();
        $selected = $this->selected($roots, $recorder);
        $chain = $this->chainIds($selected);

        $drew = false;
        if ($this->layers['bounds']) {
            $drew = $this->paintBounds($canvas, $roots, $viewport, $theme, $chain) || $drew;
        }
        if ($this->layers['box-model'] && $selected !== null) {
            $box = $selected->effectiveBox();
            $drew = ($box !== null && $this->paintBoxModel($canvas, $box, $theme)) || $drew;
        }

        return $drew;
    }

    /**
     * 全体盒的边界。容器淡、叶子浓、选中链满 —— 与前端的判据只有一份（A81）。
     *
     * 成本是遍历整棵树（每节点一次 `effectiveBox()` + 一遍子节点判容器），只在
     * 这一层真打开时才跑。别把它接进任何"每帧都要"的路径。
     *
     * @param list<Element> $roots
     * @param array<int, true> $chain
     */
    private function paintBounds(Canvas $canvas, array $roots, Rect $viewport, ThemeData $theme, array $chain): bool
    {
        // 一个 Element 的 `effectiveBox()` 会下沉到子盒（Stateless 自己没有盒）⇒
        // 同一个盒会被好几个 Element 报到。不去重就是同一个位置叠画三四遍，
        // 看起来"这一块特别浓"，而原因和节点结构毫无关系。
        $seen = [];
        $drew = false;

        foreach ($roots as $root) {
            foreach ($this->walk($root) as $element) {
                $box = $element->effectiveBox();
                if ($box === null) {
                    continue;
                }

                $boxId = \spl_object_id($box);
                if (isset($seen[$boxId])) {
                    continue;
                }
                $seen[$boxId] = true;

                $layers = BoxLayers::of($box);
                if ($layers === null) {
                    continue;
                }

                $b = $layers['border'];
                $rect = new Rect($b['x'], $b['y'], $b['w'], $b['h']);
                if ($rect->intersect($viewport)->isEmpty()) {
                    // 视口外的盒不画：它们只会被裁掉，白花一次遍历的绘制。
                    continue;
                }

                $inChain = isset($chain[\spl_object_id($element)]);
                if ($inChain) {
                    $paint = Palette::boundsChain($theme);
                } else {
                    $alpha = $this->hasBoxChild($element) ? Palette::BOUNDS_CONTAINER : Palette::BOUNDS_LEAF;
                    $paint = Palette::boundsPlain($theme, $alpha);
                }

                $canvas->drawRect($rect, $paint);
                $drew = true;
            }
        }

        return $drew;
    }

    private function paintBoxModel(Canvas $canvas, RenderBox $box, ThemeData $theme): bool
    {
        $layers = BoxLayers::of($box);
        if ($layers === null) {
            return false;
        }

        $b = $layers['border'];
        $border = new Rect($b['x'], $b['y'], $b['w'], $b['h']);
        $c = $layers['content'];
        $content = new Rect($c['x'], $c['y'], $c['w'], $c['h']);
        $m = $layers['margin'];
        $bw = $layers['borderWidth'];

        // margin：border box 向外扩
        $marginBox = new Rect(
            $border->left - $m['l'],
            $border->top - $m['t'],
            $border->width + $m['l'] + $m['r'],
            $border->height + $m['t'] + $m['b'],
        );
        $drew = $this->fillRings($canvas, $marginBox, $border, Palette::margin($theme));

        // border：跨在边线上（内外各一半）—— 本项目的 border 是描边，不占布局空间，
        // 要让它"厚度 = borderWidth"，环就只能这么放。
        if ($bw > 0.0) {
            $half = $bw / 2.0;
            $outer = new Rect($border->left - $half, $border->top - $half, $border->width + $bw, $border->height + $bw);
            $inner = new Rect($border->left + $half, $border->top + $half, $border->width - $bw, $border->height - $bw);
            $drew = $this->fillRings($canvas, $outer, $inner, Palette::border($theme)) || $drew;
        }

        // padding：border box 内缘到 content
        $padInner = $bw > 0.0
            ? new Rect($border->left + $bw / 2.0, $border->top + $bw / 2.0, $border->width - $bw, $border->height - $bw)
            : $border;
        $drew = $this->fillRings($canvas, $padInner, $content, Palette::padding($theme)) || $drew;

        // content：整块填充（最淡），一眼能看出"内容实际占多少"
        // padding 挤爆尺寸时 content 宽高会是非正数，那时这一块不存在 —— 不能算画过。
        if ($content->width > 0.0 && $content->height > 0.0) {
            $canvas->drawRect($content, Palette::content($theme));
            $drew = true;
        }

        return $drew;
    }

    /** 外框减内框的四条边；厚度非正的边不出矩形（否则会画出零宽的高亮线） */
    private function fillRings(Canvas $canvas, Rect $outer, Rect $inner, Paint $paint): bool
    {
        $pieces = [
            new Rect($outer->left, $outer->top, $outer->width, $inner->top - $outer->top),
            new Rect($outer->left, $inner->bottom(), $outer->width, $outer->bottom() - $inner->bottom()),
            new Rect($outer->left, $inner->top, $inner->left - $outer->left, $inner->height),
            new Rect($inner->right(), $inner->top, $outer->right() - $inner->right(), $inner->height),
        ];

        $drew = false;
        foreach ($pieces as $piece) {
            if ($piece->width > 0.0 && $piece->height > 0.0) {
                $canvas->drawRect($piece, $paint);
                $drew = true;
            }
        }

        return $drew;
    }

    /** @param list<Element> $roots */
    private function viewport(array $roots): Rect
    {
        $size = $roots[0]->effectiveBox()?->size();
        if ($size === null) {
            return Rect::zero();
        }

        return new Rect(0.0, 0.0, $size->width, $size->height);
    }

    /**
     * @param list<Element> $roots
     */
    private function selected(array $roots, Recorder $recorder): ?Element
    {
        $node = $this->history?->highlightedNode() ?? 0;
        if ($node <= 0) {
            return null;
        }

        return Element::findInRoots($roots, $node, fn(Element $e): bool => $recorder->owns($e));
    }

    /**
     * 选中链：选中节点 + 祖先。子孙不豁免 —— 那是 Web 上"选中一个容器看里面"的场景，
     * 真机叠加已经有 `bounds` 层画全体，不需要第二份标记。
     *
     * @return array<int, true>
     */
    private function chainIds(?Element $selected): array
    {
        $chain = [];
        for ($cursor = $selected; $cursor !== null; $cursor = $cursor->parent()) {
            $chain[\spl_object_id($cursor)] = true;
        }

        return $chain;
    }

    private function hasBoxChild(Element $element): bool
    {
        $found = false;
        $element->visitChildren(function (Element $child) use (&$found): void {
            if (!$found && $child->effectiveBox()?->hasSize() === true) {
                $found = true;
            }
        });

        return $found;
    }

    /** @return \Generator<int, Element> */
    private function walk(Element $element): \Generator
    {
        yield $element;

        $children = [];
        $element->visitChildren(function (Element $child) use (&$children): void {
            $children[] = $child;
        });

        foreach ($children as $child) {
            yield from $this->walk($child);
        }
    }
}
