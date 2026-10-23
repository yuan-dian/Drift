<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\App\OverlayEntry;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Padding;
use Drift\Widget\SizedBox;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * Tooltip 夹具（W8-2）。
 *
 * 布局（**每一条留白都有判据上的理由**）：
 *
 * ```text
 *   Padding(left 100)        ← 锚点离左边缘够远，"居中"才不会被 ⑤ 夹取吃掉
 *     Column
 *       SizedBox(0,120)      ← 让 a 的 ay 足够大 ⇒ ABOVE 首选方向真的会被走到
 *       Button 'd'           ← 被 a 的提示**盖住**（验"提示不吃点击"）
 *       Button 'a' tooltip   ← 悬停 500ms 弹 / 离开即走
 *       Button 'b' tooltip   ← 已显示时相邻切换立即换
 *       Button 'c'           ← 反例：没有 tooltip，永不弹
 * ```
 *
 * ★ 坐标一律 `effectiveBox()` + `absoluteOrigin()` 现取（不赌字模）。
 * ★ 按钮都挂 `onPressed` 记进 `$seen` —— "提示层不吃点击"的证据是**下面的按钮
 *   真的收到了点击**，而不是"没有报错"。
 *
 * @internal 测试夹具
 */
final class TooltipApplication extends Application
{
    /** @var list<string> 依次收到的点击（`d` / `a` / `b` / `c`） */
    public array $seen = [];

    public function buildRoot(): Widget
    {
        return Padding::make(
            Column::make()->children([
                SizedBox::make()->size(0.0, 120.0),
                Button::make('d')->onPressed(function (): void {
                    $this->seen[] = 'd';
                }),
                Button::make('a')->tooltip('保存当前文档')->onPressed(function (): void {
                    $this->seen[] = 'a';
                }),
                Button::make('b')->tooltip('永久删除，不可恢复')->onPressed(function (): void {
                    $this->seen[] = 'b';
                }),
                Button::make('c')->onPressed(function (): void {
                    $this->seen[] = 'c';
                }),
            ])
        )->only(left: 100.0);
    }

    /** 浮层栈层数（`0` = 没有提示在显示） */
    public function overlayCount(): int
    {
        return $this->overlays()->count();
    }

    /** 栈顶条目（读 `maxWidth` / 落点 / `hitTest` 用） */
    public function topEntry(): ?OverlayEntry
    {
        return $this->overlays()->top();
    }

    /**
     * 文档序的全部 `Button`（递归 —— `Element::visitChildren()` **只访问直接子**，
     * 而本夹具的根是 `Padding → Column → 按钮`，用 visitChildren 会一个都拿不到）。
     *
     * @return list<Element>
     */
    public function buttons(): array
    {
        return self::buttonsIn($this->root());
    }

    /** @return list<Element> */
    private static function buttonsIn(?Element $element): array
    {
        if ($element === null) {
            return [];
        }

        $out = $element->widget() instanceof Button ? [$element] : [];

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            foreach (self::buttonsIn($element->childAt($i)) as $found) {
                $out[] = $found;
            }
        }

        return $out;
    }

    /**
     * 栈顶提示里那行文字（不是提示层 ⇒ `null`）。
     *
     * ★ 走"找 Text 元素"而不是反射私有字段：`TooltipPanel` 的形体是可换的，
     *   判据应该盯**用户看得见的东西**。
     */
    public function tooltipText(): ?string
    {
        $found = self::findText($this->overlays()->top()?->element);
        if (!$found instanceof Element) {
            return null;
        }

        $widget = $found->widget();

        return $widget instanceof Text ? $widget->content() : null;
    }

    /** 深度优先找第一个 `Text` 元素 */
    private static function findText(?Element $element): ?Element
    {
        if ($element === null) {
            return null;
        }

        if ($element->widget() instanceof Text) {
            return $element;
        }

        for ($i = 0, $n = $element->childCount(); $i < $n; $i++) {
            $hit = self::findText($element->childAt($i));
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }
}
