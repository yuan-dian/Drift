<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Platform;

use Drift\Platform\X11\X11Dpr;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * X11 DPR 换算判据：只测纯函数，与 Win32 Dpr 同形。
 * 真机 Xft.dpi → scale() 的链路由 WSL Xvfb 门在 [THEME]/[WM] 里验。
 */
final class X11DprTest extends TestCase
{
    #[Test]
    public function testDpiToDpr(): void
    {
        self::assertSame(1.0, X11Dpr::fromDpi(96.0));
        self::assertSame(1.25, X11Dpr::fromDpi(120.0));
        self::assertSame(1.5, X11Dpr::fromDpi(144.0));
    }

    #[Test]
    public function testZeroAndNegativeFallBackToOne(): void
    {
        self::assertSame(1.0, X11Dpr::fromDpi(0.0));
        self::assertSame(1.0, X11Dpr::fromDpi(-72.0));
    }

    #[Test]
    public function testInvalidHandleReturnsOne(): void
    {
        self::assertSame(1.0, X11Dpr::of(0));
        self::assertSame(1.0, X11Dpr::of(-5));
    }

    #[Test]
    public function testReadsBridgeDpi(): void
    {
        $GLOBALS['DRIFT_FAKE_X11_DPI'] = 144.0;
        try {
            self::assertSame(1.5, X11Dpr::of(7));
        } finally {
            unset($GLOBALS['DRIFT_FAKE_X11_DPI']);
        }
    }
}
