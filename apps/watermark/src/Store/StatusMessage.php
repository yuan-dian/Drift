<?php
declare(strict_types=1);

namespace App\Watermark\Store;

use Drift\State\Equatable;

/**
 * 一条状态栏消息（原文的 `showMsg(id, type, msg)`）。
 *
 * ★ **不可变 + 值相等**：实现 `Equatable` 后 `SubjectBuilder` 的 `select` 门控
 *   自动按内容比，调用点不必再手写 `->equals(...)`；漏写也不会退化成每次重建。
 * ★ `kind` 用字符串常量而不是枚举：枚举 case 不存在 / 外部常量求值成 0 是本仓
 *   踩过的 AOT 坑（见 `MEMORY`），而这里只有四个值、没有任何"穷尽"需求。
 */
final class StatusMessage implements Equatable
{
    public const INFO = 'info';

    public const SUCCESS = 'success';

    public const ERROR = 'error';

    public const LOADING = 'loading';

    public function __construct(
        public readonly string $kind,
        public readonly string $text,
    ) {
    }

    public static function info(string $text): self
    {
        return new self(self::INFO, $text);
    }

    public static function success(string $text): self
    {
        return new self(self::SUCCESS, $text);
    }

    public static function error(string $text): self
    {
        return new self(self::ERROR, $text);
    }

    public static function loading(string $text): self
    {
        return new self(self::LOADING, $text);
    }

    /** 空消息（不显示） */
    public static function none(): self
    {
        return new self('', '');
    }

    public function isVisible(): bool
    {
        return $this->kind !== '' && $this->text !== '';
    }

    /** 接口收 `Equatable`：不同实现之间一律判不等，不猜对方的字段。 */
    public function equals(Equatable $other): bool
    {
        return $other instanceof self
            && $this->kind === $other->kind
            && $this->text === $other->text;
    }
}
