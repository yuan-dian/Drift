<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * eval 回执的读数：值 + 类型 + 两行条件提示。
 *
 * ## 为什么提示是有条件的
 *
 * 两件事只在发生时要说，没发生时说"没发生"是噪声：
 *
 * - `truncated` —— 结果树被深度 / 宽度截断，读者不能把看到的值当全部；
 * - `evidenceChanged` —— 求值改了应用状态，求值前的证据解释不了求值后的行为。
 *
 * 后者尤其不能省：eval 是内省不是证据（A55），但它**可能**改状态，而用户
 * 很可能正拿着刚导出的包在旁边对照。不说，他会拿旧证据解释新状态。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class EvalText
{
    /** @param array<array-key, mixed> $answer 一次 `eval` 的回执 */
    public static function lines(array $answer): array
    {
        $expression = \is_string($answer['expression'] ?? null) ? $answer['expression'] : '';
        $type = \is_string($answer['type'] ?? null) ? $answer['type'] : 'unknown';
        $value = $answer['value'] ?? null;
        $node = \is_int($answer['node'] ?? null) ? $answer['node'] : 0;

        $lines = [
            '$ ' . $expression,
            '= ' . self::show($value),
            '  ' . $type . ($node > 0 ? "  @node={$node}" : ''),
        ];

        if (($answer['truncated'] ?? false) === true) {
            $lines[] = '  （结果已截断：深度或宽度超上限，上面不是全部）';
        }
        if (($answer['evidenceChanged'] ?? false) === true) {
            $lines[] = '  [注意] 求值改变了应用状态：求值前导出的证据不能解释现在的行为';
        }

        return $lines;
    }

    /** 紧凑可读：标量直出，结构走 JSON（已经是 JSON-safe 的规范化结果）。 */
    private static function show(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }
        if (\is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (\is_scalar($value)) {
            return (string) $value;
        }

        $json = \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_UNESCAPED_SLASHES | \JSON_PRETTY_PRINT);

        return $json === false ? '<无法编码的值>' : $json;
    }
}
