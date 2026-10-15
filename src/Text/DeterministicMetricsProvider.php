<?php
declare(strict_types=1);

namespace Drift\Text;

use Drift\Geometry\Scale;

/**
 * 度量的确定性实现（§8.1 最后一层 / §14.2）—— Widget 层问度量、不问平台。
 *
 * ★ 断行算法**不在这里**：它由 `TextLayout` 提供，真测量后端与确定性参考实现
 *   共用同一份（§14.2）—— 于是 conformance 抓住的是"度量"的真差异，而不是
 *   "模拟器与真实排版各自漂移"。
 *
 * ★ 缓存是**实现内部**的（§7.1），键 = `(text, style, maxWidth, scale)`，
 *   接口上不暴露 —— 缓存不是契约。
 *
 * @api
 */
final class DeterministicMetricsProvider implements TextMetricsProvider
{
    private readonly TextLayout $layout;

    /** @var array<string, Paragraph> */
    private array $cache = [];

    public function __construct(
        private readonly FontCollection $collection,
        private readonly TextShaper $shaper,
    ) {
        $this->layout = new TextLayout($shaper);
    }

    public function layout(string $text, TextStyle $style, float $maxWidth, Scale $scale): Paragraph
    {
        $key = Paragraph::keyFor($text, $style, $maxWidth, $scale);
        return $this->cache[$key] ??= $this->layout->layout($text, $style, $maxWidth, $scale, $this->collection);
    }

    public function advance(string $text, TextStyle $style, Scale $scale): float
    {
        // 不折行的便捷入口：只量一行的宽度，不产生段落
        $total = 0.0;
        foreach ($this->shaper->shape($text, $style, $this->collection, $scale->dpr) as $run) {
            $total += $run->width;
        }
        return $total;
    }

    /** 当前缓存条目数（诊断用，不属于接口契约） */
    public function cachedCount(): int
    {
        return count($this->cache);
    }

    public function clearCache(): void
    {
        $this->cache = [];
    }
}
