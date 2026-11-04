<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

/**
 * 一组耗时采样的分位摘要 —— 量具的**误差棒**。
 *
 * ★ 为什么要有它（2026-11-04 夜）：本仓的耗时量具原来都只报**一个数**，而那个数是
 *   "N 次取平均"。实测同一棵 12k 节点树连续 `layout()` 20 次：第一发 63.3ms、
 *   稳态 41.4–46.8ms（**第一发慢 37–53%**）。`layout()` 在 attach 之后是**首次强制重排**，
 *   缓存最冷 —— 把它算进分母，`layoutUs` 就系统性偏高（实测 1–13%）。
 *   更麻烦的是读者无法从 "48,383" 这个数里看出它有多可信。
 *   ⇒ 报 `min / median / max` 三个数：`median` 当主数，min/max 当误差棒。
 *
 * ★ 主数为什么用 median 不用 min：min 是"缓存全热 + 机器最闲"的理想值，
 *   拿它当帧预算的分母会**低估**真实成本。median 是"典型一次"，
 *   而 min/max 就在旁边，谁要保守口径自己看。
 *
 * ★ 为什么不报 p95：n=7 时 nearest-rank 的 p95 恒等于 max，是个**假精度**。
 *   真要 p95 得采几十次，而这里的成本是 45ms/次。宁可只报三个真数。
 *
 * ★ 为什么不算标准差：耗时分布是**右偏**的（偶发调度/GC 只会让它变慢），
 *   标准差被尾部拖着走，两三个离群点就能让它失去意义。min/med/max 更抗这个。
 */
final class Samples
{
    /**
     * @param int   $n      采样数（不含被丢弃的预热）
     * @param float $min    最快一次（微秒）
     * @param float $median 典型一次（微秒）
     * @param float $max    最慢一次（微秒）
     */
    private function __construct(
        public readonly int $n,
        public readonly float $min,
        public readonly float $median,
        public readonly float $max,
    ) {
    }

    /**
     * @param list<float> $us 每次采样的耗时（微秒）。**顺序无关**，内部会排序（在副本上）。
     */
    public static function of(array $us): self
    {
        if ($us === []) {
            return new self(0, 0.0, 0.0, 0.0);
        }

        $sorted = $us;
        \sort($sorted);
        $n = \count($sorted);
        $mid = \intdiv($n, 2);
        // 偶数个取中间两个的均值；奇数个取正中间。
        $median = $n % 2 === 1 ? $sorted[$mid] : ($sorted[$mid - 1] + $sorted[$mid]) / 2.0;

        return new self($n, $sorted[0], $median, $sorted[$n - 1]);
    }

    /**
     * 离散度 `max/min` —— "这个数有多可信"的直接指标。
     *
     * ★ 只进**信息行**，不进判据：离散是机器的属性（调度、频率、GC），
     *   判它等于判机器，会变成一台随机失败的机器。这一条在 `DEBT` §39 里论证过。
     */
    public function spread(): float
    {
        return $this->n < 2 || $this->min <= 0.0 ? 1.0 : $this->max / $this->min;
    }

    /** 一行给人看：`med 44.4 / min 41.7 / max 46.2（离散 1.11×, n=7）` */
    public function text(string $unit = 'us'): string
    {
        if ($this->n === 0) {
            return '（没采到样）';
        }

        return \sprintf(
            'med %.1f / min %.1f / max %.1f %s（离散 %.2f×, n=%d）',
            $this->median,
            $this->min,
            $this->max,
            $unit,
            $this->spread(),
            $this->n,
        );
    }

    /** @return array{n: int, min: float, median: float, max: float, spread: float} */
    public function toArray(): array
    {
        return [
            'n' => $this->n,
            'min' => \round($this->min, 1),
            'median' => \round($this->median, 1),
            'max' => \round($this->max, 1),
            'spread' => \round($this->spread(), 2),
        ];
    }
}
