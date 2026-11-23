<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Platform;

use Drift\Geometry\Rect;
use Drift\Input\CursorShape;
use Drift\Platform\X11\X11WindowHost;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

use function x11CursorCalls;
use function x11DragCalls;
use function x11FakeReset;
use function x11FramelessCalls;
use function x11MinSizeCalls;
use function x11PlaceCalls;

/**
 * X11 窗口端口把意图翻成哪个桥 ABI 的判据。
 * ★ 不构造真窗口：只验逻辑↔物理折算是否走 X11Dpr、光标 value 是否原样下传。
 *   真机效果（光标形状 / 无边框 / 拖拽）由 WSL 门在 native 层验。
 */
final class X11WindowHostTest extends TestCase
{
    protected function setUp(): void
    {
        x11FakeReset();
    }

    #[Test]
    public function testCursorPassesEnumValue(): void
    {
        $host = new X11WindowHost();
        $host->setCursor(11, CursorShape::IBeam);
        $host->setCursor(11, CursorShape::NotAllowed);

        self::assertSame([[11, 1], [11, 7]], x11CursorCalls());
    }

    #[Test]
    public function testFramelessQuantityIsLogicalTimesDpr(): void
    {
        $GLOBALS['DRIFT_FAKE_X11_DPI'] = 120.0;   // 1.25×
        try {
            $host = new X11WindowHost();
            $host->setFrameless(9, 40);           // 40 逻辑 ⇒ 50 物理
            self::assertSame([[9, 50]], x11FramelessCalls());
        } finally {
            unset($GLOBALS['DRIFT_FAKE_X11_DPI']);
        }
    }

    #[Test]
    public function testBeginDragPassesHandle(): void
    {
        $host = new X11WindowHost();
        $host->beginDrag(6);

        self::assertSame([6], x11DragCalls());
    }

    #[Test]
    public function testMinSizeFoldsLogicalToPhysical(): void
    {
        $GLOBALS['DRIFT_FAKE_X11_DPI'] = 120.0;   // 1.25×
        try {
            $host = new X11WindowHost();
            $host->setMinSize(3, 100, 200);        // 100×1.25=125, 200×1.25=250
            self::assertSame([[3, 125, 250]], x11MinSizeCalls());
        } finally {
            unset($GLOBALS['DRIFT_FAKE_X11_DPI']);
        }
    }

    #[Test]
    public function testPlaceFoldsLogicalToPhysical(): void
    {
        $GLOBALS['DRIFT_FAKE_X11_DPI'] = 144.0;   // 1.5×
        try {
            $host = new X11WindowHost();
            $host->place(3, new Rect(10.0, 20.0, 100.0, 200.0), false);
            self::assertSame([[3, 15.0, 30.0, 150.0, 300.0, false]], x11PlaceCalls());
        } finally {
            unset($GLOBALS['DRIFT_FAKE_X11_DPI']);
        }
    }

    #[Test]
    public function testClientSizeUnfoldsPhysicalToLogical(): void
    {
        $GLOBALS['DRIFT_FAKE_X11_DPI'] = 144.0;   // 1.5×
        $GLOBALS['DRIFT_FAKE_X11_W'] = 900;
        $GLOBALS['DRIFT_FAKE_X11_H'] = 600;
        try {
            $host = new X11WindowHost();
            $size = $host->clientSize(3);
            self::assertSame(600.0, $size->width);
            self::assertSame(400.0, $size->height);
        } finally {
            x11FakeReset();
        }
    }
}
