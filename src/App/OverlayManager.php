<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\Modal;
use Drift\Widget\Snackbar;
use Drift\Widget\SnackbarPanel;
use Drift\Widget\TooltipPanel;
use Drift\Widget\Widget;

/**
 * **浮层栈的 Owner**（S1 第 8 步）：`OverlayStack` 的所有权 + 开合策略 + 时序件。
 *
 * ```text
 * A 栈所有权：overlays 栈本体（push / remove / pop / all / top / at / count）
 * B 栈查询：isMenuOpen / isModalOpen / modal / topModalEntry / focusTrapRoots
 * C 几何布局：→ {@see OverlayLayout}（第 7 步，宿主 = 本类，读栈 + 关条目）
 * D 时序件：Tooltip（悬停 500ms，相邻切换不等）/ Snackbar（FIFO + 自动消失）
 * E 入口 API：showMenu / closeMenu / showModal / closeModal / showSnackbar …
 * F 片间契约：onHoverHit（宿主 attach 的 hover 回调）/ focusTrapRoots（帧循环）
 * ```
 *
 * ★ 与第 5/6/7 步同构：输入侧是 `ScrollInputRouter ← InputRouter ← App 壳`，
 *   浮层侧是 `OverlayLayout ← OverlayManager ← App 壳` —— 被依赖多的晚拆，
 *   "拆一层、上游改指向"的口径两处一致。
 * ★ **15 个 public 业务方法的壳留在 `Application`**（API 位置不动，实现搬走）；
 *   本类方法一律 @internal（类头已标，方法不再带 `@api` 标签）。
 * ★ **为什么 E 与 D 不再分刀**：`closeMenu()` 全清后必须复位 snackbar/tooltip
 *   状态位并取消 snackTimer（E 碰 D），`pushOverlay()` 被 showTooltip /
 *   presentSnackbar / toggleWidgetPanel 三处复用（D 碰 A）⇒ 分刀会造出
 *   两片 + 片间组合，边界互相穿孔。定稿见 arch-v2 `12` §4h。
 *
 * @internal S1 第 8 步从 Application 拆出（public 方法的委托壳已留在壳上）
 */
final class OverlayManager
{
    /**
     * 悬停多久才弹出 Tooltip（毫秒，W8-2）。
     *
     * ★ 500ms 是桌面惯例（Windows / macOS 都在 400–700ms 这一档）。太短 ⇒ 指针
     *   扫过一排图标时提示乱闪；太长 ⇒ 用户以为没有提示。
     * ★ **相邻切换不等**：已经显示着的时候移到另一个带提示的控件，**立即**换内容
     *   （Windows 手感）—— 见 `onHoverHit()`。
     */
    private const TOOLTIP_DELAY_MS = 500;

    /**
     * 控件声明的浮层面板（`Widget::overlayPanel()`）的宽度上限（W8-2 Select）。
     *
     * ★ 与 `TooltipPanel::MAX_WIDTH` 分开：那是"一行提示"，这是"一列选项"。
     * ★ 宽度上限是**必须**的（不是审美）：`placeEntry()` 给非居中层的是无界约束，
     *   `RenderPopupPanel` 只在有界时才夹宽，而 ⑤ 的夹紧**只挪 x 不改宽** ⇒
     *   不限宽就是"长选项右边溢出到客户区外"。
     */
    private const PANEL_MAX_WIDTH = 320.0;

    /** 浮层栈本体（`01` §8.1）—— 本类自持，宿主与各片经 `overlays()` 现读 */
    private OverlayStack $overlays;

    public function __construct(private OverlayHost $host)
    {
        $this->overlays = new OverlayStack();
    }

    /** 当前悬停的 Tooltip 目标（`tooltipTextValue()` 声明者链上的最近 Element）。 */
    private ?Element $tooltipTarget = null;

    /** 已排期的"显示"定时器 id；`null` = 没有在等待（已显示 / 已取消） */
    private ?int $tooltipTimer = null;

    /** Tooltip 浮层当前是否**真的挂在栈上**（决定"相邻切换是否要再等 500ms"） */
    private bool $tooltipShown = false;

    /** Tooltip 浮层条目（隐藏时精确移除它，不误伤同栈的菜单/模态） */
    private ?OverlayEntry $tooltipEntry = null;

    /**
     * 排队中的 Snackbar（**不含正在显示的那条**，W8-2）。
     *
     * ★ FIFO、**不设上限**：连续触发（保存 → 保存 → 保存）都该看到。
     *   框架不替应用猜"哪条更重要" —— 雪崩场景由应用自己限流。
     *
     * @var list<Snackbar>
     */
    private array $snackQueue = [];

    /** 正在显示的那条（`null` = 没有通知在显示） */
    private ?OverlayEntry $snackEntry = null;

    /** 当前这条的自动消失定时器（`null` = 没有排期，含 `duration=0` 的"永不消失"） */
    private ?int $snackTimer = null;

    /**
     * 打开浮层面板（O-20 方案 A：第二棵根渲染树）。
     *
     * ```php
     * // 菜单标题的 onOpen 里
     * ->onOpen(fn(Menu $m, ?Element $anchor) => $this->showMenu($m->panel(), $anchor))
     * ```
     *
     * @param Widget $panel 面板（通常是 `PopupPanel`；菜单用 `Menu::panel()` / `ContextMenu::panel()`）
     * @param Element|null $anchor 锚点 Element；`null` ⇒ 用最近一次命中的（`FocusManager::lastHit()`）
     *
     * ★ **调用点是应用**（菜单的 `onOpen` / 右键入口）—— 打开要碰 `Application`，
     *   而 §4.3 禁 `Widget → App`；于是"菜单声明形体、应用管落位"这条分工
     *   （§5.1 裁决 1）在类型层面就成立了。
     *
     */
    public function showMenu(Widget $panel, ?Element $anchor = null): void
    {
        if ($this->host->root() === null) {
            return; // 未挂载：没有客户区，浮层无处安放
        }

        $anchor ??= $this->host->focus()?->lastHit();

        $e = new OverlayEntry($panel);
        $e->anchor = $anchor;
        $e->prefer = OverlayEntry::BELOW;
        $e->barrier = true;
        $e->scrim = false;
        $e->dismissOnTapOutside = true;
        $e->dismissOnEsc = true;
        $e->focusTrap = false;

        $this->pushOverlay($e);
    }

    /** 关掉浮层（已关 ⇒ 无事发生；幂等） */
    public function closeMenu(): void
    {
        // 语义不变：它原来清的就是"整个槽位" ⇒ 栈版 = 全清
        while (($e = $this->overlays->pop()) !== null) {
            $e->element?->unmount();
        }

        // ★ 全清会顺手把 Tooltip / Snackbar 也清掉 ⇒ 它们的状态位必须跟着复位，
        //   否则下一次悬停会以为"还显示着"从而**跳过 500ms 延时**（或反过来握着
        //   一个已经不在栈里的条目、把队列卡死）。
        $this->tooltipEntry = null;
        $this->tooltipShown = false;
        $this->snackEntry = null;

        if ($this->snackTimer !== null) {
            $this->host->cancelAfter($this->snackTimer);
            $this->snackTimer = null;
        }
    }

    /**
     * 悬停命中变化（`FocusManager::onHoverChanged` 的消费者）—— Tooltip 的时序全在这里。
     *
     * ```text
     * 进入带提示的控件 ──after(500)──▶ showTooltip
     * 离开            ──cancel + 立即──▶ hideTooltip
     * 已显示时移到另一个 ──立即──▶ showTooltip（Windows 手感）
     * ```
     *
     * ★ **相邻切换不等 500ms** 是刻意的：指针扫过一排图标时，如果每个都要重新
     *   停留 500ms，用户会觉得"提示坏了"。代价是"手快划过两个图标"也会换一次
     *   内容 —— 但那比"等不到"好。
     * ★ **时序只在框架一处**：这正是"声明式"相对"应用写 hover 回调"的主要收益
     *   （见 `Widget::tooltip()` 的注释）。
     *
     * @internal 宿主 `attach()` 的 hover 回调直调本方法（片间契约，非扩展点）
     */
    public function onHoverHit(?Element $hit): void
    {
        $next = self::tooltipOwner($hit);

        if ($next === $this->tooltipTarget) {
            return;   // 同一个控件内的移动 / 同样"没有提示" ⇒ 什么都不做
        }

        $this->tooltipTarget = $next;

        // 还没到点就换了目标 ⇒ 撤掉旧排期（否则 500ms 后会弹出上一个控件的提示）
        if ($this->tooltipTimer !== null) {
            $this->host->cancelAfter($this->tooltipTimer);
            $this->tooltipTimer = null;
        }

        if ($next === null) {
            $this->hideTooltip();

            return;
        }

        if ($this->tooltipShown) {
            $this->showTooltip($next);

            return;
        }

        // ★ 闭包**不捕获 `$next`**：到点时以 `$tooltipTarget` 为准（它可能已经被
        //   后来的 hover 换掉了）。捕获旧值 + 忘记比对 = 弹出上一个控件的提示。
        $this->tooltipTimer = $this->host->after(self::TOOLTIP_DELAY_MS, function (): void {
            $this->tooltipTimer = null;

            $target = $this->tooltipTarget;
            if ($target !== null) {
                $this->showTooltip($target);
            }
        });
    }

    /**
     * 沿父链找最近的**带提示**声明者（`Widget::tooltipTextValue() !== null`）。
     *
     * ★ 与 `FocusManager::hoverOwner()` 是**两条独立判据**：那个找的是 `hover()`
     *   声明者（为了派发悬停高亮），本方法找的是 `tooltip()` 声明者。一个控件可以
     *   只声明其中任意一个。
     */
    private static function tooltipOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            // ★ 空串判据（不是 `!== null`）：可选标量在 AOT 下退化 `php::Var`，
            //   2026-10-23 真机上 `?string` 恒读成 null ⇒ 提示永远不出现。
            if ($node->widget()->tooltipTextValue() !== '') {
                return $node;
            }
        }

        return null;
    }

    /**
     * 弹出提示（已显示则先撤旧的）。
     *
     * ★ 条目参数逐条都有理由：
     *   - `barrier=false` ⇒ 不拦层外点击（提示不该吃掉用户的点击）
     *   - `hitTest=false` ⇒ **指针看不见这一层**（否则悬停目标被面板抢走 ⇒ 闪烁死循环）
     *   - `dismissOnTapOutside=false` / `dismissOnEsc=false` ⇒ 提示不是模态，
     *     "关闭"只归悬停离开
     *   - `owner=$anchor` ⇒ 锚点被回收时自动收尸（`placeOverlays()` 的清扫）
     *   - `restoreFocus=null`（push 之后覆盖）⇒ **不许动焦点**：`pushOverlay()` 会
     *     快照当前焦点，关闭时"恢复"会把焦点从用户刚点的地方抢走
     */
    private function showTooltip(Element $anchor): void
    {
        $text = $anchor->widget()->tooltipTextValue();
        if ($text === '') {
            return;
        }

        $this->hideTooltip();   // 换内容：先撤旧的（同一条路径，不重复实现）

        $e = new OverlayEntry(TooltipPanel::make($text));
        $e->anchor = $anchor;
        $e->owner = $anchor;
        $e->prefer = OverlayEntry::ABOVE_CENTER;
        $e->barrier = false;
        $e->scrim = false;
        $e->dismissOnTapOutside = false;
        $e->dismissOnEsc = false;
        $e->focusTrap = false;
        $e->hitTest = false;
        $e->maxWidth = TooltipPanel::MAX_WIDTH;

        $this->pushOverlay($e);

        // ★ 必须在 push **之后**覆盖：`pushOverlay()` 内部会把当前焦点快照进
        //   `restoreFocus`（那是菜单/模态需要的"焦点回锚"）。
        $e->restoreFocus = null;

        $this->tooltipEntry = $e;
        $this->tooltipShown = true;
    }

    /** 撤掉提示浮层（未显示 ⇒ 无事发生，幂等） */
    private function hideTooltip(): void
    {
        $e = $this->tooltipEntry;

        $this->tooltipEntry = null;
        $this->tooltipShown = false;

        if ($e !== null) {
            $this->removeOverlay($e);
        }
    }

    /**
     * 弹一条底部通知（W8-2，2026-10-23）。
     *
     * ```php
     * // ★ 组合根接线（§4.3：Widget 层够不到 Application）—— 把这条闭包递给页面
     * ($this->notify)(
     *     Snackbar::make('已保存')->action('撤销', function (): void { ... })
     * );
     * ```
     *
     * ★★ **`$this->application()->showSnackbar(...)` 不存在**（`State` 上没有那个
     *   访问器，§4.3 禁 `Widget → App`）。本注释 2026-10-23 上午写成了那个形态，
     *   结果示例台真机点一下就把进程打死，而 smoke 全绿 —— 夹具本来就拿着
     *   `Application`，根本没走那条路。**示例台是这条 API 唯一的真实消费者。**
     * ★ **第一条立即显示，后面的排队**（FIFO）。不排队的话"连续保存三次"只会
     *   看到最后一次 —— 那正是通知最容易失职的场景。
     * ★ **`duration=0` ⇒ 永不自动消失**，只能 `dismissSnackbar()` 收（错误提示用）。
     * ★ 与 Tooltip 一样**不动焦点**（`restoreFocus` 置 null）：通知不该把焦点从
     *   用户正在操作的地方抢走。
     * ★★ 与 Tooltip **相反**的一点：`hitTest` 保持默认 `true` —— 动作按钮要能点。
     *   Tooltip 是 `false`（指针看不见它），Snackbar 必须看得见。这两个需求正好
     *   相反，改任何一个之前先想清楚是哪一类。
     *
     */
    public function showSnackbar(Snackbar $snackbar): void
    {
        if ($this->host->root() === null) {
            return; // 未挂载：没有客户区，通知无处安放
        }

        if ($this->snackEntry !== null) {
            $this->snackQueue[] = $snackbar;

            return;
        }

        $this->presentSnackbar($snackbar);
    }

    /**
     * 收掉当前这条通知（有排队 ⇒ 立刻上队首那条）。幂等。
     *
     * ★ 动作按钮点完也走这里（见 `SnackbarPanel` 注入的关闭回调）。
     */
    public function dismissSnackbar(): void
    {
        if ($this->snackTimer !== null) {
            $this->host->cancelAfter($this->snackTimer);
            $this->snackTimer = null;
        }

        $e = $this->snackEntry;
        $this->snackEntry = null;

        if ($e !== null) {
            $this->removeOverlay($e);
        }

        $next = $this->shiftSnackQueue();
        if ($next !== null) {
            $this->presentSnackbar($next);
        }
    }

    /** 当前是否有通知在显示（测试 / devtools） */
    public function hasSnackbar(): bool
    {
        return $this->snackEntry !== null;
    }

    /** 排队等待的通知条数（测试 / devtools） */
    public function snackbarQueueLength(): int
    {
        return \count($this->snackQueue);
    }

    /** 当前通知的条目（测试 / devtools 读参数用） */
    public function snackbarEntry(): ?OverlayEntry
    {
        return $this->snackEntry;
    }

    /**
     * 取队首并出队（`null` = 队列空）。
     *
     * ★ **单独一个带返回类型的方法**：`$this->snackQueue[0]` 的类型在 AOT 侧会被
     *   推成 `php::Var`，直接传给 `presentSnackbar(Snackbar)` 会 C2440。集中在这里
     *   转换一次，调用点就有类型了（同 `OverlayStack::at()` 的 `?OverlayEntry`）。
     */
    private function shiftSnackQueue(): ?Snackbar
    {
        $next = null;
        $rest = [];

        foreach ($this->snackQueue as $item) {
            if ($next === null) {
                $next = $item;

                continue;
            }

            $rest[] = $item;
        }

        $this->snackQueue = $rest;

        return $next;
    }

    /** 把一条通知压栈并排自动消失（内部用；已挂载前提下才调） */
    private function presentSnackbar(Snackbar $snackbar): void
    {
        $e = new OverlayEntry(SnackbarPanel::make($snackbar, function (): void {
            $this->dismissSnackbar();
        }));

        $e->prefer = OverlayEntry::BOTTOM_CENTER;
        $e->inset = Snackbar::MARGIN;
        $e->barrier = false;
        $e->scrim = false;
        $e->dismissOnTapOutside = false;
        $e->dismissOnEsc = false;
        $e->focusTrap = false;
        $e->maxWidth = Snackbar::MAX_WIDTH;
        // ★ `hitTest` 保持默认 true —— 动作按钮要能点（与 Tooltip 相反）。

        $this->pushOverlay($e);

        // ★ push 之后覆盖：`pushOverlay()` 会把当前焦点快照进 `restoreFocus`。
        $e->restoreFocus = null;

        $this->snackEntry = $e;

        $duration = $snackbar->durationValue();
        if ($duration <= 0) {
            return; // 永不自动消失（要显式 dismissSnackbar）
        }

        $this->snackTimer = $this->host->after($duration, function (): void {
            $this->snackTimer = null;
            $this->dismissSnackbar();
        });
    }

    /**
     * 浮层栈本体（`01` §8.1）—— 测试 / devtools 的正式入口。
     *
     * ★ 下面那批 `menu*` / `modal*` 兼容方法是**旧槽位 API 的残留**：它们都只是
     *   对这一个栈的投影。新代码要"看有几层、每层是什么"就读这里。
     *
     */
    public function overlays(): OverlayStack
    {
        return $this->overlays;
    }

    /**
     * 菜单是否开着：栈里存在 `barrier=true && scrim=false` 的条目（`01` §8.1）。
     *
     * ★ **模态不算菜单**：模态是 `scrim=true` ⇒ 不满足条件。混答的后果是
     *   `showModal()` 之后 `isMenuOpen()` 返回真，应用里"菜单开着就别响应某个
     *   快捷键"之类的判断会开始撒谎。
     */
    public function isMenuOpen(): bool
    {
        foreach ($this->overlays->all() as $e) {
            if ($e->barrier && !$e->scrim) {
                return true;
            }
        }

        return false;
    }

    /**
     * 打开模态（§A.3.7 / §12.1 第 6 条）—— 与菜单**共用**同一个浮层槽位。
     *
     * ```php
     * // 组合根接线（§4.3：Widget 层够不到 Application）
     * ($this->openModal)(Modal::make()->title('确认删除')->child($body));
     * ```
     *
     * ★ **`$this->application()` 不存在**（同 `showSnackbar()` 的注释）。
     * ★ **模态盖在菜单之上**（`01` §8 的**唯一行为变化**）：W3 之前"同一槽位 ⇒
     *   打开模态先关菜单"，那是**槽位的产物、不是需求** —— 在下拉里点「新建分类…」
     *   弹出对话框是正常交互，关掉对话框后下拉应该还在。要"开模态就关菜单"是
     *   **应用的策略**（自己先 `closeMenu()`），框架不再替它决定。
     * ★ **面板自居中靠组合**：`Modal::build()` 给的是 `ModalHost → Center →
     *   PopupPanel`，本方法只负责"盖满 + 原点归零"（`placeEntry()` 的 `CENTER` 分支）。
     *
     */
    public function showModal(Modal $modal): void
    {
        if ($this->host->root() === null) {
            return; // 未挂载：没有客户区，模态无处安放（同 showMenu）
        }

        // ★ 关闭按钮接线（2026-10-25）：`Modal` 自己拿不到 `Application`（§4.3），
        //   而"对话框右上角那个 X"必须真的能关 —— 与 `presentSnackbar()` 给
        //   `SnackbarPanel::make()` 注入 `$onDismiss` 是同一条模式：控件只声明
        //   口子，动作由框架接。
        // ★ 放在 `new OverlayEntry` 之前：注入要赶在 `pushOverlay()` 建 Element、
        //   `build()` 读 `showsCloseIcon()` 之前。
        // ★ 用 `bindDismiss()` 而不是 `->onClose()`：同一实例可被开两次，第二次
        //   已 seal，走 `mutate()` 会当场抛。
        $modal->bindDismiss(function (): void {
            $this->closeModal();
        });

        $e = new OverlayEntry($modal);
        $e->modal = $modal;   // ← "是模态"记在入栈这一笔上（`modal()` 靠它，不靠 instanceof）
        $e->anchor = null;
        $e->prefer = OverlayEntry::CENTER;
        $e->barrier = true;
        $e->scrim = true;
        $e->dismissOnTapOutside = $modal->isDismissible();
        $e->dismissOnEsc = $modal->isDismissible();
        $e->focusTrap = true;

        $this->pushOverlay($e);
    }

    /**
     * 关掉模态（幂等；没开 ⇒ 无事发生）。
     *
     * ★ **只有应用能调它**：`Modal` 自己不关（§A.3.7「关不关归应用」）。
     *   `dismissible` 只管"用户动作能不能关"，不限制这里 —— 应用永远能关。
     *
     */
    public function closeModal(): void
    {
        $e = $this->topModalEntry();
        if ($e === null) {
            return;
        }

        $this->removeOverlay($e);
    }

    /**
     * 把条目挂载并压栈（`showMenu` / `showModal` 的共同部分）。
     *
     * ★ `restoreFocus` 在这里快照：关闭时焦点该回到打开前的节点。
     */
    private function pushOverlay(OverlayEntry $e): void
    {
        $element = $e->panel->createElement();
        $element->mount(null, $this->menuContext());

        $e->element = $element;
        $e->restoreFocus = $this->host->focus()?->focused();

        $this->overlays->push($e);
    }

    /** 精确移除一个浮层条目并卸载它的树（`01` §7.1） */
    private function removeOverlay(OverlayEntry $e): void
    {
        $restore = $e->restoreFocus;

        $e->element?->unmount();
        $this->overlays->remove($e);

        // `01` §6.4 焦点回锚：关掉浮层后焦点回到**打开它之前**的节点。
        // ★ 锚点已离线（它自己也被这次关闭带走了）⇒ 什么都不做，让下一帧的
        //   `collect()` 把焦点清空 —— 不猜"上一个焦点"，猜错就是焦点跑到
        //   用户没预期的地方。
        if ($restore !== null && $restore->isMounted()) {
            $this->host->focus()?->focus($restore);
        }
    }

    /**
     * 按条目关浮层（S1 第 8 步起：`InputRouter` / `OverlayLayout` 两片的契约口子）。
     *
     * ★ 不是应用扩展点 —— `removeOverlay()` 的浮层策略（卸载 / 焦点回锚）整个
     *   住本类，消费片只表达"关掉这个条目"一个意图，不碰策略细节。
     *
     * @internal
     */
    public function dismissOverlay(OverlayEntry $e): void
    {
        $this->removeOverlay($e);
    }

    /**
     * 焦点陷阱的根集（`01` §6.4）—— 空数组 = 无陷阱。
     *
     * ★ 取**最上面**那个 `focusTrap` 条目（§9 边界 #14：模态里再开模态时只有
     *   最上面那层生效），并把**压在它上面的层一起收进来**：模态里开 `Select`
     *   下拉时，下拉是独立根、不在模态子树里 —— 只收模态会让下拉 Tab 不到。
     *
     * @internal 宿主帧循环的 `focus->collect()` 直调本方法（片间契约，非扩展点）
     */
    public function focusTrapRoots(): array
    {
        $all = $this->overlays->all();
        $from = null;
        foreach ($all as $i => $e) {
            if ($e->focusTrap) {
                $from = $i;
            }
        }

        if ($from === null) {
            return [];
        }

        $roots = [];
        for ($i = $from, $n = count($all); $i < $n; $i++) {
            if ($all[$i]->element !== null) {
                $roots[] = $all[$i]->element;
            }
        }

        return $roots;
    }

    /**
     * 最上面的 scrim（模态）条目（`01` §8.1）。
     *
     * ★ 取**最上面的 scrim 条目**，不是"栈顶是不是模态"：模态下面可能还压着
     *   菜单（新语义允许）⇒ 栈顶是模态时才相等。
     */
    private function topModalEntry(): ?OverlayEntry
    {
        $found = null;
        foreach ($this->overlays->all() as $e) {
            if ($e->scrim) {
                $found = $e;
            }
        }

        return $found;
    }

    public function isModalOpen(): bool
    {
        return $this->topModalEntry() !== null;
    }

    /** 当前模态（测试 / devtools / 应用读 `dismissible`） */
    public function modal(): ?Modal
    {
        $e = $this->topModalEntry();

        return $e?->modal;
    }

    /** 浮层的渲染环境（复用主树的 —— 同主题 / 同度量） */
    private function menuContext(): BuildContext
    {
        return $this->host->root()?->context()
            ?? new BuildContext(0.0, 0.0, $this->host->theme(), $this->host->metrics());
    }

    /**
     * 开 / 关控件声明的面板（W8-2 Select）—— `InputRouter::activateFocusedWidget()`
     * 的通用缝（S1 第 6 步起激活语义住 InputRouter，本方法是其片间契约口子）。
     *
     * ★ **开关语义**：同一个控件再激活一次就收掉。键盘 Enter 能走到这里（鼠标点
     *   第二下走不到 —— 面板开着时点控件在面板**外**，`barrier` 会先把它关掉并吞掉
     *   那次按下，这是下拉的标准手感）。
     * ★ **不靠字段记"谁开着"**：直接扫栈里 `owner === $anchor` 的条目。字段会在
     *   `dismissOnTapOutside` / `closeMenu()` / owner 清扫这些**不经过本类**的移除
     *   路径上变陈旧（Snackbar 的 `snackEntry` 就是靠 `closeMenu()` 手动复位兜的，
     *   那种补丁能少一个是一个）。
     *
     * @internal 片间契约口子，不是应用扩展点
     */
    public function toggleWidgetPanel(Element $anchor, \Closure $factory): void
    {
        $open = $this->widgetPanelEntry($anchor);
        if ($open !== null) {
            $this->removeOverlay($open);

            return;
        }

        // ★ dismiss 由**本类**注入（§4.3：面板里的按钮拿不到 `Application`）。
        //   走"按 owner 找条目"而不是捕获 `$e` —— 闭包里按引用捕获变量是 tpc 未验证形态。
        $panel = $factory(function () use ($anchor): void {
            $this->closeWidgetPanel($anchor);
        });

        $e = new OverlayEntry($panel);
        $e->anchor = $anchor;
        // ★ owner = 锚点自身：页面走了没关下拉 ⇒ `placeOverlays()` 的 owner 清扫收尸。
        $e->owner = $anchor;

        // ★ 受控值传播的**基线**（W8-2，2026-10-24，见 `syncPanelSignatures()`）：
        //   开面板这一刻就把"锚点当前 Widget"和"它声明的签名"记下来 —— 否则下一帧
        //   会以为"签名从空变成了有值"，白重建一次面板（滚动位置回到顶部）。
        $anchorWidget = $anchor->widget();
        $e->seenAnchor = $anchorWidget;
        $signature = $anchorWidget->overlaySignatureValue();
        if ($signature !== null) {
            $e->lastSignature = $signature();
        }
        $e->prefer = OverlayEntry::BELOW;
        // ★★ `barrier = true`（与菜单同）—— 这是 `dismissOnTapOutside` **唯一**生效的
        //   路径：`dispatchPointer()` 里"点在条目盒外"那一支整个包在 `if ($e->barrier)` 里，
        //   `barrier = false` 的条目直接穿透，永远轮不到关闭。
        $e->barrier = true;
        $e->scrim = false;
        $e->dismissOnTapOutside = true;
        $e->dismissOnEsc = true;
        $e->focusTrap = false;
        $e->maxWidth = self::PANEL_MAX_WIDTH;

        // ★ **不动 `restoreFocus`**（与 Tooltip / Snackbar 相反）：那两个是被"悬停"/
        //   "定时器"触发的，打开时焦点根本不在锚点上，快照下来是错的；这里打开它的
        //   就是锚点自己 ⇒ `pushOverlay()` 的快照天然正确，收面板时焦点回到控件上。
        $this->pushOverlay($e);
    }

    /** 栈里属于这个锚点的面板条目（没有 ⇒ `null`） */
    private function widgetPanelEntry(Element $anchor): ?OverlayEntry
    {
        foreach ($this->overlays->all() as $e) {
            if ($e->owner === $anchor) {
                return $e;
            }
        }

        return null;
    }

    /** 收掉这个锚点的面板（幂等；没有 ⇒ 无事发生） */
    private function closeWidgetPanel(Element $anchor): void
    {
        $e = $this->widgetPanelEntry($anchor);
        if ($e === null) {
            return;
        }

        $this->removeOverlay($e);
    }
}
