<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Theme;

use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Rendering\StatusTone;
use PHPUnit\Framework\TestCase;

final class StatusColorTest extends TestCase
{
    public function testEveryToneMapsToRealColorRoles(): void
    {
        foreach (StatusTone::cases() as $tone) {
            self::assertContains($tone->foregroundToken(), ColorToken::cases());
            self::assertContains($tone->surfaceToken(), ColorToken::cases());
        }
    }

    public function testStatusPairsStayReadableInBothThemes(): void
    {
        $tones = [StatusTone::Info, StatusTone::Success, StatusTone::Warning, StatusTone::Error, StatusTone::Loading];
        foreach ($tones as $tone) {
            foreach ([true, false] as $dark) {
                $fg = $dark ? $tone->foregroundToken()->darkValue() : $tone->foregroundToken()->lightValue();
                $bg = $dark ? $tone->surfaceToken()->darkValue() : $tone->surfaceToken()->lightValue();
                self::assertGreaterThanOrEqual(
                    3.0,
                    self::contrast($fg, $bg),
                    $tone->name . ($dark ? ' dark' : ' light'),
                );
            }
        }
    }

    public function testLoadingKeepsItsSemanticsWhileSharingWarningColors(): void
    {
        self::assertNotSame(StatusTone::Loading, StatusTone::Warning);
        self::assertSame(StatusTone::Loading->foregroundToken(), StatusTone::Warning->foregroundToken());
        self::assertSame(StatusTone::Loading->surfaceToken(), StatusTone::Warning->surfaceToken());
    }

    private static function contrast(Color $a, Color $b): float
    {
        $la = self::luminance($a);
        $lb = self::luminance($b);

        return (max($la, $lb) + 0.05) / (min($la, $lb) + 0.05);
    }

    private static function luminance(Color $c): float
    {
        $channel = static fn (int $v): float => ($v / 255) <= 0.03928
            ? ($v / 255) / 12.92
            : ((($v / 255) + 0.055) / 1.055) ** 2.4;

        return 0.2126 * $channel($c->r) + 0.7152 * $channel($c->g) + 0.0722 * $channel($c->b);
    }
}
