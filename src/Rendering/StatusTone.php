<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 状态消息的语义档位 → 一对 `ColorToken`。
 *
 * ★ 放在 Rendering 而不是应用里：状态点是"角色 + 取色"这件事，不是业务数据；
 *   三个应用各写一遍 `kind → 颜色` 的 if 链，加一档就漏两处。
 * ★ `Loading` 与 `Warning` 分开：业务语义不同，色系可以相同（都用橙色）。
 *
 * @api
 */
enum StatusTone
{
    case Idle;
    case Info;
    case Success;
    case Warning;
    case Error;
    case Loading;

    public function foregroundToken(): ColorToken
    {
        return match ($this) {
            self::Idle    => ColorToken::Muted,
            self::Info    => ColorToken::Info,
            self::Success => ColorToken::Success,
            self::Warning, self::Loading => ColorToken::Warning,
            self::Error   => ColorToken::Danger,
        };
    }

    public function surfaceToken(): ColorToken
    {
        return match ($this) {
            self::Idle    => ColorToken::Surface,
            self::Info    => ColorToken::InfoSurface,
            self::Success => ColorToken::SuccessSurface,
            self::Warning, self::Loading => ColorToken::WarningSurface,
            self::Error   => ColorToken::DangerSurface,
        };
    }
}
