<?php
declare(strict_types=1);

namespace Drift\Widget\Menu;

use Drift\Widget\Column;
use Drift\Widget\PopupPanel;
use Drift\Widget\ScrollView;
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

    /**
     * 面板内容高度上限（逻辑像素）。
     *
     * ★ 与 `Menu::PANEL_MAX_HEIGHT` **同值同理**（两个类的 `panel()` 本就是同一形态，
     *   文档已说明这种不对称是刻意的）：不设上限时超长菜单会被夹成整个客户区高、
     *   超出部分被裁 ⇒ **第 N 项之后点不到**。值是策略圆整数，"贴着还是滚"由
     *   `RenderPopupPanel` 按实测自然高决定。
     */
    public const PANEL_MAX_HEIGHT = 320.0;

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
        // ★ 套 `ScrollView` + `maxHeight()`：右键菜单同样可能很长（"对这一行可做的
        //   操作"常常是动态列表）。Tab 导航由 `revealFocused()` 自动滚焦点项进视口。
        return PopupPanel::make(
            ScrollView::make(Column::make()->children($this->items))
        )
            ->minWidth($this->minWidth)
            ->maxHeight(self::PANEL_MAX_HEIGHT);
    }
}
