<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;

/**
 * 三树的中间一棵：**身份 + State 宿主 + 调和**（§3.2）。
 *
 * 职责三件：① 持有 `State`；② `(type, key)` diff 决定复用还是重建（§12.3）；
 * ③ 帧末脏重建。它**不画东西、不算几何** —— 那两件是 RenderObject 的事。
 *
 * ★ **身份由位置 + Key 决定**，不由实例决定：`children([$t, $t])` 里两个 `$t`
 *   是两个 Element（位置 0 与 1）；要"位置变了身份不变"必须给不同 `Key`。
 *
 * @api
 */
abstract class Element
{
    protected ?Element $parent = null;
    protected BuildContext $context;
    protected Widget $widget;

    /** @var list<Element> */
    protected array $children = [];

    private bool $mounted = false;

    /** @internal §13.2：本节点的渲染盒；结构节点为 null */
    private ?RenderBox $box = null;

    public function __construct(Widget $widget)
    {
        $this->widget = $widget;
    }

    public function widget(): Widget
    {
        return $this->widget;
    }

    public function parent(): ?Element
    {
        return $this->parent;
    }

    public function context(): BuildContext
    {
        return $this->context;
    }

    public function isMounted(): bool
    {
        return $this->mounted;
    }

    /**
     * 挂载到父链下，并建整棵子树。
     *
     * @param BuildContext $context 父链解析后的渲染环境（§3.4）
     */
    public function mount(?Element $parent, BuildContext $context): void
    {
        $this->parent = $parent;
        $this->context = $context;
        $this->widget->seal(); // §15.2 Seal-on-Mount
        $this->mounted = true;
        $this->syncBox();
        $this->rebuild();
    }

    /** 本节点的渲染盒（§13.2）；结构节点可能没有 ⇒ `null` */
    public function renderObject(): ?RenderBox
    {
        return $this->box;
    }

    /**
     * 造 / 换渲染盒（§13.2）。
     *
     * ★ 配置更新 ⇒ **重新造一个换掉**，不就地改字段。Widget 不可变，所以"换盒"
     *   与"换 Widget"是同一件事的两次表达。
     *
     * @internal
     */
    protected function syncBox(): void
    {
        $this->box = $this->widget instanceof RenderBoxWidget
            ? $this->widget->createRenderObject($this->context)
            : null;
    }

    /**
     * 子树建完后把**子节点的盒**收进自己的盒（C1：父管挂接，子不自定位）。
     *
     * 基类是空操作：叶子没有子盒要收。容器覆写它 —— 从 `children` 里拉盒。
     *
     * @internal
     */
    protected function adoptBoxes(): void
    {
    }

    /**
     * 换新描述（§13.2）。
     *
     * ★ Widget 不可变 ⇒ 参数只会**整体换新**，不存在"字段级半同步" ——
     *   GUI 仓"两份手写字段赋值 ⇒ 首次正常、刷新变样且静默"因此结构上不可能。
     */
    public function update(Widget $newWidget): void
    {
        $this->widget = $newWidget;
        $this->widget->seal();
        $this->syncBox(); // §13.2：新描述 ⇒ 新盒
        $this->didUpdateWidget($newWidget);
        $this->rebuild();
    }

    /**
     * @internal 子类钩子：新描述已到位、**重建之前**触发。
     *
     * `StatefulElement` 用它把新 Widget 递给还在活着的 State —— 于是"跟手输入
     * 不丢、展开状态还在"是结构保证，不是文档承诺。
     */
    protected function didUpdateWidget(Widget $newWidget): void
    {
    }

    abstract public function rebuild(): void;

    /**
     * 本节点展开出的**子 Widget 列表** —— 调和的唯一入口。
     *
     * `StatelessElement` = `[build($ctx)]`；**结构节点**（`Column` / `Row` /
     * `ForEach`，§15.7 说它们继承裸 `Widget`、没有 `build()`）由自己的 Element
     * 覆写这里返回 `children`。扩展点只有一处，于是"叶子/无状态/有状态/容器"四种
     * 节点共用同一套 diff。
     */
    protected function expand(): array
    {
        return [];
    }

    public function visitChildren(callable $visitor): void
    {
        foreach ($this->children as $child) {
            $visitor($child);
        }
    }

    public function childCount(): int
    {
        return count($this->children);
    }

    public function childAt(int $index): ?Element
    {
        return $this->children[$index] ?? null;
    }

    public function unmount(): void
    {
        foreach ($this->children as $child) {
            $child->unmount();
        }
        $this->children = [];
        $this->mounted = false;
    }

    /**
     * `(type, key)` diff（§12.3 / §3.2）：逐槽找一个可复用的旧子。
     *
     * - 类型 + `Key` 都一致 ⇒ 复用同一个 Element（`State` / 子树身份稳定）
     * - 位置相同而都没 Key ⇒ 视为可复用（身份退化为位置）
     * - 槽位变了 ⇒ 卸载旧的、挂新的
     */
    protected function updateChildren(array $newWidgets): void
    {
        $old = $this->children;
        $used = [];
        $next = [];

        foreach ($newWidgets as $newWidget) {
            $slot = null;
            foreach ($old as $index => $candidate) {
                if (isset($used[$index])) {
                    continue;
                }
                if (get_class($candidate->widget()) === get_class($newWidget)
                    && Key::same($candidate->widget()->identity(), $newWidget->identity())
                ) {
                    $slot = $index;
                    break;
                }
            }

            if ($slot === null) {
                $element = $newWidget->createElement();
                $element->mount($this, $this->context);
                $next[] = $element;
                continue;
            }

            $used[$slot] = true;
            $element = $old[$slot];
            if ($element->widget() !== $newWidget) {
                $element->update($newWidget);
            }
            $next[] = $element;
        }

        foreach ($old as $index => $candidate) {
            if (!isset($used[$index])) {
                $candidate->unmount();
            }
        }

        $this->children = $next;
    }
}
