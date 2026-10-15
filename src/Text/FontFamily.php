<?php
declare(strict_types=1);

namespace Drift\Text;

/**
 * 字体家族（§8.2 坑 1）。
 *
 * ★ **逻辑家族**优先：`sans` / `serif` / `mono` 是跨平台的语义名字，落地名
 *   （"Microsoft YaHei" / "PingFang SC"）由平台字体配置负责。
 *   直接写平台名的后果是"同一份 UI 代码在两个平台解析到不同家族"。
 *
 * @api
 */
final readonly class FontFamily
{
    public function __construct(
        public string $name = 'sans',
        public bool $logical = true,
    ) {
    }

    public static function sans(): self
    {
        return new self('sans', true);
    }

    public static function serif(): self
    {
        return new self('serif', true);
    }

    public static function mono(): self
    {
        return new self('mono', true);
    }

    /** 显式指定落地家族名 —— 只在确实要钉死某个字体时用 */
    public static function named(string $name): self
    {
        return new self($name, false);
    }

    public function isLogical(): bool
    {
        return $this->logical;
    }

    public function equals(FontFamily $other): bool
    {
        return $this->name === $other->name && $this->logical === $other->logical;
    }
}
