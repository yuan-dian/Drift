<?php
declare(strict_types=1);

namespace App\Watermark\Store;

/**
 * 一条状态栏消息（原文的 `showMsg(id, type, msg)`）。
 *
 * ★ **不可变 + 值相等**：`SubjectBuilder` 的 `select` 靠 `===` 门控，消息没换就
 *   不该重建那一块。用可变对象的话，改一个字就是同一个实例 ⇒ 界面永远不刷新
 *   （或者相反：每次都 new ⇒ 每帧重建）。所以 `equals()` 必须比**内容**。
 * ★ `kind` 用字符串常量而不是枚举：枚举 case 不存在 / 外部常量求值成 0 是本仓
 *   踩过的 AOT 坑（见 `MEMORY`），而这里只有四个值、没有任何"穷尽"需求。
 */
final class StatusMessage
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

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->text === $other->text;
    }
}
