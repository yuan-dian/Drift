<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\App\Application;
use Drift\Dev\Smoke\App\TableCellSelectApplication;
use Drift\Dev\Smoke\App\TableCellSelectHostState;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\KeyChord;
use Drift\Input\KeyEvent;
use Drift\Input\PointerEvent;
use Drift\Widget\TableSelectionBehavior;

/**
 * **格级矩形选区**（2026-10-31）：点一格 / Shift 扩成矩形 / 拖动框选 / 方向键扩选 /
 * `Ctrl+A` 全表 / `Ctrl+C` 复制成 TSV。
 *
 * ★ 全走真实路由：`down` 被行容器 `ownsPress` 捕获，之后 `move` / `up` 经
 *   `dispatchToCapture()` 派给同一个回调；键盘走真 `FocusManager`。直接调
 *   `onSelectionChange` 验不出"点这一格会不会被行容器抢走"。
 * ★ 几何（见 `TableCellSelectApplication`）：表头 32 + 行高 40；列 `id` 宽 60、
 *   `name` 宽 260 ⇒ 列中心 x：`id`=30、`name`=190。
 *
 * ★ 反例自检（每条都试过红）：
 *   - 只把 `covers()` 实现成 `has(row)` ⇒ "只有那一格"红（会覆盖整行）；
 *   - 拖动只记行不记列 ⇒ "拖成矩形"红（列区间恒为 1）；
 *   - `Ctrl+A` 走 `withAll` 逐行加 ⇒ `ranges()` 条数红；
 *   - `copyTextOf()` 不读 `field()` ⇒ TSV 全空红。
 */
final class CellSelectTest extends SmokeTestCase
{
    private float $clock = 20.0;

    /** @return array{0: TableCellSelectApplication, 1: TableCellSelectHostState} */
    private static function app(TableSelectionBehavior $behavior = TableSelectionBehavior::Cells): array
    {
        $app = new TableCellSelectApplication(6, $behavior);
        $app->attach(new SurfaceProbe(), Fixtures::tight());
        $app->frame(20.0, Fixtures::tight());
        $host = $app->host();
        self::assertNotNull($host);

        return [$app, $host];
    }

    /** ① 点一格 ⇒ 只有这一格（行集 = 那一行，但列只有一列） */
    public function testClickSelectsExactlyOneCell(): void
    {
        [$app, $host] = self::app();

        $app->dispatchInput(PointerEvent::down(190.0, self::y(0)), 1000.0);
        $this->tick($app);

        $sel = $host->selection;
        check(
            'Table · 格选区：点一格 ⇒ 只有那一格被选中',
            $sel->covers(1, 'name')
                && !$sel->covers(1, 'id')
                && !$sel->covers(2, 'name'),
            \json_encode([$sel->keys(), \count($sel->ranges())]),
        );
        check(
            'Table · 格选区：行级 API 仍然可用（`keys()` = 被碰到的行）',
            $sel->keys() === [1] && $sel->has(1),
            \json_encode($sel->keys()),
        );

        $this->flushChecks();
    }

    /** ② 像素：选中的格有色、同一行没选中的格是白的（**按格**染，不是整行） */
    public function testOnlySelectedCellIsTinted(): void
    {
        [$app] = self::app();

        $app->dispatchInput(PointerEvent::down(190.0, self::y(0)), 1000.0);
        $this->tick($app);

        $raster = Fixtures::rasterOf(Fixtures::boxOf($app->root()), (int) Fixtures::VIEW_W, (int) Fixtures::VIEW_H);
        $selected = $raster->pixelAt(190, 52);   // 行 0 的 `name` 格中心
        $sameRow = $raster->pixelAt(30, 52);     // 行 0 的 `id` 格中心

        check(
            'Table · 格选区：选中的格有底色，同一行**未选中**的格仍是白的',
            $selected->toArgb() !== $sameRow->toArgb() && $sameRow->toArgb() === 0xFFFFFFFF,
            \sprintf('选中 %08X / 同行未选中 %08X', $selected->toArgb(), $sameRow->toArgb()),
        );

        $this->flushChecks();
    }

    /** ③ `Shift+点` ⇒ 锚点到目标格的矩形（行 × 列各取区间） */
    public function testShiftClickExtendsToRectangle(): void
    {
        [$app, $host] = self::app();

        $app->dispatchInput(PointerEvent::down(30.0, self::y(0)), 1000.0);       // 行 0 的 id
        $this->tick($app);
        // ★ 必须 `up`：行容器是 `ownsPress`，不松手的话第二次 `down` 会经
        //   `dispatchToCapture()` 回到**第一行**的回调（指针底下早已不是那一行）
        $app->dispatchInput(PointerEvent::up(30.0, self::y(0)), 1050.0);
        $this->tick($app);
        $app->dispatchInput(PointerEvent::down(190.0, self::y(2), shift: true), 1100.0);
        $this->tick($app);

        $sel = $host->selection;
        $expected = [
            [1, 'id'], [1, 'name'],
            [2, 'id'], [2, 'name'],
            [3, 'id'], [3, 'name'],
        ];
        $actual = [];
        foreach ([1, 2, 3] as $rowKey) {
            foreach (['id', 'name'] as $colKey) {
                if ($sel->covers($rowKey, $colKey)) {
                    $actual[] = [$rowKey, $colKey];
                }
            }
        }

        check(
            'Table · 格选区：Shift+点 ⇒ 锚点到目标的 3 行 × 2 列矩形',
            $actual === $expected,
            \json_encode($actual),
        );

        $this->flushChecks();
    }

    /** ④ 拖动 ⇒ 拖动中按格高亮、松手**一次**上报矩形 */
    public function testDragReportsRectangleOnceOnRelease(): void
    {
        [$app, $host] = self::app();

        $app->dispatchInput(PointerEvent::down(30.0, self::y(0)), 1000.0);
        $this->tick($app);
        $afterDown = $host->notifyCount;

        $app->dispatchInput(PointerEvent::move(190.0, self::y(1)), 1050.0);
        $this->tick($app);
        $midNotify = $host->notifyCount;

        $raster = Fixtures::rasterOf(Fixtures::boxOf($app->root()), (int) Fixtures::VIEW_W, (int) Fixtures::VIEW_H);
        $dragged = $raster->pixelAt(190, 92);    // 行 1 的 name 格（在拖动矩形内）
        $outside = $raster->pixelAt(190, 132);   // 行 2 的 name 格（矩形外）

        check(
            'Table · 格选区：拖动中**不逐帧上报**，但矩形立刻按格高亮',
            $afterDown === 1 && $midNotify === 1 && $dragged->toArgb() !== $outside->toArgb(),
            \sprintf('down 后 %d / 拖后 %d / 格色 %08X vs %08X', $afterDown, $midNotify, $dragged->toArgb(), $outside->toArgb()),
        );

        $app->dispatchInput(PointerEvent::up(190.0, self::y(1)), 1100.0);
        $this->tick($app);

        $sel = $host->selection;
        check(
            'Table · 格选区：松手 ⇒ 一次上报 2 行 × 2 列',
            $host->notifyCount === 2
                && $sel->covers(1, 'id') && $sel->covers(1, 'name')
                && $sel->covers(2, 'id') && $sel->covers(2, 'name')
                && !$sel->covers(3, 'id'),
            \sprintf('notify=%d keys=%s', $host->notifyCount, \json_encode($sel->keys())),
        );

        $this->flushChecks();
    }

    /** ⑤ `Shift+方向键` ⇒ 从锚点格扩选（Excel 手感：先落锚再移） */
    public function testShiftArrowsExtendSelection(): void
    {
        [$app, $host] = self::app();
        $app->focus()?->focusNext();
        $this->tick($app);

        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::make('Right', shift: true)));
        $this->tick($app);

        check(
            'Table · 格选区：Shift+→ ⇒ 锚点格 + 右边一格（不是"只选目标格"）',
            $host->selection->covers(1, 'id') && $host->selection->covers(1, 'name'),
            \json_encode($host->selection->keys()),
        );

        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::make('Down', shift: true)));
        $this->tick($app);

        check(
            'Table · 格选区：再 Shift+↓ ⇒ 矩形长到 2 行（锚点仍在 (0,0)）',
            $host->selection->covers(1, 'id') && $host->selection->covers(2, 'name')
                && !$host->selection->covers(3, 'id'),
            \json_encode($host->selection->keys()),
        );

        $this->flushChecks();
    }

    /** ⑥ `Ctrl+A` ⇒ 全表一块矩形（**一条** range，不是逐行累加） */
    public function testCtrlASelectsWholeGridAsOneRange(): void
    {
        [$app, $host] = self::app();
        $app->focus()?->focusNext();
        $this->tick($app);

        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::make('a', ctrl: true)));
        $this->tick($app);

        $sel = $host->selection;
        check(
            'Table · 格选区：Ctrl+A ⇒ 6 行 × 2 列全选，且只有一条 range',
            \count($sel->ranges()) === 1
                && $sel->covers(6, 'name')
                && \count($sel->keys()) === 6,
            \sprintf('ranges=%d keys=%s', \count($sel->ranges()), \json_encode($sel->keys())),
        );

        $this->flushChecks();
    }

    /** ⑦ `Ctrl+C` ⇒ 选区交成 TSV（行 `\n`、格 `\t`） */
    public function testCtrlCCopiesSelectionAsTsv(): void
    {
        [$app, $host] = self::app();
        $app->focus()?->focusNext();
        $this->tick($app);

        // 行 0..1 × 两列
        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::make('a', ctrl: true)));
        $this->tick($app);
        $app->focus()?->dispatchKey(KeyEvent::down(KeyChord::make('c', ctrl: true)));
        $this->tick($app);

        $expected = "1\t用户 1\n2\t用户 2\n3\t用户 3\n4\t用户 4\n5\t用户 5\n6\t用户 6";
        check(
            'Table · 格选区：Ctrl+C ⇒ 全表 TSV（`\t` 分格、`\n` 分行）',
            $host->copied === $expected,
            \json_encode($host->copied),
        );

        $this->flushChecks();
    }

    /** ⑧ 回归：同一夹具切回 `Rows` ⇒ 点一格选**整行**（老行为一个字没改） */
    public function testRowsBehaviorStillSelectsWholeRow(): void
    {
        [$app, $host] = self::app(TableSelectionBehavior::Rows);

        $app->dispatchInput(PointerEvent::down(190.0, self::y(0)), 1000.0);
        $this->tick($app);

        $sel = $host->selection;
        check(
            'Table · 格选区回归：Rows 行为点一格 ⇒ 整行（两列都在选区内，且是整行 range）',
            $sel->covers(1, 'id') && $sel->covers(1, 'name')
                && \count($sel->ranges()) === 1
                && $sel->ranges()[0]->isWholeRow(),
            \json_encode($sel->keys()),
        );

        $this->flushChecks();
    }

    private function tick(Application $app): void
    {
        $this->clock += 1.0;
        $app->frame($this->clock, Fixtures::tight());
    }

    /** 行中心的根坐标 y（行 i 占 [32+40i, 72+40i)） */
    private static function y(int $index): float
    {
        return Fixtures::HEADER_H + Fixtures::ROW_H * $index + Fixtures::ROW_H / 2.0;
    }
}
