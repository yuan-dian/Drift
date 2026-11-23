<?php
declare(strict_types=1);

namespace Drift\Widget\Menu;

use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\KeyResult;
use Drift\Widget\ActivationContext;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\PopupPanel;
use Drift\Widget\ScrollView;
use Drift\Widget\Widget;

/**
 * 下拉菜单的**标题**（`MenuBar` 里那个可点的名字，§A.3.1）。
 *
 * ```php
 * Menu::make('文件')->items([
 *     MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('users.refresh'),
 *     MenuItem::separator(),
 * ])->onOpen(fn(Menu $m, ?Element $anchor) => $this->showMenu($m->panel(), $anchor))
 * ```
 *
 * ★ **它只管形体与内容**（标题 = 按钮形态、`panel()` 给出要浮起来的面板）；
 *   **"打开"由应用接线**（`onOpen`）—— 因为打开要调 `Application::showMenu()`，
 *   而 §4.3 禁止 `Widget → App`。等价的写法是把 `onOpen` 关掉、改在菜单栏宿主
 *   里用命令打开；当前以回调落地（形状见 §A.3.1）。
 *
 * ★ **`onOpen` 的触发点**：`Application::activateFocused()`（键盘 Enter/Space）与
 *   `dispatchPointer()` 的 down 分支（指针按下，2026-10-17 四相位已落地 §24.2 #16）
 *   —— 两条路径同终点 `activateFocusedWidget()`。
 *
 * @api
 */
final class Menu extends Button
{
    /** @var list<Widget> */
    private array $items = [];

    private float $minWidth = 120.0;

    /**
     * 面板内容高度上限（逻辑像素）。
     *
     * ★ 与 `Select::PANEL_MAX_HEIGHT` 同一条理由：不设上限时，一列很长的菜单会被
     *   `placeEntry()` 的"超大退化"夹成**整个客户区那么高**，超出部分被面板自己的
     *   `Overflow::Clip` 裁掉 ⇒ **第 N 项之后点不到**（Tab 也走不到 —— 焦点环
     *   画在视口外，用户看不见焦点在哪）。
     *
     * ★ 值是**策略圆整数**（菜单行约 28–32px ⇒ 约 10 行），不是布局计算：
     *   "贴着内容还是滚动"由 `RenderPopupPanel` 按**实测自然高**决定，
     *   所以短菜单不会因为上限设得大就凭空变长，也不会因为设得小就无故滚。
     */
    public const float PANEL_MAX_HEIGHT = 320.0;

    /** @var null|callable(self, ?\Drift\Widget\Element): void */
    private $openHandler = null;

    public function __construct(string $title)
    {
        parent::__construct($title);

        // 菜单栏规范（ARIA menubar / WPF / Qt 同款）：标题上按 ↓ = 打开并聚焦首项。
        // ★ `←→` 不要抢 —— 同一排相邻标题天然在左右，几何导航已经能走；
        //   `↑`（打开并聚焦末项）今天没有第二个消费者，等有再加。
        // ★ 没有 `onOpen` 时返回 `Declined`：占站 ⇔ 真按得动，吞掉就是"按了没反应"。
        $this->keyboard(function (KeyEvent $event, Element $self): KeyResult {
            if ($this->openHandler() !== null && $event->chord->is(KeyChord::down())) {
                $this->activate(new ActivationContext($self));

                return KeyResult::Handled;
            }

            return KeyResult::Declined;
        });
    }

    public static function make(string $title): self
    {
        return new self($title);
    }

    /** @param list<Widget> $items */
    public function items(array $items): static
    {
        $this->mutate();
        $this->items = $items;

        return $this;
    }

    /** @return list<Widget> */
    public function itemList(): array
    {
        return $this->items;
    }

    public function minWidth(float $value): static
    {
        $this->mutate();
        $this->minWidth = $value;

        return $this;
    }

    /**
     * 打开回调：`fn(Menu $menu, ?Element $anchor)`。
     *
     * 里面通常写 `$app->showMenu($menu->panel(), $anchor)` —— 锚点由框架给
     * （`null` ⇒ `Application` 用最近一次命中的 Element，§5.1 裁决 1 的锚点来源）。
     *
     * @param callable(self, ?\Drift\Widget\Element): void $handler
     */
    public function onOpen(callable $handler): static
    {
        $this->mutate();
        $this->openHandler = $handler(...);

        return $this;
    }

    public function openHandler(): ?\Closure
    {
        return $this->openHandler;
    }

    /**
     * 激活 = **打开菜单**，不是"按一下"。
     *
     * ★ 覆写 `Button::activate()` 且**不调 parent** —— 菜单标题不跑命令、也不跑
     *   `onPressed`（`Menu` 从来不给内层按钮设 `onPressed`，见 `Select` 的同款裁决）。
     *
     * ★★ **这一处覆写就是旧代码那段"顺序脆弱"的解药**：以前 `InputRouter` 必须把
     *   `Menu` 分支写在 `Button` 之前（"子类会被 `instanceof` 认成父类"）。改成虚方法
     *   之后多态天然优先 —— 顺序不存在了，也就不需要注释提醒。
     *
     * ★ `onOpen` 为 `null` 时**什么都不做**（与搬迁前一致：那时也是先 `return`，
     *   不会掉进 `Button` 分支）。
     */
    public function activate(ActivationContext $context): void
    {
        $open = $this->openHandler();
        if ($open === null) {
            return;
        }

        $open($this, $context->element());
    }

    /** 要浮起来的面板（`Application::showMenu()` 的入参） */
    public function panel(): Widget
    {
        // ★ **必须套 `ScrollView`**：`PopupPanel::maxHeight()` 只负责给上限，
        //   "超高就滚"是靠内容自己是滚动视口实现的（它不会替内容滚）。
        // ★ Tab 导航不用额外接线：`MenuItem` 是 `Button`（可聚焦），焦点变化时
        //   `Application::revealFocused()` 会把焦点项滚进视口。
        return PopupPanel::make(
            ScrollView::make(Column::make()->children($this->items))
        )
            ->minWidth($this->minWidth)
            ->maxHeight(self::PANEL_MAX_HEIGHT);
    }

    public function title(): string
    {
        return $this->label();
    }
}
