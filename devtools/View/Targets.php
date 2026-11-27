<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * 跨窗口并列投影：一行一个会话，不把两个窗口揉成一份证据。
 *
 * ## 为什么不 join
 *
 * 节点号是 per-recorder 的（`Recorder::$tag` + `Element::$debugSession`）：两个
 * 窗口的"节点 7"不是同一个节点，帧号也各自从 1 开始。按它们 join 会造出
 * 看起来很合理的假因果——A53 在采集层防的是串证据，这一层防的是同一件事的
 * 投影版。真正跨会话共享身份的那天（worker / 拖拽源）要先在采集层给全局 id
 * 并另立裁决（A59 的失效条件），不是在这里按数字猜。
 *
 * ## 投影只读，不回采集
 *
 * 输入是调用方已经取好的 `Facts::gather()` 与 `Brief::project()` 结果：发现
 * 仍然只由规则注册表产生，"一份投影三种呈现"的纪律不变。这里只做两件查询
 * 层不做的事：把每份压成一行标量，以及给这一组会话算裁剪账。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Targets
{
    /**
     * @param list<array{
     *     id: string,
     *     target: string,
     *     pid: int,
     *     port: int,
     *     startedAt: string,
     *     state: string,
     *     error: string|null,
     *     facts: array<string, mixed>|null,
     *     brief: array<string, mixed>|null
     * }> $rows 每个活会话一行；`state !== 'ok'` 时后两项为 `null`
     *
     * @return array{targets: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function project(array $rows): array
    {
        $targets = [];
        $bySeverity = ['error' => 0, 'warning' => 0, 'info' => 0];
        $connected = 0;
        $unreachable = 0;
        $incomplete = 0;
        $totalFindings = 0;

        foreach ($rows as $row) {
            $facts = \is_array($row['facts'] ?? null) ? $row['facts'] : null;
            $brief = \is_array($row['brief'] ?? null) ? $row['brief'] : null;
            $ok = $row['state'] === 'ok' && $facts !== null && $brief !== null;

            $window = $ok ? Facts::mapOf($facts, 'window') : [];
            $digest = $ok ? Facts::mapOf($facts, 'digest') : [];
            $findings = $ok ? self::findingsOf($brief, $bySeverity) : 0;
            $coverage = $ok ? Facts::mapOf($brief, 'coverage') : [];
            $complete = $ok && ($coverage['complete'] ?? false) === true;

            if ($ok) {
                ++$connected;
                $totalFindings += $findings;
                if (!$complete) {
                    ++$incomplete;
                }
            } else {
                ++$unreachable;
            }

            $targets[] = [
                'id' => (string) ($row['id'] ?? ''),
                'target' => (string) ($row['target'] ?? ''),
                'pid' => (int) ($row['pid'] ?? 0),
                'port' => (int) ($row['port'] ?? 0),
                'startedAt' => (string) ($row['startedAt'] ?? ''),
                'state' => $row['state'],
                'error' => $row['error'],
                'label' => $ok ? (string) ($facts['label'] ?? '') : '',
                'frames' => (int) ($window['count'] ?? 0),
                'window' => [
                    'from' => (int) ($window['from'] ?? 0),
                    'to' => (int) ($window['to'] ?? 0),
                    'capacity' => (int) ($window['capacity'] ?? 0),
                ],
                'worst' => $ok ? self::worstOf($facts) : null,
                'findings' => $findings,
                'digest' => (string) ($digest['digest'] ?? ''),
                'complete' => $complete,
            ];
        }

        // 登记顺序就是启动顺序；再按 startedAt / id 定序，保证同一组会话
        // 每次呈现一致。不按发现数排：那会让"重的在上"随规则改动而变化。
        \usort($targets, static function (array $a, array $b): int {
            $byTime = \strcmp($a['startedAt'], $b['startedAt']);

            return $byTime !== 0 ? $byTime : \strcmp($a['id'], $b['id']);
        });

        return [
            'targets' => $targets,
            'summary' => [
                'sessions' => \count($targets),
                'connected' => $connected,
                'unreachable' => $unreachable,
                'findings' => $totalFindings,
                'bySeverity' => $bySeverity,
                'incomplete' => $incomplete,
            ],
        ];
    }

    /**
     * 发现数只从规则投影里数，不在这里另判一遍（判据与实现同源是最常见的静默缺口）。
     *
     * @param array<string, mixed>      $brief
     * @param array<string, int> $bySeverity 就地累加
     */
    private static function findingsOf(array $brief, array &$bySeverity): int
    {
        $list = $brief['findings'] ?? [];
        if (!\is_array($list)) {
            return 0;
        }

        $count = 0;
        foreach ($list as $finding) {
            if (!\is_object($finding) || !\method_exists($finding, 'toArray')) {
                continue;
            }
            $row = $finding->toArray();
            $severity = (string) ($row['severity'] ?? '');
            if (isset($bySeverity[$severity])) {
                ++$bySeverity[$severity];
            }
            ++$count;
        }

        return $count;
    }

    /** @param array<string, mixed> $facts */
    private static function worstOf(array $facts): ?array
    {
        $worstFrame = 0;
        $worstCost = -1.0;
        foreach (Facts::listOf($facts, 'frames') as $frame) {
            $cost = (float) ($frame['cost'] ?? 0.0);
            if ($cost > $worstCost) {
                $worstCost = $cost;
                $worstFrame = (int) ($frame['id'] ?? 0);
            }
        }

        return $worstFrame > 0 ? ['frame' => $worstFrame, 'cost' => $worstCost] : null;
    }
}
