<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Commands\CommandArg;
use Drift\Input\KeyChord;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderScrollView;
use Drift\Widget\Column;
use Drift\Widget\Drag\Draggable;
use Drift\Widget\Drag\DragTarget;
use Drift\Widget\Element;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Menu\MenuBar;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\Widget;
use Drift\Dev\Smoke\Overlay\FlatBox;

/**
 * @internal 冒烟夹具：菜单 / 右键 / 拖放的宿主应用（§9.1 + §A.3.5）。
 *
 * 三行确定几何（`FlatBox` 固定尺寸）：菜单栏 · 可右键+可拖的行 · 放置目标行。
 */
final class MenuApplication extends Application
{
    /** @var list<string> 收到过的命令名 */
    public array $seen = [];

    /** @var list<mixed> 被 `DragTarget` 接收的负载 */
    public array $drop = [];

    /** @var list<bool> `onHover` 的调用序列（进入 / 离开各一次，不重复） */
    public array $hover = [];

    public bool $opened = false;

    public ?Element $anchor = null;

    /** 上次 `buildRoot()` 建出来的"文件"菜单（测试直接开它，绕开焦点状态） */
    private ?Menu $fileMenu = null;

    /**
     * 长菜单（14 项）—— 验 `PopupPanel::maxHeight()` 的"超高才滚、够短就贴着"。
     *
     * ★ **刻意不进 `MenuBar`**：菜单栏的自然宽有既有断言（标题 + 3 个导航 + 菜单），
     *   加一个标题会把它顶翻。测试照 `fileMenu()` 的用法直接 `showMenu($m->panel())`。
     */
    private ?Menu $longMenu = null;

    public function fileMenu(): ?Menu
    {
        return $this->fileMenu;
    }

    public function longMenu(): ?Menu
    {
        return $this->longMenu;
    }

    /**
     * 当前菜单面板里的滚动视口（`PopupPanel::maxHeight()` 的判据要读它的 `maxScroll()`）。
     *
     * ★ 走 `visitChildren()` 而不是手抄盒链（"面板里再套一层"会让直接孩子的假设静默取错）。
     * ★★ 调用方必须**每次重取**：滚一格 ⇒ `scrollBy()` → `setState` → `update()`
     *    会换一个新 `RenderScrollView`，拿旧盒读 `appliedOffset()` 恒为上一帧的值
     *    （看着像"滚轮没生效"）。
     */
    public function panelScrollView(): ?RenderScrollView
    {
        $box = $this->menuElement()?->effectiveBox();
        if ($box === null) {
            return null;
        }

        return self::findScrollView($box);
    }

    /** 子树里第一个滚动视口（没有 ⇒ `null`） */
    private static function findScrollView(RenderBox $box): ?RenderScrollView
    {
        if ($box instanceof RenderScrollView) {
            return $box;
        }

        $kids = [];
        $box->visitChildren(static function ($child) use (&$kids): void {
            $kids[] = $child;
        });

        foreach ($kids as $kid) {
            if (!$kid instanceof RenderBox) {
                continue;
            }
            $hit = self::findScrollView($kid);
            if ($hit !== null) {
                return $hit;
            }
        }

        return null;
    }

    public function buildRoot(): Widget
    {
        $this->fileMenu = Menu::make('文件')
            ->items([
                MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('menu.refresh'),
                MenuItem::separator(),
                MenuItem::make('退出')->command('app.quit'),
            ])
            ->onOpen(function (Menu $menu, ?Element $anchor): void {
                $this->opened = true;
                $this->anchor = $anchor;
                $this->showMenu($menu->panel(), $anchor);
            });

        $longItems = [];
        for ($i = 1; $i <= 30; $i++) {
            // 每 5 项插一条分隔线 —— 分隔线也占高，行高不均才验得出"按实测判"。
            // 30 项 ⇒ 自然高 ≈ 25×26.8 + 5×7 ≈ 705，溢出视口（≈300）约 405px
            //   ⇒ 够滚 **8 格以上**，断言才看得出"部分滚动"（一格就饱和的菜单
            //   只能证明"滚到了底"，证不了"滚对了量"）。
            $longItems[] = $i % 5 === 0
                ? MenuItem::separator()
                : MenuItem::make('第 ' . $i . ' 项')->command('menu.pick');
        }

        $this->longMenu = Menu::make('长菜单')
            ->items($longItems)
            ->onOpen(function (Menu $menu, ?Element $anchor): void {
                $this->showMenu($menu->panel(), $anchor);
            });

        return Column::make()->children([
            MenuBar::make()->menus([$this->fileMenu]),
            FlatBox::make(320.0, 40.0)
                ->contextMenu(ContextMenu::make()->items([
                    MenuItem::make('删除该行')->command('row.del'),
                ]))
                ->draggable(Draggable::make(payload: 'user-7')),
            FlatBox::make(320.0, 40.0)
                ->dragTarget(DragTarget::make()
                    ->onAccept(function (mixed $payload): void {
                        $this->drop[] = $payload;
                    })
                    ->onHover(function (bool $hot): void {
                        $this->hover[] = $hot;
                    })),
        ]);
    }

    public function commands(): iterable
    {
        $handler = new RecordingHandler(function (string $name, CommandArg $arg): void {
            $this->seen[] = $name;
        });

        return [
            'menu.refresh' => $handler,
            'app.quit' => $handler,
            'row.del' => $handler,
        ];
    }
}
