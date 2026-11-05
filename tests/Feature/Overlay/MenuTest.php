<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Overlay;

use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\MenuApplication;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderMenuItem;
use Drift\Layout\RenderPopupPanel;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Menu\ContextMenu;
use Drift\Widget\Menu\Menu;
use Drift\Widget\Menu\MenuBar;
use Drift\Widget\Menu\MenuItem;
use Drift\Widget\PopupPanel;
use Drift\Widget\Row;

/**
 * 菜单这件东西：怎么开、落在哪、怎么关，以及四件（Menu / MenuItem / MenuBar /
 * ContextMenu）的形体。
 *
 * ★ 关闭语义有四个来源，每条都要能区分"关对了"与"顺手关了"：
 *   ① 点面板外（且**吞掉**这次按下，不穿透到下面的右键菜单）
 *   ② 命令分发**之后**才关（先关就找不到接收者）
 *   ③ ESC 在 `dispatchKey` 之前拦（不被焦点控件吃掉）
 *   ④ 点面板内不关（指针先归浮层）
 * ★ 落位判据是"面板左上角 = 锚点左下"，不是"面板在锚点附近"：差一个 anchoring
 *   高度就会盖住锚点本身，点第二次就点不到了。
 */
final class MenuTest extends SmokeTestCase
{
    private const TIGHT = [320.0, 240.0];

    private static function boot(): array
    {
        [$w, $h] = self::TIGHT;
        $surface = new SvgSurface();
        $surface->resize((int) $w, (int) $h, 1.0);
        $app = new MenuApplication();
        $app->attach($surface, BoxConstraints::tight(new Size($w, $h)));

        return [$app, $surface, BoxConstraints::tight(new Size($w, $h))];
    }

    public function testKeyboardOpensAndPinsBelowAnchor(): void
    {
        [$app, $surface, $tight] = self::boot();
        check('浮层 · attach 后默认没开（浮层槽位为空）', !$app->isMenuOpen());

        // —— 键盘路径打开：Tab 到菜单标题 ⇒ Enter（Application::activateFocused 的 Menu 分支）
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        check(
            '浮层 · Menu 标题 Enter ⇒ onOpen 被调 + 应用打开面板',
            $app->opened && $app->isMenuOpen(),
        );
        check(
            '浮层 · 锚点 = 标题 Element（菜单钉在被点的控件旁）',
            $app->anchor !== null && $app->anchor->widget() instanceof Menu,
        );

        $panelBox = $app->overlays()->top()?->element?->effectiveBox();
        check('浮层 · 面板盒是 RenderPopupPanel（与根树同构：一个 Widget 一个 Element）', $panelBox instanceof RenderPopupPanel);

        // —— 渲染：主树先画、浮层后画（顺序即层叠）
        $before = $surface->svg();
        $app->frame(16.0, $tight);
        $after = $surface->svg();
        check(
            '浮层 · 渲染多画了一棵（SVG 的 rect 数增加 —— 面板底 / 边框 / 项文本）',
            substr_count($after, '<rect') > substr_count($before, '<rect'),
        );

        // —— 落位：面板左上角 = 锚点左下；夹进客户区（按**面板**矩形算）
        $top = $app->overlays()->top();
        $my = $top?->y ?? 0.0;
        $titleBox = $app->anchor?->effectiveBox();
        $titleBottom = $titleBox === null ? 0.0 : boxOrigin($titleBox)[1] + $titleBox->size()->height;
        $panelInset = $panelBox instanceof RenderPopupPanel ? $panelBox->panelRect()->left : 0.0;
        check(
            '浮层 · 面板左上角钉在锚点左下（含投影留白补偿）',
            abs(($my + $panelInset) - $titleBottom) < 1.0,
            sprintf('面板 y=%.6g 锚点底=%.6g', $my + $panelInset, $titleBottom),
        );
        check(
            '浮层 · 面板夹进客户区（右 / 下都不越界）',
            $panelBox !== null
                && ($top?->x ?? 0.0) + $panelBox->size()->width <= 320.5
                && $my + $panelBox->size()->height <= 240.5,
        );
    }

    public function testCloseSemanticsHasFourSources(): void
    {
        [$app, , $tight] = self::boot();
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $app->frame(16.0, $tight);

        $colBox = $app->rootBox();
        $rows = $colBox instanceof RenderFlex ? $colBox->children() : [];
        $rowBox = $rows[1] ?? null;
        $targetBox = $rows[2] ?? null;
        [$rx, $ry] = $rowBox === null ? [0.0, 0.0] : boxOrigin($rowBox);
        [$sx, $sy] = $targetBox === null ? [0.0, 0.0] : boxOrigin($targetBox);

        // ① 点面板外 ⇒ 关 + 吞掉该次按下
        //    ★ 坐标要同时满足：**在面板矩形之外**、**仍在带 ContextMenu 的那行上**。
        //      面板窄（≈140）而客户区宽 320 ⇒ 取该行右侧（x+200）。若穿透，
        //      这次右键会顺手打开下面那个菜单（最容易被抓的形态）。
        $app->dispatchInput(PointerEvent::down($rx + 200.0, $ry + 10.0, 1));
        check(
            '浮层 · 点面板外 ⇒ 关且**吞掉**按下（不穿透到下面的右键菜单）',
            !$app->isMenuOpen(),
            $app->isMenuOpen() ? '穿透了：菜单被重开' : '',
        );

        // —— 右键最小集：再点一次（此刻无浮层）⇒ 打开命中的 ContextMenu
        $app->dispatchInput(PointerEvent::down($rx + 10.0, $ry + 10.0, 1));
        check('右键 · 无浮层时右键 ⇒ 打开命中行的 ContextMenu', $app->isMenuOpen());

        // ② 命令分发**之后**才关
        $dispatched = $app->dispatch('row.del');
        check(
            '浮层 · 命令分发之后才关（语义 #2）',
            $dispatched && !$app->isMenuOpen() && $app->seen === ['row.del'],
        );

        // —— 右键落在无声明处 ⇒ 什么也不做（不报错、不落给焦点）
        $app->dispatchInput(PointerEvent::down($sx + 10.0, $sy + 10.0, 1));
        check('右键 · 落在无 ContextMenu 声明的行 ⇒ 什么也不做', !$app->isMenuOpen());

        // ③ ESC 在 dispatchKey 之前拦
        $app->showMenu($app->fileMenu()?->panel()); // 直接开（不依赖焦点当前在哪儿）
        check('浮层 · 重开（ESC 用例的前置）', $app->isMenuOpen());
        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        check('浮层 · ESC ⇒ 关（语义 #3：在 dispatchKey 之前拦，不被焦点控件吃掉）', !$app->isMenuOpen());

        // ④ 点面板**内**（空白处，非菜单项）⇒ 不关
        //   ★ 取面板右下 padding（项区右界 x=150、下界 y≈70.6，取 (w−6, h−6) 必空白）：
        //     点菜单项会**正确**地关浮层（语义 #2），那条是另一个用例。
        $app->showMenu($app->fileMenu()?->panel());
        $app->frame(32.0, $tight); // 走一帧 ⇒ 落位算过（overlays()->top() 才准）
        $top = $app->overlays()->top();
        $panel = $top?->element?->effectiveBox();
        $app->dispatchInput(PointerEvent::down(
            ($top?->x ?? 0.0) + ($panel?->size()?->width ?? 160.0) - 6.0,
            ($top?->y ?? 0.0) + ($panel?->size()?->height ?? 80.0) - 6.0,
        ));
        check('浮层 · 点面板内 ⇒ 不关（指针先归浮层，语义 #1 的另一半）', $app->isMenuOpen());
    }

    public function testPointerOnItemDispatchesThenCloses(): void
    {
        [$app, , $tight] = self::boot();
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));   // 键盘打开「文件」菜单
        $app->frame(16.0, $tight);                                // 走一帧 ⇒ 落位、盒才有尺寸
        check('浮层 · 菜单已开（指针项激活用例前置）', $app->isMenuOpen());

        $itemBox = findElement(
            $app->overlays()->top()?->element,
            fn (Element $e): bool => $e->widget() instanceof MenuItem,
        )?->effectiveBox();
        // ★ `boxOrigin()` 给**绝对**原点（浮层根会加自己的落点）⇒ 再叠一次
        //   `overlays()->top()` 就是加两遍，点会落到面板外、静默不生效。
        [$bx, $by] = $itemBox === null ? [0.0, 0.0] : boxOrigin($itemBox);
        $app->dispatchInput(PointerEvent::down(
            $bx + ($itemBox?->size()?->width ?? 0.0) / 2.0,
            $by + ($itemBox?->size()?->height ?? 0.0) / 2.0,
        ));
        check(
            '浮层 · 指针点菜单项 ⇒ 命令分发 + 分发后关浮层（语义 #2，与 Enter 同路）',
            $app->seen === ['menu.refresh'] && !$app->isMenuOpen(),
            'seen=' . implode(',', $app->seen) . ' open=' . ($app->isMenuOpen() ? 'yes' : 'no'),
        );
    }

    /**
     * 关闭浮层 ⇒ **焦点回锚**（两条关闭路径必须同一条纪律）。
     *
     * ★ ESC 走 `removeOverlay()`、命令分发走 `closeMenu()`（全清）—— 后者曾绕过回锚，
     *   于是"点完菜单项"这个最常用的动作会把焦点丢在虚空里，下一次 Tab 从页首重来。
     *   行为差异只在键盘用户身上可见，所以判据必须落在 `focused()` 上。
     */
    public function testClosingRestoresFocusToTheInvoker(): void
    {
        [$app, , $tight] = self::boot();

        // ESC 路径：焦点必须**先进面板**（只停在标题上时关闭不丢焦点，测不出东西）
        $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $app->frame(16.0, $tight);
        $invoker = $app->focus()?->focused();
        check('焦点回锚 · 键盘开菜单后焦点在标题上（前置）', $app->isMenuOpen() && $invoker !== null);

        $inPanel = $this->tabIntoPanel($app, $tight);
        check('焦点回锚 · Tab 能把焦点送进面板项（前置）', $inPanel !== null, 'focused=' . self::describe($inPanel));

        $app->dispatchInput(KeyEvent::down(KeyChord::escape()));
        check(
            '焦点回锚 · ESC 关闭 ⇒ 焦点回到打开菜单的标题',
            !$app->isMenuOpen() && $app->focus()?->focused() === $invoker,
        );

        // 命令分发路径（`closeMenu()` 全清）
        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        $app->frame(32.0, $tight);
        check('焦点回锚 · 重开（命令路径的前置）', $app->isMenuOpen() && $this->tabIntoPanel($app, $tight) !== null);

        $dispatched = $app->dispatch('menu.refresh');
        check(
            '焦点回锚 · 菜单项命令关闭 ⇒ 焦点同样回到标题（与 ESC 路径一致）',
            $dispatched && !$app->isMenuOpen() && $app->focus()?->focused() === $invoker,
            'seen=' . implode(',', $app->seen) . ' same=' . ($app->focus()?->focused() === $invoker ? 'y' : 'n'),
        );
    }

    /** 连按 Tab 直到焦点落进浮层面板（今日 Tab 序是"主树在前、浮层在后"） */
    private function tabIntoPanel(MenuApplication $app, BoxConstraints $tight): ?Element
    {
        for ($i = 0; $i < 12; $i++) {
            $focused = $app->focus()?->focused();
            if ($focused !== null && $focused->widget() instanceof MenuItem) {
                return $focused;
            }
            $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
            $app->frame(48.0 + $i, $tight);
        }

        $focused = $app->focus()?->focused();

        return $focused !== null && $focused->widget() instanceof MenuItem ? $focused : null;
    }

    private static function describe(?Element $element): string
    {
        return $element === null ? 'null' : $element->widget()::class;
    }

    public function testFourPieceShapes(): void    {
        $context = smokeContext();
        $menu = Menu::make('文件')->items([MenuItem::make('刷新')]);
        check('Menu · panel() 是 PopupPanel（浮层形体归 PopupPanel，落位归 App）', $menu->panel() instanceof PopupPanel);
        check('Menu · 是一种按钮（命令 / 焦点 / 激活整套复用，同 NavButton 先例）', $menu instanceof Button && $menu->isFocusable());

        $item = MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('menu.refresh');
        check(
            'MenuItem · 命令 / chord 声明可读',
            $item->commandName() === 'menu.refresh' && $item->chordValue()?->label() === 'Ctrl+R',
        );
        check('MenuItem · 是一种按钮 + 可聚焦（键盘能选中）', $item instanceof Button && $item->isFocusable());
        check('MenuItem · separator 形态', MenuItem::separator()->isSeparator());
        check('MenuBar · build 出横排（纯组合控件，自己没有盒）', MenuBar::make()->menus([$menu])->build($context) instanceof Row);
        check('ContextMenu · panel() 是 PopupPanel（声明对象，不是 Widget）', ContextMenu::make()->items([
            MenuItem::make('删除'),
        ])->panel() instanceof PopupPanel);
    }

    public function testItemBoxLayout(): void
    {
        $context = smokeContext();
        $item = MenuItem::make('刷新')->chord(KeyChord::ctrl('R'))->command('menu.refresh');

        // ★ 约束用 **tight 宽 + loose 高**（与 `RenderPopupPanel` 给内容的一致）——
        //   tight 高会把文本挤爆（内容放不下就该报，这里是我们给的约束不对）。
        $itemBox = $item->createRenderObject($context);
        check('MenuItem · 盒是 RenderMenuItem', $itemBox instanceof RenderMenuItem);
        $itemBox->layout(new BoxConstraints(180.0, 180.0, 0.0, 60.0));

        // 盒 → 内层 Row → label/chord（跳过 Row 本身，直接取两端的孩子盒）
        $inner = childBoxes($itemBox);
        $rowKids = $inner === [] ? [] : childBoxes($inner[0]);
        $first = $rowKids[0] ?? null;
        $last = $rowKids === [] ? null : $rowKids[count($rowKids) - 1];
        check(
            'MenuItem · chord 在行的右端（label 与 chord 两端分布 ⇒ 整行可点）',
            $first !== null && $last !== null && $last->offsetX() > $first->offsetX() + 40.0,
            sprintf('label x=%.6g chord x=%.6g', $first?->offsetX() ?? -1, $last?->offsetX() ?? -1),
        );

        $sepBox = MenuItem::separator()->createRenderObject($context);
        $sepBox->layout(new BoxConstraints(180.0, 180.0, 0.0, 20.0));
        check(
            'MenuItem · 分隔线有墨（1px 横线真画了）',
            inkCount(spinCanvas($sepBox, 180, 8)) > 0,
        );
    }
}
