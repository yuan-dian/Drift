<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Core;

use Drift\Core\BlackBox;
use PHPUnit\Framework\TestCase;

/**
 * 常驻黑匣子的形状与账。
 *
 * 这一组只判"写进去的东西能不能被正确读回来"；"有没有人在正确时机写"
 * 由 Feature 组的 Pipeline / Frame 判据负责——两组合起来才回答得了
 * "崩溃时能看到什么"。
 */
final class BlackBoxTest extends TestCase
{
    private string $dir = '';

    protected function setUp(): void
    {
        $this->dir = \sys_get_temp_dir() . '/drift-blackbox-' . \bin2hex(\random_bytes(6));
        \mkdir($this->dir, 0700, true);
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: [] as $file) {
            @\unlink($file);
        }
        @\rmdir($this->dir);
        parent::tearDown();
    }

    public function testRingReportsEvictionInsteadOfSilentlyDropping(): void
    {
        $box = new BlackBox(3);
        for ($i = 1; $i <= 5; ++$i) {
            $box->record($i, (float) $i, 0, 0, 0, false);
        }

        $snapshot = $box->snapshot();

        self::assertSame(3, $snapshot['capacity']);
        self::assertSame(5, $snapshot['total']);
        self::assertSame(2, $snapshot['dropped']);
        self::assertSame([3, 4, 5], \array_column($snapshot['frames'], 'frame'));
    }

    public function testSlowestFrameSurvivesEviction(): void
    {
        $box = new BlackBox(2);
        $box->record(1, 99.0, 0, 0, 0, false);
        $box->record(2, 1.0, 0, 0, 0, false);
        $box->record(3, 2.0, 0, 0, 0, false);

        $snapshot = $box->snapshot();

        self::assertSame([2, 3], \array_column($snapshot['frames'], 'frame'));
        self::assertSame(['frame' => 1, 'cost' => 99.0], $snapshot['worst']);
    }

    public function testFailureKeepsTheFirstSiteAndPointsAtCurrentFrame(): void
    {
        $box = new BlackBox(8);
        $box->record(1, 1.0, 0, 0, 0, false);
        $box->noteFailure('settle', '第一现场');
        $box->noteFailure('paint', '连锁反应');

        $failure = $box->snapshot()['failure'];

        self::assertSame('settle', $failure['stage']);
        self::assertSame('第一现场', $failure['message']);
        self::assertSame(2, $failure['frame']);
    }

    public function testRecoverableBreadcrumbsNeverTakeTheFailureSlot(): void
    {
        $box = new BlackBox(8);
        $box->noteRecoverable('先来的告警');
        $box->record(1, 1.0, 0, 0, 0, false);
        $box->noteFailure('settle', '真现场');

        $snapshot = $box->snapshot();

        self::assertSame(3, $snapshot['protocol']);
        self::assertSame('settle', $snapshot['failure']['stage']);
        self::assertSame('真现场', $snapshot['failure']['message']);
        self::assertSame([['frame' => 1, 'message' => '先来的告警']], $snapshot['recoverables']['items']);
    }

    public function testRecoverableRingReportsEviction(): void
    {
        $box = new BlackBox(8);
        for ($i = 1; $i <= 18; ++$i) {
            $box->noteRecoverable('告警 ' . $i);
        }

        $ring = $box->snapshot()['recoverables'];

        self::assertSame(16, $ring['capacity']);
        self::assertSame(18, $ring['total']);
        self::assertSame(2, $ring['dropped']);
        self::assertSame(['告警 3', '告警 18'], [
            array_column($ring['items'], 'message')[0],
            array_column($ring['items'], 'message')[15],
        ]);
    }

    public function testRecordCarriesSkippedAndStageScalars(): void
    {
        $box = new BlackBox(2);
        $box->record(7, 12.5, 11, 22, 33, true);

        $row = $box->snapshot()['frames'][0];

        self::assertSame(7, $row['frame']);
        self::assertSame(12.5, $row['cost']);
        self::assertSame(['settle' => 11, 'layout' => 22, 'paint' => 33], [
            'settle' => $row['settle'],
            'layout' => $row['layout'],
            'paint' => $row['paint'],
        ]);
        self::assertTrue($row['skipped']);
    }

    public function testPeriodicFlushLeavesAnAtomicSidecarForNativeCrash(): void
    {
        $file = $this->dir . '/blackbox.json';
        $box = new BlackBox(64, $file);

        for ($i = 1; $i < BlackBox::FLUSH_EVERY_FRAMES; ++$i) {
            $box->record($i, 1.0, 0, 0, 0, false);
        }
        self::assertFileDoesNotExist($file);

        $box->record(BlackBox::FLUSH_EVERY_FRAMES, 1.0, 0, 0, 0, false);

        self::assertFileExists($file);
        $raw = file_get_contents($file);
        self::assertIsString($raw);
        self::assertSame(BlackBox::FLUSH_EVERY_FRAMES, json_decode($raw, true)['lastFlushFrame']);
        self::assertSame([], glob($file . '.tmp-*') ?: []);
    }

    public function testFailedFlushDoesNotLieAboutLastFlushFrame(): void
    {
        $target = $this->dir . '/blackbox.json';
        mkdir($target);   // rename 到目录必然失败，且不依赖权限位
        try {
            $box = new BlackBox(8, $target);
            $box->noteRecoverable('告警');

            self::assertSame(0, $box->snapshot()['lastFlushFrame']);
        } finally {
            @rmdir($target);
        }
    }

    public function testFlushWritesReadableJsonAndNeverThrowsOnUnwritablePath(): void
    {
        $file = $this->dir . '/blackbox.json';
        $box = new BlackBox(4, $file);
        $box->record(1, 3.5, 0, 0, 0, false);
        $box->noteFailure('layout', '崩了');
        $box->noteRecoverable('之前还有一条告警');

        $raw = \file_get_contents($file);
        self::assertIsString($raw);
        self::assertSame($box->snapshot(), \json_decode($raw, true));

        // 落盘失败绝不能变成第二种失败：G2 的中止序列后面没有 catch。
        $bad = new BlackBox(2, $this->dir . '/missing/deep/box.json');
        @\chmod($this->dir, 0500);
        try {
            $bad->flush();
            self::assertTrue(true);
        } finally {
            @\chmod($this->dir, 0700);
        }
    }
}
