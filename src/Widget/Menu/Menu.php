<?php
declare(strict_types=1);

namespace Drift\Widget\Menu;

use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\PopupPanel;
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

    /** @var null|callable(self, ?\Drift\Widget\Element): void */
    private $openHandler = null;

    public function __construct(string $title)
    {
        parent::__construct($title);
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

    /** 要浮起来的面板（`Application::showMenu()` 的入参） */
    public function panel(): Widget
    {
        return PopupPanel::make(Column::make()->children($this->items))->minWidth($this->minWidth);
    }

    public function title(): string
    {
        return $this->label();
    }
}
