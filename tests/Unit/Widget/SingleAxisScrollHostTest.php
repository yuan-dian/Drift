<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\LazyFixtures;
use Drift\Dev\Tests\Support\ScrollFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Scrollable;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextArea;

/**
 * `SingleAxisScrollHost`：三个单轴滚动 State 的 `lane()` / `tickFrame()` 单点。
 *
 * ## 判据怎么落
 *
 * ① **行为**：对每个宿主（ScrollView / LazyList / TextArea）走同一条链 ——
 *   `glideByAxis()` 点亮滚动 → 推若干 `tickFrame()` → 偏移必须离开 0。
 *   这同时证明 trait 的 `lane()` 懒建用的是**各宿主自己的** `$lane` 字段、
 *   `tickFrame()` 把 tick 的位移回流到宿主的 `scrollBy()`（各自盒类型不同）。
 * ② **结构**：三个类不再各自声明 `lane()` / `tickFrame()` 方法体（否则第四份
 *   手抄会静默回来），但**仍各自声明** `private ?ScrollLane $lane`（trait 读写它）。
 *   附量具自证：扫描确实找到三个宿主，防止路径写坏时空跑恒绿。
 *
 * ★ ② 判据落在**代码**上（跳过 docblock）：注释里出现方法名不算违规。
 */
final class SingleAxisScrollHostTest extends SmokeTestCase
{
    /** 挂一棵够长的 TextArea（内容远超视口）并取 State */
    private static function textAreaState(): ?Scrollable
    {
        $value = \str_repeat(\str_repeat('中', 40) . "\n", 12);
        $root = TextArea::make()->value($value)->minLines(3)->createElement();
        $root->mount(null, \smokeContext());
        $state = $root instanceof StatefulElement ? $root->state() : null;
        $root->settle();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(240.0, 200.0)));

        return $state instanceof Scrollable ? $state : null;
    }

    /** 三个宿主各造一个可滚实例 + 取 State */
    private static function hosts(): array
    {
        return [
            'ScrollView' => [ScrollFixtures::stateOf(ScrollFixtures::vertical()), Axis::Vertical],
            'LazyList' => [LazyFixtures::stateOf(LazyFixtures::mount(LazyFixtures::column(5000, 56.0))), Axis::Vertical],
            'TextArea' => [self::textAreaState(), Axis::Vertical],
        ];
    }

    public function testEachHostAdvancesOffsetThroughTraitTickFrame(): void
    {
        foreach (self::hosts() as $name => [$state, $axis]) {
            check(
                "$name · 取到 State 且实现 Scrollable",
                $state instanceof Scrollable,
                $state === null ? 'null' : \get_class($state),
            );
            if (!$state instanceof Scrollable) {
                continue;
            }

            $state->glideByAxis($axis, 48.0);
            $t = 0.0;
            for ($i = 0; $i < 12; $i++) {
                $t += 16.0;
                $state->tickFrame($t);
            }

            // TextArea 不实现 ScrollMetrics（无 offset()）⇒ 统一读 trait 驱动的 lane 字段。
            $lane = (new \ReflectionProperty($state, 'lane'));
            $lane = $lane->getValue($state);
            $offset = $lane !== null ? $lane->offset() : -1.0;
            check(
                "$name · glide 后推 12 帧 ⇒ lane 偏移离开 0（trait 的 lane/tickFrame 真的驱动了这台宿主）",
                $offset > 0.0,
                'offset=' . $offset,
            );
        }

        $this->flushChecks();
    }

    public function testHostsShareTraitAndNoLongerOwnTheMethods(): void
    {
        $bad = [];

        foreach (self::hostFiles() as $file => $src) {
            $code = self::codeOnly($src);
            $short = \basename($file);

            if (!\preg_match('/^[ \t]*use[ \t]+SingleAxisScrollHost[ \t]*;/m', $code)) {
                $bad[] = $short . '（未 use trait）';
            }
            if (\preg_match('/function[ \t]+(lane|tickFrame)\s*\(/', $code) === 1) {
                $bad[] = $short . '（仍自持 lane()/tickFrame()）';
            }
            if (\preg_match('/private\s+\?ScrollLane\s+\$lane\b/', $code) !== 1) {
                $bad[] = $short . '（丢了 $lane 字段：trait 读写它）';
            }
        }

        \sort($bad);
        check(
            '三宿主共用 SingleAxisScrollHost：不各自实现 lane/tickFrame，但各自保留 $lane 字段',
            $bad === [],
            $bad === [] ? '' : \implode(', ', $bad),
        );

        $this->flushChecks();
    }

    /** 量具自证：扫描确实命中三个宿主，防止路径写坏时上一条恒绿 */
    public function testGaugeSeesAllThreeHosts(): void
    {
        $found = \array_map('\basename', \array_keys(self::hostFiles()));
        \sort($found);

        check(
            '量具自证：确实找到 3 个单轴滚动宿主（扫描没写坏）',
            $found === ['LazyListState.php', 'ScrollViewState.php', 'TextAreaState.php'],
            \implode(', ', $found),
        );

        $this->flushChecks();
    }

    /** @return array<string, string> */
    private static function hostFiles(): array
    {
        $out = [];
        $root = \repoRoot() . '/src/Widget/';

        foreach (['ScrollViewState.php', 'LazyListState.php', 'TextAreaState.php'] as $name) {
            $file = $root . $name;
            if (\is_file($file)) {
                $out[$file] = (string) \file_get_contents($file);
            }
        }

        return $out;
    }

    private static function codeOnly(string $src): string
    {
        $keep = [];
        foreach (\explode("\n", $src) as $line) {
            $trim = \ltrim($line);
            if (\str_starts_with($trim, '*') || \str_starts_with($trim, '/*')
                || \str_starts_with($trim, '//') || \str_starts_with($trim, '#')) {
                continue;
            }
            $keep[] = $line;
        }

        return \implode("\n", $keep);
    }
}
