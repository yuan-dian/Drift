<?php
declare(strict_types=1);

namespace App\Watermark\Store;

use Drift\Rendering\StatusTone;
use Drift\State\Equatable;

/**
 * 一条状态栏消息（原文的 `showMsg(id, type, msg)`）。
 *
 * ★ **不可变 + 值相等**：实现 `Equatable` 后 `Cell` 的相等门控自动按内容比，
 *   所以"每次都 new 一个消息"不会变成"每次都通知、每次都重建"。
 * ★ `kind` 用字符串常量而不是枚举：enum 里声明非 case 常量在 AOT 下会崩
 *   （见 `MEMORY` enum 节），而这里只有四个值、没有"穷尽"需求。
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

    /** 业务 kind → 主题色档；未知 kind 落 Idle，症状是"颜色不对"，不是崩。 */
    public function tone(): StatusTone
    {
        if ($this->kind === self::SUCCESS) {
            return StatusTone::Success;
        }
        if ($this->kind === self::ERROR) {
            return StatusTone::Error;
        }
        if ($this->kind === self::LOADING) {
            return StatusTone::Loading;
        }
        if ($this->kind === self::INFO) {
            return StatusTone::Info;
        }

        return StatusTone::Idle;
    }

    /** 接口收 `Equatable`：不同实现之间一律判不等，不猜对方的字段。 */
    public function equals(Equatable $other): bool
    {
        return $other instanceof self
            && $this->kind === $other->kind
            && $this->text === $other->text;
    }
}
