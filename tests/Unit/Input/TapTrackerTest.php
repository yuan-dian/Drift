<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Input;

use Drift\Input\PointerEvent;
use Drift\Input\TapTracker;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * 点击链：四条规则（间隔 / 位移 / 按键 / 修饰键）+ 三个断链边界。
 *
 * ★ 全部用**显式时间戳**驱动，一秒不睡：判据落在"第几下"上，不落在"睡多久之后
 *   是什么"上 —— 后者在 CI 里必然抖。
 * ★ 基准链统一是 (10,10)@1000ms，第二下只差一个变量。这样每条用例只回答一个
 *   问题："改了这一个东西，链还连不连得上"。
 *
 * ★ 反例自检：`chains()` 去掉 `$elapsed >= 0.0` ⇒ "时间倒退"那条红（负间隔恒小于
 *   阈值，任意两次点击都被串成连击）；比较 `ctrl` 那行去掉 ⇒ "按了 Ctrl"红
 *   （多选时点两下就突然进编辑）。
 */
final class TapTrackerTest extends TestCase
{
    private const BASE_MS = 1000.0;

    public function testSecondTapWithinIntervalAndSlopIsDoubleClick(): void
    {
        $taps = new TapTracker();

        $first = $taps->annotate(self::tap(10.0, 10.0), self::BASE_MS);
        // 手抖 1px / 2px —— 真机上每次点击都会抖，这不是"另一次点击"
        $second = $taps->annotate(self::tap(11.0, 12.0), self::BASE_MS + 200.0);

        self::assertSame(1, $first->clickCount);
        self::assertSame(2, $second->clickCount);
        self::assertTrue($second->isDoubleClick());
    }

    /**
     * @return iterable<string, array{0: PointerEvent, 1: float, 2: string}>
     */
    public static function brokenChains(): iterable
    {
        yield '间隔 600ms（窗口 500）' => [
            self::tap(10.0, 10.0),
            self::BASE_MS + 600.0,
            '慢速双击必须当成两次单击（否则编辑器反复开关）',
        ];
        yield '位移 20px（slop 4）' => [
            self::tap(30.0, 10.0),
            self::BASE_MS + 100.0,
            '手抖几像素仍算双击，跨格不算',
        ];
        yield '换了按键' => [
            self::tap(10.0, 10.0, 2),
            self::BASE_MS + 100.0,
            '右键链不与左键链串成双击',
        ];
        yield '按了 Ctrl' => [
            self::tap(10.0, 10.0, 0, true),
            self::BASE_MS + 100.0,
            'Ctrl+点两次不许当双击 —— 多选时会突然进编辑',
        ];
        yield '时间倒退' => [
            self::tap(10.0, 10.0),
            self::BASE_MS - 100.0,
            '负间隔不许恒小于阈值（注入假钟 / 校时会走到这里）',
        ];
    }

    #[DataProvider('brokenChains')]
    public function testChainBreaks(PointerEvent $second, float $at, string $why): void
    {
        $taps = new TapTracker();
        $taps->annotate(self::tap(10.0, 10.0), self::BASE_MS);

        self::assertSame(1, $taps->annotate($second, $at)->clickCount, $why);
    }

    public function testLeaveBreaksTheChain(): void
    {
        $taps = new TapTracker();
        $taps->annotate(self::tap(10.0, 10.0), self::BASE_MS);
        $taps->annotate(PointerEvent::leave(10.0, 10.0), self::BASE_MS + 50.0);

        // 离开客户区再回来 ≠ 连击（Windows 同款）
        self::assertSame(1, $taps->annotate(self::tap(10.0, 10.0), self::BASE_MS + 100.0)->clickCount);
    }

    public function testTripleTapCountsThreeAndIsNotDoubleClick(): void
    {
        $taps = new TapTracker();
        $taps->annotate(self::tap(10.0, 10.0), self::BASE_MS);
        $taps->annotate(self::tap(10.0, 10.0), self::BASE_MS + 100.0);
        $third = $taps->annotate(self::tap(10.0, 10.0), self::BASE_MS + 200.0);

        self::assertSame(3, $third->clickCount);
        // ★ "第三下"不是"双击"：`isDoubleClick()` 只认 2，否则全选/选段会退化成进编辑
        self::assertFalse($third->isDoubleClick());
    }

    public function testOnlyDownIsAnnotated(): void
    {
        $taps = new TapTracker();
        $taps->annotate(self::tap(10.0, 10.0), self::BASE_MS);

        $move = $taps->annotate(PointerEvent::move(10.0, 10.0), self::BASE_MS + 50.0);
        $up = $taps->annotate(PointerEvent::up(10.0, 10.0), self::BASE_MS + 60.0);

        // 原样返回（不标注）；链也应停在 1 —— 没推进，也就没"多出来的一下"
        self::assertSame(1, $move->clickCount);
        self::assertSame(1, $up->clickCount);
        self::assertSame(1, $taps->count());
    }

    private static function tap(float $x, float $y, int $button = 0, bool $ctrl = false): PointerEvent
    {
        return PointerEvent::down($x, $y, $button, $ctrl);
    }
}
