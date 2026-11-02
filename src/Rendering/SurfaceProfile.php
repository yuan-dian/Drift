<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 表面级计时：`beginFrame` / `toRgba` / `present` 三段。
 *
 * ★ **默认关闭，零开销**：`$on` 为 false 时各调用点只做一次静态 bool 读
 *   （AOT 下是一次全局读 + 分支），不取时钟、不写数组。
 *
 * ★ **为什么计时器住 `Rendering` 而不是 `App`**：这三段全在 `RenderSurface`
 *   的实现里，而 `RenderSurface` 属于 `Rendering`（§7.1）。放 `App` 会让
 *   `Platform/Win32/*` 反向依赖 `App` —— 那是 §4.3 明令禁止的方向。
 *
 * ★ 为什么需要它：`26` §3.2 指出 M3 的分母（`settle + layout + paint`）**不含
 *   present**，而真机上一帧的大头很可能就在这里。要判断"该不该拆布局"，必须先
 *   知道 present 占端到端多少 —— 那就是这个类唯一的用途。
 *
 * @api platform 量具入口（`apps/bench` 的 M4 用它）
 */
final class SurfaceProfile
{
    /** 打开后各表面实现开始采样 */
    public static bool $on = false;

    /** @var array<string, int> 段名 → 累计纳秒 */
    public static array $ns = [];

    /** @var array<string, int> 段名 → 调用次数 */
    public static array $calls = [];

    public static function add(string $key, int $elapsedNs): void
    {
        self::$ns[$key] = (self::$ns[$key] ?? 0) + $elapsedNs;
        self::$calls[$key] = (self::$calls[$key] ?? 0) + 1;
    }

    public static function reset(): void
    {
        self::$ns = [];
        self::$calls = [];
    }

    /**
     * 段名 → 每次调用**平均**纳秒（累计 / 次数）。
     *
     * ★ 是**平均**不是中位：算式就是 `$total / $calls`。这行注释 2026-11-02 之前
     *   写着"中位"，与代码不符 —— 量具的**说明**也是判据的一部分，它错了会让读者
     *   用错口径（中位对长尾更稳，平均会被单次尖峰拉高；两种都合理，但只能有一个）。
     *
     * @return array<string, int> 纳秒（**不是** µs —— 换算见 {@see self::formatNs()}）
     */
    public static function perCall(): array
    {
        $out = [];
        foreach (self::$ns as $key => $total) {
            $n = self::$calls[$key] ?? 0;
            $out[$key] = $n > 0 ? (int) \round((float) $total / (float) $n) : 0;
        }

        return $out;
    }

    /**
     * 纳秒 → 人读单位。**全仓唯一的 ns→文本换算**。
     *
     * ★★ 为什么必须有它（2026-11-02）：`frame-tax-probe` 自己写过
     *   `round($v / 1000.0, 2) . 'ms'`，而 `$v` 是**纳秒** ⇒ 每个分段被印成真值的
     *   **1000 倍**：`anim=972.92ms` 其实是 `972.92µs`。于是同一段输出里
     *   "稳态帧 985.7µs / anim 段 972.92ms" 自相矛盾，**却没人发现** —— 而
     *   "该不该做 M5"正是拿这张表判的（差三个数量级）。
     *   ⇒ 换算收成一处，与 `Scale::deviceX()` 同一条纪律（同一件事写两遍必然漂移）；
     *     并配 {@see self::selfTest()} 让契约自己证一次（`probe_pixels.px()` 的教训：
     *     共享件迁移后必须自带契约自检）。
     */
    public static function formatNs(int $ns): string
    {
        if ($ns < 1000) {
            return $ns . 'ns';
        }
        if ($ns < 1000000) {
            return \round($ns / 1000.0, 2) . 'µs';
        }

        return \round($ns / 1000000.0, 2) . 'ms';
    }

    /**
     * **量具契约自检**：本类只有两条语义，两条都要能被证伪。
     *
     * ① 单位：三档边界逐位对上（把除数写错 1000 倍，这里立刻红）；
     * ② 聚合：`perCall()` 是**平均**（同一段两次 2µs 与一次 4µs 不可区分，
     *    但"两次 2µs"必须给 2µs 而不是 4µs —— 后者是"累计"冒充"每次"）。
     *
     * ★ 不污染现场：跑前快照、跑后还原（调用方可能正拿着自己的采样）。
     *
     * @return list<string> 失败项（空数组 = 通过）
     */
    public static function selfTest(): array
    {
        $fail = [];

        $units = [
            [0, '0ns'],
            [999, '999ns'],
            [1000, '1µs'],
            [1500, '1.5µs'],
            [972920, '972.92µs'],
            [1000000, '1ms'],
            [1500000, '1.5ms'],
        ];
        foreach ($units as [$ns, $want]) {
            $got = self::formatNs($ns);
            if ($got !== $want) {
                $fail[] = \sprintf('单位：formatNs(%d) = "%s"，期望 "%s"', $ns, $got, $want);
            }
        }

        $ns0 = self::$ns;
        $calls0 = self::$calls;
        self::reset();
        self::add('probe', 2000);
        self::add('probe', 2000);
        $per = self::perCall();
        // ★ 变量名必须与上面那个 `$got` 分开（2026-11-02）：AOT 的类型推断是
        //   **方法内单一定型**的，同一个 `$got` 先被赋 `string`（`formatNs` 的返回）
        //   再被赋 `int` ⇒ `Cannot re-assign $got from php::Int to php::Str`。
        //   与 `MEMORY` 里"`foreach` key 复用已定型变量"是同一条坑的另一种形态。
        $gotInt = (int) ($per['probe'] ?? -1);
        if ($gotInt !== 2000) {
            $fail[] = \sprintf(
                '聚合：两次 2000ns 的 perCall 应是 2000（平均），实得 %d —— 累计冒充了"每次"',
                $gotInt,
            );
        }
        self::$ns = $ns0;
        self::$calls = $calls0;

        return $fail;
    }
}
