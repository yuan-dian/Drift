<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\App\Application;
use Drift\Dev\Smoke\App\TableCellSelectApplication;
use Drift\Dev\Smoke\App\TableScrollApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Widget\TableState;

/**
 * `PageUp` / `PageDown`：一屏一跳，步长来自**行区可见高**。
 *
 * ★ 输入一律用 `KeyChord::make('PageDown')`（壳层送进来的裸键名），**绝不用**
 *   `KeyChord::pageDown()` 构造 —— 后者与实现同源，工厂键名写成 `PgDn` 时两边一起绿
 *   （`KeyChord.php` 记过 `Ctrl+A` 写成小写的同类事故）。
 * ★ 步长既不是 `viewportH / rowHeight`（会多算表头那一行），也不是
 *   `createdRows()`（渲染窗口含 cache 行）；三档视口高把它钉死。
 * ★ 壳层 `cpp-src/drift_win32.cc` 的 `VK_PRIOR` / `VK_NEXT` 早就归一成这两个键名，
 *   这里补的是上层消费，不是平台层能力。
 */
final class PageKeyTest extends SmokeTestCase
{
    private float $clock = 20.0;

    /** @return array{0: TableScrollApplication, 1: ?TableState, 2: BoxConstraints} */
    private function open(int $rows = 100, float $viewH = Fixtures::VIEW_H): array
    {
        $app = new TableScrollApplication($rows);
        $tight = BoxConstraints::tight(new Size(Fixtures::VIEW_W, $viewH));
        $app->attach(new SurfaceProbe(), $tight);
        $this->clock = 20.0;
        $app->frame($this->clock, $tight);
        $app->focus()?->focusNext();
        $this->tick($app, $tight);

        return [$app, Fixtures::stateOf($app->root()), $tight];
    }

    private function tick(Application $app, ?BoxConstraints $tight = null): void
    {
        $this->clock += 16.0;
        $app->frame($this->clock, $tight ?? Fixtures::tight());
    }

    private function press(Application $app, BoxConstraints $tight, string $key, bool $ctrl = false, bool $alt = false, bool $shift = false): bool
    {
        $ate = $app->focus()?->dispatchKey(
            KeyEvent::down(KeyChord::make($key, $ctrl, $alt, $shift))
        ) ?? false;
        $this->tick($app, $tight);

        return $ate;
    }

    public function testStepComesFromBodyHeightNotViewportOrWindow(): void
    {
        // 行区 = 视口高 − 表头 32；行高 40 ⇒ 240 → 5、160 → 3、40 → 1（至少一行）
        foreach ([240 => 5, 160 => 3, 40 => 1] as $viewH => $expect) {
            [$app, $state, $tight] = $this->open(100, (float) $viewH);
            $ate = $this->press($app, $tight, 'PageDown');
            $cell = $state?->focusedCell();

            check(
                "Table · 翻页：视口 {$viewH} ⇒ PageDown 跳 {$expect} 行（行区/行高，非整窗高、非渲染窗口）",
                $ate && $cell === ['row' => $expect, 'col' => 0],
                'ate=' . ($ate ? 'y' : 'n') . ' cell=' . \json_encode($cell),
            );
        }

        [$one, $stateOne, $tightOne] = $this->open();
        $this->press($one, $tightOne, 'Down');
        check(
            'Table · 翻页：↓ 仍只走 1 行（翻页没有改掉方向键语义）',
            $stateOne?->focusedCell() === ['row' => 1, 'col' => 0],
            'cell=' . \json_encode($stateOne?->focusedCell()),
        );

        $this->flushChecks();
    }

    public function testPageUpDownAreInverseClampAndReveal(): void
    {
        [$app, $state, $tight] = $this->open();

        $this->press($app, $tight, 'PageDown');
        $this->press($app, $tight, 'PageDown');
        $afterTwo = $state?->focusedCell();
        $this->press($app, $tight, 'PageUp');
        $afterUp = $state?->focusedCell();

        check(
            'Table · 翻页：PageDown ×2 ⇒ 10 行，PageUp ⇒ 回到 5 行（互逆）',
            $afterTwo === ['row' => 10, 'col' => 0] && $afterUp === ['row' => 5, 'col' => 0],
            'two=' . \json_encode($afterTwo) . ' up=' . \json_encode($afterUp),
        );

        $allAte = true;
        for ($i = 0; $i < 5; $i++) {
            $allAte = $this->press($app, $tight, 'PageUp') && $allAte;
        }
        check(
            'Table · 翻页：顶端继续 PageUp 仍吃键、光标夹在 0 行',
            $allAte && $state?->focusedCell() === ['row' => 0, 'col' => 0],
            'ate=' . ($allAte ? 'y' : 'n') . ' cell=' . \json_encode($state?->focusedCell()),
        );

        $lastAte = false;
        for ($i = 0; $i < 30; $i++) {
            $lastAte = $this->press($app, $tight, 'PageDown');
        }
        $offset = Fixtures::boxOf($app->root())?->appliedOffset(Axis::Vertical) ?? -1.0;
        check(
            'Table · 翻页：末端夹在 99 行、吃键，且光标滚进视口',
            $lastAte && $state?->focusedCell() === ['row' => 99, 'col' => 0] && $offset > 200.0,
            'ate=' . ($lastAte ? 'y' : 'n') . ' cell=' . \json_encode($state?->focusedCell())
                . ' offsetY=' . \sprintf('%.1f', $offset),
        );

        $this->flushChecks();
    }

    public function testCtrlAltAndEmptyTablePassThrough(): void
    {
        [$app, $state, $tight] = $this->open();
        $ctrl = $this->press($app, $tight, 'PageDown', ctrl: true);
        $alt = $this->press($app, $tight, 'PageDown', alt: true);

        check(
            'Table · 翻页：Ctrl / Alt + PageDown 不吃（放行给加速键），光标不动',
            !$ctrl && !$alt && $state?->focusedCell() === ['row' => 0, 'col' => 0],
            'ctrl=' . ($ctrl ? 'y' : 'n') . ' alt=' . ($alt ? 'y' : 'n')
                . ' cell=' . \json_encode($state?->focusedCell()),
        );

        [$empty, , $tightEmpty] = $this->open(0);
        $ate = $this->press($empty, $tightEmpty, 'PageDown');
        check(
            'Table · 翻页：空格子表不吃这个键（让给上层滚页面）',
            !$ate,
            'ate=' . ($ate ? 'y' : 'n'),
        );

        $this->flushChecks();
    }

    public function testShiftPageDownExtendsByWholePage(): void
    {
        $app = new TableCellSelectApplication(6);
        $app->attach(new SurfaceProbe(), Fixtures::tight());
        $app->frame(20.0, Fixtures::tight());
        $host = $app->host();
        $app->focus()?->focusNext();
        $this->tick($app);

        $ate = $app->focus()?->dispatchKey(
            KeyEvent::down(KeyChord::make('PageDown', shift: true))
        ) ?? false;
        $this->tick($app);
        $sel = $host?->selection;

        check(
            'Table · 翻页：Shift+PageDown 一次扩到整屏（第 6 行被选中，不是只到第 2 行）',
            $ate && $sel !== null && $sel->covers(6, 'id') && $sel->covers(1, 'id'),
            'ate=' . ($ate ? 'y' : 'n') . ' keys=' . \json_encode($sel?->keys()),
        );

        $this->flushChecks();
    }

    public function testFactoriesMatchShellKeyNames(): void
    {
        // 字面量是外部规格：cpp-src/drift_win32.cc 的 VK_PRIOR / VK_NEXT 归一值
        check(
            'KeyChord · pageDown() 的键名 = 壳层 "PageDown"，且不带修饰键',
            KeyChord::pageDown()->key === 'PageDown'
                && !KeyChord::pageDown()->ctrl
                && !KeyChord::pageDown()->alt
                && !KeyChord::pageDown()->shift,
            'key=' . KeyChord::pageDown()->key,
        );
        check(
            'KeyChord · pageUp() 的键名 = 壳层 "PageUp"，且不带修饰键',
            KeyChord::pageUp()->key === 'PageUp'
                && !KeyChord::pageUp()->ctrl
                && !KeyChord::pageUp()->alt
                && !KeyChord::pageUp()->shift,
            'key=' . KeyChord::pageUp()->key,
        );

        $this->flushChecks();
    }
}
