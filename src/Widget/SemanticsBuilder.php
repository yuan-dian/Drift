<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsNode;
use Drift\Input\SemanticsRole;
use Drift\Input\SemanticsTree;
use Drift\Layout\RenderBox;

/**
 * 语义树构建器。
 *
 * ★★ **唯一的构建入口，且"没人调就不建"** —— 只在 UIA 请求 / 语义操作时按需
 *   构建，不进每帧；零开销靠这个前提保证，比"默认关"的布尔开关更难被误打开。
 *   （每帧遍历整棵树是纯增开销，而语义在绝大多数应用里没有消费者。）
 *
 * ★★ **走 Element 树，不走渲染树** —— 一个控件可能产出多个盒（装饰 / 手势包装），
 *   走渲染树会**重复**命中同一个控件；走 Element 树则"声明了角色的控件恰好贡献
 *   一个节点"，天然去重。
 *
 * ★ **几何向渲染树要**：`Element::renderObject()` 对 Stateless/Stateful 会转发到
 *   子盒，那正是"这个控件实际占据的盒"；绝对原点走
 *   `RenderBox::absoluteOrigin()`（与绘制 / 命中测试同源 —— **别**沿 Element 链
 *   自己累加，Element 不持 offset，盒才持）。
 *
 * ★ **只收有声明的控件**：`semanticsRole() === null` 的节点既不建对象也不算几何
 *   ⇒ "没接语义的应用"建一棵空树是 O(n) 次空判断。
 *
 * @api
 */
final class SemanticsBuilder
{
    /** @internal 不允许实例化（全部静态） */
    private function __construct()
    {
    }

    /**
     * @param ?Element $root Element 树根（`Application::root()` 的那棵；null ⇒ 空树）
     * @param ?Element $focused 焦点元素（`FocusManager::focused()`；null ⇒ 无节点持焦点）
     * @param ?array<int, int> $focusable 可聚焦表（`spl_object_id` ⇒ 1）；
     *   null ⇒ 视为"框架没给这份事实" ⇒ 全部报不可聚焦（纯单测的默认降级）
     */
    public static function build(?Element $root, ?Element $focused = null, ?array $focusable = null): SemanticsTree
    {
        $tree = new SemanticsTree();
        if ($root === null) {
            return $tree;
        }
        self::descend($root, $tree, -1, 0, $focused, false, $focusable);

        return $tree;
    }

    /**
     * 前序遍历（父在子前 ⇒ 节点即前序，`hitTest()` 取最深才有意义）。
     *
     * ★ 用 `childCount()/childAt()` 而**不是闭包 visitChildren** —— 递归里递闭包
     *   在 AOT 下多一层不可见的调用，能用索引循环就别递函数。
     */
    private static function descend(
        Element $el,
        SemanticsTree $tree,
        int $parentIdx,
        int $depth,
        ?Element $focused,
        bool $disabled,
        ?array $focusable
    ): void {
        $role = $el->widget()->semanticsRole();

        // ★ `disabled()` 是**子树级**（与 `hitTest()` / `FocusManager::walk()` 同一条）：
        //   禁用容器里的按钮，语义上也是不可用的 —— 读屏必须念出来。
        $off = $disabled || $el->widget()->mods()->disabled();
        $cur = $parentIdx;
        $nextDepth = $depth;

        if ($role !== null) {
            $w = $el->widget();
            $n = new SemanticsNode();
            $n->role = $role->value;
            $n->type = self::shortClass($w);
            $n->key = self::keyText($w->identity());
            $n->name = $w->semanticsName();
            $n->value = $w->semanticsValue();
            $n->rangeMin = $w->semanticsRangeMin();
            $n->rangeMax = $w->semanticsRangeMax();
            // ★ 声明与实际状态取**与**：`semantics(..., enabled: false)` 是控件自己
            //   声明的静态事实（"我这个角色天生不可用"），`disabled()` 是运行期状态。
            //   两者任一为假都该报 `IsEnabled=false`，否则"禁用了但读屏说可用"，
            //   客户端会在 Invoke 前放行、然后 provider 收不到（`drift_win32.cc`
            //   里 `UIA_IsEnabledPropertyId` 那段注释说的正是这个坑）。
            $n->enabled = $w->semanticsEnabled() && !$off;
            $n->focused = $focused !== null && $el === $focused;
            // 禁用的控件在 UIA 里也不可聚焦（否则读屏会让用户 Tab 到一个死控件上）。
            $n->focusable = !$off && $focusable !== null && isset($focusable[spl_object_id($el)]);
            $n->selectState = $w->semanticsSelectState();
            $n->expandState = $w->semanticsExpandState();
            $n->level = $w->semanticsLevel();
            $n->parent = $parentIdx;
            $n->depth = $depth;

            // ★★ 用 `effectiveBox()`（自己的盒，没有就往下找第一个有盒的子孙），
            //   **不是** `renderObject()`。语义只声明在控件上，而"组合型"控件的盒
            //   在**子元素**上：`Button extends BoxWidget` 自己产盒（有几何），
            //   但 `TextField` / `ScrollView extends StatefulWidget` 的 `build()`
            //   返回 `TextFieldBox` / `ScrollViewBox` ⇒ `renderObject()` 是 null ⇒
            //   几何恒为 0 ⇒ UIA 侧 `BoundingRectangle` 变成 `Rect.Empty`（读屏
            //   无法高亮/命中）。2026-10-25 真机实测：`搜索`(Edit) 与 `列表`(List)
            //   两个节点没有包围盒，其余按钮都有。
            $box = $el->effectiveBox();
            if ($box !== null && $box->hasSize()) {
                [$ox, $oy] = RenderBox::absoluteOrigin($box);
                $n->x = (int) $ox;
                $n->y = (int) $oy;
                $n->w = (int) $box->size()->width;
                $n->h = (int) $box->size()->height;
            }

            $cur = $tree->add($n);
            $nextDepth = $depth + 1;
        }

        $count = $el->childCount();
        for ($i = 0; $i < $count; $i++) {
            $kid = $el->childAt($i);
            if ($kid !== null) {
                self::descend($kid, $tree, $cur, $nextDepth, $focused, $off, $focusable);
            }
        }
    }

    /** 类名短名（去命名空间）：`Drift\Widget\Button` → `Button` */
    private static function shortClass(Widget $w): string
    {
        $cn = get_class($w);
        $bs = strrpos($cn, '\\');
        if ($bs !== false) {
            $cn = substr($cn, $bs + 1);
        }

        return $cn;
    }

    /**
     * Key 的字符串形式（`Key::$value` 是 `string|int` 联合 ⇒ 先落局部再判，
     * 避免 AOT 把分支推成 `Variant`）。
     */
    private static function keyText(?Key $key): string
    {
        if ($key === null) {
            return '';
        }
        $v = $key->value;

        return is_string($v) ? $v : (string) $v;
    }
}
