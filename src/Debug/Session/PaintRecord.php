<?php
declare(strict_types=1);

namespace Drift\Debug\Session;

/**
 * 一帧的绘制事实：画了哪些节点、层缓存命中几次、哪些边界因什么重录。
 *
 * 只存标量：`painted` 是节点号，`misses` 是节点号 ⇒ 原因。绘制发生在元素树之外，
 * 记录里不能持有 `RenderBox` —— 环形窗口裁剪时那会拖住已卸载的盒。
 *
 * @api
 */
final readonly class PaintRecord
{
    /** 首帧 / 新边界：缓存里还没有命令流。 */
    public const string MISS_FIRST = 'first';

    /** 写点主动 `LayerCache::invalidate()`（视觉维、合成槽、子树重排）。 */
    public const string MISS_INVALIDATED = 'invalidated';

    /** 尺寸变了：缓存键含宽高。 */
    public const string MISS_RESIZED = 'resized';

    /** 回放端能力位变了：Optional op 集合不能跨端复用。 */
    public const string MISS_CAPABILITIES = 'capabilities';

    /** 没有层缓存的盒，每帧直接画；不是"未命中"。 */
    public const string REASON_DIRECT = 'direct';

    /**
     * @param list<int>              $painted      本帧真画的节点号（先序，去重，可能被上限截断）
     * @param array<int, string>     $misses       层缓存未命中的节点号 ⇒ 原因
     * @param int                    $paintedTotal 真画节点总数（截断前，含无主盒）
     * @param int                    $layerHits    层缓存命中次数（命中即整棵子树不画）
     * @param int                    $unmapped     没有 Element 认领的盒数（浮层 / 内部盒）
     * @param bool                   $truncated    painted 或 misses 被上限截断
     */
    public function __construct(
        public int $frame,
        public array $painted,
        public array $misses,
        public int $paintedTotal,
        public int $layerHits,
        public int $unmapped,
        public bool $truncated,
    ) {
    }

    /** 层缓存命中率；没有边界绘制时是 0，不是 100%。 */
    public function hitRate(): float
    {
        $total = $this->layerHits + \count($this->misses);

        // ★ `(float)` 不能省：`int / int` 在 AOT 下是整数除法，这一行会退化成
        //   0 或 1，而解释器里的 `/` 是浮点 —— 同一份记录两个答案。
        return $total === 0 ? 0.0 : (float) $this->layerHits / $total;
    }

    /**
     * 查询面的形状：在无损字段上补两个**派生**量。
     *
     * `hitRate` 现算不存：它是 `hits / (hits + misses)` 的纯函数，存进包里就是第二份
     * 真相 —— 一份改过 `hits` 却没改比率的包会自洽地撒谎。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'frame' => $this->frame,
            'painted' => $this->painted,
            'misses' => $this->misses,
            'paintedTotal' => $this->paintedTotal,
            'paintedNodes' => \count($this->painted),
            'hits' => $this->layerHits,
            'hitRate' => $this->hitRate(),
            'unmapped' => $this->unmapped,
            'truncated' => $this->truncated,
        ];
    }

    /** @return array<string, mixed> */
    public function encode(): array
    {
        return [
            'frame' => $this->frame,
            'painted' => $this->painted,
            'misses' => $this->misses,
            'paintedTotal' => $this->paintedTotal,
            'hits' => $this->layerHits,
            'unmapped' => $this->unmapped,
            'truncated' => $this->truncated,
        ];
    }

    /** @param array<string, mixed> $raw */
    public static function decode(array $raw): self
    {
        $painted = [];
        foreach (self::listOf($raw['painted'] ?? []) as $node) {
            if (\is_int($node) || \is_string($node)) {
                $painted[] = (int) $node;
            }
        }

        $misses = [];
        $rawMisses = $raw['misses'] ?? [];
        if (\is_array($rawMisses)) {
            foreach ($rawMisses as $node => $reason) {
                $misses[(int) $node] = (string) $reason;
            }
        }

        return new self(
            (int) ($raw['frame'] ?? 0),
            $painted,
            $misses,
            (int) ($raw['paintedTotal'] ?? \count($painted)),
            (int) ($raw['hits'] ?? 0),
            (int) ($raw['unmapped'] ?? 0),
            (bool) ($raw['truncated'] ?? false),
        );
    }

    /** @return list<mixed> */
    private static function listOf(mixed $value): array
    {
        return \is_array($value) ? \array_values($value) : [];
    }
}
