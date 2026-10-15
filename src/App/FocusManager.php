<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Input\FocusNode;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextFieldState;

/**
 * **焦点链 + 输入路由的最小实现**（§9.1 路由链的 ②③ 环与命中测试）。
 *
 * ```text
 * KeyEvent ──▶ ①加速键（未做）──▶ ②IME（未做）──▶ ③焦点链 ← 本类
 * PointerEvent ──▶ 命中测试（RenderBox 树）──▶ 焦点 ← 本类
 * ```
 *
 * ★ **为什么住 `Drift\App` 而不是 `Drift\Input`**：`Input` 按 §4.3 **不许依赖
 *   `Widget` / `Rendering`**，而本类必须引用 `Element` 与 `TextFieldState`。
 *   §9.1 本来就把路由判给 `EventLoop`（`Drift\App`）—— 这里只是把那条裁决落成代码，
 *   不含 EventLoop 的**循环**能力（`pump()` 由平台驱动，§5.2 裁决 1）。
 *
 * ★ **它是 `@internal`**：§4.2 的 `Drift\App` 行没有这个名字 —— 焦点链是
 *   路由的**实现细节**，不是公共契约。公共面是 `FocusNode`（声明）与
 *   `KeyEvent` / `PointerEvent`（数据），都在 `Drift\Input`。
 *
 * ★ **焦点链是"渲染态"**（§9.1）：本类只存 `?Element`，不碰任何业务值。
 *   切焦点时同步 `TextFieldState` 的 `beginEditing()` / `endEditing()` ——
 *   于是"插入符画不画"自动跟着焦点走。
 *
 * @internal
 */
final class FocusManager
{
    /** @var list<Element> 文档序（深度优先），同容器内按 `focusOrder` 稳定排序 */
    private array $order = [];

    /** @var array<int, Element> `spl_object_id` ⇒ Element（判断"焦点还在不在链里"） */
    private array $byId = [];

    private ?Element $focused = null;

    /**
     * 从根重建焦点链（挂载 / 更新后调）。
     *
     * ★ 树变了 ⇒ 原来的焦点 Element 可能已被移除 ⇒ 这里**显式清掉**，
     *   不留一个指向死节点的焦点（那会让后续按键静默丢掉）。
     */
    public function collect(Element $root): void
    {
        $this->order = [];
        $this->byId = [];
        $this->walk($root);

        if ($this->focused !== null && !isset($this->byId[spl_object_id($this->focused)])) {
            $this->leave($this->focused);
            $this->focused = null;
        }
    }

    /** @return list<Element> 当前焦点链（文档序） */
    public function order(): array
    {
        return $this->order;
    }

    public function focused(): ?Element
    {
        return $this->focused;
    }

    /** 焦点落在一个节点上（切走旧焦点、进入新焦点，编辑态跟着走） */
    public function focus(Element $element): void
    {
        if ($this->focused === $element) {
            return;
        }

        $this->leave($this->focused);
        $this->focused = $element;
        $this->enter($element);
    }

    /** Tab：前进一格。到末尾**就停**（不环绕 —— 与 Windows / Flutter 一致） */
    public function focusNext(): bool
    {
        return $this->step(1);
    }

    /** Shift+Tab：后退一格 */
    public function focusPrevious(): bool
    {
        return $this->step(-1);
    }

    /**
     * 派发一次按键（§9.1 第 ③ 环）。
     *
     * 顺序：`Tab` / `Shift+Tab`（焦点链自己的键）⇒ 焦点节点的 `handleKey()`。
     * 返回 `false` ⇒ **这一环没吃掉**，上层（将来的加速键 / IME）可以继续。
     */
    public function dispatchKey(KeyEvent $event): bool
    {
        if ($event->chord->is(KeyChord::tab())) {
            return $this->focusNext();
        }

        if ($event->chord->is(KeyChord::shift('Tab'))) {
            return $this->focusPrevious();
        }

        $state = $this->stateOf($this->focused);

        if ($state instanceof TextFieldState) {
            return $state->handleKey($event);
        }

        return false;
    }

    /**
     * 派发一次指针按下：命中测试 ⇒ 焦点（§9.1）。
     *
     * ★ 命中的**不是**可聚焦节点（点了空白 / `Text`）⇒ 返回 `false` 且**不清焦点**
     *   —— "点空白丢焦点"是另一条设计决定，不在这里偷偷做掉。
     */
    public function dispatchPointer(PointerEvent $event, Element $root): bool
    {
        $box = $root->effectiveBox();

        if ($box === null) {
            return false;
        }

        $hit = $box->hitTest($event->x, $event->y);

        if ($hit === null) {
            return false;
        }

        // ★ 命中的是**最深**的盒，但焦点链收的是**声明可聚焦的那个 Element**。
        //   `TextField` 是 `StatefulWidget` ⇒ 自己没盒，命中的是它 build 出来的
        //   `TextFieldBox`（`RenderBoxWidget`）⇒ 必须沿父链上溯到最近的焦点链成员，
        //   否则"点得到、却给不了焦点"（且不报错）。
        $element = $this->elementForBox($root, $hit);

        while ($element !== null && !isset($this->byId[spl_object_id($element)])) {
            $element = $element->parent();
        }

        if ($element === null) {
            return false;
        }

        $this->focus($element);

        return true;
    }

    /** 当前焦点是否**独占**这个组合（§9.1 让位规则 ②：编辑命令优先于加速键） */
    public function claims(KeyChord $chord): bool
    {
        $node = $this->focused?->widget()->focusNode();

        return $node !== null && $node->claims($chord);
    }

    // ── 内部 ────────────────────────────────────────────────────────────

    private function walk(Element $element): void
    {
        $node = $element->widget()->focusNode();

        // `skipFocus()` 是**子树级**（§A.3.4：AppBar 工具组整体不进 Tab 序）
        if ($node !== null && $node->skip) {
            return;
        }

        if ($element->widget()->isFocusable()) {
            $this->order[] = $element;
            $this->byId[spl_object_id($element)] = $element;
        }

        /** @var list<Element> $children */
        $children = [];
        $element->visitChildren(function (Element $child) use (&$children): void {
            $children[] = $child;
        });

        // 同一容器内按 `focusOrder` 稳定排序（§9.1）。混用"有/无"是 lint 的活，
        // 这里只求行为**确定**：没声明的排在声明的之后，彼此保持文档序。
        usort($children, static function (Element $a, Element $b): int {
            $oa = $a->widget()->focusNode()?->order;
            $ob = $b->widget()->focusNode()?->order;

            if ($oa === $ob) {
                return 0;
            }

            return ($oa ?? PHP_INT_MAX) <=> ($ob ?? PHP_INT_MAX);
        });

        foreach ($children as $child) {
            $this->walk($child);
        }
    }

    private function step(int $delta): bool
    {
        $count = count($this->order);

        if ($count === 0) {
            return false;
        }

        $current = $this->focused === null ? -1 : $this->indexOf($this->focused);
        $next = $current + $delta;

        if ($next < 0 || $next >= $count) {
            return false;
        }

        $this->focus($this->order[$next]);

        return true;
    }

    private function indexOf(Element $element): int
    {
        foreach ($this->order as $index => $candidate) {
            if ($candidate === $element) {
                return $index;
            }
        }

        return -1;
    }

    /** 最深优先：先找子（组合节点的 `effectiveBox()` 会返回后代的盒，取最深才对） */
    private function elementForBox(Element $element, RenderBox $box): ?Element
    {
        $found = null;
        $element->visitChildren(function (Element $child) use ($box, &$found): void {
            if ($found !== null) {
                return;
            }
            $found = $this->elementForBox($child, $box);
        });

        if ($found !== null) {
            return $found;
        }

        return $element->effectiveBox() === $box ? $element : null;
    }

    private function enter(?Element $element): void
    {
        $state = $this->stateOf($element);

        if ($state instanceof TextFieldState) {
            $state->beginEditing();
        }
    }

    private function leave(?Element $element): void
    {
        $state = $this->stateOf($element);

        if ($state instanceof TextFieldState) {
            $state->endEditing();
        }
    }

    private function stateOf(?Element $element): ?State
    {
        return $element instanceof StatefulElement ? $element->state() : null;
    }
}
