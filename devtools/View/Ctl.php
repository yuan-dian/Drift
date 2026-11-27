<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * 控制面的读数：一行状态 + 一行"接下来能做什么"。
 *
 * ## 为什么第二行必须有
 *
 * 干预是一条反向流，用户按完暂停之后的下一个问题是"怎么继续"，而不是"我现在在哪
 * 个状态"。只打一行 `paused (at)` 等于把答案留在文档里，而他正在终端上。
 *
 * 第二行只在**能说话的时候**说话：`finished` 没有下一步动作可说，就不编一句。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Ctl
{
    /** `reason` 的人话；`null` 是"没说"，空串是"写了但读不懂"，两种情况都不编。 */
    private const REASONS = [
        'at' => '到了 --at 那个观察点',
        'requested' => '有人按了暂停',
        'step' => '要的那一步走完了',
    ];

    /**
     * @param array<array-key, mixed> $state 一次 `state` 查询（或 `pause` / `resume` 的回执）
     *
     * @return list<string>
     */
    public static function lines(array $state): array
    {
        $session = \is_string($state['session'] ?? null) ? $state['session'] : '';
        $name = \is_string($state['state'] ?? null) ? $state['state'] : 'unknown';
        $reason = \is_string($state['reason'] ?? null) ? $state['reason'] : null;
        $last = \is_int($state['lastFrame'] ?? null) ? $state['lastFrame'] : 0;

        $lines = [\sprintf(
            '会话 %s  %s  末帧 %d',
            $session === '' ? '(离线)' : $session,
            $reason === null ? $name : "{$name} ({$reason})",
            $last,
        )];

        $hint = self::hint($name, $reason);
        if ($hint !== null) {
            $lines[] = $hint;
        }

        return $lines;
    }

    private static function hint(string $name, ?string $reason): ?string
    {
        if ($name === 'paused') {
            $why = $reason === null ? '' : (self::REASONS[$reason] ?? $reason) . '；';

            return '  ' . $why . '继续：drift debug ctl resume（走一步：--frames=1）';
        }

        if ($name === 'crashed') {
            return '  驱动崩了：详情在宿主的 stderr（看 terminal 或输出重定向那个文件）';
        }

        // `running` 与 `finished` 都不编：前者没什么可做的，后者没什么能做的。
        return null;
    }
}
