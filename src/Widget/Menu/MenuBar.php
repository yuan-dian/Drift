<?php
declare(strict_types=1);

namespace Drift\Widget\Menu;

use Drift\Widget\BuildContext;
use Drift\Widget\Row;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Widget;

/**
 * 菜单栏：一排菜单标题（§A.3.1：放在 `AppBar` 的 children 里，或 `Dock::top`）。
 *
 * ```php
 * AppBar::make()->title('用户管理')->children([
 *     MenuBar::make()->menus([
 *         Menu::make('文件')->items([...]),
 *         Menu::make('编辑')->items([...]),
 *     ]),
 * ])
 * ```
 *
 * ★ **纯组合**（`StatelessWidget` → `Row`）：它自己没有盒、没有状态 —— 菜单栏
 *   不该有独立外形（底与高度由宿主 `AppBar` / `Dock::top` 决定）。
 *
 * @api
 */
final class MenuBar extends StatelessWidget
{
    /** @var list<Menu> */
    private array $menus = [];

    private float $spacing = 2.0;

    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    /** @param list<Menu> $menus */
    public function menus(array $menus): static
    {
        $this->mutate();
        $this->menus = $menus;

        return $this;
    }

    /** @return list<Menu> */
    public function menuList(): array
    {
        return $this->menus;
    }

    public function spacing(float $value): static
    {
        $this->mutate();
        $this->spacing = $value;

        return $this;
    }

    public function build(BuildContext $context): Widget
    {
        return Row::make()->spacing($this->spacing)->children($this->menus);
    }
}
