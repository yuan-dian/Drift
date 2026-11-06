<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Core\Pipeline;
use Drift\Core\PipelineAccess;
use Drift\Errors;
use Drift\Layout\RenderBox;

/**
 * 三树中间一棵：身份 + State 宿主 + 调和。持有 State；(type,key) diff 决定复用
 * 还是重建；帧末脏重建。不画、不算几何（那是 RenderObject 的事）。
 * 身份由位置 + Key 决定，不由实例：children([$t,$t]) 是两个 Element；想让位置
 * 变了身份仍不变，必须给不同 Key。
 *
 * @api
 */
abstract class Element
{
    use PipelineAccess;

    /**
     * 收敛循环的最大轮数：防御上限，正常 ≤ 2。
     */
    private const SETTLE_ROUNDS = 16;

    protected ?Element $parent = null;
    protected BuildContext $context;
    protected Widget $widget;

    /** @var list<Element> */
    protected array $children = [];

    // AOT：父类方法在子类实例上访问这两个属性，父类 private 不进子类属性表
    // ⇒ 子类实例访问会报 Undefined property 并终止请求；放宽成 protected 解决。
    protected bool $mounted = false;

    /** @internal 本节点的渲染盒；结构节点为 null */
    protected ?RenderBox $box = null;

    /** 本节点是否在动效活跃表里（语义："表里现在有没有我"，由收/出表两处写）。 */
    protected bool $animationActive = false;

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

    /**
     * 把焦点交给 `$target` —— 是"交给目标"，不是"给自己拿焦点"（`FocusManager`
     * 那边叫"请求"：目标不可聚焦 / 未挂载时静默不动）。
     *
     * 没有宿主（纯单测直接 `mount()`、帧外落在默认管线）⇒ 同样静默，不崩。
     */
    public function requestFocus(Element $target): void
    {
        $host = $this->pipeline()->focusHost();

        if ($host instanceof FocusHost) {
            $host->requestFocus($target);
        }
    }

    /**
     * 本节点所在树的根（沿父链到顶；自己就是根 ⇒ 自己）。
     * @internal 框架内部查找
     */
    public function root(): Element
    {
        $node = $this;

        while (true) {
            $up = $node->parent();
            if ($up === null) {
                return $node;
            }

            $node = $up;
        }
    }

    public function isMounted(): bool
    {
        return $this->mounted;
    }

    /** @internal 本节点在不在动效活跃表里（见 $animationActive）；Application 收/出表时读 */
    public function isAnimationActive(): bool
    {
        return $this->animationActive;
    }

    /** @internal 记/清"在活跃表里"；只有 Application::collectActiveAnimations() 与 updateActiveAnimations() 写 */
    public function markAnimationActive(bool $active): void
    {
        $this->animationActive = $active;
    }

    /**
     * 挂载到父链下，并建整棵子树。
     */
    public function mount(?Element $parent, BuildContext $context): void
    {
        $this->parent = $parent;
        $this->context = $context;
        $this->widget->seal(); // §15.2 Seal-on-Mount
        $this->mounted = true;
        $this->pipeline()->bumpStructure(); // ★ 结构变了（`07` §6.2）
        $this->onMount();
        $this->syncBox();
        $this->rebuild();
    }

    /**
     * @internal 子类钩子：已封存、首次 build 之前触发。
     * StatefulElement 用它跑 State::initState()：订阅须在第一次 build() 前到位，
     * 否则首帧读不到已选中的值。
     */
    protected function onMount(): void
    {
    }

    /** 本节点的渲染盒；结构节点可能没有 ⇒ null */
    public function renderObject(): ?RenderBox
    {
        return $this->box;
    }

    /**
     * 造 / 换渲染盒。
     *
     * @internal
     */
    protected function syncBox(): void
    {
        // 全仓唯一的盒创建点：bumpLayoutEpoch 让"新盒 ⇒ 必须重排"记这里；
        // 帧外换盒（update/applyContext）不经过 settle，不 bump ⇒ 新盒 size() 恒 null ⇒ 命中落空。
        $this->pipeline()->bumpLayoutEpoch();

        $previous = $this->box;
        $this->box = $this->widget instanceof RenderBoxWidget
            ? $this->widget->createRenderObject($this->context)
            : null;

        // 盒每次重建都换新，悬停态住盒上 ⇒ 不交回会在 setState() 时清零（鼠标移上不变色）；
        // 只移交描述之外的部分（RenderRuntime），只认同类型，且用 get_class() 而非 $previous::class（AOT 启动即崩）。
        if ($previous !== null && $this->box !== null
            && \get_class($previous) === \get_class($this->box)) {
            $this->box->inheritRuntimeState($previous);
        }

        // 光标形状是 Widget 的声明，集中落一份到盒上，避免各 createRenderObject 漏抄只用默认光标。
        $this->box?->setCursor($this->widget->cursorShape());

        // 修饰同形：一个引用递给盒，不逐项传递。
        if ($this->box !== null) {
            $mods = $this->widget->mods();

            // 主题派生默认盒样式（Card 底/边/圆角）在此合，不在控件里：控件拿不到"设过没有"，
            // 各写会有人用 boxStyle()===null 当判据 ⇒ 默认又叠回打架；只对不自绘 chrome 的控件生效。
            if ($this->widget instanceof RenderBoxWidget && !$this->widget->paintsBoxChrome()) {
                $default = $this->widget->defaultBoxStyle($this->context);
                if ($default !== null) {
                    $mods = $mods->withBoxStyleIfBlank($default);
                }
            }

            // 自绘 chrome 把视觉三字段派生进 BoxStyle，这份 mods 要剥掉那三项，否则 paint() 重画；描边不幂等。
            if ($this->widget instanceof RenderBoxWidget && $this->widget->paintsBoxChrome()) {
                $mods = $mods->withoutBoxVisual();
            }

            // 控件自己画禁用外观 ⇒ 别让框架再乘一层 alpha
            if ($this->widget instanceof RenderBoxWidget && $this->widget->paintsDisabledLook()) {
                $mods = $mods->withoutDim();
            }

            $this->box->setMods($mods);

            return;
        }

        // 修饰无处可去 ⇒ 响亮失败，不静默丢掉：不产盒的控件套修饰完全不生效
        // （尺寸一点没变）。修法：用 Padding 包装节点，或改用控件自己的样式参数。
        if (!$this->widget->mods()->isDefault()) {
            Errors::report(sprintf(
                '%s 声明了修饰（padding / 尺寸 / 背景…），但它自己不产渲染盒 ⇒ 这些修饰不会生效。'
                    . "\n" . '修法：① 用 `Padding::make(8)->child($x)` 这样的包装节点；'
                    . '② 或改用该控件自己的样式参数（如 `TextField` 的 `label`）。',
                $this->widget::class
            ));
        }
    }

    /**
     * 本节点对父可见的盒：自己有就用；没有往下找第一个有盒的后代。
     * StatelessWidget 常 wrap 渲染子树，自己没盒但底下有盒，中间层应透明；
     * 少了这步，包几层的子树收不到子盒，画面整块消失（不报错）。
     *
     * @internal 父管挂接时用它，不要用 renderObject()
     */
    public function effectiveBox(): ?RenderBox
    {
        if ($this->box !== null) {
            return $this->box;
        }

        foreach ($this->children as $child) {
            $found = $child->effectiveBox();
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 让自己在下次 settle 重建。
     * 只有 StatefulElement 有状态可重读；无态节点调它什么都不发生。
     * 消费方 Application::syncPanelSignatures()（跨树：浮层是第二棵根渲染树，主树 setState 到不了）。
     *
     * @internal 框架内部用，控件不要调
     */
    public function markNeedsBuild(): void
    {
        if ($this instanceof StatefulElement) {
            $this->state()?->markDirty();
        }
    }

    /**
     * 子树里第一个匹配的元素（先序，**含自己**；找不到 ⇒ null）。
     * 与 `findBox()` 同形：`requestFocus()` 要"先找到目标"才能把焦点交出去。
     *
     * @param callable(Element):bool $match
     * @internal 框架内部查找
     */
    public function findElement(callable $match): ?Element
    {
        if ($match($this)) {
            return $this;
        }

        foreach ($this->children as $child) {
            $found = $child->findElement($match);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * 子树里第一个类型匹配的渲染盒（找不到 ⇒ null）。
     * 与 effectiveBox() 区别：那个是父挂接子盒用的第一个盒（可能是包装层）；
     * 这里要指定控件的盒，须穿过包装层。IME 光标矩形必须用 RenderTextField 本身。
     *
     * @param callable(RenderBox):bool $match
     * @internal 框架内部查找
     */
    public function findBox(callable $match): ?RenderBox
    {
        if ($this->box !== null && $match($this->box)) {
            return $this->box;
        }

        foreach ($this->children as $child) {
            $found = $child->findBox($match);
            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * findBox() 的类型化特化：子树里第一个指定类的盒（找不到 ⇒ null）。
     * 查询知识（"是不是某类盒"）只写一份，避免各调用方判据写反、漏 null。
     * 返回 ?RenderBox：调用方用 @var 声明具体类型。
     *
     * @param class-string<RenderBox> $boxClass
     * @internal 同 findBox()
     */
    public function findBoxOf(string $boxClass): ?RenderBox
    {
        return $this->findBox(static fn(RenderBox $b): bool => $b instanceof $boxClass);
    }

    /**
     * effectiveBox() 的类型化特化：自己的有效盒是指定类时才返回。
     * 给"控件认自己的盒"用（ScrollViewState / LazyListState 的 ensureVisible()
     * 与视口估计）：首帧前 / 盒被换掉 ⇒ null。语义同 effectiveBox()，会下沉到
     * 后代第一个盒，但这三处 element 恒有自己的渲染盒，实际等价。
     *
     * @param class-string<RenderBox> $boxClass
     * @internal 同 findBox()
     */
    public function effectiveBoxOf(string $boxClass): ?RenderBox
    {
        $box = $this->effectiveBox();

        return $box instanceof $boxClass ? $box : null;
    }

    /**
     * 子树建完后把子节点的盒收进自己的盒（父管挂接，子不自定位）。
     * 基类空操作：叶子没有子盒要收，容器覆写它。
     *
     * @internal
     */
    protected function adoptBoxes(): void
    {
    }

    /**
     * 换新描述。Widget 不可变 ⇒ 参数只会整体换新，不存在字段级半同步。
     */
    public function update(Widget $newWidget): void
    {
        $this->widget = $newWidget;
        $this->widget->seal();
        $this->pipeline()->bumpContent(); // ★ 声明可能变了（`focusOrder` / `disabled` / 标签）
        $this->syncBox(); // §13.2：新描述 ⇒ 新盒
        $this->didUpdateWidget($newWidget);
        $this->rebuild();
    }

    /**
     * @internal 子类钩子：新描述已到位、重建之前触发。
     * StatefulElement 用它把新 Widget 递给活着的 State —— 跟手输入不丢、展开状态
     * 还在是结构保证，不是文档承诺。
     */
    protected function didUpdateWidget(Widget $newWidget): void
    {
    }

    /** 本节点算出的、要递给子层的渲染环境 */
    private function childContext(): BuildContext
    {
        return $this->widget->childContext($this->context);
    }

    /**
     * 换一份渲染环境（主题切换的唯一入口）。
     *
     * ① 无状态/容器节点立即重建：按主题取值的控件渲染盒在 createRenderObject(ctx)
     *   里算，不重算等于换表不换色。
     * ② 有状态节点只标脏，等帧末 flush：紧跟着读 $state 旧值是"推迟"不是"丢"。
     *
     * 递归只走一遍（无状态节点 rebuild()→updateChildren() 给每子层 applyContext()）。
     *
     * 顺序要紧：先 syncBox() 再 rebuild()，否则子盒挂进旧盒、新盒换掉旧盒 ⇒
     *   新盒是空的（容器子树静默消失，直到下次 settle 才补回）。
     *
     * @internal 由父 Element 调
     */
    public function applyContext(BuildContext $context): void
    {
        if ($this->context === $context) {
            return; // 同一份 ⇒ 幂等（父层重复下发不会引发二次重建）
        }

        $this->context = $context;

        // 先按新 ctx 造新盒（可能为 null：纯结构节点不产盒）
        $this->syncBox();

        if ($this instanceof StatefulElement) {
            $this->state()?->markDirty();
        } else {
            $this->rebuild(); // 内部 adoptBoxes() 挂的是上面刚造的**新盒**
        }
    }

    abstract public function rebuild(): void;

    /**
     * 帧末统一重建：走全树，只重建标脏的节点。
     *
     * 子层重建可能换了盒 ⇒ 本节点必须重新挂接（adoptBoxes()）。漏掉静默：脏的
     *   StatefulElement 造出新盒，但祖先容器仍握旧盒 ⇒ 画面不变也不报错；挂接后序，父层才拿到新盒。
     *
     * 多轮收敛：脏在"后代→祖先"方向传播时单遍收不住 ⇒ 循环到 pendingDirty()===0
     *   或 16 轮上限。settle() 是 final：仅 ErrorBoundaryElement 覆写整遍会让第 2 遍异常
     *   绕过边界 ⇒ 错误边界退化且不报错；拆 settlePass() 后语法不可达。
     *
     * @return bool 本次 settle 是否重建过任何节点
     * @internal 由 Drift\App\Frame 调
     */
    final public function settle(): bool
    {
        $before = $this->pipeline()->rebuiltCount();

            // 第一遍无条件跑，不能因 pendingDirty()===0 整遍跳过：这一遍还做
            // adoptBoxes()（挂载路径不调它），首帧盒挂接全靠 settle 这一遍。
            // 跳过 ⇒ 容器握空盒 ⇒ 画面全白且不报错。
            for ($round = 1; $round <= self::SETTLE_ROUNDS; $round++) {
                $passBefore = $this->pipeline()->rebuiltCount();
                $this->settlePass();

                if ($this->pipeline()->pendingDirty() === 0) {
                    break;                        // 干净了：绝大多数帧到这里就退
                }

                if ($this->pipeline()->rebuiltCount() === $passBefore) {
                    // 有脏计数但这一遍什么都没重建 ⇒ 计数漂移（脏 State 不在树里
                    // /已卸载），不是真循环。再跑只是空转，且会误报 E-FLUSH-CYCLE。
                    break;
                }
            }

            if ($round > self::SETTLE_ROUNDS) {
                Errors::report(sprintf(
                    '一帧内 rebuild 了 %d 轮仍未收敛：有 State 在 build() 里改了会标脏的东西（E-FLUSH-CYCLE）',
                    self::SETTLE_ROUNDS,
                ));
            }

            return $this->pipeline()->rebuiltCount() > $before;
    }

    /**
     * 单遍收敛（前序：先自己、再子节点、最后挂接）。
     *
     * 循环归 settle() 所有且它 final，子类无法用覆写整遍绕过收敛。
     */
    protected function settlePass(): void
    {
        if ($this instanceof StatefulElement && $this->state()?->needsBuild()) {
            $this->rebuild(); // rebuild() 内部消费脏标记
        }

        // 普通闭包而非箭头函数：fn()=>$c->settlePass() 会报
        // "A void method must not return a value"（箭头函数隐式返回表达式）。
        $this->visitChildren(function (Element $child): void {
            $child->settlePass();
        });

        // 后序：子层已定盒，这里才把它们收进自己的盒（容器覆写，叶子空操作）
        $this->adoptBoxes();
    }

    /**
     * 本节点展开出的子 Widget 列表 —— 调和的唯一入口。
     * StatelessElement = [build($ctx)]；结构节点（Column/Row/ForEach）由自己的
     * Element 覆写返回 children。扩展点只有一处，四种节点共用同一套 diff。
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
        $this->pipeline()->bumpStructure(); // ★ 结构变了（`07` §6.2）
    }

    /**
     * (type, key) diff：逐槽找一个可复用的旧子。
     * - 类型 + Key 都一致 ⇒ 复用同一 Element（State/子树身份稳定）
     * - 位置相同而都没 Key ⇒ 视为可复用（身份退化为位置）
     * - 槽位变了 ⇒ 卸载旧的、挂新的
     * 两段：① 前缀同步（都对上就复用，不分配数组）；② 剩余段按 (class,key) 建桶取桶头（O(剩余)）。
     */
    protected function updateChildren(array $newWidgets): void
    {
        $old = $this->children;
        $used = [];
        $next = [];

        $oldCount = \count($old);
        $newCount = \count($newWidgets);
        $index = 0;

        // ① 前缀同步：位置、类型、键三者都对上就复用（顺序不变时的全部开销）
        $limit = $oldCount < $newCount ? $oldCount : $newCount;
        for (; $index < $limit; $index++) {
            $candidate = $old[$index];
            $newWidget = $newWidgets[$index];
            $this->pipeline()->bumpPairCheck();

            if (get_class($candidate->widget()) !== get_class($newWidget)
                || !Key::same($candidate->widget()->identity(), $newWidget->identity())
            ) {
                break;   // 前缀到此为止，剩下的交给索引
            }

            $used[$index] = true;
            $next[] = $this->reuseChild($candidate, $newWidget);
        }

        // ② 剩余段：按 (class, key) 建桶，逐个子取桶头（O(剩余)）
        if ($index < $newCount) {
            /** @var array<string, list<int>> $buckets 键 ⇒ 旧槽位（桶内升序） */
            $buckets = [];
            for ($j = $index; $j < $oldCount; $j++) {
                $buckets[self::slotKey($old[$j]->widget())][] = $j;
            }

            /** @var array<string, int> $cursor 每个桶取到第几个（不用 array_shift：那是 O(n)） */
            $cursor = [];

            for (; $index < $newCount; $index++) {
                $newWidget = $newWidgets[$index];
                $slotKey = self::slotKey($newWidget);
                $bucket = $buckets[$slotKey] ?? null;
                $at = $cursor[$slotKey] ?? 0;

                if ($bucket !== null && isset($bucket[$at])) {
                    $this->pipeline()->bumpPairCheck();

                    $slot = $bucket[$at];
                    $cursor[$slotKey] = $at + 1;
                    $used[$slot] = true;
                    $next[] = $this->reuseChild($old[$slot], $newWidget);
                    continue;
                }

                $element = $newWidget->createElement();
                $element->mount($this, $this->childContext());
                $next[] = $element;
            }
        }

        // 循环变量不能叫 $index：上面已把它定型成 Int，foreach 的 $k 是 Var ⇒
        // AOT 报类型不匹配（解释器与 smoke 都不报，只有 AOT 构建炸）。
        foreach ($old as $oldIndex => $candidate) {
            if (!isset($used[$oldIndex])) {
                $candidate->unmount();
            }
        }

        // 重排也算结构变（语义树顺序变了），而重排不触发 mount()/unmount()（子被
        // 复用）⇒ 必须在这里单独判一次。判据逐个位置比，无条件 +1 会让版本号每帧都变。
        if (self::compositionChanged($old, $next)) {
            $this->pipeline()->bumpStructure();
        }

        $this->children = $next;
    }

    /**
     * 复用一个旧子：换新描述（实例相同则跳过）+ 递新环境。
     * applyContext() 对每个复用子都要调：本节点 ctx 结论换了（切主题）⇒ 已存在子层
     * 也必须拿到新值，否则切主题对老子树静默失效。
     * $element->widget()!==$newWidget 才 update()：同 Widget 实例复用到多处合法。
     */
    private function reuseChild(Element $element, Widget $newWidget): Element
    {
        if ($element->widget() !== $newWidget) {
            $element->update($newWidget);
        }

        $element->applyContext($this->childContext());

        return $element;
    }

    /**
     * 配对用的桶键：class + Key 的单射编码（同 Key::same() 等价类）。
     * Key::same() 规则：都无 key ⇒ 相等（身份退化为位置）；一方有 ⇒ 不等；
     * 都有 ⇒ === 比值且不做类型互转（1 与 '1' 不等）⇒ 编码必须带类型前缀。
     * 字符串键带长度前缀：否则 'i5' 与 5 可能撞进同一桶。
     */
    private static function slotKey(Widget $widget): string
    {
        $class = get_class($widget);
        $key = $widget->identity();

        if ($key === null) {
            return $class . "\0#";
        }

        $value = $key->value;

        if (\is_int($value)) {
            return $class . "\0i" . $value;
        }

        return $class . "\0s" . \strlen($value) . ':' . $value;
    }

    /**
     * 子表组合是否变了（数量 / 每个位置上的实例）。
     *
     * @param list<Element> $old
     * @param list<Element> $next
     */
    private static function compositionChanged(array $old, array $next): bool
    {
        if (count($old) !== count($next)) {
            return true;
        }
        foreach ($next as $index => $element) {
            if (($old[$index] ?? null) !== $element) {
                return true;
            }
        }

        return false;
    }
}
