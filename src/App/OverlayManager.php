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
 * 浮层调度核心：栈的层级维护 + 开合策略 + 时序件（Tooltip / Snackbar）。
 * 与 `OverlayLayout` 协作完成几何布局；public 委托壳留在 `Application`。
 *
 * @internal S1 第 8 步从 Application 拆出（public 方法的委托壳已留在壳上）
 */
final class OverlayManager
{
    /** 悬停多久才弹 Tooltip（毫秒）；相邻切换不等（已显示时立即换内容） */
    private const TOOLTIP_DELAY_MS = 500;

    /** 面板宽度上限：非居中层的约束无界，不限宽会右侧溢出客户区 */
    private const PANEL_MAX_WIDTH = 320.0;

    /** 浮层栈本体 —— 本类自持，宿主经 `overlays()` 现读 */
    private OverlayStack $overlays;

    public function __construct(private OverlayHost $host)
    {
        $this->overlays = new OverlayStack();
    }

    /** 当前悬停的 Tooltip 目标（声明者链上最近的 Element） */
    private ?Element $tooltipTarget = null;

    /** 已排期的"显示"定时器 id；`null` = 没有在等待（已显示 / 已取消） */
    private ?int $tooltipTimer = null;

    /** Tooltip 当前是否真的挂在栈上（决定相邻切换是否再等 500ms） */
    private bool $tooltipShown = false;

    /** Tooltip 浮层条目（隐藏时精确移除，不误伤同栈菜单/模态） */
    private ?OverlayEntry $tooltipEntry = null;

    /**
     * 排队中的 Snackbar（不含正在显示的）；FIFO、不设上限。
     *
     * @var list<Snackbar>
     */
    private array $snackQueue = [];

    /** 正在显示的那条（`null` = 没有通知在显示） */
    private ?OverlayEntry $snackEntry = null;

    /** 当前这条的自动消失定时器（`null` = 没有排期，含 `duration=0`） */
    private ?int $snackTimer = null;

    /**
     * 打开浮层面板（菜单 / 右键入口）。
     *
     * @param Widget $panel 面板（通常 `PopupPanel`；菜单用 `Menu::panel()` / `ContextMenu::panel()`）
     * @param Element|null $anchor 锚点；`null` ⇒ 用最近一次命中（`FocusManager::lastHit()`）
     * @param float|null $atX 指针位置（客户区坐标）；给了 ⇒ 菜单**弹在这一点**，`$anchor`
     *   只用于 owner 回收。键盘入口不给（没有指针位置 ⇒ 退化为元素盒）。
     */
    public function showMenu(
        Widget $panel,
        ?Element $anchor = null,
        ?float $atX = null,
        ?float $atY = null,
    ): void {
        if ($this->host->root() === null) {
            return; // 未挂载：没有客户区，浮层无处安放
        }

        $anchor ??= $this->host->focus()?->lastHit();

        $e = new OverlayEntry($panel);
        $e->anchor = $anchor;
        $e->atX = $atX;
        $e->atY = $atY;
        $e->prefer = $atX === null ? OverlayEntry::BELOW : OverlayEntry::AT_POINTER;
        $e->barrier = true;
        $e->scrim = false;
        $e->dismissOnTapOutside = true;
        $e->dismissOnEsc = true;
        $e->focusTrap = false;

        $this->pushOverlay($e);

        // 打开即聚焦首项：菜单是"焦点进面板"的模型（`MenuItem` 是 `Button`，↑↓/Enter
        // 都靠焦点），不像 `Select` 那样把高亮留在触发框里。少了这一步，键盘用户点开
        // 菜单后还得先按一次 Tab —— ARIA menubar / WPF / Qt 都是打开即进首项。
        // ★ 顺序不能反：`pushOverlay()` 里快照的 `restoreFocus` 必须是**打开前**的焦点。
        $focus = $this->host->focus();
        $first = $e->element === null ? null : $focus?->firstFocusable($e->element);
        if ($first !== null) {
            $focus?->focus($first);
        }
    }

    /** 关掉浮层（已关 ⇒ 无事发生；幂等） */
    public function closeMenu(): void
    {
        // ★ 焦点回锚与 `removeOverlay()` 同一条纪律：全清也要把焦点还回打开前那个节点。
        //   否则「菜单项派完命令 ⇒ 全清」这条最常用的路径会把焦点丢在虚空里，
        //   下一次 Tab 从页首重来（ESC / 点外部走 `removeOverlay()` 却会回锚 —— 两条路不一致）。
        //   取**最后弹出**那条的 `restoreFocus`：它压在最底层，快照的正是菜单打开前的焦点。
        $restore = null;
        while (($e = $this->overlays->pop()) !== null) {
            if ($e->restoreFocus !== null) {
                $restore = $e->restoreFocus;
            }
            $e->element?->unmount();
        }

        // ★ 全清会顺手清掉 Tooltip / Snackbar ⇒ 它们的状态位必须复位，
        //   否则下次悬停会跳过 500ms 延时（或握着已不在栈里的条目、卡死队列）。
        $this->tooltipEntry = null;
        $this->tooltipShown = false;
        $this->snackEntry = null;

        if ($this->snackTimer !== null) {
            $this->host->cancelAfter($this->snackTimer);
            $this->snackTimer = null;
        }

        if ($restore !== null && $restore->isMounted()) {
            $this->host->focus()?->focus($restore);
        }
    }

    /**
     * 悬停命中变化（`FocusManager::onHoverChanged` 的消费者）—— Tooltip 的时序全在这里。
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

        // 还没到点就换了目标 ⇒ 撤掉旧排期，否则 500ms 后会弹出上一个控件的提示
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

        // 闭包不捕获 `$next`：到点时以 `$tooltipTarget` 为准（可能已被后来的 hover 换掉）；
        // 捕获旧值 + 忘记比对 = 弹出上一个控件的提示。
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
     * 与 `FocusManager::hoverOwner()` 是两条独立判据：一个找 `hover()` 声明者（派发悬停高亮），
     * 另一个找 `tooltip()` 声明者，一个控件可只声明其中任意一个。
     */
    private static function tooltipOwner(?Element $element): ?Element
    {
        for ($node = $element; $node !== null; $node = $node->parent()) {
            // ★ 空串判据（不是 `!== null`）：可选标量在 AOT 下退化 `php::Var`，
            //   `?string` 恒读成 null ⇒ 提示永远不出现（用 `!== null` 就永远不弹）。
            if ($node->widget()->tooltipTextValue() !== '') {
                return $node;
            }
        }

        return null;
    }

    /**
     * 弹出提示（已显示则先撤旧的）。
     *
     * 条目参数：`barrier=false` 不拦层外点击；`hitTest=false` 指针看不见这一层
     * （否则悬停目标被面板抢走 ⇒ 闪烁死循环）；`owner=$anchor` 锚点回收时自动收尸。
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

        // 必须在 push 之后覆盖：`pushOverlay()` 会把当前焦点快照进 `restoreFocus`
        // （菜单/模态需要的"焦点回锚"）；提示不抢焦点 ⇒ 置 null。
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
     * 弹一条底部通知。
     *
     * 第一条立即显示，后面的排队（FIFO）：不排队则"连续保存三次"只看到最后一次。
     * `duration=0` ⇒ 永不自动消失，只能 `dismissSnackbar()` 收（错误提示用）。
     * 与 Tooltip 相反：`hitTest` 保持默认 `true`（动作按钮要能点）。
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
     * 动作按钮点完也走这里（见 `SnackbarPanel` 注入的关闭回调）。
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
     * 单独一个带返回类型的方法：集中做一次类型转换，调用点就有精确类型（同 `OverlayStack::at()`）。
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
        // `hitTest` 保持默认 true —— 动作按钮要能点（与 Tooltip 相反）。

        $this->pushOverlay($e);

        // push 之后覆盖：`pushOverlay()` 会把当前焦点快照进 `restoreFocus`，通知不抢焦点 ⇒ 置 null。
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
     * 浮层栈本体 —— 测试 / devtools 的正式入口。
     * `menu*` / `modal*` 兼容方法是旧槽位 API 的投影，新代码读这里。
     */
    public function overlays(): OverlayStack
    {
        return $this->overlays;
    }

    /**
     * 菜单是否开着：栈里存在 `barrier=true && scrim=false` 的条目。
     * 模态是 `scrim=true` ⇒ 不算菜单：混答会让"菜单开着就别响应某快捷键"的判断撒谎。
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
     * 打开模态（与菜单共用同一浮层槽位）。
     *
     * 模态盖在菜单之上：下拉里点「新建分类…」弹对话框，关掉后下拉还在（应用自己决定要不要先 `closeMenu()`）。
     * 面板自居中靠组合：`Modal::build()` 给 `ModalHost → Center → PopupPanel`，本方法只负责盖满 + 原点归零。
     */
    public function showModal(Modal $modal): void
    {
        if ($this->host->root() === null) {
            return; // 未挂载：没有客户区，模态无处安放（同 showMenu）
        }

        // 关闭按钮接线：`Modal` 自己拿不到 `Application`，X 必须真的能关，
        // 与 `presentSnackbar` 给 `SnackbarPanel::make()` 注入 `$onDismiss` 同模式。
        // 放在 `new OverlayEntry` 之前：注入要赶在 `pushOverlay()` 建 Element、`build()` 读 `showsCloseIcon()` 之前。
        // 用 `bindDismiss()` 而不是 `->onClose()`：同一实例可被开两次，第二次已 seal，走 `mutate()` 会当场抛。
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
     * 只有应用能调它：`Modal` 自己不关（`dismissible` 只管用户动作，不限制这里）。
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
     * `restoreFocus` 在这里快照：关闭时焦点回到打开前的节点。
     */
    private function pushOverlay(OverlayEntry $e): void
    {
        $element = $e->panel->createElement();
        $element->mount(null, $this->menuContext());

        $e->element = $element;
        $e->restoreFocus = $this->host->focus()?->focused();

        $this->overlays->push($e);
    }

    /** 精确移除一个浮层条目并卸载它的树 */
    private function removeOverlay(OverlayEntry $e): void
    {
        $restore = $e->restoreFocus;

        $e->element?->unmount();
        $this->overlays->remove($e);

        // 关掉浮层后焦点回到打开它之前的节点（焦点回锚）。
        // 锚点已离线（被这次关闭带走）⇒ 什么都不做，让下一帧 `collect()` 清空焦点；
        // 不猜"上一个焦点"，猜错就是焦点跑到用户没预期的地方。
        if ($restore !== null && $restore->isMounted()) {
            $this->host->focus()?->focus($restore);
        }
    }

    /**
     * 按条目关浮层（`InputRouter` / `OverlayLayout` 的契约口子）。
     * 不是应用扩展点：`removeOverlay()` 的浮层策略（卸载 / 焦点回锚）整个住本类。
     *
     * @internal
     */
    public function dismissOverlay(OverlayEntry $e): void
    {
        $this->removeOverlay($e);
    }

    /**
     * 焦点陷阱的根集（空数组 = 无陷阱）。
     * 取最上面的 `focusTrap` 条目，并把压在它上面的层一起收进来：模态里开 `Select` 下拉时，
     * 下拉是独立根、不在模态子树里，只收模态会让下拉 Tab 不到。
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
     * 最上面的 scrim（模态）条目。
     * 取最上面的 scrim 条目，不是"栈顶是不是模态"：模态下面可能还压着菜单（新语义允许）。
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
     * 开 / 关控件声明的面板（`InputRouter::activateFocusedWidget()` 的通用缝）。
     *
     * 开关语义：同一个控件再激活一次就收掉（鼠标点第二下走不到 —— 面板开着时点控件在面板外，
     * `barrier` 先关掉并吞掉那次按下）。不靠字段记"谁开着"：直接扫栈里 `owner === $anchor` 的条目
     * （字段会在不经过本类的移除路径上变陈旧）。
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

        // dismiss 由本类注入（面板里的按钮拿不到 `Application`）；走"按 owner 找条目"而非捕获 `$e`
        // （闭包按引用捕变量是 tpc 未验证形态）。
        $panel = $factory(function () use ($anchor): void {
            $this->closeWidgetPanel($anchor);
        });

        $e = new OverlayEntry($panel);
        $e->anchor = $anchor;
        // owner = 锚点自身：页面走了没关下拉 ⇒ owner 清扫收尸。
        $e->owner = $anchor;

        // 受控值传播基线：开面板时记下"锚点当前 Widget"和"它声明的签名"，
        // 否则下一帧会以为签名从空变有值、白重建一次（滚动位置回顶）。
        $anchorWidget = $anchor->widget();
        $e->seenAnchor = $anchorWidget;
        $signature = $anchorWidget->overlaySignatureValue();
        if ($signature !== null) {
            $e->lastSignature = $signature();
        }
        $e->prefer = OverlayEntry::BELOW;
        // ★ `barrier = true`（与菜单同）：这是 `dismissOnTapOutside` 唯一生效的路径 ——
        //   `dispatchPointer()` 里"点在条目盒外"那一支包在 `if ($e->barrier)` 里，
        //   `barrier = false` 的条目直接穿透，永远轮不到关闭。
        $e->barrier = true;
        $e->scrim = false;
        $e->dismissOnTapOutside = true;
        $e->dismissOnEsc = true;
        $e->focusTrap = false;
        // 默认口径是"一列选项"，控件可自报更宽（`overlayPanel($f, $maxWidth)`）—— 并排两块面板不是 320 装得下。
        $e->maxWidth = $anchorWidget->overlayPanelMaxWidthValue() ?? self::PANEL_MAX_WIDTH;

        // 不动 `restoreFocus`（与 Tooltip/Snackbar 相反）：开面板的是锚点自己，
        // `pushOverlay()` 的快照天然正确，收面板时焦点回到控件上。
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
