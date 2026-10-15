<?php
declare(strict_types=1);

namespace Drift\Commands;

/**
 * 命令的参数（§11.4.3）。
 *
 * ★ **不是裸 int** —— 裸 int 传不了 string id / 复合参数。但也不做成泛型
 *   `CommandArg<T>`：PHP 的泛型只是文档，真到运行期还是一个 `int` + 一个 `string`
 *   两个槽。两个槽 + 三个工厂，比一个"看起来泛型其实不泛型"的容器诚实。
 *
 * @api
 */
final readonly class CommandArg
{
    public function __construct(
        public int $i = 0,
        public string $s = '',
    ) {
    }

    /** 无参数（`Menu::separator` / 无参动作） */
    public static function none(): self
    {
        return new self();
    }

    /** int id（列表行的 `fn(User $u): int => $u->id`） */
    public static function id(int $id): self
    {
        return new self(i: $id);
    }

    /** string key（路由名 / 槽位名） */
    public static function key(string $k): self
    {
        return new self(s: $k);
    }
}
