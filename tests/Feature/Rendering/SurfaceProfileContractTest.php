<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\SurfaceProfile;

/**
 * `SurfaceProfile` 的**量具契约** —— 单位与聚合，两条都必须能被证伪。
 *
 * ## 为什么量具也要有测试（2026-11-02）
 *
 * 这个类只有一个用途：告诉人"一帧的钱花在谁身上"。它错了不会崩、不会红、连
 * 输出都"看起来正常" —— 只是结论错。本轮实测到的形态：
 *
 * ```text
 *    500          985.7       7251.4       0     50      ← 整帧 985.7µs
 *        分段: ... anim=972.92ms ...                      ← 同一份输出里的 972.92"ms"
 * ```
 *
 * 真相是 `972.92µs`：`perCall()` 返回**纳秒**，而消费者自己写了
 * `round($v / 1000.0, 2) . 'ms'` ⇒ 每一段被印成真值的 **1000 倍**。
 * 同一份输出里两个数差三个数量级，三轮里没人多看一眼 —— 因为读者会"自动校正"。
 *
 * ⇒ 换收成唯一一份（`formatNs()`），并在这里把两条契约钉住：
 *
 * | 契约 | 写错的样子 | 症状 |
 * |---|---|---|
 * | 单位：`ns → ns/µs/ms` 三档 | 除数写错 1000 倍 | 人读到错三个数量级的数，拿它做重构决策 |
 * | 聚合：`perCall()` = **平均** | 返回累计 | "这一段每次花了 2µs"被读成 4µs；段与段不可比 |
 *
 * ★ 第二条有个额外的坑：同一个类里已经有 `Stats::median()`（`apps/bench` 用），
 *   `perCall()` 的注释曾写着"中位"。**平均与中位都合理，但只能有一个说法** ——
 *   所以说清楚，并让测试只承认代码实际做的那一个。
 *
 * ★ 不碰调用方的现场：`selfTest()` 跑前快照、跑后还原（同一进程里可能正拿着
 *   自己的采样）。
 */
final class SurfaceProfileContractTest extends SmokeTestCase
{
    /**
     * ① 单位：三档边界逐位对上，且**不是** 1000 倍的那个值。
     *
     * ★ 光断"等于 µs 串"还不够 —— 若 `formatNs()` 整体写成了"除以 1000 再标 µs"
     *   （正是本轮的事故形态），`formatNs(1000)` 会给出 `10µs` 而不是 `1µs`，
     *   这里逐位对上才算过。
     */
    public function testFormatNsUsesTheRightScale(): void
    {
        $cases = [
            [0, '0ns'],
            [1, '1ns'],
            [999, '999ns'],
            [1000, '1µs'],          // 边界：1000ns 是 1µs，不是 10µs
            [1500, '1.5µs'],
            [972920, '972.92µs'],   // 本轮实测到的那一段（曾被印成 972.92"ms"）
            [1000000, '1ms'],
            [1500000, '1.5ms'],
        ];

        foreach ($cases as [$ns, $want]) {
            check(
                \sprintf('formatNs(%d) === %s', $ns, $want),
                SurfaceProfile::formatNs($ns) === $want,
                \sprintf('实得 "%s"', SurfaceProfile::formatNs($ns)),
            );
        }

        $this->flushChecks();
    }

    /**
     * ② 聚合：两次 2000ns 的"每次"仍是 2000ns（平均），不是 4000ns（累计）。
     *
     * ★ 这条比"返回值不为 0"强得多：累计冒充"每次"时数字**看起来依然合理**
     *   （4µs 也是个小数字），只有和另一个段比才会露馅。
     */
    public function testPerCallIsMeanNotTotal(): void
    {
        $this->withIsolatedProfile(function (): void {
            SurfaceProfile::add('probe', 2000);
            SurfaceProfile::add('probe', 2000);

            $per = SurfaceProfile::perCall();
            check(
                '两次 2000ns ⇒ perCall 仍是 2000ns（平均）',
                ($per['probe'] ?? null) === 2000,
                '实得 ' . \var_export($per['probe'] ?? null, true) . '（4000 就是"累计冒充每次"）',
            );
            check(
                '调用次数记的是 2（次数与总量分开存）',
                SurfaceProfile::$calls['probe'] === 2,
                '实得 ' . \var_export(SurfaceProfile::$calls['probe'] ?? null, true),
            );

            // 同一段量的 2µs 与 4µs 落在两个段上时必须**分得开**（不是取全局平均）
            SurfaceProfile::add('slow', 4000);
            $per = SurfaceProfile::perCall();
            check(
                '两段各自平均：probe=2000 / slow=4000',
                ($per['probe'] ?? null) === 2000 && ($per['slow'] ?? null) === 4000,
                \sprintf('probe=%s slow=%s', \var_export($per['probe'] ?? null, true), \var_export($per['slow'] ?? null, true)),
            );
        });

        $this->flushChecks();
    }

    /**
     * ③ `selfTest()` 本身：空 = 通过；且它是**只读**的（不改调用方的采样）。
     *
     * ★ 第三条不能省：这个自检会被 `frame-tax-probe --check`（闸门第 12 项）调用，
     *   若它顺手清掉了累计值，量具就变成"测一次废一次"。
     */
    public function testSelfTestPassesAndDoesNotDisturbSamples(): void
    {
        $this->withIsolatedProfile(function (): void {
            SurfaceProfile::add('keep', 1234);

            $before = [SurfaceProfile::$ns, SurfaceProfile::$calls];
            $fail = SurfaceProfile::selfTest();
            $after = [SurfaceProfile::$ns, SurfaceProfile::$calls];

            check('selfTest() 无失败项', $fail === [], \implode('；', $fail));
            check(
                'selfTest() 不污染现有采样',
                $before === $after,
                \sprintf('跑前 %s，跑后 %s', \json_encode($before), \json_encode($after)),
            );
        });

        $this->flushChecks();
    }

    /** 包一层快照/还原，免得本测试的采样漏到别的用例里 */
    private function withIsolatedProfile(callable $body): void
    {
        $ns = SurfaceProfile::$ns;
        $calls = SurfaceProfile::$calls;
        SurfaceProfile::$on = true;
        SurfaceProfile::reset();

        try {
            $body();
        } finally {
            SurfaceProfile::$ns = $ns;
            SurfaceProfile::$calls = $calls;
        }
    }
}
