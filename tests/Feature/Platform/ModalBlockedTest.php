<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Platform;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Platform\Win32\Win32Platform;

/**
 * **原生模态记账**（2026-10-30）：`Platform::consumeModalBlockedMs()` 的契约。
 *
 * ## 为什么这一组存在（真机事故）
 *
 * 用户在原生对话框里停留 8.3s ⇒ 下一帧的"两帧间隔"= 8344ms ⇒ RECOVERABLE 弹框。
 * 模态不是缺陷，但帧时钟的判据只看间隔 —— 所以**平台必须把模态时间记出来**，
 * `FrameClock::advance()` 才能剔除（剔除那半边钉在 `FrameWaitTest` 里）。
 *
 * ## 这一组钉两条
 *
 * ① **账本记的是"进桥到出桥"**：`fatalBox()` 的 `MessageBoxW` 是原生模态，
 *    用户停留多久就记多久 —— 让假桥真的停留 40ms，断言账本 ≥ 10ms。
 *    ★ 没有"停留"的假桥这条**验不了**（hrtime 差值小到无法断言，">= 0"恒真）。
 *    ★ 40ms 不是随意放大：CI 的 PHP 8.4 上 15ms 睡眠只量到 7.09ms（时钟粒度），
 *      留 4 倍余量才能跨 PHP 版本稳定，又不至于拖慢测试。
 * ② **读后即清**：同一笔时间不许被剔除两次（清不掉就会把真卡顿也剔没）。
 */
final class ModalBlockedTest extends SmokeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        winLoopReset();
        winMessageBoxLatencyFake(0.0);
    }

    protected function tearDown(): void
    {
        winMessageBoxLatencyFake(0.0);
        winLoopReset();
        parent::tearDown();
    }

    public function testFatalBoxTimeIsAccountedAndReset(): void
    {
        winMessageBoxLatencyFake(40.0);   // 用户在框里停留了 40ms（跨 PHP 版本留足量测余量）
        $platform = new Win32Platform();

        $platform->fatalBox('标题', '正文');

        $first = $platform->consumeModalBlockedMs();
        $second = $platform->consumeModalBlockedMs();

        check(
            '模态记账 · `fatalBox()` 的停留时长进了账本（≥ 10ms，不是恒真的 >= 0）',
            $first >= 10.0,
            \sprintf('first=%.2fms', $first),
        );
        check(
            '模态记账 · **读后即清**（同一笔不许被剔除两次）',
            $second === 0.0,
            \sprintf('second=%.2fms', $second),
        );
        check(
            '模态记账 · 调用本身发生过（不是假桥没被调到的假绿）',
            winMessageBoxCalls() === [['标题', '正文']],
            \json_encode(winMessageBoxCalls()),
        );

        $this->flushChecks();
    }

    public function testNoModalMeansZero(): void
    {
        $platform = new Win32Platform();

        check(
            '模态记账 · 没开过模态 ⇒ `0.0`（EventLoop 每轮都问，空账零成本）',
            $platform->consumeModalBlockedMs() === 0.0,
            \sprintf('%.2f', $platform->consumeModalBlockedMs()),
        );

        $this->flushChecks();
    }
}
