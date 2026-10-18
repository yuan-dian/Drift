<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Input\SemanticsNode;
use Drift\Input\SemanticsRole;
use Drift\Input\SemanticsTree;
use Drift\Layout\RenderBox;

/**
 * 语义树构建器（§9 无障碍 / M5）。
 *
 * ★★ **唯一的构建入口，且"没人调就不建"** —— 正常运行根本不会走到这里，
 *   零开销靠"没有调用点"保证，比一个"默认关"的布尔开关更难被误打开。
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
     */
    public static function build(?Element $root): SemanticsTree
    {
        $tree = new SemanticsTree();
        if ($root === null) {
            return $tree;
        }
        self::descend($root, $tree, -1, 0);

        return $tree;
    }

    /**
     * 前序遍历（父在子前 ⇒ 节点即前序，`hitTest()` 取最深才有意义）。
     *
     * ★ 用 `childCount()/childAt()` 而**不是闭包 visitChildren** —— 递归里递闭包
     *   在 AOT 下多一层不可见的调用，能用索引循环就别递函数。
     */
    private static function descend(Element $el, SemanticsTree $tree, int $parentIdx, int $depth): void
    {
        $role = $el->widget()->semanticsRole();
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
            $n->enabled = $w->semanticsEnabled();
            $n->parent = $parentIdx;
            $n->depth = $depth;

            $box = $el->renderObject();
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
                self::descend($kid, $tree, $cur, $nextDepth);
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
