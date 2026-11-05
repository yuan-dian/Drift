<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Overlay;

use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Smoke\App\ContextMenuKeyApplication;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Menu\MenuItem;

/**
 * 上下文菜单的**键盘入口**（§59）：`Shift+F10` / 菜单键 `Apps`。
 *
 * ★ 两条必须分开判的语义：
 *   ① "打开了谁的菜单" —— 起点是**焦点元素**，沿父链找最近的 `contextMenu()` 声明者；
 *   ② "谁先看到这个键" —— 焦点链（控件 `keyboard()`）先于这一环，应用加速键也先于它。
 */
final class ContextMenuKeyTest extends SmokeTestCase
{
    private const TIGHT = [320.0, 240.0];

    private static function boot(): array
    {
        [$w, $h] = self::TIGHT;
        $surface = new SvgSurface();
        $surface->resize((int) $w, (int) $h, 1.0);
        $app = new ContextMenuKeyApplication();
        $app->attach($surface, BoxConstraints::tight(new Size($w, $h)));

        return [$app, $surface, BoxConstraints::tight(new Size($w, $h))];
    }

    /** Tab 到第 `$n` 个按钮（0 基） */
    private static function tabTo(ContextMenuKeyApplication $app, int $n): ?Element
    {
        for ($i = 0; $i <= $n; $i++) {
            $app->dispatchInput(KeyEvent::down(KeyChord::tab()));
        }

        return $app->focus()?->focused();
    }

    public function testShiftF10OpensContextMenuOfFocusedSubtree(): void
    {
        [$app, , $tight] = self::boot();
        $row = self::tabTo($app, 0);
        check('上下文菜单键 · 焦点在「行」上（前置）', $row?->widget() instanceof Button && $row?->widget()->label() === '行');

        $app->dispatchInput(KeyEvent::down(KeyChord::shift('F10')));
        $app->frame(16.0, $tight);
        check('上下文菜单键 · Shift+F10 ⇒ 焦点元素的 contextMenu 打开', $app->isMenuOpen());

        $first = $app->focus()?->focused();
        check(
            '上下文菜单键 · 打开后焦点已在首项（与菜单栏同一条纪律：不用再 Tab）',
            $first !== null && $first->widget() instanceof MenuItem,
            'focused=' . ($first === null ? 'null' : $first->widget()::class),
        );

        $app->dispatchInput(KeyEvent::down(KeyChord::enter()));
        check(
            '上下文菜单键 · Enter 执行项 + 关浮层 + 焦点回到「行」',
            $app->seen === ['row.del'] && !$app->isMenuOpen() && $app->focus()?->focused() === $row,
            'seen=' . implode(',', $app->seen) . ' open=' . ($app->isMenuOpen() ? 'y' : 'n'),
        );
    }

    public function testMenuKeyIsTheSameKey(): void
    {
        [$app, , $tight] = self::boot();
        self::tabTo($app, 0);
        $app->dispatchInput(KeyEvent::down(KeyChord::make('Apps')));
        $app->frame(16.0, $tight);
        check('上下文菜单键 · 菜单键 Apps 与 Shift+F10 等价', $app->isMenuOpen());
    }

    public function testNoDeclarationIsSilent(): void
    {
        [$app, , $tight] = self::boot();
        $blank = self::tabTo($app, 1);
        check('上下文菜单键 · 焦点在「空白」上（前置）', $blank?->widget() instanceof Button);

        $app->dispatchInput(KeyEvent::down(KeyChord::shift('F10')));
        $app->frame(16.0, $tight);
        check('上下文菜单键 · 子树没有声明 ⇒ 什么也不开（不猜、不报错）', !$app->isMenuOpen());
    }

    public function testWidgetClaimWinsOverTheFramework(): void
    {
        [$app, , $tight] = self::boot();
        self::tabTo($app, 2);

        $app->dispatchInput(KeyEvent::down(KeyChord::shift('F10')));
        $app->frame(16.0, $tight);
        check(
            '上下文菜单键 · 控件 keyboard() 认领了 ⇒ 框架不开菜单（第 ④ 环排最后的意义）',
            $app->claimed && !$app->isMenuOpen(),
            'claimed=' . ($app->claimed ? 'y' : 'n') . ' open=' . ($app->isMenuOpen() ? 'y' : 'n'),
        );
    }

    /**
     * 首项进不了 Tab 序（`disabled()` / `skipFocus()`）⇒ 焦点要落到**下一个**能用的项上。
     *
     * ★ 这是"移焦首项"里唯一有分支的一步：不判这两条就是"焦点落在看不见/按不动的项上"，
     *   而画面完全正常 —— 与 roving tabindex 那批缺陷同一个形状（§57 / §58）。
     */
    public function testFirstFocusableSkipsDisabledAndSkippedItems(): void
    {
        [$app, , $tight] = self::boot();
        self::tabTo($app, 3);

        $app->dispatchInput(KeyEvent::down(KeyChord::shift('F10')));
        $app->frame(16.0, $tight);
        $first = $app->focus()?->focused();
        check(
            '上下文菜单键 · 首项禁用 / 次项跳过 ⇒ 焦点落到第三项',
            $first !== null && $first->widget() instanceof MenuItem
                && $first->widget()->label() === '落点项',
            'focused=' . ($first === null ? 'null' : $first->widget()::class)
                . ' label=' . ($first?->widget() instanceof Button ? $first->widget()->label() : '?'),
        );
    }
}
