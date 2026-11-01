<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Platform;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Platform\Win32\Dpr;

/**
 * DPR 换算的唯一入口（`Dpr`）。
 *
 * ## 为什么值得单独立项
 *
 * 这段换算原本在三个文件里手写了六遍，而且兜底**不一致**：五份写了
 * `<= 0 ⇒ 1.0`，`Win32Screen::scale()` 没写。桥返回 `0` 时它把 `0.0` 交出去，
 * 消费者拿它做除数 ⇒ 整棵布局变 NaN / INF，**且不报错**（症状是"界面没了"，
 * 离原因隔了整条管线）。
 *
 * `0` 不是理论值：C 侧 `dpiOfWindow()` 问不到 `GetDpiForWindow`、也拿不到 DC 时，
 * 退到"这个窗口上次算出的值"（`slotOf(hwnd).dpi`），那个槽位在窗口刚建时就是 `0`。
 *
 * ## 断言口径
 *
 * `fromDpi()` 是纯函数（`of()` 才是碰桥的那半边）⇒ 能直接喂 `0` / 负值。
 * **`0` 与负值两条是本文件的重点**：去掉 `Dpr::fromDpi()` 里的 `> 0.0` 兜底，
 * 它们必须变红 —— 否则这就是一组只测"分数缩放算术"的装饰性测试。
 */
final class DprTest extends SmokeTestCase
{
    /**
     * @return array<string, array{0: float, 1: float}>
     */
    private static function table(): array
    {
        return [
            '96dpi ⇒ 1.0' => [96.0, 1.0],
            '120dpi ⇒ 1.25' => [120.0, 1.25],
            '144dpi ⇒ 1.5' => [144.0, 1.5],
            '192dpi ⇒ 2.0' => [192.0, 2.0],
            '桥给 0 ⇒ 兜底 1.0' => [0.0, 1.0],
            '桥给负值 ⇒ 兜底 1.0' => [-96.0, 1.0],
        ];
    }

    public function testFromDpiTable(): void
    {
        foreach (self::table() as $label => [$dpi, $expected]) {
            $actual = Dpr::fromDpi($dpi);
            check(
                'Platform · DPR ' . $label,
                $actual === $expected,
                '入参 ' . $dpi . '，实际 ' . $actual,
            );
        }

        // 前置判据：兜底值不能与正常值重合，否则"桥给 0"那条一旦丢兜底也可能凑巧为 1.0。
        check(
            'Platform · 前置：120dpi 的 DPR 不是 1.0（兜底与正常可区分）',
            Dpr::fromDpi(120.0) !== 1.0,
            '实际 ' . Dpr::fromDpi(120.0),
        );
    }

    /**
     * 句柄无效 ⇒ `1.0`。**这是契约，不是"短路"的实现细节**。
     *
     * ★ **这条测不出短路**（2026-11-01 实测）：桥在 `hwnd = 0` 时 `dpiOfWindow()` 也返回 `0`，
     *   而 `fromDpi(0)` 自己就兜底成 `1.0` ⇒ 把那三行 `$windowHandle <= 0` 删掉，本方法仍然绿。
     *   留着它是因为消费者依赖的是**值**（`Screen::scale()` 返回什么），不是"有没有调桥"；
     *   短路只省一次 `GetDpiForWindow`。
     */
    public function testInvalidHandleIsOne(): void
    {
        check('Platform · DPR 句柄 0 ⇒ 1.0', Dpr::of(0) === 1.0, '实际 ' . Dpr::of(0));
        check('Platform · DPR 句柄 -1 ⇒ 1.0', Dpr::of(-1) === 1.0, '实际 ' . Dpr::of(-1));
    }
}
