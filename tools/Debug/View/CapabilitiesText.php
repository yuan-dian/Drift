<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

/**
 * 能力目录的文本面：纯函数，出口只负责 `fwrite`。
 *
 * 排版走 {@see Fit}，不手写 `sprintf('%-Ns')`：角色与理由里有中文，数字节会
 * 让后面每一列都错开。这里也不重算任何事实 —— 行里的名字、可用性、理由全部
 * 照抄协议目录，视图只决定它们摆在哪一列。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class CapabilitiesText
{
    /**
     * @param array<string, mixed> $reply
     * @return list<string>
     */
    public static function render(array $reply, int $width = 100): array
    {
        $w = \max(40, $width);
        $lines = [];
        $client = $reply['clientProtocol'] ?? null;
        $lines[] = Fit::spread(
            '协议 v' . (string) ($reply['protocol'] ?? '?') . '（最低 v' . (string) ($reply['minProtocol'] ?? '?') . '）',
            '客户端 ' . ($client === null ? '未声明' : 'v' . (string) $client),
            $w,
        );

        $host = $reply['host'] ?? null;
        if (\is_array($host)) {
            $lines[] = '宿主：会话' . (($host['sessionOpen'] ?? false) === true ? '已开' : '未开')
                . ' · 帧' . (($host['driven'] ?? false) === true ? '自走' : '客户端推')
                . ' · eval' . (($host['allowEval'] ?? false) === true ? '开' : '关')
                . ' · 拾取' . (($host['pickArmable'] ?? false) === true ? '可布防' : '无窗口')
                . ' · 令牌' . (($host['tokenRequired'] ?? false) === true ? '要' : '不要');
        }

        $methods = self::rows($reply['methods'] ?? []);
        $lines[] = \sprintf('方法 %d：', \count($methods));
        $nameW = 13;
        $kindW = 11;
        foreach ($methods as $row) {
            $nameW = \max($nameW, Fit::width((string) $row['name']));
            $kindW = \max($kindW, Fit::width((string) $row['kind']));
        }
        $nameW = \min($nameW, 20);
        $detailW = \max(8, $w - $nameW - $kindW - 12);
        foreach ($methods as $row) {
            $state = $row['available'] ?? null;
            $tag = $state === null ? '' : ($state === true ? '可用' : '不可用');
            $detail = (string) ($row['reason'] ?? '') !== ''
                ? (string) $row['reason']
                : \implode(' ', \array_map('strval', (array) ($row['params'] ?? [])));
            $lines[] = \rtrim('  ' . Fit::pad(Fit::clip((string) $row['name'], $nameW), $nameW)
                . ' ' . Fit::pad(Fit::clip((string) $row['kind'], $kindW), $kindW)
                . ' ' . Fit::pad(Fit::clip($detail, $detailW), $detailW)
                . ' ' . $tag);
        }

        $queries = self::rows($reply['queries'] ?? []);
        $selfServed = \count(\array_filter($queries, static fn(array $q): bool => ($q['origin'] ?? '') === 'session'));
        $lines[] = \sprintf('查询 %d（会话自产 %d）：', \count($queries), $selfServed);
        $queryW = 14;
        foreach ($queries as $row) {
            $queryW = \max($queryW, Fit::width((string) $row['name']));
        }
        $queryW = \min($queryW, 24);
        foreach ($queries as $row) {
            $params = [];
            foreach ((array) ($row['params'] ?? []) as $p) {
                if (!\is_array($p)) {
                    continue;
                }
                $item = (string) ($p['name'] ?? '') . ':' . (string) ($p['type'] ?? '');
                if (($p['injected'] ?? false) === true) {
                    $item .= '@' . (string) ($p['source'] ?? '');
                } elseif (($p['required'] ?? false) !== true) {
                    $item .= '=' . self::show($p['default'] ?? null);
                }
                $params[] = $item;
            }
            $lines[] = '  ' . Fit::pad(Fit::clip((string) $row['name'], $queryW), $queryW)
                . ' ' . Fit::clip(($row['origin'] ?? '') === 'session' ? '会话' : '规格', 4)
                . ' ' . Fit::clip(\implode(' ', $params), \max(8, $w - $queryW - 8));
        }

        $errors = \is_array($reply['errors'] ?? null)
            ? \array_values(\array_filter($reply['errors'], '\is_string'))
            : [];
        $lines[] = \sprintf('错误码 %d：%s', \count($errors), \implode(' ', $errors));
        $lines[] = '老宿主没有这个动词时回 unknown_method；把它当「版本太老」，别当「没有能力」。';

        return $lines;
    }

    /** @return list<array<string, mixed>> */
    private static function rows(mixed $value): array
    {
        if (!\is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $one) {
            if (\is_array($one)) {
                $out[] = $one;
            } elseif (\is_string($one)) {
                $out[] = $one;
            }
        }

        return $out;
    }

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

        return '?';
    }
}
