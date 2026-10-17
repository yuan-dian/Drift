<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Input\CursorShape;
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
     * 最近一次指针命中解析出的 Element（O-20 的锚点来源）。
     *
     * ★ 菜单打开的锚点不能用坐标（命令是"意图"，不带位置 —— §11.4.3）⇒
     *   `Application::showMenu($panel)` 不给锚点时就取这个。
     */
    private ?Element $lastHit = null;

    /**
     * 从根重建焦点链（挂载 / 更新后调）。
     *
     * ★ **变参**：浮层是第二棵根渲染树（O-20）∀ 它的焦点链也要能进 Tab 序 ⇒
     *   调用方传 `collect($root, $menuElement)`（文档序 = 主树在前、浮层在后）。
     *
     * ★ 树变了 ⇒ 原来的焦点 Element 可能已被移除 ⇒ 这里**显式清掉**，
     *   不留一个指向死节点的焦点（那会让后续按键静默丢掉）。
     */
    public function collect(Element ...$roots): void
    {
        $this->order = [];
        $this->byId = [];

        foreach ($roots as $root) {
            $this->walk($root);
        }

        if ($this->focused !== null && !isset($this->byId[spl_object_id($this->focused)])) {
            $this->leave($this->focused);
            $this->focused = null;
        }

        if ($this->lastHit !== null && !$this->lastHit->isMounted()) {
            $this->lastHit = null;
        }
    }

    /** 最近一次命中的 Element（浮层锚点用；未命中过 / 已离线 ⇒ `null`） */
    public function lastHit(): ?Element
    {
        return $this->lastHit;
    }

    /**
     * 命中的盒 ⇒ 它对应的 Element（右键找 `ContextMenu` 声明者要用）。
     *
     * ★ 从**命中盒**反查（不是从坐标重算）—— §9.1「一次命中，三方消费」。
     */
    public function elementAt(Element $root, RenderBox $hit): ?Element
    {
        return $this->elementForBox($root, $hit);
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
     * ★ 它是 `hitTest()` + `focusHit()` 的组合体，**保留给"只关心焦点"的调用方**
     *   （`drift-preview --focus` / smoke）。真机的输入派发走
     *   `Application::dispatchInput()` —— 那里一次命中要同时喂给焦点与光标。
     */
    public function dispatchPointer(PointerEvent $event, Element $root): bool
    {
        return $this->focusHit($this->hitTest($event->x, $event->y, $root), $root);
    }

    /**
     * 命中测试（§9.1）：**一次走树**，结果供多方消费。
     *
     * ★ 单独抽出来是因为 §9.1 的「一次命中，三方消费」（事件路由 / 语义 /
     *   光标形状）**明确要求不重复走树** ⇒ 调用方先拿一次命中结果，再分别喂给
     *   `focusHit()` 与 `cursorOf()`，而不是各自 hitTest 一遍。
     */
    public function hitTest(float $x, float $y, Element $root): ?RenderBox
    {
        return $root->effectiveBox()?->hitTest($x, $y);
    }

    /**
     * 命中链解析光标形状（§9.1「三方消费」的第 ③ 方）。
     *
     * ★ 从命中的盒**向上**找最近的声明者 ⇒ "给 `Column` 声明一次，里面所有子节点
     *   都生效"，而子节点自己声明时覆盖祖先。
     * ★ 收的是**已经命中的盒**（不是坐标）：不重复走树（见 `hitTest()`）。
     * ★ 一路没人声明 ⇒ `CursorShape::Default`（**不是** `null` —— 见该枚举的
     *   docblock："未声明"与"明确要箭头"是两件事）。
     */
    public function cursorOf(?RenderBox $hit): CursorShape
    {
        for ($node = $hit; $node !== null; $node = $node->parent()) {
            $shape = $node->cursor();

            if ($shape !== null) {
                return $shape;
            }
        }

        return CursorShape::Default;
    }

    /**
     * 把**已经算好的命中**落到焦点上（§9.1 的第 ① 方）。
     *
     * ★ 命中的**不是**可聚焦节点（点了空白 / `Text`）⇒ 返回 `false` 且**不清焦点**
     *   —— "点空白丢焦点"是另一条设计决定，不在这里偷偷做掉。
     */
    public function focusHit(?RenderBox $hit, Element $root): bool
    {
        if ($hit === null) {
            return false;
        }

        // ★ 命中的是**最深**的盒，但焦点链收的是**声明可聚焦的那个 Element**。
        //   `TextField` 是 `StatefulWidget` ⇒ 自己没盒，命中的是它 build 出来的
        //   `TextFieldBox`（`RenderBoxWidget`）⇒ 必须沿父链上溯到最近的焦点链成员，
        //   否则"点得到、却给不了焦点"（且不报错）。
        $element = $this->elementForBox($root, $hit);

        // 锚点记的是**最深命中**（不是上溯后的可聚焦节点）：菜单要钉在被点的那个
        // 控件旁边，而"可聚焦的那个"可能大得多（§5.1 裁决 1 的锚点来源）。
        $this->lastHit = $element;

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
    /**
     * 命中盒 → 拥有它的 Element。
     *
     * ★ chrome 类（`RenderMenuItem` 等）**自绘**子内容（含文字）⇒ 最深命中盒
     *   常常没有任何 Element 拥有它（旧实现按"effectiveBox 精确等于命中盒"
     *   匹配，此时落空 ⇒ 返回 null）。此时必须沿**渲染树**上溯到第一个"有
     *   Element 的盒"—— 否则 chrome 控件（菜单项）的指针点击永远落不了焦点。
     *   键盘路径不经过这里（焦点序里直接取 focused()）⇒ 该缺陷一直没暴露。
     */
    private function elementForBox(Element $element, RenderBox $box): ?Element
    {
        $owners = [];
        $collect = function (Element $el) use (&$collect, &$owners): void {
            $own = $el->effectiveBox();
            if ($own !== null) {
                $owners[spl_object_id($own)] = $el;
            }
            $el->visitChildren($collect);
        };
        $collect($element);

        for ($node = $box; $node !== null; $node = $node->parent()) {
            $id = spl_object_id($node);
            if (isset($owners[$id])) {
                return $owners[$id];
            }
        }

        return null;
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
