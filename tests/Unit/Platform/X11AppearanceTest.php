<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Platform;

use Drift\Platform\X11\X11Appearance;
use Drift\Theme\SystemTheme;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * X11 外观桥的归一化判据：只测 PHP 侧能确定的两轴，不碰 X server。
 *
 * ★ 不继承 SmokeTestCase：纯函数、无窗口、无帧。真正的 XSettings / 环境兜底
 *   由 probes/linux_x11/build.sh 的 [THEME] 判据在真机上验，两层不互相冒充。
 */
final class X11AppearanceTest extends TestCase
{
    #[Test]
    public function testDarkAndHighContrastAreIndependentAxes(): void
    {
        $appearance = X11Appearance::fromBridge(['dark' => true, 'high_contrast' => true]);

        $this->assertSame(SystemTheme::Dark, $appearance->theme);
        $this->assertTrue($appearance->highContrast);
    }

    #[Test]
    public function testMissingBridgeFallsBackToLightWithoutSystemColors(): void
    {
        $appearance = X11Appearance::fromBridge([]);

        $this->assertSame(SystemTheme::Light, $appearance->theme);
        $this->assertFalse($appearance->highContrast);
        $this->assertNull($appearance->window);
        $this->assertNull($appearance->windowText);
        $this->assertNull($appearance->buttonFace);
        $this->assertNull($appearance->hotlight);
    }
}
