<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
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
    /**
     * 收敛循环的最大轮数（`03` §5.1）：**防御上限，正常 ≤ 2**。
     *
     * ★ 16 不是拍脑袋：脏在"祖先 → 后代"方向传播时前序遍历同一遍就收住（§5.2），
     *   需要第二遍的只有"后代 → 祖先"；留 8 倍余量给病态树形。
     */
    private const SETTLE_ROUNDS = 16;

    /**
     * 结构版本号（`07` §6.2）：节点**增删**或子表**重排**时 +1。
     *
     * ★ 唯一消费者是语义推送的档位选择：结构变了 ⇒ 必须推 `full`（增量表达
     *   不了"多了一个节点"）。没有它就只能靠"有 State 脏"猜 —— 而脏不等于
     *   结构变（改个 label 是脏，不是结构变）。
     *
     * ★ **只在真的变了时 +1**：`updateChildren()` 每次 rebuild 都会被调，
     *   无条件 +1 会让版本号每帧都变 ⇒ "结构没变"永远判不出来（等于没做）。
     */
    private static int $structureVersion = 0;

    protected ?Element $parent = null;
    protected BuildContext $context;
    protected Widget $widget;

    /** @var list<Element> */
    protected array $children = [];

    // ★ AOT 注意：这两个属性被**父类方法**（`effectiveBox()` / `syncBox()` /
    //   `mount()` 等）在**子类实例**上访问。tpc 在按运行时类型查属性表时，
    //   父类的 `private` 不进子类属性表 ⇒ 子类实例上访问它们会报
    //   `Undefined property: <Sub>::$box` 并终止请求。放宽成 `protected`
    //   让它们进入子类布局（语义不变，仅可见性放宽）。
    protected bool $mounted = false;

    /** @internal §13.2：本节点的渲染盒；结构节点为 null */
    protected ?RenderBox $box = null;

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

    /** @internal 结构变了（节点增删 / 重排）—— 见 `$structureVersion` */
    public static function bumpStructure(): void
    {
        ++self::$structureVersion;
    }

    /** @internal 语义推送用它判"结构变没变"（`07` §6.2） */
    public static function structureVersion(): int
    {
        return self::$structureVersion;
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
        self::bumpStructure(); // ★ 结构变了（`07` §6.2）
        $this->onMount();
        $this->syncBox();
        $this->rebuild();
    }

    /**
     * @internal 子类钩子：已封存、**首次 build 之前**触发。
     *
     * `StatefulElement` 用它跑 `State::initState()` —— 顺序要紧：订阅必须在
     * 第一次 `build()` 之前到位，否则首帧读不到已选中的值。
     *
     * ★ 做成钩子而不是让 `StatefulElement` 重写 `mount()`：挂载路径只有一份
     *   （W1 同源），重写就等于把封存 / 造盒 / 重建的顺序抄第二遍。
     */
    protected function onMount(): void
    {
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
        // ★★ **全仓唯一的盒创建点** ⇒ "新盒 ⇒ 必须重排"这个事实就记在这里。
        //   不记的后果是静默的：`Frame` 用 `settle()` 的**帧内**重建差判"本帧有没有
        //   变"，而 `update()` / `applyContext()` 这些**帧外**路径换盒不经过 settle
        //   ⇒ 判成"没变" ⇒ 跳过 layout ⇒ 新盒 `size()` 恒 `null` ⇒ 命中测试全落空。
        //   详见 `State::$boxEpoch`。
        State::noteBoxReplaced();

        $this->box = $this->widget instanceof RenderBoxWidget
            ? $this->widget->createRenderObject($this->context)
            : null;

        // §9.1：光标形状是 Widget 的**声明**，落一份到盒上供命中链解析。
        // ★ 落在这里而不是每个 `createRenderObject()` 里各传一次：那是 12+ 处
        //   抄同一句，漏抄的不会报错 —— 只会静默用默认光标。
        $this->box?->setCursor($this->widget->cursorShape());

        // 修饰同形（`02` §11）：一个引用递给盒，不逐项传递。
        if ($this->box !== null) {
            $this->box->setMods($this->widget->mods());

            return;
        }

        // ★★ **修饰无处可去 ⇒ 响亮失败，不静默丢掉**（`02` §11.7）。
        //   `StatelessWidget` 自己不产盒（`TextField` 的盒在 `TextFieldBox` 里；
        //   `StatefulWidget` 只有**不**覆写 `createRenderObjectFromState()` 时才
        //   不产盒）⇒ 给它们套修饰会**完全不生效**：声明的 pad 只留在 Widget 上，
        //   盒永远读不到。实测现象是"尺寸一点没变"。
        //   给这两类控件套修饰，正确的写法是**用包装节点**
        //   （`Padding::make(8)->child($tf)`）或该控件自己的样式参数。
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
     * 本节点对父可见的盒：自己有就用自己的；**没有就往下找第一个有盒的后代**。
     *
     * ★ 为什么需要它：`StatelessWidget` 常作为"组合描述"直接 wrap 一棵渲染子树
     *   （页面根 `DemoPage extends StatelessWidget` ⇒ build() 返回 `Column`）。
     *   它自己没有盒，但它底下的 `Column` 有 —— 中间层对渲染树应当是**透明的**。
     *   少了这一步，`Column[DemoPage[Column[...]]]` 会收不到子盒，画面整块消失
     *   （且不报错：C6 只查上界，0×0 在 tight 约束下是合法的）。
     *
     * @internal §13.2 父管挂接时用它，不要用 `renderObject()`
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
     * 让**自己**在下次 settle 重建（W8-2，2026-10-24）。
     *
     * ★ 只有 `StatefulElement` 有"状态"可重读；纯结构 / 无态节点调它**什么都不发生**
     *   —— 这也是对的：无态面板的内容只由 Widget 决定，而 Widget 换不了（面板的
     *   Widget 是开面板那一刻造好的）。
     *
     * ★ 消费方是 `Application::syncPanelSignatures()`（受控值变了 ⇒ 面板重读），
     *   它是**跨树**的：浮层是第二棵根渲染树，主树的 `setState` 到不了这里。
     *
     * @internal 框架内部用（`Application` 的浮层传播段），控件不要调
     */
    public function markNeedsBuild(): void
    {
        if ($this instanceof StatefulElement) {
            $this->state()?->markDirty();
        }
    }

    /**
     * 子树里**第一个类型匹配**的渲染盒（找不到 ⇒ `null`）。
     *
     * ★ 与 `effectiveBox()` 的区别：那个是"父要挂接子盒"用的**第一个盒**（可能是
     *   包装层，例如 `TextField` 有 label 时的 `Column`）；这里要的是"**指定控件**的
     *   盒"，必须穿过包装层继续往下找。
     *
     * 用在 IME 光标矩形（`TextFieldState::caretRect()`）：它必须拿到 `RenderTextField`
     * 本身 —— 拿 `Column` 的盒去问光标位置只会得到 `null`，候选框就贴屏幕左上角
     * （2026-10-19 实测：有 label 的字段全错、无 label 的全对）。
     *
     * @param callable(RenderBox):bool $match
     * @internal 框架内部查找（Widget 侧不要用它做业务判断）
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

    /** 本节点算出的、要递给子层的渲染环境 */
    private function childContext(): BuildContext
    {
        return $this->widget->childContext($this->context);
    }

    /**
     * 换一份渲染环境（**主题切换**的唯一入口）。
     *
     * ★ 两种兑现时机，**必须写清否则是陷阱**：
     *   ① **无状态 / 容器节点立即重建** —— 它没有脏标记可标，而"按主题取值的
     *      控件"（Text 字号、Button 底色）的渲染盒是在 `createRenderObject(ctx)`
     *      里算的，不重算就等于换表不换色。
     *   ② **有状态节点只标脏**，等帧末 flush（§5.2 裁决 3：不在事件处理中途重建）。
     *      于是紧跟着读 `$state` 里的旧值是可能的 —— 那是"推迟"，不是"丢"。
     *
     * ★ 递归只走一遍：无状态节点的 `rebuild()` → `updateChildren()` 会给每个
     *   子层 `applyContext()`（新挂的走 `mount()`），所以这里不再自己遍历。
     *   `BuildContext` 是 readonly 值对象 ⇒ `===` 比身份，重复下发直接返回。
     *
     * ★★ **顺序要紧：先 `syncBox()` 再 `rebuild()`**。`rebuild()` 末尾的
     *   `adoptBoxes()` 是"把子盒挂进**本节点当前的盒**"；若先 rebuild 后 syncBox，
     *   子盒会挂进**旧盒**，紧接着新盒把旧盒整个换掉 ⇒ **新盒是空的**（容器整棵
     *   子树静默消失，直到下一次 `settle()` 才被补回来）。§13.2 说"盒按 ctx 算"
     *   ⇒ 必须先换盒、再挂子。
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
     * 帧末统一重建（§5.2 裁决 3）：走全树，**只重建标脏的节点**。
     *
     * ★ 住 `Element` 而不是 `Frame`：① 树遍历是树自己的事；② `Offstage` 重新
     *   可见时要兑现隐藏期间攒下的脏标记，那个调用点在 Widget 层 —— 而
     *   §4.3 禁 `Widget → App`，放 `Frame` 里 Offstage 够不着。
     *
     * ★ 无条件全树重建就退回"每帧重排整棵树"，§13.3 的脏边界白做。
     *
     * ★★ **子层重建可能换了盒 ⇒ 本节点必须重新挂接**（`adoptBoxes()`）。这是
     *   §13.2 + C1 的必然一步，漏掉就是**静默**的：脏的 `StatefulElement` 重建
     *   后 `syncBox()` 造出新盒，但**它的祖先容器仍握着旧盒对象** —— 于是
     *   `layout()/paint()` 走的是重建前那棵渲染树，画面上什么都不变、也不报错。
     *   首个暴露者：`TextField`（第一个"重建后盒内容会变"的 `StatefulWidget`）——
     *   输入焦点/文本改了，画面纹丝不动。挂接是**后序**（先 `visitChildren` 再
     *   自己挂），父层拿到的才是子层刚造好的新盒。
     *
     * ★ `adoptBoxes()` 幂等（容器先 `clearChildren()` 再重挂），所以无条件调
     *   不会重复追加子盒；代价只是每帧一次 O(子数) 的指针重连，相对 `layout()`
     *   的 O(整树) 可忽略（§13.3：Render 层本来就无脏边界）。
     *
     * ★★ **多轮收敛**（`03` §5.1，W2/S3）：脏在"后代 → 祖先"方向传播时
     *   （子 rebuild 造出新节点、它的 build 标脏了祖先）单遍收不住 ⇒ 循环到
     *   `pendingDirty() === 0` 或 16 轮上限。`settle()` 因此是 **`final`**：
     *   全仓只有 `ErrorBoundaryElement` 一个覆写者，它覆写"整遍"就意味着
     *   第 2 遍起的异常绕过边界直冒 `Frame::run()` ⇒ **错误边界粒度从
     *   "到节点"退化成"到整帧"，且不报错**。拆出 `settlePass()`（单遍）后，
     *   那个错误在语法层面不可达。
     *
     * @return bool 本次 settle **是否重建过任何节点**（`05` §8 的"跳过 layout"用它）
     *
     * @internal 由 `Drift\App\Frame` 调
     */
    final public function settle(): bool
    {
        $before = State::rebuiltCount();

            // ★★ 第一遍**无条件**跑，不能因 `pendingDirty() === 0` 整遍跳过：
            //   这一遍不只重建脏节点，还做 `adoptBoxes()`（把子盒挂进父盒）——
            //   **挂载路径不调它**（`StatefulElement::rebuild()` 不像
            //   `StatelessElement::rebuild()` 那样以 adoptBoxes 收尾），首帧的
            //   盒挂接全靠 settle 的这一遍。跳过 ⇒ 容器握着空盒子 ⇒ 画面全白
            //   **且不报错**（真机探针抓到的：smoke 全绿而 showcase 首屏无墨）。
            //   `03` §5.1 原稿的"O(1) 提前退出"假设 settle 只重建脏节点，
            //   对本框架不成立 —— 已按实况改正。
            for ($round = 1; $round <= self::SETTLE_ROUNDS; $round++) {
                $passBefore = State::rebuiltCount();
                $this->settlePass();

                if (State::pendingDirty() === 0) {
                    break;                        // 干净了：绝大多数帧到这里就退
                }

                if (State::rebuiltCount() === $passBefore) {
                    // ★ 有脏计数但这一遍**什么都没重建** ⇒ 计数漂移（脏 State 不在这棵树里 /
                    //   已卸载），不是真循环。再跑下去只是空转，且会误报 E-FLUSH-CYCLE
                    //   （§10 预告的症状是"每帧多扫一遍、性能退化、不报错"—— 按那个来）。
                    break;
                }
            }

            if ($round > self::SETTLE_ROUNDS) {
                Errors::report(sprintf(
                    '一帧内 rebuild 了 %d 轮仍未收敛：有 State 在 build() 里改了会标脏的东西（E-FLUSH-CYCLE）',
                    self::SETTLE_ROUNDS,
                ));
            }

            return State::rebuiltCount() > $before;
    }

    /**
     * 单遍收敛（前序：先自己、再子节点、最后挂接）。
     *
     * ★ 循环归 `settle()` 所有，且它是 `final` —— 子类无法再用"覆写整遍"的
     *   方式绕过收敛（`ErrorBoundaryElement` 覆写的正是这一层，见那里）。
     */
    protected function settlePass(): void
    {
        if ($this instanceof StatefulElement && $this->state()?->needsBuild()) {
            $this->rebuild(); // rebuild() 内部消费脏标记
        }

        // 普通闭包而不是箭头函数：`fn(): void => $c->settlePass()` 会报
        // "A void method must not return a value" —— 箭头函数隐式返回它的表达式。
        $this->visitChildren(function (Element $child): void {
            $child->settlePass();
        });

        // 后序：子层已定盒，这里才把它们收进自己的盒（容器覆写，叶子空操作）
        $this->adoptBoxes();
    }

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
        self::bumpStructure(); // ★ 结构变了（`07` §6.2）
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
                $element->mount($this, $this->childContext());
                $next[] = $element;
                continue;
            }

            $used[$slot] = true;
            $element = $old[$slot];
            if ($element->widget() !== $newWidget) {
                $element->update($newWidget);
            }
            // 本节点的 ctx 结论换了（ThemeScope light → dark）⇒ 已存在的子层
            // 也必须拿到新值。只更新新挂的子层会让"切主题"对老子树静默失效。
            $element->applyContext($this->childContext());
            $next[] = $element;
        }

        foreach ($old as $index => $candidate) {
            if (!isset($used[$index])) {
                $candidate->unmount();
            }
        }

        // ★ **重排也算结构变**（语义树的顺序变了），而重排**不触发**
        //   `mount()`/`unmount()`（子被复用）⇒ 必须在这里单独判一次。
        // ★ 判据是**逐个位置比**（O(n) 指针比较）：`updateChildren()` 每次
        //   rebuild 都会被调，无条件 +1 会让版本号每帧都变 ⇒ 结构判据失效。
        if (self::compositionChanged($old, $next)) {
            self::bumpStructure();
        }

        $this->children = $next;
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
