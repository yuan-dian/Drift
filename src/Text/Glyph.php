<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 一个已定位的字形（塑形输出，§8.1 的 `TextShaper` 产物）。
 *
 * `cluster` 是**源文本里的字节偏移** —— 断行、光标、IME 组合区间都靠它把
 * 字形映射回原文（§8.2 坑 7）。
 *
 * @api
 */
final readonly class Glyph
{
    public function __construct(
        public int $codepoint,
        public float $advance,
        public float $offsetX = 0.0,
        public float $offsetY = 0.0,
        public int $cluster = 0,
    ) {
    }

    public function withAdvance(float $advance): self
    {
        return new self($this->codepoint, $advance, $this->offsetX, $this->offsetY, $this->cluster);
    }

    /** 跨 span 拼接时把 cluster 抬到**全文**的字节偏移 */
    public function withCluster(int $cluster): self
    {
        return new self($this->codepoint, $this->advance, $this->offsetX, $this->offsetY, $cluster);
    }

    public function equals(Glyph $other): bool
    {
        return $this->codepoint === $other->codepoint
            && $this->advance === $other->advance
            && $this->offsetX === $other->offsetX
            && $this->offsetY === $other->offsetY
            && $this->cluster === $other->cluster;
    }
}
