<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use Drift\App\OverlayPolicy;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 浮层策略判据：七位开关的组合是**约定**，不是随手可调的参数。
 *
 * 钉死组合的理由：这些位单独看都合理，改动任何一位都不会让任何一条功能测试变红 ——
 * 变的只是真机上"点外面还关不关""提示会不会抢悬停"这类没有断言覆盖的行为。
 */
final class OverlayPolicyTest extends TestCase
{
    /** @return array<string, array{OverlayPolicy, list<bool>}> */
    private static function presets(): array
    {
        return [
            'popup' => [
                OverlayPolicy::popup(),
                [true, false, true, true, false, true, true],
            ],
            'tooltip' => [
                OverlayPolicy::tooltip(),
                [false, false, false, false, false, false, false],
            ],
            'snackbar' => [
                OverlayPolicy::snackbar(),
                [false, false, false, false, false, true, false],
            ],
            'modal(dismissible)' => [
                OverlayPolicy::modal(true),
                [true, true, true, true, true, true, true],
            ],
            'modal(不可关)' => [
                OverlayPolicy::modal(false),
                [true, true, false, false, true, true, true],
            ],
        ];
    }

    /** @return list<bool> */
    private static function flags(OverlayPolicy $p): array
    {
        return [
            $p->blocksOutsidePointer(),
            $p->paintsScrim(),
            $p->dismissesOnTapOutside(),
            $p->dismissesOnEsc(),
            $p->trapsFocus(),
            $p->participatesInHitTest(),
            $p->restoresFocusOnClose(),
        ];
    }

    #[Test]
    public function 预设的七位组合被钉死(): void
    {
        $order = '拦层外 / 遮罩 / 点外关 / Esc关 / 困焦点 / 参与命中 / 回锚';

        foreach (self::presets() as $name => [$policy, $want]) {
            self::assertSame($want, self::flags($policy), $name . ' 的开关组合变了（顺序：' . $order . '）');
        }
    }

    #[Test]
    public function 提示与通知只差参与命中这一位(): void
    {
        // 这是 TooltipTest 反例对照的前提：两条策略的差异必须只剩被测变量。
        $tooltip = self::flags(OverlayPolicy::tooltip());
        $snackbar = self::flags(OverlayPolicy::snackbar());

        $diff = [];
        foreach ($tooltip as $i => $bit) {
            if ($bit !== $snackbar[$i]) {
                $diff[] = $i;
            }
        }

        self::assertSame([5], $diff, '差异位应只有"参与命中"');
    }

    #[Test]
    public function 模态只有用户可关那两位跟着dismissible走(): void
    {
        $on = self::flags(OverlayPolicy::modal(true));
        $off = self::flags(OverlayPolicy::modal(false));

        $diff = [];
        foreach ($on as $i => $bit) {
            if ($bit !== $off[$i]) {
                $diff[] = $i;
            }
        }

        self::assertSame([2, 3], $diff, '不可关只该影响"点外关"与"Esc关"');
    }

    #[Test]
    public function 轻量弹出层只认拦层外且无遮罩(): void
    {
        self::assertTrue(OverlayPolicy::popup()->isLightPopup());

        // 模态有遮罩、提示与通知不拦层外 ⇒ 都不算"有东西在替用户挡着下面"。
        self::assertFalse(OverlayPolicy::modal(true)->isLightPopup());
        self::assertFalse(OverlayPolicy::tooltip()->isLightPopup());
        self::assertFalse(OverlayPolicy::snackbar()->isLightPopup());
    }

    #[Test]
    public function 去掉指针拦截只改那一位(): void
    {
        $popup = OverlayPolicy::popup();
        $through = $popup->withoutPointerBarrier();

        self::assertFalse($through->blocksOutsidePointer());
        self::assertSame(
            \array_slice(self::flags($popup), 1),
            \array_slice(self::flags($through), 1),
            '其余六位必须原样保留',
        );

        // 穿透之后不再算"挡着下面"，否则 isMenuOpen() 会替一个拦不住任何东西的层撒谎。
        self::assertFalse($through->isLightPopup());
    }
}
