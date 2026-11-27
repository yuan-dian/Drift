<?php
declare(strict_types=1);

namespace Drift\DevTools\Cli;

/** 用户输入不合法。出口把它翻成 stderr + 退出码 2。 */
final class CliError extends \RuntimeException
{
}

/** 一个选项的声明。重名（含别名）在 schema 合并时就拒绝，不留给运行时猜。 */
final class Opt
{
    public const string FLAG = 'flag';
    public const string INT = 'int';
    public const string FLOAT = 'float';
    public const string STRING = 'string';
    public const string APPEND = 'append';

    /** @param list<string> $aliases */
    private function __construct(
        public readonly string $name,
        public readonly string $type,
        public readonly mixed $default,
        public readonly ?float $min,
        public readonly ?float $max,
        public readonly array $aliases,
        public readonly string $help,
    ) {
    }

    public static function flag(string $name, string $help = ''): self
    {
        return new self($name, self::FLAG, false, null, null, [], $help);
    }

    /** @param list<string> $aliases */
    public static function int(string $name, int $default, ?int $min = null, ?int $max = null, string $help = '', array $aliases = []): self
    {
        return new self($name, self::INT, $default, $min, $max, $aliases, $help);
    }

    /** @param list<string> $aliases */
    public static function float(string $name, float $default, ?float $min = null, ?float $max = null, string $help = '', array $aliases = []): self
    {
        return new self($name, self::FLOAT, $default, $min, $max, $aliases, $help);
    }

    public static function str(string $name, ?string $default = null, string $help = ''): self
    {
        return new self($name, self::STRING, $default, null, null, [], $help);
    }

    public static function append(string $name, string $help = ''): self
    {
        return new self($name, self::APPEND, [], null, null, [], $help);
    }
}
