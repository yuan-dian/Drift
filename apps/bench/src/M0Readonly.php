<?php
declare(strict_types=1);

namespace Drift\Bench;

/**
 * M0-1：`readonly` 的成本（`08` §3.3）。
 *
 * ── 要回答的唯一问题 ──────────────────────────────────────────────────────
 * `readonly` 属性在 AOT 下是不是一次**运行时 Hook**（v6 断言"慢 10×+"，未实测）。
 * 这个问题决定 `02` §3.1 的 `Modifier` 20 个字段用 `public readonly`（简洁）
 * 还是 `private` + `#[\Getter]`（快但要写 20 个方法）。
 *
 * ★★ 本仓现状：`02` **已经按最保守的假设写了** `private` + getter ⇒
 *    本基准的作用是"**确认能否简化**"（少写 10 个 getter），不是"决定形状"。
 *    这是"先测再写"的反例，`08` §3.6 已承认。
 *
 * ── 三组 ────────────────────────────────────────────────────────────────
 *   A  `public readonly float` 直读      ← 候选的简洁形态
 *   B  `private float` + `v()` 读       ← 已采用的形态
 *   C  `public float` 直读（**非** readonly 类）← 对照组，隔离 readonly 本身
 *
 * ★★ **C 组是后来加的，而且是三组里最要紧的**：只比 A/B 回答不了 v6 的那句
 *    "readonly 慢 10×+" —— 它测的是"readonly 读 vs 方法调用"，而 readonly
 *    可能根本不引入 Hook，A 快只是因为**属性读本来就比方法调用便宜**。
 *    全仓 187 处 `readonly` / 32 个 `final readonly class` 关心的是
 *    "readonly 有没有 Hook"，那只能由 A − C 回答。
 *
 * ── 三个防"测出来的不是想测的" ──────────────────────────────────────────
 *   1) **循环取对象**（`$i % OBJECTS`）：如果只有一个对象，`$acc += $ro->v`
 *      会被提升成 `$acc += READS * $ro->v`，A 组直接归零 ⇒ 必须让每次读
 *      落到不同对象上。
 *   2) **`float` 而不是 `int`**：int 会被 tpc 的常量折叠进一步吃掉。
 *   3) **读点包在闭包里**（与 M1/M2 同形）：`static function` + `use` 捕获，
 *      两组形状逐字一致，唯一差异就是"属性读 vs 方法调用"。
 *
 * ★ 判据（`08` §3.3 修正版）：
 *     (readonly_ns - private_ns) × 每帧读次数(≈10) × 60 < 1ms/秒 ⇒ 用 readonly
 *     即差值 < 1666 ns/read 都可忽略；另设 100 ns/read 为"显著"门槛。
 */
final class M0Readonly
{
    /** 夹具对象数：循环取，防读被提升出循环（见上） */
    private const OBJECTS = 64;

    /** 每轮读次数（10 万 × 80 轮 = 800 万次读，压过噪声） */
    private const READS = 100_000;

    private const ROUNDS = 80;

    /** `Modifier` 字段在一次 `layout()` 里被读的次数上界（`08` §3.3） */
    private const READS_PER_FRAME = 10;

    /**
     * @return array<string, int> 组名 → 每读中位纳秒
     */
    public static function run(): array
    {
        $ro = self::fixtureRO(self::OBJECTS);
        $pv = self::fixturePV(self::OBJECTS);
        $pl = self::fixturePL(self::OBJECTS);
        $reads = self::READS;
        $objs = self::OBJECTS;
        $out = [];

        $out['A readonly direct'] = self::measure(static function () use ($ro, $reads, $objs): float {
            $acc = 0.0;
            for ($i = 0; $i < $reads; $i++) {
                $acc += $ro[$i % $objs]->v;
            }

            return $acc;
        });

        $out['B private+getter'] = self::measure(static function () use ($pv, $reads, $objs): float {
            $acc = 0.0;
            for ($i = 0; $i < $reads; $i++) {
                $acc += $pv[$i % $objs]->v();
            }

            return $acc;
        });

        $out['C plain public'] = self::measure(static function () use ($pl, $reads, $objs): float {
            $acc = 0.0;
            for ($i = 0; $i < $reads; $i++) {
                $acc += $pl[$i % $objs]->v;
            }

            return $acc;
        });

        return $out;
    }

    /**
     * @return array<int, RO>
     */
    private static function fixtureRO(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = new RO(1.0 + (float) ($i % 7));
        }

        return $out;
    }

    /**
     * @return array<int, PV>
     */
    private static function fixturePV(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = new PV(1.0 + (float) ($i % 7));
        }

        return $out;
    }

    /**
     * @return array<int, PL>
     */
    private static function fixturePL(int $n): array
    {
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $out[] = new PL(1.0 + (float) ($i % 7));
        }

        return $out;
    }

    /**
     * @param callable():float $body
     */
    private static function measure(callable $body): int
    {
        $samples = [];
        $acc = 0.0;

        // 预热：让常量池 / 分支预测 / 缓存进入稳态（第一轮永远偏慢）
        for ($i = 0; $i < 5; $i++) {
            $acc += $body();
        }

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $t0 = \hrtime(true);
            $acc += $body();
            $t1 = \hrtime(true);
            $samples[] = $t1 - $t0;
        }

        return (int) \round((float) Stats::median($samples) / (float) self::READS);
    }

    /**
     * 按 `08` §3.3 的修正判据给结论。返回**给报告用的一行字**。
     *
     * ★ 三组一起看，两条判据分开答：
     *   · A − C ⇒ **readonly 本身有没有 Hook**（v6"慢 10×+"那条断言）
     *   · B − C ⇒ **getter 方法调用的净成本**（`02` 要不要为它牺牲简洁）
     *
     * @param array<string, int> $r
     */
    public static function verdict(array $r): string
    {
        $a = (int) ($r['A readonly direct'] ?? 0);
        $b = (int) ($r['B private+getter'] ?? 0);
        $c = (int) ($r['C plain public'] ?? 0);

        if ($a <= 0 || $b <= 0 || $c <= 0) {
            return 'n/a（计时失败）';
        }

        $hook = $a - $c;   // readonly 自身开销
        $getter = $b - $c; // getter 方法调用净成本
        $diff = $a - $b;   // 两个候选形态的直接差

        // 判据一：readonly 有没有 Hook（v6 的"慢 10×+"）
        $hookWord = 'readonly 自身开销 %+d ns/read（A−C）';
        if ($hook <= 100) {
            $hookWord = \sprintf($hookWord . ' ⇒ **无 Hook**（v6"慢 10×+"在本仓不成立）', $hook);
        } else {
            $hookWord = \sprintf($hookWord . ' ⇒ 有 Hook，但不构成 10× 量级', $hook);
        }

        // 判据二：Modifier 用哪个形态
        $perSecond = $diff * self::READS_PER_FRAME * 60;
        if ($diff <= 100 || $perSecond < 1_000_000) {
            $pick = \sprintf(
                'A−B = %+d ns/read ⇒ 可用 public readonly（少写 10 个 getter）',
                $diff
            );
        } else {
            $pick = \sprintf(
                'A−B = %+d ns/read（折算 %s ms/秒）⇒ 保持 private + #[\Getter]',
                $diff,
                \number_format($perSecond / 1_000_000.0, 3, '.', '')
            );
        }

        return $hookWord . '；getter 净成本 ' . \sprintf('%+d', $getter)
            . ' ns/read（B−C）；' . $pick;
    }
}

/**
 * A 组夹具：`public readonly float`（提升属性）。
 *
 * ★ 用**提升属性**而不是显式 `public readonly float $v;` + 构造赋值：
 *   后者在 `readonly` 类里写两遍字段名，且 tpc 对"构造外赋值"的检查会多一条路径。
 */
final readonly class RO
{
    public function __construct(public float $v)
    {
    }
}

/**
 * B 组夹具：`private float` + getter。
 *
 * ★ getter 名字故意叫 `v()` 而不是 `getV()`：这里要量的就是"一次普通方法调用"
 *   的净成本，与 `#[\Getter]` 生成的 `getXxx()` 同价（都是普通方法，见硬坑）。
 */
final class PV
{
    public function __construct(private float $v)
    {
    }

    public function v(): float
    {
        return $this->v;
    }
}

/**
 * C 组夹具（对照组）：`public float`，**类上没有 `readonly`**。
 *
 * ★ 与 `RO` 逐字相同，只差 `readonly` 一个关键字 ⇒ A − C 就是 readonly 的
 *   自身开销，B − C 就是 getter 方法调用的净成本。这是三组里唯一能回答
 *   "readonly 到底有没有 Hook"的比较。
 */
final class PL
{
    public function __construct(public float $v)
    {
    }
}
