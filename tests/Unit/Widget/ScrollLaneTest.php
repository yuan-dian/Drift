<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\ScrollBarFade;
use Drift\Widget\ScrollLane;

/**
 * `ScrollLane`：一根轴的滚动状态机（三个 `Scrollable` 实现共用一份）。
 *
 * ## 为什么要有这个类（以及这个测试）
 *
 * `ScrollViewState` / `LazyListState` / `TableState` 的"偏移怎么存、夹在哪、
 * 动画怎么收口、条什么时候亮"原先各写了一遍。代价不是行数，是**同一条契约要改
 * 三处**：`b7257bf`（滚动不重建）与 `58305af`（滚轮平滑）两次提交都必须同时改
 * 三个 State，而漏掉一处**不报错**：
 *   - 漏"回写盒夹过的值" ⇒ 真机"拖着条，画面不动"（死区）；
 *   - 漏"到边界收口" ⇒ 每帧白跑一次全量 paint（实测 81 帧 vs 190 帧）；
 *   - 漏"先亮条后推盒" ⇒ 滚了但条没反应 / 淡出抖动。
 *
 * ## 判据怎么落
 *
 * ① **行为**：夹下界 0、回写盒夹过的值、边界早退不亮条、动画累加目标、
 *    到边界就地收口、注入的 fade 是同一个对象。
 * ② **结构**（`testScrollableStatesDoNotOwnTheMachinery`）：任何 `implements
 *    Scrollable` 的 State 都不许自己声明 `ScrollMotion` / `ScrollBarFade` 字段。
 *    ——这条是"下次再抄第四份"的闸，比"记得别抄"可靠。
 *
 * ★ ② 必须有**量具自证**（`testTheGaugeSeesAllScrollableStates`）：路径写错 /
 *   正则写坏时"0 个实现者"会让上一条恒真（`MEMORY.md` 的假绿清单第 3 类）。
 */
final class ScrollLaneTest extends SmokeTestCase
{
    /** 推给盒的"原样返回"（= 盒不在 / 不做夹取） */
    private static function identity(float $value): float
    {
        return $value;
    }

    public function testMoveClampsLowerBoundAndWritesBackBoxValue(): void
    {
        $lane = new ScrollLane();

        check(
            'ScrollLane · 已在起点再往回滚 ⇒ 返回 false（不推、不重排，省一帧）',
            $lane->move(-10.0, self::identity(...)) === false && $lane->offset() === 0.0,
            'o=' . $lane->offset(),
        );

        check(
            'ScrollLane · 正向滚 ⇒ 返回 true 且偏移到位',
            $lane->move(10.0, self::identity(...)) === true && $lane->offset() === 10.0,
            'o=' . $lane->offset(),
        );

        // 盒把 30 → 40 夹回去（内容比目标短）：**必须回写盒的值**
        $clamped = $lane->move(20.0, static fn(float $value): float => 40.0);
        check(
            'ScrollLane · 回写**盒夹过的值**（不是增量累加值）—— 否则之后每笔增量先消耗死区',
            $clamped === true && $lane->offset() === 40.0,
            'o=' . $lane->offset(),
        );

        $lane->set(7.5);
        check(
            'ScrollLane · `set()` 不夹取（播种 / 锚点还原 / build 回夹三个入口共用）',
            $lane->offset() === 7.5,
            'o=' . $lane->offset(),
        );

        $this->flushChecks();
    }

    public function testGlideAccumulatesTargetAcrossConsecutiveWheels(): void
    {
        $lane = new ScrollLane();

        // 连续两格滚轮（第二格在动画还没跑之前）⇒ 目标必须累加，不是"从当前位置重来"
        $lane->glide(48.0);
        $lane->glide(48.0);

        // ★ 无条件推满 150 帧（2.4s）：`AnimatedScalar` 首帧只记起点 ⇒ 第一帧
        //   `tick()` 返回 false。真机上那几帧靠 `ScrollBarFade` 的 900ms 上膛撑着，
        //   在测试里没有帧时钟 ⇒ 不能拿"返回 false"当收口判据（会 0 帧就退出）。
        $ts = 0.0;
        for ($i = 1; $i <= 150; $i++) {
            $ts = $i * 16.0;
            $lane->tick($ts, static function (float $delta) use ($lane): void {
                $lane->move($delta, self::identity(...));
            });
        }

        check(
            'ScrollLane · 连续两格滚轮 ⇒ 目标累加（≈96，不是 48；错实现给 48 或 69.79）',
            abs($lane->offset() - 96.0) < 0.5,
            'o=' . \sprintf('%.6g', $lane->offset()),
        );
        check(
            'ScrollLane · 推满 150 帧后已收口（不是永远差 0.01）',
            $lane->tick($ts + 16.0, static function (float $delta) use ($lane): void {
                $lane->move($delta, self::identity(...));
            }) === false,
            'o=' . \sprintf('%.6g', $lane->offset()),
        );

        $this->flushChecks();
    }

    public function testTickSnapsToBoundaryInsteadOfGrinding(): void
    {
        // 盒永远把值夹回 40（= 已经滚到内容末端）
        $lane = new ScrollLane(40.0);
        $lane->glide(48.0);

        // 数"第一次真的动过之后，还要几帧才落闸"（首帧 `tick()` 恒 false，不算）
        $moved = 0;
        $after = -1;
        for ($i = 1; $i <= 400; $i++) {
            $busy = $lane->tick($i * 16.0, static function (float $delta) use ($lane): void {
                $lane->move($delta, static fn(float $value): float => 40.0);
            });

            if ($busy) {
                ++$moved;

                continue;
            }

            if ($moved > 0) {
                $after = $moved;

                break;
            }
        }

        // 不收口的话 motion 会朝一个**到不了**的目标指数衰减（实测 190 帧）
        check(
            'ScrollLane · 到边界**就地收口**（动过之后 ≤ 3 帧落闸；不收口要转一百多帧，每帧一次全量 paint）',
            $after !== -1 && $after <= 3,
            '动过 ' . $moved . ' 帧，落闸前共 ' . $after . ' 帧',
        );
        check(
            'ScrollLane · 收口后偏移停在边界值上',
            $lane->offset() === 40.0,
            'o=' . \sprintf('%.6g', $lane->offset()),
        );

        $this->flushChecks();
    }

    public function testInjectedFadeIsShared(): void
    {
        $fade = new ScrollBarFade();
        $y = new ScrollLane(0.0, $fade);
        $x = new ScrollLane(0.0, $fade);

        check(
            'ScrollLane · 注入同一份 fade ⇒ 两根轴共用（表的横竖条同时淡出，不该一根亮一根暗）',
            $y->fade() === $x->fade() && $y->fade() === $fade,
        );

        $y->move(5.0, self::identity(...));
        check(
            'ScrollLane · 一根轴滚过 ⇒ 共用的那份条也亮（不是各记各的）',
            $x->fade()->opacity() === 1.0,
            'o=' . $x->fade()->opacity(),
        );

        $solo = new ScrollLane();
        check(
            'ScrollLane · 不注入 ⇒ 自建一份（单轴容器不需要知道 fade 的存在）',
            $solo->fade() !== $fade && $solo->fade()->opacity() === 1.0,
        );

        $this->flushChecks();
    }

    /**
     * 结构性闸门：`implements Scrollable` 的 State **不许**自己持动画节律。
     *
     * ★ 这条防的是"下次再抄第四份" —— 抄出来不会报错，只会让同一条契约回到
     *   "改三处"的年代（见类注释里的两个提交）。
     * ★ 判据只针对 `ScrollMotion` 与**直接调状态机**，不针对 `ScrollBarFade` 字段：
     *   淡出是**跨轴共享**的资源（表格两根轴必须看到同一份，否则一根亮一根暗），
     *   它的所有者只能是 State；而动画节律是**每轴一份**，它属于 lane。
     *   这不是白名单 —— 是两类东西的所有权本来就不同。
     * ★ 判据落在**代码**上（跳过 docblock）：注释里提到类名不算违规，否则
     *   每个解释接口的文档都得改写。
     */
    public function testScrollableStatesDoNotOwnTheMachinery(): void
    {
        $bad = [];

        foreach (self::scrollableStates() as $file => $src) {
            $code = self::codeOnly($src);

            if (\str_contains($code, 'ScrollMotion')) {
                $bad[] = \basename($file) . '（持/造 ScrollMotion）';

                continue;
            }

            // 直接推状态机（绕开 lane）—— 夹取、收口、亮条就不在一条路上了
            if (\preg_match('/->(noteScroll|snapTo|glideBy)\(/', $code) === 1) {
                $bad[] = \basename($file) . '（直接调 noteScroll/snapTo/glideBy）';
            }
        }

        \sort($bad);

        check(
            'ScrollLane · `implements Scrollable` 的 State 不碰动画节律'
            . '（三个实现共用 `ScrollLane`，别再抄第四份）',
            $bad === [],
            $bad === [] ? '' : '仍自持：' . \implode(', ', $bad),
        );

        $this->flushChecks();
    }

    /**
     * 量具自证：扫描真的找到了四个 `Scrollable` 实现。
     *
     * ★ 没有这条，`testScrollableStatesDoNotOwnTheMachinery` 在"路径写错 / 正则
     *   写坏 / 目录改名"时**恒绿**（0 个文件当然没有违规）。
     * ★ 实现增加时**这个数字要跟着改**（`TextAreaState` 是 2026-11-03 加的第四个）——
     *   量具自证本身就要求"数字是人对过的"，自动跟随就失去了自证的意义。
     */
    public function testTheGaugeSeesAllScrollableStates(): void
    {
        $found = \array_keys(self::scrollableStates());

        check(
            'ScrollLane · 量具自证：src 里确实有 4 个 `implements Scrollable` 的 State'
            . '（扫描没写坏）',
            \count($found) === 4,
            '实际 ' . \count($found) . ' 个：' . \implode(', ', \array_map('\basename', $found)),
        );

        $this->flushChecks();
    }

    /**
     * 扫 `src/Widget/*.php`，返回"声明了 `implements ... Scrollable`"的文件 => 源码。
     *
     * ★ 只看**类声明行**（`class X ... implements ... Scrollable`），不看
     *   docblock 里提到接口名的文字 —— 否则每个讲接口的注释都会被算成实现者。
     *
     * @return array<string, string>
     */
    private static function scrollableStates(): array
    {
        $out = [];

        foreach (\glob(repoRoot() . '/src/Widget/*.php') ?: [] as $file) {
            $src = (string) \file_get_contents($file);
            if (\preg_match('/^\s*(?:final\s+)?class\s+\w+[^\n{]*\bimplements\b[^\n{]*\bScrollable\b/m', $src) !== 1) {
                continue;
            }

            $out[$file] = $src;
        }

        return $out;
    }

    /**
     * 去掉注释行后的源码（只留真代码）。
     *
     * ★ 判据必须落在**代码**上：三个 State 的 docblock 大量解释"`ScrollMotion` 怎么
     *   收口""`noteScroll` 什么时候亮"，把注释也算进来就是误报（`LoudFailurePath`
     *   的 `Errors::` 扫描踩过同一个坑）。
     */
    private static function codeOnly(string $src): string
    {
        $keep = [];

        foreach (\explode("\n", $src) as $line) {
            $trim = \ltrim($line);
            if (\str_starts_with($trim, '*')
                || \str_starts_with($trim, '/*')
                || \str_starts_with($trim, '//')
                || \str_starts_with($trim, '#')) {
                continue;
            }

            $keep[] = $line;
        }

        return \implode("\n", $keep);
    }
}
