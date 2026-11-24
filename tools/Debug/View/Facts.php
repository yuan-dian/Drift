<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\Debug\Session\SessionQueries;

/**
 * 投影的输入：一份把查询结果摊平的事实袋。
 *
 * ## 为什么它不在出口里
 *
 * 它原先是 `debug-brief.php` 末尾的一个全局函数。第二个消费者（Web 视图）要用它时，
 * 只有三条路：复制（必然与终端漂开）、`include` 出口文件（会把出口的 `exit()` 一起
 * 拉进来）、或改成静态类。第一条最省事也最坏：`facts` 是投影的唯一输入，它有两份，
 * "同一份证据"这个前提就没了。
 *
 * ## 为什么读它也要走这里
 *
 * `listOf` / `mapOf` 不是语法糖。`facts` 一路穿过查询层与 JSON 边界，任何一个键都
 * 可能是 `null`、字符串或空数组；直接 `$facts['frames']` 在缺键时是警告，在类型不对时
 * 是 `TypeError`，而两者的表现都是"报告崩了"而不是"这份证据缺了一段"。守卫放在
 * 造它的同一个类上，读的人不必重新怀疑一遍。
 *
 * @internal 证据层内部；`tools/debug-brief.php` 与 `View\Web` 是它的调用方
 */
final class Facts
{
    /**
     * 从查询面取事实。**不碰 `Recorder`** —— 远端会话没有它。
     *
     * `limit: 0` 是"不截断"（查询层对 0 的约定）。这一条是自报裁剪的前提：
     * 裁剪只能发生在投影层，由查询层先截断的话，投影层就数不出自己裁了多少。
     *
     * @return array<string, mixed>
     */
    public static function gather(SessionQueries $session, string $label): array
    {
        $stats = $session->query('stats');
        // `frames` 自 schema 收口后返回信封（`frames` / `window` / `dropped`）：裸列表在类型上
        // 表达不了"漏了 N 帧"，而简报的每个数字都要能说清自己覆盖了哪一段。
        $framePage = $session->query('frames', ['limit' => 0]);
        $frames = self::listOf($framePage, 'frames');
        // `frames: 0` 与 `limit: 0` 是两件事：前者是**聚合窗口**（默认只看最近 300 帧），
        // 后者是**返回条数**（默认 10 条）。只关掉后者，聚合仍然只数最近 300 帧 ——
        // 而表头写的是整个窗口，"出现 3 帧"就会与 `frames` 的帧数对不上。
        // 两处都归零，简报里的每个数字才都能回溯到同一批帧。
        $hotspots = $session->query('hotspots', ['frames' => 0, 'limit' => 0]);
        $nodes = $session->query('nodes', ['frames' => 0, 'limit' => 0]);
        $digest = $session->query('digest');

        $ids = [];
        $worst = 0;
        $worstCost = -1.0;
        foreach ($frames as $frame) {
            $ids[] = (int) $frame['id'];
            $cost = (float) ($frame['cost'] ?? 0.0);
            if ($cost > $worstCost) {
                $worstCost = $cost;
                $worst = (int) $frame['id'];
            }
        }

        $facts = [
            'label' => $label,
            'stats' => $stats,
            'frames' => $frames,
            'hotspots' => $hotspots,
            'nodes' => $nodes,
            'digest' => $digest,
            'window' => [
                'from' => $ids === [] ? 0 : $ids[0],
                'to' => $ids === [] ? 0 : $ids[\count($ids) - 1],
                'count' => \count($ids),
                'capacity' => (int) ($stats['capacity'] ?? 0),
            ],
        ];

        if ($worst > 0) {
            $facts['explain'] = $session->query('explain', ['frame' => $worst]);
            // 因果树要**全量**（`maxChildren: 0`）：截断交给投影层，它才知道裁了多少。
            // 深度给 12：同形组常常在第三、第四层（表格页是 `Card > Column > SizedBox ×12`），
            // 只给 2 层就永远看不到"列表没虚拟化"这一类。
            //
            // ★ 请求的深度要**原样记进 facts**：投影层据此判断"规则看到的是不是完整的树"。
            //   规则自己不许另设一个深度上限 —— 两边各写一个常量，迟早打架，而打架的
            //   表现形式是"报告显示没问题"。
            $cascadeQuery = ['frame' => $worst, 'maxDepth' => 12, 'maxChildren' => 0];
            $facts['cascadeQuery'] = $cascadeQuery;
            $facts['cascade'] = $session->query('cascade', $cascadeQuery);
        }

        // 几何读同一个最贵帧：布局的"为什么"要与因果的"为什么"对齐到一帧，
        // 否则报告里 `frame:2 node:61` 的两种答案来自两次运行。
        // 走查询面而不是摸 Recorder —— 远端会话没有后者。
        $facts['geometry'] = $worst > 0
            ? $session->query('boxes', ['frame' => $worst])
            : $session->query('boxes');

        return $facts;
    }

    /**
     * @param array<string, mixed> $source
     * @return list<array<string, mixed>>
     */
    public static function listOf(array $source, string $key): array
    {
        $value = $source[$key] ?? null;
        if (!\is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $item) {
            if (\is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function mapOf(array $source, string $key): array
    {
        $value = $source[$key] ?? null;

        return \is_array($value) ? $value : [];
    }
}
