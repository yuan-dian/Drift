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
 * **输入路由主体**（S1 第 6 步）：§9.1 路由链的键 / 指针 / 滚轮三侧 + 手势状态。
 *
 * ```text
 * InputEvent ──▶ dispatch() ──┬─ KeyEvent    ：编辑命令 → 加速键 → 焦点链 → 激活
 *                             ├─ PointerEvent：dispatchPointer() 四相位
 *                             │                （浮层优先 → 捕获 → thumb → 命中）
 *                             ├─ ScrollEvent ：浮层优先 → scrollAt（最近 Scrollable）
 *                             └─ FilesDropped：浮层优先 → 命中 → 父链冒泡 onFilesDropped
 * 手势状态：gestureArena（Tap/Drag 消歧）· drag（拖放）· captureNode（指针捕获）
 * ```
 *
 * ★ **为什么从 Application 拆出来**：这是 Application 里最大的一块（≈800 行），
 *   且它是"喂进来一条事件、决定谁吃"的完整决策链 —— 键的让位规则、指针四相位、
 *   浮层优先、捕获、手势消歧本来就该住在一起。拆完后 Application 只剩壳 +
 *   记账 + 主题分发（后者是帧循环职责，不是输入路由）。
 *
 * ★ **与另三片的分工**：`TextEditingBridge`（编辑命令 / 框选）、
 *   `ScrollInputRouter`（滚轮 / thumb）与 `OverlayManager`（浮层栈 + 开合策略，
 *   S1 第 8 步）经构造参数收进来做片间组合 —— 不经 host（S1 第 5 步立的口径：
 *   host 只摸"应用状态"，片间调用直连）。应用状态（焦点 / 根 / 命令派发 /
 *   加速键）走 `InputHost` 的 4 个口子，全部是"现读"式 getter 或转发，
 *   片不持应用值的副本。
 *
 * @internal S1 的拆分片，同 `FocusManager` —— 不是应用契约
 */
final class InputRouter
{
    /**
     * 滚轮一格滚多少逻辑像素（2026-10-17："不跟手"的根因修复）。
     *
     * ★ 分工：平台只做**量化**（`÷120` 把 WHEEL_DELTA 收成"格"，§6.1），
     *   "一格值多少 px" 是**策略**，归框架。48 = 3 行 × 16px 行高，与
     *   Windows 默认 `SPI_GETWHEELSCROLLINES=3`、浏览器每格 ~40–53px 同量级。
     *   ★ 修复前是 1px/格 ⇒ "滑不动/不跟手"（一格 notch 只挪一个像素）。
     * ★ 桥未暴露 `SPI_GETWHEELSCROLLINES` ⇒ 这里是常量而非系统值（缺口已登记）。
     */
    private const WHEEL_NOTCH_PX = 48.0;

    /**
     * 进行中的手势竞技场（§9.1 手势消歧，2026-10-18 落地）。
     *
     * ★ **仅当命中链上有 `Draggable` 声明者时开**：`down` 记 arena（成员 =
     *   Tap + Drag），激活从 down 推迟到裁决 —— `up` 未过阈值 ⇒ Tap 胜 ⇒ 激活；
     *   `move` 过阈值 ⇒ Drag claim ⇒ Tap 让位（修"拖可拖控件时命令在 down 先响"）。
     *   无 `Draggable` 声明者的控件维持"down 即激活"（A15 不动，防回归）。
     * ★ 拖动阈值 `DRAG_THRESHOLD_PX` 归 `GestureArena`（识别器自带，单一出处）。
     */
    private ?GestureArena $gestureArena = null;

    /** 拖动状态机（§A.3.5）；懒建 —— 不拖的应用零成本 */
    private ?DragController $drag = null;

    /**
     * 捕获指针的节点（`06` §5，W3-L5-6）：非空 ⇒ 指针事件**只发给它**，
     * 不再命中测试。
     *
     * ★ **捕获的全部意义就是"不命中测试"**：拖到控件外面仍然归它 —— 否则
     *   "拖出边界就断"这类 bug 每个控件都要自己防一遍（现在已经有
     *   `DragController` 和 thumb 拖动两套手写版本，第三套必然走样）。
     * ★ 四条释放路径（少一条就是"事件永远发给死节点"）：
     *   ① 指针抬起（UP / CANCEL）② 捕获者卸载（`frame()` 里清扫 + DIAGNOSTIC）
     *   ③ 窗口失焦 ④ 浮层外的 `down`（浮层优先于捕获，见 `dispatchPointer`）。
     */
    private ?Element $captureNode = null;

    /**
     * @param ScrollInputRouter $scrollInput 滚轮 / thumb 片（片间组合，不经 host）
     * @param TextEditingBridge $textEditing 文本编辑片（同上）
     */
    public function __construct(
        private InputHost $host,
        private ScrollInputRouter $scrollInput,
        private TextEditingBridge $textEditing,
        private OverlayManager $overlayHost,
    ) {
    }

    /**
     * 喂一条平台事件（§9.1 路由链的入口；@api 壳在 `Application::dispatchInput()`）。
     *
     * ★ 记账（`lastInputMs` / `keyboardFocusVisible`）、失活收口、主题分发与
     *   "未挂载"守卫都住壳里（应用侧职责）—— 本方法拿到的是**已挂载**的
     *   root/focus，且失活事件已在壳里收口过。
     */
    public function dispatch(InputEvent $event, Element $root, FocusManager $focus): ?CursorShape
    {
        if ($event instanceof KeyEvent) {
            // §9.1 关闭语义 #3：**ESC 在 `dispatchKey` 之前拦** —— 否则被焦点
            // 控件（输入框）吃掉，菜单关不掉。
            if ($event->chord->is(KeyChord::escape())) {
                // 浮层栈：栈顶条目决定 Esc 语义（arch-v2 `01` §6.3）
                // ★ `dismissOnEsc=false` ⇒ **吞掉但不关**。"拒绝"不等于"放行" ——
                //   若把 ESC 交回加速键链，Ctrl+W 之类能绕过"必须处理的对话框"。
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

                // ESC 同样取消文本框选手势（选区折叠归焦点控件的 Esc 分支，
                // 见 `EditableText::handleKey()`）
                $this->textEditing->finishSelection();
            }

            // §9.1 第 ① 环**之前**：编辑命令（Ctrl+C / X / V，§9.1 让位规则 ②
            // 的"编辑命令优先"）。返回 false = 这一环没吃 ⇒ 原样落给加速键。
            if ($this->textEditing->dispatchEditCommand($event)) {
                return null;
            }

            // §9.1 第 ① 环：加速键（在焦点之前）。
            // 让位规则 ②：焦点控件声明了独占该组合 ⇒ 不吃快捷键，往下走焦点链。
            $accel = $this->host->accelerators()->resolve($event->chord);
            if ($accel !== null && !$focus->claims($event->chord)) {
                $this->host->dispatch($accel);

                return null;
            }

            // ★ 焦点链自己的键（Tab / Shift+Tab）会**换焦点**：换完要把新焦点滚进
            //   它所在的视口，否则焦点环画在视口外、用户看不到焦点在哪（`04` §6.3）。
            //   判据是"焦点 Element 变了"而不是"按的是 Tab" —— 焦点控件也可能吃掉
            //   别的键，那时焦点没动，不该滚（滚一下就是用户没要求的位移）。
            $focusedBefore = $focus->focused();
            if ($focus->dispatchKey($event)) {
                if ($focus->focused() !== $focusedBefore) {
                    $this->scrollInput->revealFocused();
                }

                return null;
            }

            // §9.1：焦点按钮"回车/空格能触发"—— 动作缝的键盘侧激活
            $this->activateFocused($event);

            return null;
        }

        if ($event instanceof PointerEvent) {
            return $this->dispatchPointer($event, $root, $focus);
        }

        if ($event instanceof ScrollEvent) {
            // 滚轮（2026-10-17）：命中 → 沿命中链找最近的 ScrollViewState → scrollBy。
            //
            // ★ 与指针分支同构的三段：**浮层开着 ⇒ 先归浮层**（长菜单自己滚），
            //   轮在浮层外 ⇒ 不穿透滚主树（同语义 #1：否则"关菜单"翻掉整页）。
            //   ★ 命中一次、就滚那一个：指针停在哪个视口里就滚哪个 —— 多个滚动
            //   容器各自滚动，外层的视口不吞内层的事件（scrollAt 只找最近的）。
            // ★ **格 → 像素只在这里发生一次**（策略层）：`$event->delta` 是平台
            //   量化出的"格"（WHEEL_DELTA/120），`scrollByAxis()` 起收的是逻辑像素。
            // ★ **轴由事件带来**（2026-10-27 阶段 5b）：壳把 `WM_MOUSEHWHEEL` 与
            //   Shift+竖轮都翻成 `Axis::Horizontal`，路由不再判修饰键 ——
            //   "平台惯例 → 框架语义"只发生在壳里一处。
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
     * 系统拖放派发（§7 A5-②，§27）：命中 → 沿父链冒泡找最近的 `onFilesDropped`
     * 声明者 → 调它的闭包。
     *
     * ★ 与 `dispatchPointer` / ScrollEvent 分支同构：浮层开着先归浮层，`barrier`
     *   ⇒ 不穿透主树；否则落主树命中。
     * ★ 命中一次、只投一个：一次拖放只给"落点那条链上最近的声明者"，不广播
     *   （广播没有消费者，且"谁处理了"无法判定）。
     * ★ 找不到声明者 ⇒ 静默丢弃：拖到不接收文件的地方是合法的（同
     *   `openContextMenu()` 的"右键落在没菜单的地方"），不报错也不落给焦点。
     * ★ 坐标先 `(float)` 落局部再参与运算 —— `$event->x` 跨实例读到的是标量 /
     *   Var，直接拿去减浮层偏移会在 AOT 侧踩 C2679（§24 纪律③）。
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
                // ★ 用 RECOVERABLE 而不是默认 FATAL：`Errors::abort()` 末尾是 `exit()`
                //   （§1.2 中止序列），一次回调异常就让整个进程退出，与"兜住异常、
                //   不吞消息泵"正好相反。RECOVERABLE 在 release 下只 appendLog +
                //   return；在 debug 下仍会中止（同 `Subject::notifyChanged()` 对
                //   订阅者异常的隔离口径 —— 那也是"一个回调炸了，其余继续"）。
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
     * 指针事件派发（**四相位**，2026-10-17 move/up/leave 通路落地）。
     *
     * - **down**：三段（浮层优先 / 右键菜单 / 焦点激活 —— M3 修复的行为不动）
     *   + 拖放阈值起点（落在 `Draggable` 上记 pending，**不开始拖动**）
     * - **move**：光标刷新 + 悬停派发（目标变化才通知）+ 拖放阈值推进
     * - **up**：拖放放下（激活仍住 down —— 不做 press/release 视觉，A15）
     * - **leave**：悬停全退出 + 拖放取消（无 `SetCapture` 的诚实边界：
     *   拖出窗口即取消，**不卡死**）
     *
     * ★ **浮层开着时四相位都先归浮层**（与 down 同一条"不穿透"纪律）：
     *   命中浮层 ⇒ 菜单项悬停 / 光标；miss 浮层 ⇒ move/leave 只退悬停、
     *   **不关浮层**（关只归 down —— §9.1 语义 #1）。
     */
    private function dispatchPointer(PointerEvent $event, Element $root, FocusManager $focus): ?CursorShape
    {
        // ── 浮层栈：**从顶往下**逐层问（arch-v2 `01` §6.1）─────────────
        for ($i = $this->overlayHost->overlays()->count() - 1; $i >= 0; $i--) {
            $e = $this->overlayHost->overlays()->at($i);
            if ($e === null || $e->element === null) {
                continue;
            }

            // ★ 不参与命中的层（Tooltip）：整层跳过 —— 既不算命中，也不算"点在层外"，
            //   连 `barrier` 判断都跳过（`hitTest=false` 的语义就是"指针看不见我"）。
            //   没有这一句，tooltip 一出就把锚点的悬停目标抢走 ⇒ 显示/隐藏死循环。
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
                    // 浮层接管这次按下 ⇒ 捕获必须让位（`06` §10「浮层优先于捕获」：
                    // 用户主动点浮层，那次 down 不该被拖拽者吃掉）
                    $this->releasePointer();

                    // 菜单项（MenuItem extends Button）与根树按钮同规则：焦点落在
                    // 浮层内本次点击上 ⇒ 立即激活。命令经 dispatch() 分发 ⇒ 浮层按
                    // 语义 #2 在分发后关闭 —— 与键盘 Enter 完全同路。
                    if ($focus->focusHit($hit, $e->element)) {
                        $this->activateFocusedWidget();
                    }
                } elseif ($event->isMove()) {
                    // 浮层内悬停：菜单项高亮（§9.1 语义 #5）
                    $focus->hoverTo($focus->elementAt($e->element, $hit));
                } elseif ($event->isLeave()) {
                    $focus->hoverTo(null);
                }

                return $event->isDown() || $event->isMove() ? $this->cursorFor($hit, $event->x, $event->y) : null;
            }

            // 点在条目盒外
            if ($e->barrier) {
                if ($event->isDown()) {
                    $this->releasePointer();   // 浮层优先于捕获（`06` §10）

                    // §9.1 关闭语义 #1：点浮层外 ⇒ 关且**吞掉该次按下**（不穿透）
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

        // ── 指针捕获（`06` §5，W3-L5-6）：捕获期**不命中测试** ───────────
        // ★ 放在浮层段**之后**：浮层优先于捕获（`06` §10 裁决）—— 用户主动点浮层
        //   时那次 down 先归浮层；但 `barrier=false` 的浮层（Toast / Tooltip）
        //   在上面时照样穿透到捕获者，拖到一半弹个 Toast 不该断拖。
        $capture = $this->captureNode;
        if ($capture !== null) {
            $cursor = $this->dispatchToCapture($capture, $event);

            if ($event->isUp() || $event->isLeave()) {
                $this->releasePointer();   // 释放路径 ①：指针抬起 / 离开窗口
            }

            return $cursor;
        }

        // ── 滚动条 thumb 拖动（§12.3，2026-10-19）─────────────────────
        // thumb 是 `paint()` 画的胶囊，**不是子盒** ⇒ 命中测试看不见它，
        // 所以必须先于内容接管：down 落在 thumb 上就吃掉本次手势（不喂
        // focusHit、不派发给内容），move 按位移映射成滚动量，up/leave 结束。
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
            // ★ 右键最小集（§9.1）：非主键 ⇒ 找最近的 `ContextMenu` 声明者并打开。
            if (!$event->isPrimary()) {
                $this->openContextMenu($element);

                return null;
            }

            // ★ 禁用的命中：**吞掉，什么都不做**（2026-10-29）。排在 `pointer()`
            //   与 `Draggable` 之前，因为它们在 `focusHit()` **之前**接管按下 ——
            //   而 `focusHit()` 会自己拦住禁用元素（它不在可聚焦表里），所以只有
            //   这两条路可能绕过禁用。返回 `null` = 这次按下不产生任何路由
            //   （不激活、不捕获、不开 arena、不改光标）。
            //   ★ 只拦主键：右键菜单是"区域属于谁"的问题，禁用的控件不该连祖先的
            //     菜单一起吃掉（上面那条分支已经先行返回）。
            if ($hit !== null && $hit->isDisabled()) {
                return null;
            }

            // ★ 窗口拖动（P1-Stage 1b 无边框窗）：命中链上有 `windowDrag()`
            //   声明者、且**途中没有"自己处理按下"的控件** ⇒ 这次按下是抓标题栏。
            //   ★ 排在 `pointerOwner` **之前**：它比"接管按下"更早 —— 拖窗时这次
            //     按下根本不进控件树（不捕获、不改焦点、不激活）。`WM_NCHITTEST`
            //     返 `HTCAPTION` 做不到这一点：壳看不见控件树，AppBar 上的按钮会
            //     连点击一起被拖走。
            $windowDrag = $this->windowDragOwner($element);
            if ($windowDrag !== null) {
                $this->beginWindowDrag($windowDrag);

                return null;
            }

            // ★ W8-2（Slider 立的口子）：命中链上有**声明"按下归我"**的
            //   `pointer()`（`pointer($h, ownsPress: true)`）⇒ 它接管本次按下。
            //
            //   与下面两条路的分工：
            //   | 声明 | down 归谁 |
            //   |---|---|
            //   | `ownsPress: true` | **本控件**（框架捕获它 + 把 down 交过去）|
            //   | `Draggable` | GestureArena 裁决（过阈值才算拖）|
            //   | 都没有 | 框架激活（`activateFocusedWidget()`）|
            //
            //   ★ **必须排在 `Draggable` 之前**：`pointer()` 是"我自己处理指针"的
            //     最深声明，比祖先的 `Draggable` 更具体（同 `draggableOwner` 的
            //     "子声明覆盖祖先"）。否则"可排序列表里放一个 Slider"会被祖先的
            //     Draggable 抢走按下，Slider 点不动。
            //   ★ 焦点**照旧落上去**（键盘可达），只是不走 `activateFocusedWidget()`
            //     —— 位置即语义的控件，激活的含义由它自己定义。
            //   ★ 捕获后 move/up 经 `dispatchToCapture()` 直达同一个回调
            //     ⇒ **不引入第六个手势状态**：`$captureNode` 的四条收口路径
            //     （抬起 / 卸载 / 失焦 / 浮层外按下）全部现成。
            $pointerOwner = $this->pointerOwner($element);
            if ($pointerOwner !== null) {
                $focus->focusHit($hit, $root);
                $this->capturePointer($pointerOwner);

                return $this->dispatchToCapture($pointerOwner, $event);
            }

            // §9.1 手势消歧：命中链上有 `Draggable` 声明者 ⇒ 开 GestureArena，
            // **激活从 down 推迟到 arena 裁决**（up 未过阈值 ⇒ Tap 胜 ⇒ 激活；
            // move 过阈值 ⇒ Drag claim ⇒ Tap 让位）—— 修"拖可拖控件时命令在 down
            // 先响过"（原注释自承"激活已在 down 发生过"，§9.1"拖动中的 tap 让位"）。
            if ($this->draggableOwner($element) !== null) {
                $this->beginGestureArena($element, $hit, $root, $focus, $event->x, $event->y);

                return $this->cursorFor($hit, $event->x, $event->y);
            }

            // 无 `Draggable` 竞争者 ⇒ 维持 M3 行为：焦点真落在本次点击上 ⇒ 立即
            // 激活（与键盘 Enter/Space 同入口 activateFocusedWidget）。空点击 /
            // 不可聚焦命中 ⇒ focusHit 返回 false，不激活。按下即激活，不做
            // press/release 视觉（A15）。
            if ($focus->focusHit($hit, $root)) {
                $this->activateFocusedWidget();

                // 点进**编辑中**的输入框 ⇒ 插入符落到指针处 + 起框选手势
                // （§A.2.9 的指针侧：与 Shift+方向键同一个选区模型）
                $this->textEditing->beginTextSelection($event->x);
            }

            return $this->cursorFor($hit, $event->x, $event->y);
        }

        if ($event->isMove()) {
            // 框选推进：down 在输入框上起的手势 ⇒ 延伸选区活动端
            // （无手势时片内直接返回，调用方不必先问）
            $this->textEditing->extendTextSelection($event->x);

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

        // leave：指针离开客户区（无 SetCapture ⇒ 拖出窗外收不到后续 move/up）
        // ⇒ 与"窗口失活"共用同一条收口路径。
        $this->cancelActiveGestures();

        return null;
    }

    /**
     * 收口**所有**进行中的指针手势（指针离开客户区 / 窗口失活）。
     *
     * ★ 五处状态必须一起清 —— 漏一个的现象都是"拖到一半不动了"或"回来之后某个
     *   控件永久只收它的事件"：
     *   | 状态 | 漏了会怎样 |
     *   |---|---|
     *   | `$captureNode` | 指针事件永远只发给那个节点，别的控件点不动 |
     *   | `$gestureArena` | Tap/Drag 卡在未裁决，下次 down 覆盖前一直挂着 |
     *   | `$drag` | 拖动中：悬停目标永远停在上一个、`drop` 永远不来 |
     *   | thumb 拖动 | `ScrollInputRouter::finishThumbDrag()` 漏了 ⇒ 拇指跟着
     *     指针跑（哪怕没按键） |
     *   | 框选手势 | `TextEditingBridge::finishSelection()` 漏了 ⇒ 没松手，
     *     之后每次 `move` 都在延伸选区 |
     *
     * ★ **不关浮层**（W3 裁决：失焦 ≠ 放弃，`06` §10「关闭四来源」里没有这一条）。
     *
     * ★ 幂等：没有进行中的手势 ⇒ 无事发生。
     *
     * 由 `dispatchPointer()` 的 leave 分支与壳（`Application::dispatchInput()`
     * 的失活分支转调本片）调。
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
    }

    /**
     * down 命中链有 `Draggable` 声明者 ⇒ 开 `GestureArena`（§9.1 手势消歧）。
     *
     * 成员 = Tap（`up` 未过阈值 ⇒ 胜 ⇒ 激活 down 落焦点的控件）+ Drag（`move`
     * 过阈值 ⇒ claim ⇒ 开始拖动，源 = 声明者 owner）。**Tap 必须排在首位** ——
     * arena 的默认胜者是 `members[0]`。
     *
     * 由 `dispatchPointer()` 的 down 分支调。
     */
    private function beginGestureArena(Element $owner, ?RenderBox $hit, Element $root, FocusManager $focus, float $x, float $y): void
    {
        $draggable = $owner->widget()->draggableValue();

        if ($draggable === null) {
            return;
        }

        $arena = new GestureArena($x, $y);

        // Tap：up 无人 claim ⇒ 胜 ⇒ 焦点落到 down 命中处则激活（同 down 分支原语义）
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

    /** 沿父链找最近的 `Draggable` 声明者（§A.3.5：子声明覆盖祖先） */
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
     * 沿父链找最近的、**声明"按下归我"**的 `pointer()` 声明者（W8-2）。
     *
     * ★ 只看 `ownsPress`，不看"有没有 handler"：`ownsPress: false` 的中间层
     *   （如 `OverlayLab` 的锚点按钮）只是"想在捕获期收事件"，不该拦住祖先的接管声明。
     * ★ 找到的那个节点自己必然有 handler（`pointer()` 一次设两个字段），
     *   所以随后的 `dispatchToCapture()` 第一跳就命中它。
     */
    private function pointerOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            $widget = $node->widget();

            if ($widget->pointerOwnsPressValue()) {
                return $node;
            }

            // ★★ 更近的"自己处理按下"的控件 ⇒ **让位**（2026-10-26，`Table` 行点击立的规矩）。
            //   行级 `pointer(ownsPress: true)` 让"整行可点"成立，但它会顺手抢走
            //   cell 里按钮的按下（行在父链上更近地声明了 ownsPress）⇒ 表格里放按钮
            //   点不动，且**不报错**。
            //   判据与 `windowDragOwner()` 同构：可聚焦 / `Activatable` 的控件本来就
            //   有"按下 ⇒ 自己激活"的语义（走 `focusHit` + `activateFocusedWidget()`），
            //   祖先没有资格替它决定。命中点**在**这种东西上面时直接交回原路。
            if ($widget->isFocusable() || $widget instanceof Activatable) {
                return null;
            }
        }

        return null;
    }

    /**
     * 沿父链找最近的、**声明"窗口拖动"**的控件（P1-Stage 1b 无边框窗）。
     *
     * ★ 与 `pointerOwner()` 的关键差别：这里**遇到"自己处理按下"的控件就让位**
     *   （返回 `null`），不是"找到更近的就换人"。理由 —— 窗口拖动是**兜底**语义：
     *   只有当这次按下谁也不要（点在 AppBar 的空白处 / 标题文字上）才拖窗。
     *   若照 `pointerOwner()` 那样"往上继续找"，AppBar 上的按钮会被祖先抢走按下。
     * ★ 让位判据三条，缺一条都会静默吞掉点击：
     *   | 判据 | 漏了会怎样 |
     *   |---|---|
     *   | `isFocusable()` | 点 AppBar 里的搜索框 ⇒ 拖窗（输入框拿不到焦点）|
     *   | `Activatable` | 点 AppBar 上的按钮 ⇒ 拖窗（命令不响）|
     *   | `pointer()` 声明 | Slider / 自绘控件 ⇒ 拖窗（拖不动控件）|
     * ★ 命中**标题文字**仍算抓标题栏（`Text` 三条都不满足）—— 这是对的：
     *   真实窗口的标题栏上按下去就是拖窗。
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
     * 调一次窗口拖动闭包（组合根递进来的 `fn () => $window->beginDrag($handle)`）。
     *
     * ★ **不捕获指针**：Win32 的 `WM_NCLBUTTONDOWN` 自己 `SetCapture` 并进入模态
     *   移动循环（阻塞到用户松手）。框架再捕获一次 ⇒ 拖动结束后 `$captureNode`
     *   还钉在这个控件上，之后每个指针事件都发给它（别的控件点不动）。
     * ★ 异常 ⇒ 报错但不吞掉整个泵：拖窗失败最多是"这次没拖动"，不该让窗口冻住。
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
     * 右键入口（§9.1"右键最小集"）：沿命中 Element 的父链找**最近**的声明者。
     *
     * ★ 找不到 ⇒ 什么也不做（右键落在没有上下文菜单的地方是合法的，不报错、
     *   也不落给焦点 —— 右键不是"选中"）。
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

    /**
     * 焦点控件的 Enter / Space ⇒ 激活（§9.1 路由链第 ③ 环的激活语义）。
     *
     * 键盘 Enter/Space 激活路径（指针路径走 dispatchPointer() 的 down 分支，
     * 同终点 activateFocusedWidget()，2026-10-17 指针四相位已落地 §24.2 #16）。
     *
     * 由 `dispatch()` 的键盘分支调。
     */
    private function activateFocused(KeyEvent $event): void
    {
        if (!$event->chord->is(KeyChord::enter()) && !$event->chord->is(KeyChord::make('Space'))) {
            return;
        }

        $this->activateFocusedWidget();
    }

    /**
     * 激活当前焦点控件 —— 键盘（Enter/Space）与指针（按下）两路径的**唯一**入口。
     *
     * ★★ **激活的语义归控件自己**（`Activatable`，S1 第 10 步 4j-b）：本方法只做两件
     *   框架该做的事 —— ① 声明式浮层优先（`overlayPanelValue()`，比"按一下"更具体）；
     *   ② 把上下文打包交给控件。**零具体控件类认知**。
     *
     * ★ 搬迁前这里是一条 `instanceof` 塔（`Menu` / `ToggleBase` / `Segment` / `Button`），
     *   且 `Menu extends Button` 逼出一条顺序纪律（"子类分支必须排在父类前"）。
     *   改成虚方法之后：`Menu` 覆写 `activate()` 即生效，顺序问题**不存在**了。
     */
    private function activateFocusedWidget(): void
    {
        $element = $this->host->focus()?->focused();
        $widget = $element?->widget();

        // ★★ 声明式浮层（W8-2 Select）：**通用缝，排在最前**。
        //   任何控件声明了 `overlayPanel()` ⇒ 激活它 = 开/关那个面板，**不走**
        //   `onPressed` / 命令（"浮一个面板"比"按一下"更具体）。
        //   ★ 这是**唯一**一条按"能力"而不是按"类型"判的分支 —— 没有 `instanceof`，
        //     所以以后 DatePicker / ComboBox 不用再改这里一行。
        $factory = $widget?->overlayPanelValue();
        if ($factory !== null && $element !== null) {
            $this->overlayHost->toggleWidgetPanel($element, $factory);

            return;
        }

        // ★★ **能力接口**（S1 第 10 步 4j-b，2026-10-25）：激活的语义归控件自己。
        //   以前这里是一条 `instanceof` 塔（`Menu` / `ToggleBase` / `Segment` /
        //   `Button`），框架核心去认具体控件 —— 加一种可激活控件就要改这里，且
        //   `Menu extends Button` 逼出"子类分支必须排在父类前"的顺序纪律。
        //   改成 `Activatable` 之后：`Menu` 覆写即生效（多态天然优先），本方法
        //   **零具体控件类认知** —— 唯一的 `instanceof` 右侧是接口。
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
     *
     * ★ `Modal` 的 build 把面板包成 `ModalHost → Center → PopupPanel`，遮罩上的
     *   命中停在 `Center` / `ModalHost`（默认 `hitTest()`：子都不中 ⇒ 返回自己）
     *   ⇒ "父链上有一个**自称面板**的盒"（`panelRect() !== null`）就是"点在面板里"。
     *   用父链而不是"命中的是不是 Center"，是为了面板外面再包布局（比如加个底部
     *   按钮栏）也成立。
     * ★ 走能力方法而不是 `instanceof RenderPopupPanel`（S1 第 10 步 4j-c）：App 层
     *   不该认识 `Layout` 的具体盒类。判据的**含义没变** —— `RenderPopupPanel` 是
     *   目前唯一覆写 `panelRect()` 的盒。
     * ★ 边界：面板盒含 `elevation` 的投影留白 ⇒ 投影那 6 逻辑像素也算"面板内"
     *   （点它不关模态）。要精确到矩形内部得把坐标换算进盒坐标系，收益不值这个
     *   复杂度 —— 记在这里而不是假装没有。
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
     * 把一条指针事件交给捕获者（`06` §5.2）。
     *
     * ★ **坐标不换算**：回调拿到的是客户区坐标 —— 与 `PointerEvent` 同源，
     *   控件要局部坐标就自己减 `absoluteOrigin()`。在这里替它减会逼框架
     *   假设"捕获者一定有一个盒"（拖放 feedback 没有）。
     * ★ 回调抛异常 ⇒ 报错 + **释放捕获**：留着一个会抛的捕获者等于之后每个
     *   指针事件都炸一次，而且树里没有别的东西能收到事件（静默冻住）。
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
     * 光标解析的**唯一入口**：先问命中的盒"这个位置要什么"（`cursorAt`），
     * 它不表态才回落到 `Widget::cursor()` 的父链声明。
     *
     * ★ 盒级优先：位置比控件更具体（富文本的链接只在几个字上是手型，
     *   整段 `cursor()` 做不到）。
     * ★ 局部坐标 = 客户区 − `absoluteOrigin()`，与 `hitTest()` 同口径。
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
     * 捕获指针（`06` §5，W3-L5-6）：从这个节点起的指针事件**只发给它**，不再命中测试。
     *
     * ★ 捕获**没有引用计数**：后一次覆盖前一次。理由 —— 同时"按住"两个控件的
     *   唯一途径是两根手指，而当前平台是单指针（`PointerEvent` 没有 pointerId）。
     *   多指进来时这里要改成 `array<int, Element>`，届时判据是"事件带 id"。
     * ★ 释放有四条路（见 `$captureNode` 的注释），**少一条就是死节点收事件**。
     * ★ @api 壳在 `Application::capturePointer()`（控件经 `application()` 调它）。
     */
    public function capturePointer(Element $node): void
    {
        $this->captureNode = $node;
    }

    /**
     * 释放指针捕获（幂等；没捕获 ⇒ 无事发生）。
     *
     * ★ @api 壳在 `Application::releasePointer()`。
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

    // ── 应用内拖放（§A.3.5）：声明住 Widget，状态机住这里 ──────────────

    /**
     * 拖动状态机（§A.3.5）。
     *
     * ★ **真机指针序列已接线**（2026-10-17，§24.2 #16）：`down` 在 `Draggable`
     *   上记阈值起点（`beginPendingDrag`，**按下 ≠ 拖动**），`move` 过
     *   `DRAG_THRESHOLD` 才 `begin`，`up` 放下，`leave`/ESC 取消。宿主 / 测试
     *   经 Application 的四个壳入口直调（与 `Spinner::tick()` 同一个分期形态）。
     */
    public function drag(): DragController
    {
        return $this->drag ??= new DragController();
    }

    /**
     * 开始拖动（源 = 最近命中的 Element；命中处须有 `Draggable` 声明）
     */
    public function beginDrag(Draggable $draggable): bool
    {
        $this->drag()->begin($draggable, $this->host->focus()?->lastHit());

        return true;
    }

    /**
     * 指针移动：刷新悬停目标（**目标变化才通知** `onHover`）
     */
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
