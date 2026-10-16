<?php
declare(strict_types=1);

namespace Drift\Widget\Menu;

use Drift\Widget\Column;
use Drift\Widget\PopupPanel;
use Drift\Widget\Widget;

/**
 * 上下文菜单的**声明**（挂在别的控件上，右键触发；§A.3.1）。
 *
 * ```php
 * Row::make()->children([...])->contextMenu(
 *     ContextMenu::make()->items([
 *         MenuItem::make('删除该行')->command('user.del')->arg(CommandArg::id($id)),
 *         MenuItem::make('复制邮箱')->onPressed(fn() => $this->clipboard()->set($u->email)),
 *     ])
 * )
 * ```
 *
 * ★ **它不是 `Widget`**（与 `Menu` 不对称，且是本质的不对称）：`Menu` 是菜单栏里
 *   一个可点的标题（有渲染形态 ⇒ 必须是 `Widget`）；`ContextMenu` **没有固定的
 *   渲染形态**（它的触发是右键命中），所以它是**声明对象** —— 与 `Draggable` 同型。
 *
 * ★ **右键入口 = 最小集**（§9.1，用户 2026-10-16 拍板）：`PointerEvent` 的
 *   `button` 判据 + `down` 即弹（不等 `GestureArena` —— 它仍是 0 文件）。
 *   代价：没有"按下后位移超过阈值 ⇒ 取消"这条消歧（那要 `move` 事件，M4）。
 *
 * @api
 */
final class ContextMenu
{
    /** @var list<Widget> */
    private array $items = [];

    private float $minWidth = 140.0;

    private function __construct()
    {
    }

    public static function make(): self
    {
        return new self();
    }

    /** @param list<Widget> $items */
    public function items(array $items): self
    {
        $this->items = $items;

        return $this;
    }

    /** @return list<Widget> */
    public function itemList(): array
    {
        return $this->items;
    }

    public function minWidth(float $value): self
    {
        $this->minWidth = $value;

        return $this;
    }

    /** 要浮起来的面板（`Application::showMenu()` 的入参） */
    public function panel(): Widget
    {
        return PopupPanel::make(Column::make()->children($this->items))->minWidth($this->minWidth);
    }
}
