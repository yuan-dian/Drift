<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Commands\CommandArg;
use Drift\Errors;
use Drift\Input\CursorShape;
use Drift\Input\FilesDroppedEvent;
use Drift\Input\GestureArena;
use Drift\Input\GestureRecognizer;
use Drift\Input\InputEvent;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Input\ScrollEvent;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Widget\Activatable;
use Drift\Widget\ActivationContext;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Element;

/**
 * 输入路由主体：键 / 指针 / 滚轮 / 文件拖放四侧的派发 + 手势状态。
 * 派发顺序（写错不报错，只是行为错）：
 *   Esc（浮层栈）⇒ 编辑命令（剪贴板）⇒ 焦点链 ⇒ 加速键 ⇒ 激活。
 *   Esc 必须排在 `dispatchKey` 之前，否则被焦点控件吃掉、菜单关不掉。
 *
 * 片间组合直连（`ScrollInputRouter` / `TextEditingBridge` / `OverlayManager`）；应用状态只走 `InputHost`，片不持副本。
 *
 * @internal S1 的拆分片，同 `FocusManager` —— 不是应用契约
 */
final class InputRouter
{
    /** 一格滚轮的逻辑像素（策略归框架：平台只做 WHEEL_DELTA/120 的量化）。48 = 3 行 × 16px。 */
    private const WHEEL_NOTCH_PX = 48.0;

    /**
     * 进行中的手势竞技场。仅当命中链上有 `Draggable` 声明者时开：激活从 down 推迟到裁决，
     * 无 `Draggable` 的控件维持"down 即激活"。
     */
    private ?GestureArena $gestureArena = null;

    /** 拖动状态机；懒建 —— 不拖的应用零成本 */
    private ?DragController $drag = null;

    /**
     * 捕获指针的节点：非空 ⇒ 指针事件只发给它，不再命中测试。
     * 捕获的全部意义就是不命中测试；四条释放路径少一条 ⇒ 事件永远发给死节点（抬起 / 卸载 / 失焦 / 浮层外按下）。
     */
    private ?Element $captureNode = null;

    public function __construct(
        private InputHost $host,
        private ScrollInputRouter $scrollInput,
        private TextEditingBridge $textEditing,
        private OverlayManager $overlayHost,
    ) {
    }

    /**
     * 喂一条平台事件。记账 / 失活收口 / 未挂载守卫都在 `Application` 壳里，这里拿到的已挂载。
     */
    public function dispatch(InputEvent $event, Element $root, FocusManager $focus): ?CursorShape
    {
        if ($event instanceof KeyEvent) {
            // ESC 必须在 `dispatchKey` 之前拦，否则被焦点控件（输入框）吃掉。
            if ($event->chord->is(KeyChord::escape())) {
                // `dismissOnEsc=false` ⇒ 吞掉但不关。"拒绝"不等于"放行"：交回加速键链会让 Ctrl+W 绕过对话框。
                $top = $this->overlayHost->overlays()->top();
                if ($top !== null) {
                    if (!$top->dismissOnEsc) {
                        return null;
                    }

                    $this->overlayHost->dismissOverlay($top);

                    return null;
                }

                if ($this->drag?->isDragging() === true) {
                    $this->cancelDrag();

                    return null;
                }

                // 仅 arena 未裁决（按下未过阈值）⇒ ESC 丢弃这次手势意图并消费该键
                if ($this->gestureArena !== null && !$this->gestureArena->isClosed()) {
                    $this->gestureArena->leave();
                    $this->gestureArena = null;

                    return null;
                }

                // ESC 也取消文本框选手势（选区折叠归焦点控件的 Esc 分支）。
                $this->textEditing->finishSelection();
            }

            // 第 ⓪ 环：编辑命令（`Ctrl+C` / `X` / `V`）。排最前是因为它要平台剪贴板，只有 App 层拿得到；
            // 四条门控任一不满足就整族回落（不吞键）。
            if ($this->textEditing->dispatchEditCommand($event)) {
                return null;
            }

            // 第 ① 环：**焦点链**（`keyboard()` 声明者 → `EditableText`），**先于**加速键。
            //   控件认得就吃、认不得返回 false 自然落给加速键（"谁处理谁赢"）；反过来靠逐键
            //   白名单必然漏键（`Table` 的 `Ctrl+A` / `Ctrl+C` 曾是死码）。
            //
            // ★ 换焦点（Tab）后要把新焦点滚进视口，否则焦点环画在视口外。判据是"焦点
            //   Element 变了"而不是"按的是 Tab" —— 控件吃别的键时焦点没动，不该滚。
            $focusedBefore = $focus->focused();
            if ($focus->dispatchKey($event)) {
                if ($focus->focused() !== $focusedBefore) {
                    $this->scrollInput->revealFocused();
                }

                return null;
            }

            // 第 ② 环：加速键。焦点控件没吃 ⇒ 才算全局快捷键。
            $accel = $this->host->accelerators()->resolve($event->chord);
            if ($accel !== null) {
                $this->host->dispatch($accel);

                return null;
            }

            // 第 ③ 环：焦点按钮的 Enter/Space 激活。排在加速键之后 ⇒ 应用绑 Enter 可覆盖。
            $this->activateFocused($event);

            // 第 ④ 环：上下文菜单键（`Shift+F10` / 菜单键 `Apps`）。**排最后** ⇒ 控件
            //   `keyboard()` 与应用加速键都能先认领；它不是"激活"（`activateFocused`
            //   只认 Enter/Space），所以单独一支，不并进去。
            // ★ 与右键走同一个入口 `openContextMenu()`：指针与键盘在这里合流，
            //   "沿父链找最近 `contextMenu()` 声明者"只有一份实现。
            if ($event->chord->is(KeyChord::shift('F10')) || $event->chord->is(KeyChord::make('Apps'))) {
                $this->openContextMenu($focus->focused());

                return null;
            }

            return null;
        }

        if ($event instanceof PointerEvent) {
            return $this->dispatchPointer($event, $root, $focus);
        }

        if ($event instanceof ScrollEvent) {
            // 滚轮：浮层开着先归浮层（barrier ⇒ 不穿透滚主树），否则命中链上最近的 ScrollView。
            // 格→像素的换算只在这里发生一次：`$event->delta` 是格，`scrollAt()` 收逻辑像素。
            // 轴由事件带来（壳已把 WM_MOUSEHWHEEL / Shift+竖轮翻成 Horizontal）。
            $px = $event->delta * self::WHEEL_NOTCH_PX;
            $axis = $event->axis;

            for ($i = $this->overlayHost->overlays()->count() - 1; $i >= 0; $i--) {
                $e = $this->overlayHost->overlays()->at($i);
                if ($e === null || $e->element === null) {
                    continue;
                }

                $hit = $focus->hitTest($event->x - $e->x, $event->y - $e->y, $e->element);
                if ($hit !== null) {
                    $this->scrollInput->scrollAt($focus->elementAt($e->element, $hit), $axis, $px);

                    return null;
                }

                if ($e->barrier) {
                    return null; // 浮层开着、轮在浮层外：不穿透
                }
            }

            $hit = $focus->hitTest($event->x, $event->y, $root);
            $element = $hit === null ? null : $focus->elementAt($root, $hit);
            $this->scrollInput->scrollAt($element, $axis, $px);

            return null;
        }

        if ($event instanceof FilesDroppedEvent) {
            $this->dispatchFilesDropped($event, $root, $focus);

            return null;   // 不是指针事件 ⇒ 不改光标
        }

        return null;
    }

    /**
     * 系统拖放：命中 → 沿父链冒泡找最近的 `onFilesDropped` 声明者，只投一个（不广播）。
     *
     * 浮层优先、barrier 不穿透，与指针分支同构；找不到声明者 ⇒ 静默丢弃（拖到不接收的地方合法）。
     * 坐标先 `(float)` 落局部再运算 —— `$event->x` 跨实例是标量 / Var，直接减浮层偏移会踩 AOT C2679。
     */
    private function dispatchFilesDropped(FilesDroppedEvent $event, Element $root, FocusManager $focus): void
    {
        $x = (float) $event->x;
        $y = (float) $event->y;

        for ($i = $this->overlayHost->overlays()->count() - 1; $i >= 0; $i--) {
            $e = $this->overlayHost->overlays()->at($i);
            if ($e === null || $e->element === null) {
                continue;
            }

            $hit = $focus->hitTest($x - $e->x, $y - $e->y, $e->element);
            if ($hit !== null) {
                $this->deliverFilesDropped($focus->elementAt($e->element, $hit), $event);

                return;
            }

            if ($e->barrier) {
                return;
            }
        }

        $hit = $focus->hitTest($x, $y, $root);
        $this->deliverFilesDropped($hit === null ? null : $focus->elementAt($root, $hit), $event);
    }

    /** 沿父链找最近的 `onFilesDropped` 声明者并调一次（异常兜在路由层，不吞泵）。 */
    private function deliverFilesDropped(?Element $element, FilesDroppedEvent $event): void
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            $handler = $node->widget()->filesDropHandlerValue();
            if ($handler === null) {
                continue;
            }

            try {
                $handler($event);
            } catch (\Throwable $e) {
                // 用 RECOVERABLE：`Errors::abort()` 末尾是 exit()，一次回调异常会杀掉整个进程。
                Errors::report(sprintf(
                    '文件拖放回调异常已隔离，后续事件不受影响 —— %s（%s:%d）',
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine(),
                ), Errors::RECOVERABLE);
            }

            return;   // 只投最近的声明者，不继续冒泡
        }
    }

    /**
     * 指针四相位。
     * down：浮层优先 / 右键菜单 / 禁用吞掉 / 窗口拖动 / `ownsPress` / Drag 裁决 / 激活。
     * move：光标 + 悬停（目标变化才派发）+ 拖放阈值推进。
     * up：放下（激活仍住 down，不做 press/release 视觉）；leave：全部收口。
     *
     * 浮层开着时四相位都先归浮层，但 `barrier=false` 的层（Tooltip / Toast）miss 时不关层。
     */
    private function dispatchPointer(PointerEvent $event, Element $root, FocusManager $focus): ?CursorShape
    {
        // ── 浮层栈：从顶往下逐层问 ─────────────────────────────
        for ($i = $this->overlayHost->overlays()->count() - 1; $i >= 0; $i--) {
            $e = $this->overlayHost->overlays()->at($i);
            if ($e === null || $e->element === null) {
                continue;
            }

            // 不参与命中的层（Tooltip）整层跳过：既不算命中也不算层外，连 barrier 都跳过。
            // 少了这一句，tooltip 一出就抢走锚点的悬停目标 ⇒ 显隐死循环。
            if (!$e->hitTest) {
                continue;
            }

            // 浮层局部坐标 = 客户区坐标 − 浮层原点（Frame 画时的 translate）
            $hit = $focus->hitTest($event->x - $e->x, $event->y - $e->y, $e->element);

            if ($hit !== null) {
                // 模态：ModalHost 盖满客户区 ⇒ 命中恒非 null；点遮罩 = 关闭意图
                if ($e->scrim && !$this->hitsModalPanel($hit)) {
                    if ($event->isDown()) {
                        $this->releasePointer();   // 浮层优先于捕获（`06` §10）

                        if ($e->dismissOnTapOutside) {
                            $this->overlayHost->dismissOverlay($e);
                        }

                        return null; // 不可关时同样吞掉（遮罩后面不能动）
                    }

                    if ($event->isMove() || $event->isLeave()) {
                        $focus->hoverTo(null);
                    }

                    return null;
                }

                if ($event->isDown()) {
                    // 浮层接管这次按下 ⇒ 捕获让位（用户主动点浮层，不该被拖拽者吃掉）。
                    $this->releasePointer();

                    // 菜单项也是按钮（`MenuItem extends Button`）⇒ 一样要有按下反馈
                    $focus->pressTo($focus->elementAt($e->element, $hit));

                    // 菜单项（MenuItem extends Button）与根树按钮同规则：焦点落在本次点击上 ⇒
                    // 立即激活；命令经 dispatch() 分发 ⇒ 与键盘 Enter 完全同路。
                    if ($focus->focusHit($hit, $e->element)) {
                        $this->activateFocusedWidget();
                    }
                } elseif ($event->isUp()) {
                    // 浮层里没有拖拽裁决 ⇒ 按下态的寿命就是 down..up
                    $focus->pressTo(null);
                } elseif ($event->isMove()) {
                    // 浮层内悬停：菜单项高亮
                    $focus->hoverTo($focus->elementAt($e->element, $hit));
                } elseif ($event->isLeave()) {
                    $focus->hoverTo(null);
                    $focus->pressTo(null);
                }

                return $event->isDown() || $event->isMove() ? $this->cursorFor($hit, $event->x, $event->y) : null;
            }

            // 点在条目盒外
            if ($e->barrier) {
                if ($event->isDown()) {
                    $this->releasePointer();   // 浮层优先于捕获（`06` §10）

                    // 点浮层外 ⇒ 关且**吞掉该次按下**（不穿透）
                    if ($e->dismissOnTapOutside) {
                        $this->overlayHost->dismissOverlay($e);
                    }

                    return null;
                }

                // move/up/leave 在浮层外：不穿透、不关浮层（关只归 down）
                if ($event->isMove() || $event->isLeave()) {
                    $focus->hoverTo(null);
                }

                return null;
            }
            // barrier=false（Tooltip / Toast）：继续往下问
        }

        // ── 指针捕获：捕获期**不命中测试** ─────────────────────
        // 排在浮层之后（浮层优先于捕获）；`barrier=false` 的浮层在上时仍能穿到捕获者。
        $capture = $this->captureNode;
        if ($capture !== null) {
            $cursor = $this->dispatchToCapture($capture, $event);

            if ($event->isUp() || $event->isLeave()) {
                $this->releasePointer();   // 释放路径 ①：指针抬起 / 离开窗口
                $this->host->focus()?->pressTo(null);
            }

            return $cursor;
        }

        // ── 滚动条 thumb 拖动 ────────────────────────────────
        // thumb 是 `paint()` 画的胶囊、不是子盒 ⇒ 命中测试看不见它，必须先于内容接管。
        if ($this->scrollInput->isDraggingThumb()) {
            if ($event->isMove()) {
                $this->scrollInput->dragThumb($event);

                return null;
            }

            $this->scrollInput->finishThumbDrag();   // up / leave / 其它相位：本次拖动结束

            return null;
        }

        if ($event->isDown() && $event->isPrimary()
            && $this->scrollInput->beginThumbDrag($event->x, $event->y, $root, $focus)) {
            return null;   // 吞掉：大拇指上的按下不算"点内容"
        }

        $hit = $focus->hitTest($event->x, $event->y, $root);   // ★ 只走一次树
        $element = $hit === null ? null : $focus->elementAt($root, $hit);

        if ($event->isDown()) {
            // 右键：非主键 ⇒ 找最近的 `ContextMenu` 声明者并打开。
            if (!$event->isPrimary()) {
                $this->openContextMenu($element);

                return null;
            }

            // 禁用的命中：吞掉本次按下，什么都不做（不激活、不捕获、不开 arena、不改光标）。
            // ★ 必须排在 `pointer()` / `Draggable` 之前：它们在 `focusHit()` 之前接管按下，
            //   而 `focusHit()` 自己会拦住禁用元素 ⇒ 只有这两条路能绕过禁用。
            //   只拦主键 —— 禁用的控件不该连祖先的右键菜单一起吃掉。
            if ($hit !== null && $hit->isDisabled()) {
                return null;
            }

            // 窗口拖动：命中链上有 `windowDrag()` 声明者、且途中没有"自己处理按下"的控件 ⇒ 抓标题栏。
            // 排在 `pointerOwner` 之前 —— 拖窗时这次按下根本不进控件树（不捕获、不改焦点、不激活）。
            // `WM_NCHITTEST` 返 `HTCAPTION` 做不到：壳看不见控件树，AppBar 上的按钮会被连点击一起拖走。
            $windowDrag = $this->windowDragOwner($element);
            if ($windowDrag !== null) {
                $this->beginWindowDrag($windowDrag);

                return null;
            }

            // 按下态视觉：**先于**"谁接管这次按下"的所有分支 —— 用户按下就要有反馈，
            // 而激活可能被手势竞技场推迟到 up（拖拽裁决），那时才画就晚了。
            // 只对 `acceptsPress()` 的盒生效（目前是按钮族），其余盒收到也当没收到。
            $focus->pressTo($element);

            // `pointer(ownsPress: true)` 声明者 ⇒ 它接管本次按下（框架捕获它、把 down 交过去）。
            //   down 归属：`ownsPress` > `Draggable`（arena 裁决）> 框架激活。
            // 必须排在 `Draggable` 之前：`pointer()` 是"我自己处理指针"的最深声明，
            //   否则"可排序列表里放一个 Slider"会被祖先的 Draggable 抢走按下，Slider 点不动。
            // 焦点照旧落上去（键盘可达），只是不走 `activateFocusedWidget()`。
            // 捕获后的 move/up 经 `dispatchToCapture()` 直达同一回调，复用四条收口路径。
            $pointerOwner = $this->pointerOwner($element);
            if ($pointerOwner !== null) {
                $focus->focusHit($hit, $root);
                $this->capturePointer($pointerOwner);

                return $this->dispatchToCapture($pointerOwner, $event);
            }

            // 有 `Draggable` 声明者 ⇒ 开 arena，激活从 down 推迟到裁决（up 未过阈值 ⇒ Tap 胜 ⇒ 激活）。
            if ($this->draggableOwner($element) !== null) {
                $this->beginGestureArena($element, $hit, $root, $focus, $event->x, $event->y);

                return $this->cursorFor($hit, $event->x, $event->y);
            }

            // 无 `Draggable` 竞争者 ⇒ 焦点真落在本次点击上就立即激活（空点击 / 不可聚焦 ⇒ 不激活）。
            if ($focus->focusHit($hit, $root)) {
                $this->activateFocusedWidget();

                // 点进编辑中的输入框 ⇒ 按"第几次点击 / 有没有 Shift"落选区（`clickCount` 由壳标注）。
                $this->textEditing->beginTextSelection(
                    $event->x,
                    $event->y,
                    $event->clickCount,
                    $event->shift,
                );
            }

            return $this->cursorFor($hit, $event->x, $event->y);
        }

        if ($event->isMove()) {
            // 框选推进：down 在输入框上起的手势 ⇒ 延伸选区活动端。
            $this->textEditing->extendTextSelection($event->x, $event->y);

            // 手势竞技场推进：过阈值 ⇒ Drag claim（begin + 刷新悬停目标）
            $arena = $this->gestureArena;
            if ($arena !== null && !$arena->isClosed()) {
                $arena->move($event->x, $event->y);
            }

            // 已在拖动（arena 已裁决出 Drag，或宿主直起）⇒ 刷新悬停目标
            if ($this->drag !== null && $this->drag->isDragging()) {
                $this->updateDrag($event->x, $event->y);
            }

            $focus->hoverTo($element);

            return $this->cursorFor($hit, $event->x, $event->y);
        }

        if ($event->isUp()) {
            // 抬起即清按下态 —— 排在激活/结算之前：反馈的寿命是"按下期间"，
            // 与命令是否真的执行无关（命令失败也不该让按钮一直按着）。
            $focus->pressTo(null);

            // arena 未裁决 ⇒ 无人 claim ⇒ 第一个成员（Tap）胜 ⇒ 激活
            $arena = $this->gestureArena;
            if ($arena !== null && !$arena->isClosed()) {
                $arena->up($event->x, $event->y);
            }

            $this->gestureArena = null;

            if ($this->drag !== null && $this->drag->isDragging()) {
                $this->dropDrag();
            }

            $this->textEditing->finishSelection();   // 框选手势收口（选区本身留在字段里）

            return null;
        }

        // leave：无 SetCapture ⇒ 拖出窗外收不到后续事件，与"窗口失活"共用同一条收口路径。
        $this->cancelActiveGestures();

        return null;
    }

    /**
     * 收口所有进行中的指针手势（指针离开客户区 / 窗口失活）。
     * 五处必须一起清，漏一个就是"拖到一半不动"或"某控件永久只收它的事件"：
     * `$captureNode` · `$gestureArena` · `$drag` · thumb 拖动 · 框选手势。不关浮层（失焦 ≠ 放弃）；幂等。
     */
    public function cancelActiveGestures(): void
    {
        $this->releasePointer();
        $this->scrollInput->finishThumbDrag();
        $this->textEditing->finishSelection();

        if ($this->gestureArena !== null) {
            $this->gestureArena->leave();
            $this->gestureArena = null;
        }

        if ($this->drag !== null && $this->drag->isDragging()) {
            $this->cancelDrag();
        }

        // 悬停也是手势的一部分（指针已经不在这扇窗上）—— 树没了就不用清
        $this->host->focus()?->hoverTo(null);

        // 按下态同理：拖出窗外 / 窗口失活之后再回来，按钮不该还"按着"
        $this->host->focus()?->pressTo(null);
    }

    /**
     * 开手势竞技场。成员 = Tap + Drag，**Tap 必须排首位** —— arena 的默认胜者是 `members[0]`。
     */
    private function beginGestureArena(Element $owner, ?RenderBox $hit, Element $root, FocusManager $focus, float $x, float $y): void
    {
        $draggable = $owner->widget()->draggableValue();

        if ($draggable === null) {
            return;
        }

        $arena = new GestureArena($x, $y);

        // Tap：up 无人 claim ⇒ 胜 ⇒ 焦点落到 down 命中处则激活
        $arena->add(GestureRecognizer::tap(function (float $tx, float $ty) use ($focus, $hit, $root): void {
            if ($focus->focusHit($hit, $root)) {
                $this->activateFocusedWidget();
            }
        }));

        // Drag：move 过阈值 ⇒ claim ⇒ 开始拖动（源 = owner）并刷新悬停目标
        $arena->add(GestureRecognizer::drag(
            GestureArena::DRAG_THRESHOLD_PX,
            function (float $cx, float $cy) use ($draggable, $owner): void {
                $this->drag()->begin($draggable, $owner);
                $this->updateDrag($cx, $cy);
            }
        ));

        $this->gestureArena = $arena;
    }

    /** 沿父链找最近的 `Draggable` 声明者（子声明覆盖祖先） */
    private function draggableOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            if ($node->widget()->draggableValue() !== null) {
                return $node;
            }
        }

        return null;
    }

    /**
     * 沿父链找最近的、**声明"按下归我"**的 `pointer()` 声明者。
     * 只看 `ownsPress`，不看有没有 handler：`ownsPress: false` 的中间层只是"想在捕获期收事件"，
     * 不该拦住祖先的接管声明。找到的节点自己必然有 handler，`dispatchToCapture()` 第一跳就命中它。
     */
    private function pointerOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            $widget = $node->widget();

            if ($widget->pointerOwnsPressValue()) {
                return $node;
            }

            // 更近的"自己处理按下"的控件（可聚焦 / `Activatable`）⇒ 让位并返回 null。
            // 否则行级 `ownsPress` 会抢走 cell 里按钮的按下 —— 表格里放按钮点不动，且不报错。
            if ($widget->isFocusable() || $widget instanceof Activatable) {
                return null;
            }
        }

        return null;
    }

    /**
     * 沿父链找最近的、**声明"窗口拖动"**的控件。
     * 窗口拖动是兜底语义：遇到"自己处理按下"的控件就让位（返回 null），不是"找到更近的就换人" ——
     * 否则 AppBar 上的按钮会被祖先抢走按下。让位判据缺一不可：`isFocusable()` / `Activatable` / `pointer()` 声明。
     */
    private function windowDragOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            $widget = $node->widget();

            if ($widget->isFocusable()
                || $widget instanceof Activatable
                || $widget->pointerHandlerValue() !== null) {
                return null;
            }

            if ($widget->windowDragHandlerValue() !== null) {
                return $node;
            }
        }

        return null;
    }

    /**
     * 调一次窗口拖动闭包。
     * 不捕获指针：Win32 的 `WM_NCLBUTTONDOWN` 自己 SetCapture 并进入模态移动循环，框架再捕获 ⇒
     * 拖动结束后 `$captureNode` 还钉在这个控件上，之后每个指针事件都发给它。
     * 异常 ⇒ 报错但不吞泵：拖窗失败最多是"这次没拖动"，不该让窗口冻住。
     */
    private function beginWindowDrag(Element $owner): void
    {
        $handler = $owner->widget()->windowDragHandlerValue();

        if ($handler === null) {
            return;
        }

        try {
            $handler();
        } catch (\Throwable $e) {
            Errors::report(sprintf(
                '窗口拖动：回调未捕获异常 —— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
    }

    /**
     * 上下文菜单入口：沿起点 Element 的父链找最近的声明者。
     * 两个触发源共用它 —— 指针右键（起点 = 最深命中）与键盘（起点 = 焦点元素，
     * `Shift+F10` / `Apps`）。找不到 ⇒ 什么也不做（右键不是"选中"，不报错也不落给焦点）。
     */
    private function openContextMenu(?Element $hit): void
    {
        for ($element = $hit; $element !== null; $element = $element->parent()) {
            $menu = $element->widget()->contextMenuValue();

            if ($menu !== null) {
                $this->overlayHost->showMenu($menu->panel(), $element);

                return;
            }
        }
    }

    /** 焦点控件的 Enter / Space ⇒ 激活；指针路径同样以 `activateFocusedWidget()` 为终点。 */
    private function activateFocused(KeyEvent $event): void
    {
        if (!$event->chord->is(KeyChord::enter()) && !$event->chord->is(KeyChord::make('Space'))) {
            return;
        }

        $this->activateFocusedWidget();
    }

    /**
     * 激活当前焦点控件 —— 键盘（Enter/Space）与指针（按下）两路径的**唯一**入口。
     * 激活的语义归控件自己（`Activatable`）：本方法只做"声明式浮层优先"与打包上下文，
     * 零具体控件类认知。
     */
    private function activateFocusedWidget(): void
    {
        $element = $this->host->focus()?->focused();
        $widget = $element?->widget();

        // 声明式浮层（`overlayPanel()`）优先：激活它 = 开/关那个面板，不走 `onPressed` / 命令。
        // 按"能力"而非"类型"判 —— 唯一没有 `instanceof` 的分支。
        $factory = $widget?->overlayPanelValue();
        if ($factory !== null && $element !== null) {
            $this->overlayHost->toggleWidgetPanel($element, $factory);

            return;
        }

        // 能力接口：`Menu` 覆写 `activate()` 即生效，多态天然优先，顺序纪律消失。
        if (!$widget instanceof Activatable) {
            return;
        }

        $widget->activate(new ActivationContext(
            $element,
            function (string $command, CommandArg $arg): void {
                $this->host->dispatch($command, $arg);
            },
        ));
    }

    /**
     * 命中的盒是否落在**模态面板**里（而非遮罩上）。
     * 判据是"父链上有一个自称面板的盒"（`panelRect() !== null`）—— 遮罩上的命中停在
     * `Center` / `ModalHost`。面板盒含 `elevation` 投影留白 ⇒ 那几像素也算"面板内"。
     */
    private function hitsModalPanel(?RenderObject $hit): bool
    {
        for ($node = $hit; $node !== null; $node = $node->parent()) {
            if ($node->panelRect() !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * 把一条指针事件交给捕获者。
     * 坐标不换算：回调拿到客户区坐标（与 `PointerEvent` 同源），要局部坐标自己减 `absoluteOrigin()`。
     * 回调抛异常 ⇒ 报错并释放捕获，否则之后每个指针事件都炸一次且树里没人能收（静默冻住）。
     */
    private function dispatchToCapture(Element $node, PointerEvent $event): ?CursorShape
    {
        $handler = null;
        for ($n = $node; $n !== null; $n = $n->parent()) {
            $candidate = $n->widget()->pointerHandlerValue();
            if ($candidate !== null) {
                $handler = $candidate;

                break;
            }
        }

        if ($handler === null) {
            return null;   // 捕获者没声明回调：合法（捕获只用于"吃掉事件"）
        }

        try {
            $result = $handler($event, $node);
        } catch (\Throwable $e) {
            Errors::report(sprintf(
                '指针捕获：回调未捕获异常 —— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
            $this->releasePointer();

            return null;
        }

        return $result instanceof CursorShape ? $result : null;
    }

    /**
     * 光标解析的唯一入口：先问命中的盒（`cursorAt`），它不表态才回落到 `Widget::cursor()` 父链。
     * 局部坐标 = 客户区 − `absoluteOrigin()`，与 `hitTest()` 同口径。
     */
    private function cursorFor(?RenderBox $hit, float $x, float $y): CursorShape
    {
        if ($hit !== null) {
            [$ox, $oy] = RenderBox::absoluteOrigin($hit);
            $shape = $hit->cursorAt($x - $ox, $y - $oy);
            if ($shape !== null) {
                return $shape;
            }
        }

        return $this->host->focus()?->cursorOf($hit) ?? CursorShape::Default;
    }

    /**
     * 捕获指针：从这个节点起的指针事件**只发给它**，不再命中测试。
     * 没有引用计数，后一次覆盖前一次（平台单指针）；释放有四条路，少一条就是死节点收事件。
     * @api 壳在 `Application::capturePointer()`（控件经 `application()` 调它）。
     */
    public function capturePointer(Element $node): void
    {
        $this->captureNode = $node;
    }

    /**
     * 释放指针捕获（幂等；没捕获 ⇒ 无事发生）。
     * @api 壳在 `Application::releasePointer()`。
     */
    public function releasePointer(): void
    {
        $this->captureNode = null;
    }

    /** 当前捕获者（测试 / devtools；@internal 壳在 `Application::captureNode()`） */
    public function captureNode(): ?Element
    {
        return $this->captureNode;
    }

    // ── 应用内拖放：声明住 Widget，状态机住这里 ──────────────────────

    /**
     * 拖动状态机。`down` 在 `Draggable` 上记阈值起点（按下 ≠ 拖动），`move` 过阈值才 `begin`。
     */
    public function drag(): DragController
    {
        return $this->drag ??= new DragController();
    }

    /** 开始拖动（源 = 最近命中的 Element；命中处须有 `Draggable` 声明） */
    public function beginDrag(Draggable $draggable): bool
    {
        $this->drag()->begin($draggable, $this->host->focus()?->lastHit());

        return true;
    }

    /** 指针移动：刷新悬停目标（**目标变化才通知** `onHover`） */
    public function updateDrag(float $x, float $y): void
    {
        $root = $this->host->root();
        $focus = $this->host->focus();
        $drag = $this->drag;

        if ($root === null || $focus === null || $drag === null || !$drag->isDragging()) {
            return;
        }

        $hit = $focus->hitTest($x, $y, $root);

        $drag->hoverTo($hit === null ? null : $focus->elementAt($root, $hit));
    }

    /**
     * 放下：命中 `DragTarget` ⇒ 交出负载。
     *
     * @return bool 是否被接收
     */
    public function dropDrag(): bool
    {
        return $this->drag?->drop() ?? false;
    }

    /** 取消拖动（ESC 也会 cancel —— 见 `dispatch()` 的 ESC 分支） */
    public function cancelDrag(): void
    {
        $this->drag?->cancel();
    }
}
