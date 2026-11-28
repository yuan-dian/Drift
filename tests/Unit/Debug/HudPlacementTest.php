<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\DevTools\Inspect\Hud;
use Drift\Geometry\Rect;
use PHPUnit\Framework\TestCase;

/**
 * HUD 落位判据：不许挡住正在检查的那个盒。
 *
 * 不钉"某个目标必须换到某个角"（那是把像素坐标当契约），只钉不变量：
 * 选出来的位置，任何角落都不比它挡得更少。退化成"永远右下"时这条必红。
 */
final class HudPlacementTest extends TestCase
{
    private const float MARGIN = 12.0;

    private static function viewport(): Rect
    {
        return new Rect(0.0, 0.0, 800.0, 600.0);
    }

    private static function overlap(Rect $a, Rect $b): float
    {
        $hit = $a->intersect($b);

        return $hit->isEmpty() ? 0.0 : $hit->width * $hit->height;
    }

    /** 没有选中 / 没有盒时沿用右下，不因为"不知道目标在哪"而乱跳。 */
    public function testFallsBackToBottomRight(): void
    {
        $panel = Hud::place(self::viewport(), 200.0, 100.0, null);

        self::assertSame(800.0 - 200.0 - self::MARGIN, $panel->left);
        self::assertSame(600.0 - 100.0 - self::MARGIN, $panel->top);
    }

    /** 目标就在右下角时，必须真的挪开。 */
    public function testMovesAwayFromTheCornerItWouldCover(): void
    {
        $target = new Rect(600.0, 500.0, 150.0, 90.0);
        $panel = Hud::place(self::viewport(), 200.0, 100.0, $target);

        self::assertSame(0.0, self::overlap($panel, $target), '换了角还是压着目标');
    }

    /** 四角全撞（目标铺满或极大）时取重叠最小者 —— 挡不住全部，就挡得最少。 */
    public function testPicksTheLeastOccludingCorner(): void
    {
        $w = 200.0;
        $h = 100.0;
        $right = 800.0 - $w - self::MARGIN;
        $bottom = 600.0 - $h - self::MARGIN;
        $corners = [[$right, $bottom], [$right, self::MARGIN], [self::MARGIN, $bottom], [self::MARGIN, self::MARGIN]];
        $targets = [
            '铺满视口' => new Rect(0.0, 0.0, 800.0, 600.0),
            '压在右下且极宽' => new Rect(0.0, 400.0, 800.0, 200.0),
            '右半幅' => new Rect(400.0, 0.0, 400.0, 600.0),
            '偏右下的大块' => new Rect(300.0, 300.0, 500.0, 300.0),
        ];

        foreach ($targets as $case => $target) {
            $chosen = self::overlap(Hud::place(self::viewport(), $w, $h, $target), $target);
            foreach ($corners as [$x, $y]) {
                self::assertGreaterThanOrEqual(
                    $chosen,
                    self::overlap(new Rect($x, $y, $w, $h), $target),
                    $case . '：有角落比选中的位置挡得更少',
                );
            }
        }
    }

    /** 视口比面板还小时贴角，绝不给出负坐标 —— 硬件裁剪下整块会消失。 */
    public function testNeverLeavesATinyViewport(): void
    {
        $panel = Hud::place(new Rect(0.0, 0.0, 100.0, 60.0), 300.0, 200.0, null);

        self::assertSame(self::MARGIN, $panel->left);
        self::assertSame(self::MARGIN, $panel->top);
    }

    /**
     * `place()` 必须真的被 `paint()` 用上。
     *
     * 上面几条只钉几何；若绘制路径还按老办法贴右下，它们照样全绿 —— 孤儿函数
     * 是这类改动最容易留下的假绿。
     */
    public function testPaintUsesThePlacement(): void
    {
        $file = (new \ReflectionClass(Hud::class))->getFileName();
        self::assertIsString($file);

        self::assertStringContainsString('self::place($viewport', (string) \file_get_contents($file));
    }
}
