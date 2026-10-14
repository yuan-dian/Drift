<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

/**
 * `token_get_all` 结果的游标助手 —— 一个口径一个落点，规则不各写一份。
 */
final class Tokens
{
    private const SKIP = [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT];

    private function __construct()
    {
    }

    /**
     * 下一个有效 token。
     *
     * @param list<mixed> $tokens
     * @return null|array{0: int|string, 1: string, 2: int}
     */
    public static function next(array $tokens, int $i): ?array
    {
        for ($n = count($tokens); $i < $n; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok) && in_array($tok[0], self::SKIP, true)) {
                continue;
            }
            return is_array($tok) ? $tok : [$tok, $tok, 0];
        }
        return null;
    }

    /**
     * 上一个有效 token。
     *
     * @param list<mixed> $tokens
     * @return null|array{0: int|string, 1: string, 2: int}
     */
    public static function prev(array $tokens, int $i): ?array
    {
        for (; $i >= 0; $i--) {
            $tok = $tokens[$i];
            if (is_array($tok) && in_array($tok[0], self::SKIP, true)) {
                continue;
            }
            return is_array($tok) ? $tok : [$tok, $tok, 0];
        }
        return null;
    }
}
