<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Core\Pipeline;
use Drift\Input\CursorShape;
use Drift\Input\FocusNode;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\EditableText;
use Drift\Widget\States;

/**
 * 焦点链与命中的最小实现。
 *
 * ```text
 * KeyEvent ──▶ ⓪编辑命令（剪贴板）──▶ ①焦点链（`dispatchKey`）──▶ ②加速键 ──▶ ③激活
 * PointerEvent ──▶ 命中测试（RenderBox 树）──▶ 焦点
 * ```
 *
 * ① 先于 ②：控件认得就吃、认不得返回 `false` 自然落给加速键；反过来靠逐键白名单必然漏键。
 * 焦点链是渲染态：只存 `?Element`，切焦点经 `State::didChangeFocus()` 同步编辑态。
 *
 * ① 的末尾还有一条**默认动作**：链上没人认领键盘时，方向键按几何最近者移焦点
 * （`focusDirectional()`）。声明了 `keyboard()` 的控件（表 / 下拉 / 滑块 / 富文本）
 * 方向键归它，框架不抢。
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

    /** 上次 `collect()` 时的内容版本号（`null` = 还没收过），与 `$collectedRoots` 组成"要不要重收"的签名。 */
    private ?int $collectedVersion = null;

    /** @var list<int> 上次收的根签名（陷阱开关 + `spl_object_id` 序） */
    private array $collectedRoots = [];

    /**
     * 本次 `collect()` 是否处于**焦点陷阱**：陷阱只改一件事 —— Tab 到边界时在链内循环
     * （不环绕则用户在对话框里按 Tab 卡住不动）；非陷阱维持"到末尾就停"。
     */
    private bool $trapped = false;

    /** 最近一次指针命中解析出的 Element：命令不带位置，菜单锚点只能取这个。 */
    private ?Element $lastHit = null;

    /**
     * 当前悬停目标（`hoverHandlerValue()` 声明者链上最近的 Element）。
     * 与 `DragController::$hover` 同模式但独立：普通悬停常驻，拖放悬停只在拖动期活跃。
     */
    private ?Element $hovered = null;

    /**
     * 悬停**原始命中**变化的监听口（Tooltip 用）：`\Closure(?Element): void`，
     * 收的是未解析的命中元素（不是 `$hovered`）。
     * 不复用 `hoverHandler`：Tooltip 的声明者可能没有 `hover()`，两个集合不同源。
     */
    private ?\Closure $hoverListener = null;

    /** 上一次的**原始命中**元素（未经 `hoverOwner` 解析）：悬停高亮要 `$hovered`，Tooltip 要这个。 */
    private ?Element $hoverHit = null;

    /**
     * 当前处于悬停态的渲染盒（沿盒父链第一个 `acceptsHover()` 的盒）。
     * 与 `$hovered` / `$hoverHit` 是三条独立判据；存盒而不是 Element —— `acceptsHover()` 在 `RenderObject` 上。
     */
    private ?RenderObject $hoverBox = null;

    /**
     * 当前处于按下态的渲染盒（沿盒父链第一个 `acceptsPress()` 的盒）。
     *
     * ★ 与 `$hoverBox` 分开存：按下时指针一定也在盒上（两个都非 null），
     *   但松开后 `hoverBox` 还在、`pressBox` 必须已经清了。
     */
    private ?RenderObject $pressBox = null;

    /** 注入悬停命中监听（`null` 回调不注册即默认行为） */
    public function onHoverChanged(\Closure $listener): void
    {
        $this->hoverListener = $listener;
    }

    /**
     * 从根重建焦点链（挂载 / 更新后调）。
     *
     * 浮层是独立的根渲染树 ⇒ 调用方把每层浮层的 element 也放进 `$roots`（主树在前、浮层在后）。
     * `$scope` 非空 = 焦点陷阱：只收这些根，且 Tab 在链内循环。
     * 树变了 ⇒ 原焦点 Element 可能已被移除，这里显式清掉 —— 留着死节点会让后续按键静默丢弃。
     *
     * @param list<Element> $roots 全部要收的根（主树 + 各层浮层）
     * @param list<Element> $scope 非空 ⇒ 焦点陷阱，只收这些根且循环
     */
    public function collect(array $roots, array $scope = []): void
    {
        // 门控：内容版本没变、根集合没变 ⇒ 不重收（稳态帧里全树扫描是最大头）。
        // 判据用 `contentVersion()` 而不是 `structureVersion()`：后者看不见 `disabled()` 这类"节点没增删"的改动。
        // 根集合也要比：浮层开合不进主树，`contentVersion` 看不见它。
        $effective = $scope !== [] ? $scope : $roots;
        // 签名 = 陷阱开关 + 根集合（`roots` 相同而 `scope` 由空变非空时，收的面完全不同）
        $rootIds = [$scope !== [] ? 1 : 0];
        foreach ($effective as $root) {
            $rootIds[] = \spl_object_id($root);
        }
        $version = Pipeline::current()->contentVersion();
        if ($version === $this->collectedVersion && $rootIds === $this->collectedRoots) {
            return;
        }
        $this->collectedVersion = $version;
        $this->collectedRoots = $rootIds;

        $this->order = [];
        $this->byId = [];
        $this->trapped = $scope !== [];

        foreach ($effective as $root) {
            $this->walk($root);
        }

        if ($this->focused !== null && !isset($this->byId[spl_object_id($this->focused)])) {
            $this->leave($this->focused);
            $this->focused = null;

            // 焦点因元素卸载而丢失 ⇒ 焦点环还留在旧位置，必须再画一帧擦掉；这一帧的 paint 已算完，只能要下一帧。
            Pipeline::current()->scheduleFrame();
        }

        // 自动聚焦：焦点空着就交给 Tab 序里第一个声明 `autoFocus()` 的节点。
        // ★ 只在"没人聚焦"时动手（与 Flutter 同语义）：抢焦点会把用户正在操作的
        //   控件弹走。模态那一支不需要额外条件 —— 陷阱生效时上面刚把陷阱外的旧
        //   焦点清成 null，于是自然落到对话框里。
        if ($this->focused === null) {
            foreach ($this->order as $candidate) {
                if ($candidate->widget()->focusNode()?->autoFocus === true) {
                    $this->focus($candidate);

                    break;
                }
            }
        }

        if ($this->lastHit !== null && !$this->lastHit->isMounted()) {
            $this->lastHit = null;
        }

        // 悬停目标离线（菜单关了 / 子树被换掉）⇒ 显式退出，否则高亮留在最后一项上（静默错）。
        if ($this->hovered !== null && !$this->hovered->isMounted()) {
            self::fireHover($this->hovered, false);
            $this->hovered = null;
        }

        // 原始命中离线同理（Tooltip）：`$hovered` 的清理管不到它，不通知的话消费方会握着死锚点继续显示。
        if ($this->hoverHit !== null && !$this->hoverHit->isMounted()) {
            $this->hoverHit = null;

            if ($this->hoverListener !== null) {
                ($this->hoverListener)(null);
            }
        }
    }

    /** 最近一次命中的 Element（浮层锚点用；未命中过 / 已离线 ⇒ `null`） */
    public function lastHit(): ?Element
    {
        return $this->lastHit;
    }

    /** 命中的盒 ⇒ 它对应的 Element（从命中盒反查，不从坐标重算：一次命中，三方消费）。 */
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

    /** 焦点节点的 `State`（IME 光标 / 编辑态门控的唯一出口）。 */
    public function focusedState(): ?State
    {
        return States::of($this->focused);
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

        // 焦点变了 ⇒ 立刻要一帧：焦点环是应用级叠加，不经过 `setState` ⇒ 没有脏信号，
        // 不在这里举手就要等下一次鼠标移动才出现（真机现象）。
        Pipeline::current()->scheduleFrame();
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
     * 派发一次按键（焦点链）：`Tab` / `Shift+Tab` ⇒ 最近祖先的 `keyboard()` ⇒ `EditableText::handleKey()`。
     *
     * 返回 `false` ⇒ 这一环没吃掉，上层（加速键 / 激活）继续。
     *
     * `keyboard()` **沿父链找最近的声明者**：只读焦点控件自己的 widget 会让包裹式控件
     * （`ComboBox` 包 `TextField`）的声明永远收不到键。最近声明者没吃掉即停，不再往上找。
     */
    public function dispatchKey(KeyEvent $event): bool
    {
        if ($event->chord->is(KeyChord::tab())) {
            return $this->focusNext();
        }

        if ($event->chord->is(KeyChord::shift('Tab'))) {
            return $this->focusPrevious();
        }

        // 焦点控件或最近祖先的 `keyboard()` 声明者；找不到 ⇒ 这一环让开。
        // `$declared` 同时回答"这条链上到底有没有人认领键盘"——方向键的归属靠它判。
        $declared = false;
        for ($node = $this->focused; $node !== null; $node = $node->parent()) {
            $handler = $node->widget()->keyHandlerValue();
            if ($handler === null) {
                continue;
            }

            $declared = true;

            // 签名 `(KeyEvent, Element)`；老的单参 handler 在 PHP 里会忽略多余参数。
            if ($handler($event, $node) === true) {
                return true;
            }

            break;   // 最近的声明者没吃掉 ⇒ 不再往上找（更远的祖先更不具体）
        }

        $state = $this->focusedState();

        if ($state instanceof EditableText && $state->handleKey($event)) {
            return true;
        }

        // 方向键：**没人认领键盘**时才归框架。声明了 `keyboard()` 的控件（表 / 下拉 /
        // 滑块 / 富文本）方向键是它的语义，框架不抢 —— 抢了就是"表里按 ↓ 焦点跑了"。
        if ($declared) {
            return false;
        }

        if ($event->chord->is(KeyChord::left())) {
            return $this->focusDirectional(-1, 0);
        }

        if ($event->chord->is(KeyChord::right())) {
            return $this->focusDirectional(1, 0);
        }

        if ($event->chord->is(KeyChord::up())) {
            return $this->focusDirectional(0, -1);
        }

        if ($event->chord->is(KeyChord::down())) {
            return $this->focusDirectional(0, 1);
        }

        return false;
    }

    /**
     * 方向键移焦点：交给**该方向上几何最近**的可聚焦节点。
     *
     * ★ 候选取 `$byId`（可聚焦表）而不是 `$order`（Tab 序）—— `skipFocus()` 的含义是
     *   "别进 Tab 序"，不是"别能被聚焦"。日历 31 格 / 时刻表 144 格正是靠这条区分才
     *   走得到（`08` §3 记了很久的已知缺口）；用 `$order` 它们永远够不着。
     * ★ 判分 `主轴边距 + 2×副轴投影间隙`（重叠即 0）：正下方优先于斜下方，斜下方
     *   仍可达 —— 网格（日历 / 时刻表）靠这一条才走得通。
     * ★ 中心点必须严格落在该方向的半平面里；中心重合不算候选（否则左右键会原地打转）。
     * ★ 焦点空着时：**陷阱内**（浮层）落到链首 / 链尾，非陷阱不猜（返回 `false`）。
     *   浮层既然声明了焦点陷阱，焦点空着就只能靠 Tab 进 —— 方向键给它第二个入口，
     *   而主树里"按一下键焦点凭空出现"是意外，不做。
     */
    private function focusDirectional(int $dx, int $dy): bool
    {
        if ($this->focused === null) {
            $count = \count($this->order);
            if (!$this->trapped || $count === 0) {
                return false;
            }

            $this->focus($this->order[$dx > 0 || $dy > 0 ? 0 : $count - 1]);

            return true;
        }

        $from = $this->focused;
        $box = $from->effectiveBox();
        if ($box === null || $box->size() === null) {
            return false;
        }

        $size = $box->size();
        $origin = RenderBox::absoluteOrigin($box);
        $x0 = $origin[0];
        $y0 = $origin[1];
        $x1 = $x0 + $size->width;
        $y1 = $y0 + $size->height;
        $cx = ($x0 + $x1) / 2.0;
        $cy = ($y0 + $y1) / 2.0;

        $best = null;
        $bestScore = 0.0;

        foreach ($this->byId as $candidate) {
            if ($candidate === $from) {
                continue;
            }

            $other = $candidate->effectiveBox();
            if ($other === null) {
                continue;
            }

            $csize = $other->size();
            if ($csize === null || $csize->width <= 0.0 || $csize->height <= 0.0) {
                continue;
            }

            $co = RenderBox::absoluteOrigin($other);
            $c0x = $co[0];
            $c0y = $co[1];
            $c1x = $c0x + $csize->width;
            $c1y = $c0y + $csize->height;

            // 中心点必须严格落在该方向的半平面里：中心重合不算候选（否则会原地打转）
            if ((($c0x + $c1x) / 2.0 - $cx) * $dx + (($c0y + $c1y) / 2.0 - $cy) * $dy <= 0.0) {
                continue;
            }

            // 主轴用**边到边**的间隙、副轴用**投影间隙**（重叠即 0）。
            // ★ 不能用中心点算副轴：宽控件（下拉的输入框 200 宽、表格整行）中心离得远，
            //   会把"正下方"判成"斜下方"，于是按 ↓ 跳过它就走了 —— 真机上就是这么撞出来的。
            if ($dx !== 0) {
                $primary = $dx > 0 ? $c0x - $x1 : $x0 - $c1x;
                $cross = self::axisGap($y0, $y1, $c0y, $c1y);
            } else {
                $primary = $dy > 0 ? $c0y - $y1 : $y0 - $c1y;
                $cross = self::axisGap($x0, $x1, $c0x, $c1x);
            }

            if ($primary < 0.0) {
                $primary = 0.0;
            }

            $score = $primary + 2.0 * $cross;
            if ($best === null || $score < $bestScore) {
                $best = $candidate;
                $bestScore = $score;
            }
        }

        if ($best === null) {
            return false;
        }

        $this->focus($best);

        return true;
    }

    /** 两条线段在同一轴上的间隙（重叠 ⇒ 0） */
    private static function axisGap(float $a0, float $a1, float $b0, float $b1): float
    {
        if ($b0 > $a1) {
            return $b0 - $a1;
        }

        if ($a0 > $b1) {
            return $a0 - $b1;
        }

        return 0.0;
    }

    /** 命中测试 ⇒ 焦点。保留给"只关心焦点"的调用方；真机输入走 `InputRouter::dispatch()`。 */
    public function dispatchPointer(PointerEvent $event, Element $root): bool
    {
        return $this->focusHit($this->hitTest($event->x, $event->y, $root), $root);
    }

    /** 命中测试：一次走树，结果供焦点 / 光标 / 语义三方消费，不许各自重算。 */
    public function hitTest(float $x, float $y, Element $root): ?RenderBox
    {
        return $root->effectiveBox()?->hitTest($x, $y);
    }

    /**
     * 命中链解析光标形状：从命中的盒向上找最近声明者（子声明覆盖祖先）。
     * 一路没人声明 ⇒ `CursorShape::Default`，不是 `null`（"未声明"≠"明确要箭头"）。
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
     * 指针移动 ⇒ 刷新悬停目标；`null` = 没命中 / 离开客户区。只在目标变化时派发。
     */
    public function hoverTo(?Element $element): void
    {
        // 悬停态视觉先推 —— 与下面的 `hoverOwner`（回调）是两条独立判据，同一次 move 两者都不该重复派发。
        $this->applyHoverState($element);

        // 原始命中先过监听口（Tooltip），同样只在变化时派发。
        if ($element !== $this->hoverHit) {
            $this->hoverHit = $element;

            if ($this->hoverListener !== null) {
                ($this->hoverListener)($element);
            }
        }

        $next = self::hoverOwner($element);

        if ($next === $this->hovered) {
            return;
        }

        $previous = $this->hovered;
        $this->hovered = $next;

        self::fireHover($previous, false);
        self::fireHover($next, true);
    }

    /** 当前悬停目标（测试 / 预览读） */
    public function hovered(): ?Element
    {
        return $this->hovered;
    }

    /**
     * 悬停态视觉：沿**盒父链**找第一个 `acceptsHover()` 的盒，旧的复位、新的置位。
     * 问能力方法而不是 `instanceof`：App 层不得认识 `Layout` 的具体盒类。
     */
    private function applyHoverState(?Element $element): void
    {
        $next = null;
        for ($box = $element?->effectiveBox(); $box !== null; $box = $box->parent()) {
            if ($box->acceptsHover()) {
                $next = $box;

                break;
            }
        }

        if ($next === $this->hoverBox) {
            return;
        }

        // ★ 盒每次重建都换新（`Element::syncBox()`），新盒经 `inheritRuntimeState()`
        //   继承了旧盒的 `hovered=true`；而上一轮"移开"清的是旧盒 ⇒ 直接置位会被
        //   `setHovered()` 的同值早退吞掉，画面停在暗帧，直到再切一次状态才恢复。
        //   先归零，让本管理器的意图成为唯一权威（同值早退会让正常路径零成本）。
        $next?->setHovered(false);
        $this->hoverBox?->setHovered(false);
        $this->hoverBox = $next;
        $this->hoverBox?->setHovered(true);

        // 不需要再报"全树发现"：`setHovered()` 内部已报帧时钟。
    }

    /**
     * 指针按下 / 抬起 ⇒ 推按下态视觉。`null` = 抬起、离开或取消。
     *
     * ★ **只认按下瞬间的目标，不跟随移动**：桌面惯例是"按住拖出去"= 取消这次按下
     *   （Windows 原生按钮就是这样）。跟随移动会让按下态在按钮之间跳，
     *   用户会以为同时按了好几个。
     *
     * ★ 与 `applyHoverState()` 分开写而不是合成一个 `applyPointerState()`：
     *   两者的**生命周期规则不同**（hover 跟随、press 锁定），合起来就要在里面
     *   判"这次是 move 还是 down"，而那正是调用方已经知道的事。
     */
    public function pressTo(?Element $element): void
    {
        $next = null;
        for ($box = $element?->effectiveBox(); $box !== null; $box = $box->parent()) {
            if ($box->acceptsPress()) {
                $next = $box;

                break;
            }
        }

        if ($next === $this->pressBox) {
            return;
        }

        // 同 `applyHoverState()`：重建换盒后标志与视觉会分叉，先归零再按意图置位。
        $next?->setPressed(false);
        $this->pressBox?->setPressed(false);
        $this->pressBox = $next;
        $this->pressBox?->setPressed(true);
    }

    /** 当前按下态的盒（`null` = 没有）；测试 / 预览读 */
    public function pressedRenderBox(): ?RenderObject
    {
        return $this->pressBox;
    }

    /**
     * 把已算好的命中落到焦点上。命中的不是可聚焦节点（点空白）⇒ 返回 `false` 且**不清焦点**。
     */
    public function focusHit(?RenderBox $hit, Element $root): bool
    {
        if ($hit === null) {
            return false;
        }

        // 命中的是最深的盒，焦点链收的是**声明可聚焦的那个 Element** ⇒ 必须沿父链上溯，否则"点得到却给不了焦点"。
        $element = $this->elementForBox($root, $hit);

        // 锚点记最深命中：菜单要钉在被点的控件旁边，可聚焦的那个可能大得多。
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

    // ── 内部 ────────────────────────────────────────────────────────────

    /**
     * @param bool $skipped 祖先（或自己）声明了 `skipFocus()` ⇒ 本子树不进 Tab 序
     * @param bool $disabled 祖先（或自己）声明了 `disabled()` ⇒ 本子树**不可交互**
     */
    private function walk(Element $element, bool $skipped = false, bool $disabled = false): void
    {
        $widget = $element->widget();
        $node = $widget->focusNode();

        // `skipFocus()` 是**子树级**（AppBar 工具组整体不进 Tab 序）
        $skip = $skipped || ($node !== null && $node->skip);

        // `disabled()` 也是子树级，但动的是**另一张表**：`skipFocus()` 只挡 Tab 序，禁用连"点了能拿焦点"一起取消。
        $off = $disabled || $widget->mods()->disabled();

        if (!$off && $widget->isFocusable()) {
            // `byId` = 可聚焦元素表（`focusHit()` 用），`order` = Tab 序（`skipFocus()` 唯一该动的）。
            // 两者共用一个 `$skip` 分支会让 `skipFocus()` 变成"既不能 Tab 到、也点不动"（行箭头点了毫无反应）。
            // 禁用则两张都不进 —— 于是 `collect()` 末尾的"focused 不在 byId ⇒ leave()"自动把焦点挪走。
            $this->byId[spl_object_id($element)] = $element;

            if (!$skip) {
                $this->order[] = $element;
            }
        }


        // 只在真的有人声明 `focusOrder` 时才排序：没有 order 时比较器恒返回 0，跳过等价于原路径，
        // 否则每个容器每帧一次 usort + 两次 `focusNode()` 链式调用。
        $anyOrder = false;
        $element->visitChildren(static function (Element $child) use (&$anyOrder): void {
            if ($child->widget()->focusNode()?->order !== null) {
                $anyOrder = true;
            }
        });

        if (!$anyOrder) {
            // 无需排序 ⇒ 直接按文档序递归（省掉 `$children` 数组与一次 usort）
            $element->visitChildren(function (Element $child) use ($skip, $off): void {
                $this->walk($child, $skip, $off);
            });

            return;
        }

        /** @var list<Element> $children */
        $children = [];
        $element->visitChildren(function (Element $child) use (&$children): void {
            $children[] = $child;
        });

        // 同一容器内按 `focusOrder` 稳定排序：没声明的排在声明之后，彼此保持文档序。
        usort($children, static function (Element $a, Element $b): int {
            $oa = $a->widget()->focusNode()?->order;
            $ob = $b->widget()->focusNode()?->order;

            if ($oa === $ob) {
                return 0;
            }

            return ($oa ?? PHP_INT_MAX) <=> ($ob ?? PHP_INT_MAX);
        });

        foreach ($children as $child) {
            $this->walk($child, $skip, $off);
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
            // 焦点陷阱（模态）里循环；主树维持既有裁决「到末尾就停」
            if (!$this->trapped) {
                return false;
            }

            $next = ($next % $count + $count) % $count;
            if ($this->focused !== null && $this->order[$next] === $this->focused) {
                return false; // 链里只有一个节点：不自己派给自己
            }
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

    /**
     * 命中盒 → 拥有它的 Element（最深优先：先找子，组合节点的 `effectiveBox()` 返回后代盒）。
     *
     * chrome 类（`RenderMenuItem`）自绘子内容 ⇒ 最深命中盒常常没有 Element 拥有它，
     * 此时必须沿**渲染树**上溯到第一个"有 Element 的盒"，否则菜单项点了永远落不了焦点。
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
        States::of($element)?->didChangeFocus(true);
    }

    private function leave(?Element $element): void
    {
        States::of($element)?->didChangeFocus(false);
    }

    /** 沿父链找最近的悬停声明者（子声明覆盖祖先 —— 与光标同判据） */
    private static function hoverOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if ($node->widget()->hoverHandlerValue() !== null) {
                return $node;
            }
        }

        return null;
    }

    /** 派发悬停进出（无声明者 / 无处理器 ⇒ 无事发生） */
    private static function fireHover(?Element $element, bool $entered): void
    {
        $handler = $element?->widget()->hoverHandlerValue();

        if ($handler !== null) {
            $handler($entered);
        }
    }
}
