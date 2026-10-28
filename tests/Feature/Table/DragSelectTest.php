<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\App\Application;
use Drift\Dev\Smoke\App\TableDragSelectApplication;
use Drift\Dev\Smoke\App\TableDragSelectHostState;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\PointerEvent;
use Drift\Rendering\Color;
use Drift\Widget\Element;
use Drift\Widget\SizedBox;
use Drift\Widget\TableSelectionMode;

/**
 * 行级拖动框选：按住拖过多行 ⇒ **松手一次**上报区间。
 *
 * ★ 全走真实路由：`down` 被行容器 `ownsPress` 捕获，之后的 `move` / `up` 经
 *   `dispatchToCapture()` 派给**同一个**回调 —— 拖动时指针底下早已不是那一行了。
 *   直接调 `onSelectionChange` 验不出这件事。
 * ★ 几何：表头 32 + 行高 40 ⇒ 行 i 的 y ∈ [32+40i, 72+40i)，行区高 208。
 *
 * ★ 反例自检：`move` 里逐帧上报 ⇒ "拖动中不逐帧上报"红；松手后不清拖动区间 ⇒
 *   "不残留"红；反向拖不取 min/max ⇒ "反向往上拖"红（区间变空）。
 */
final class DragSelectTest extends SmokeTestCase
{
    private float $clock = 20.0;

    /** @return array{0: TableDragSelectApplication, 1: TableDragSelectHostState} */
    private static function drag(int $rows = 6, TableSelectionMode $mode = TableSelectionMode::Multiple): array
    {
        $app = new TableDragSelectApplication($rows, $mode);
        $app->attach(new SurfaceProbe(), Fixtures::tight());
        $app->frame(20.0, Fixtures::tight());
        $host = $app->host();
        self::assertNotNull($host);

        return [$app, $host];
    }

    public function testDragReportsIntervalOnceOnRelease(): void
    {
        [$app, $host] = self::drag();

        $app->dispatchInput(PointerEvent::down(150.0, self::y(2)), 1000.0);
        $this->tick($app);
        $afterDown = $host->notifyCount;

        $app->dispatchInput(PointerEvent::move(150.0, self::y(3)), 1050.0);
        $this->tick($app);
        $app->dispatchInput(PointerEvent::move(150.0, self::y(4)), 1100.0);
        $this->tick($app);

        $midBg = self::rowBgOf($app->root(), 3);
        $midNotify = $host->notifyCount;

        check(
            'Table · 拖动框选：拖动中**不逐帧上报**（只有按下那一次）',
            $afterDown === 1 && $midNotify === 1,
            \sprintf('down 后 %d / 拖到行 4 后 %d', $afterDown, $midNotify),
        );
        check(
            'Table · 拖动框选：拖过的行立刻高亮（受控值还没变 ⇒ 靠拖动区间自己画）',
            $midBg !== null && $midBg->a === 36,
            $midBg === null ? '无底色' : 'alpha=' . $midBg->a,
        );

        $app->dispatchInput(PointerEvent::up(150.0, self::y(4)), 1150.0);
        $this->tick($app);

        check(
            'Table · 拖动框选：松手 ⇒ 一次上报区间 [3,4,5]',
            $host->last?->keys() === [3, 4, 5] && $host->notifyCount === 2,
            \json_encode([$host->last?->keys(), $host->notifyCount]),
        );
        check(
            'Table · 拖动框选：应用回写的受控选中集就是那个区间（不是只上报不生效）',
            $host->selection->keys() === [3, 4, 5],
            \json_encode($host->selection->keys()),
        );

        $this->flushChecks();
    }

    /** ② 反向拖（行 4 → 行 2）⇒ 区间一样，不因起点在下就选空 */
    public function testReverseDragGivesSameInterval(): void
    {
        [$app, $host] = self::drag();

        $app->dispatchInput(PointerEvent::down(150.0, self::y(4)), 1000.0);
        $app->dispatchInput(PointerEvent::move(150.0, self::y(2)), 1100.0);
        $this->tick($app);
        $app->dispatchInput(PointerEvent::up(150.0, self::y(2)), 1200.0);
        $this->tick($app);

        check(
            'Table · 拖动框选：反向往上拖 ⇒ 区间同样 [3,4,5]',
            $host->last?->keys() === [3, 4, 5],
            \json_encode($host->last?->keys()),
        );

        $this->flushChecks();
    }

    /** ③ 手抖（位移 < 4px 阈值）⇒ 只当点击，不多报一次 */
    public function testSubThresholdMoveIsJustAClick(): void
    {
        [$app, $host] = self::drag();

        $app->dispatchInput(PointerEvent::down(150.0, self::y(2)), 1000.0);
        $app->dispatchInput(PointerEvent::move(150.0, self::y(2) + 2.0), 1050.0);
        $this->tick($app);
        $app->dispatchInput(PointerEvent::up(150.0, self::y(2) + 2.0), 1100.0);
        $this->tick($app);

        check(
            'Table · 拖动框选：位移不到阈值 ⇒ 只算点击（单行、只报一次）',
            $host->selection->keys() === [3] && $host->notifyCount === 1,
            \json_encode([$host->selection->keys(), $host->notifyCount]),
        );

        $this->flushChecks();
    }

    /** ④ 单选模式：拖一片也只落最后那一行（区间是多选专属） */
    public function testSingleSelectModeKeepsOnlyEndRow(): void
    {
        [$app, $host] = self::drag(6, TableSelectionMode::Single);

        $app->dispatchInput(PointerEvent::down(150.0, self::y(1)), 1000.0);
        $app->dispatchInput(PointerEvent::move(150.0, self::y(4)), 1100.0);
        $this->tick($app);
        $app->dispatchInput(PointerEvent::up(150.0, self::y(4)), 1200.0);
        $this->tick($app);

        check(
            'Table · 拖动框选：单选模式拖一片 ⇒ 仍只选终点那一行',
            $host->selection->keys() === [5],
            \json_encode($host->selection->keys()),
        );

        $this->flushChecks();
    }

    /** ⑤ 松手后高亮回落到受控选中集（拖动区间不能残留） */
    public function testHighlightDoesNotLingerAfterRelease(): void
    {
        [$app] = self::drag();

        $app->dispatchInput(PointerEvent::down(150.0, self::y(0)), 1000.0);
        $app->dispatchInput(PointerEvent::move(150.0, self::y(1)), 1100.0);
        $this->tick($app);
        $app->dispatchInput(PointerEvent::up(150.0, self::y(1)), 1200.0);
        $this->tick($app);

        $bg = self::rowBgOf($app->root(), 4);

        check(
            'Table · 拖动框选：松手后行 4（没被选中、也没斑马）不再有底色 —— 拖动区间不残留',
            $bg === null,
            $bg === null ? '无底色' : '仍有底色',
        );

        $this->flushChecks();
    }

    /** ⑥ 拖到行区下边界**之外** ⇒ 顺手滚半行（move 驱动；20 行 × 40 = 800 > 行区 208） */
    public function testDragPastBottomEdgeAutoScrolls(): void
    {
        [$app] = self::drag(20);

        $app->dispatchInput(PointerEvent::down(150.0, self::y(1)), 1000.0);
        $app->dispatchInput(PointerEvent::move(150.0, self::y(2)), 1050.0);   // 第一步只"进拖"，不滚
        $this->tick($app);
        $before = Fixtures::stateOf($app->root())?->offsetY();

        $app->dispatchInput(PointerEvent::move(150.0, 250.0), 1100.0);         // 行区底 240 之外
        $app->dispatchInput(PointerEvent::move(150.0, 252.0), 1150.0);
        $this->tick($app);
        $after = Fixtures::stateOf($app->root())?->offsetY();

        check(
            'Table · 拖动框选：拖到行区下边界外 ⇒ 自动往下滚（半行一步，move 驱动）',
            $before === 0.0 && $after !== null && $after > 0.0,
            \sprintf('拖前 %s / 拖后 %s', (string) $before, (string) $after),
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

    /** 行容器的底色（`null` = 没底色 / 该行没造出来） */
    private static function rowBgOf(Element $root, int $index): ?Color
    {
        $element = findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof SizedBox
                && $e->widget()->identity()?->value === $index,
        );

        return $element?->widget()->mods()->fillColor();
    }
}
