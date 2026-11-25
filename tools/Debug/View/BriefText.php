<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\View;

use Drift\Debug\Session\Thresholds;

/**
 * 终端呈现：把投影排成一段预算内的文本，并自报裁了多少。
 *
 * ## 为什么裁剪在这里，不在投影里
 *
 * 预算的单位是**行**。行是终端的概念：Web 能滚动，机器消费者拿 JSON。把行预算塞进
 * `Brief::project()` 的后果是那个函数的签名里躺着两个排版参数（`budget` / `width`），
 * 而"同一份证据给同一结论"与行宽毫无关系 —— 第二个消费者进来时得先假装自己有 100 列。
 *
 * ## 为什么阈值从投影里取
 *
 * `render()` 不收 `Thresholds` 参数，而是用 `$projection['thresholds']` 重建。参数能传
 * 错，数据不能：两条路径共用同一个数组，"表头写的阈值"与"规则用的阈值"因此不可能
 * 是两个数。这是 B4 §7 那条纪律（页面不许有第二个 16.6）在终端这边的同一句话。
 *
 * ## 三条纪律，缺一条就不再是证据
 *
 * 1. **纯函数**：同样的 `facts` + `budget` 必须给出逐字节相同的行。无时间戳、
 *    无随机、排序必须有确定的次级键（耗时降序 → 帧号升序）。
 * 2. **自报裁剪**：裁掉多少、裁在哪、怎么取回，都要写进最后一行。读者会把
 *    "我没看到"当成"不存在"，而报告本身看不出来。
 * 3. **不按时间截断**：永远保留最贵帧与最热节点。只按"最近 N 帧"截，窗口一长
 *    真正的根因正好被裁掉，而读者以为"没什么问题"。
 *
 * ## 为什么发现先于明细
 *
 * 预算不够时先丢明细、再丢发现，而不是相反：明细可以按地址重新取回，发现不能
 * ——它是"要去查什么"的入口，丢了就不知道该查哪。
 *
 * @internal 证据层内部；`tools/debug-brief.php` 是它唯一的调用方
 */
final class BriefText
{
    /**
     * @param array<string, mixed> $facts
     * @param array{findings: list<Finding>, coverage: array<string, mixed>, thresholds: array<string, float|int>, selection?: array<string, mixed>} $projection
     * @return array{lines: list<string>, omitted: array<string, int>}
     */
    public static function render(array $facts, array $projection, int $budget = 40, int $width = 100): array
    {
        $t = Thresholds::default()->with($projection['thresholds'] ?? []);
        $findings = $projection['findings'] ?? [];
        $cov = $projection['coverage'] ?? [];

        $head = self::header($facts, $width, $t, $projection['selection'] ?? []);
        $detail = self::detail($facts, $width);

        // 固定开销：表头三行 + "发现 N 条" + 空行 + 裁剪账一行。
        $fixed = \count($head) + 3;
        $room = \max(1, $budget - $fixed);

        // 发现优先：每条一行，超了就截，但至少留一条（"没有发现"也是一种结论）。
        $shown = $findings;
        $droppedFindings = 0;
        if (\count($shown) > $room) {
            $kept = \max(1, $room - 1);
            $droppedFindings = \count($shown) - $kept;
            $shown = \array_slice($shown, 0, $kept);
        }

        $detailRoom = \max(0, $room - \count($shown));
        $shownDetail = \array_slice($detail, 0, $detailRoom);
        $droppedDetail = \count($detail) - \count($shownDetail);

        $tail = self::omissions($facts, $cov, $droppedFindings, $droppedDetail, $width);

        $lines = $head;
        $lines[] = \sprintf('发现 %d 条%s', \count($findings), $droppedFindings > 0 ? \sprintf('（显示 %d 条）', \count($shown)) : '');
        foreach ($shown as $one) {
            $lines[] = '  ' . $one->line($width - 2);
        }
        if ($shownDetail !== []) {
            $lines[] = '';
            foreach ($shownDetail as $line) {
                $lines[] = $line;
            }
        }
        $lines[] = '';
        $lines[] = $tail;

        return [
            'lines' => $lines,
            'omitted' => [
                'findings' => $droppedFindings,
                'detail' => $droppedDetail,
                'budget' => $budget,
                'rows' => \count($lines),
            ],
        ];
    }

    /**
     * @param array<string, mixed> $facts
     * @param array<string, mixed> $selection
     * @return list<string>
     */
    private static function header(array $facts, int $width, Thresholds $t, array $selection = []): array
    {
        $stats = Facts::mapOf($facts, 'stats');
        $window = Facts::mapOf($facts, 'window');
        $digest = Facts::mapOf($facts, 'digest');

        $title = \sprintf(
            '证据 · %s · 窗口 #%d–#%d / %d 帧 / 容量 %d / 节点 %d',
            (string) ($facts['label'] ?? '?'),
            (int) ($window['from'] ?? 0),
            (int) ($window['to'] ?? 0),
            (int) ($window['count'] ?? 0),
            (int) ($stats['capacity'] ?? 0),
            (int) ($stats['nodes'] ?? 0),
        );

        $finger = (string) ($digest['digest'] ?? '');
        // 过滤过就报"跑了几条"，不报阈值：三个阈值在只跑一条规则时是**假信息**
        // —— 读者会以为那三条也看过，而它们连跑都没跑。
        $line = ($selection['filtered'] ?? false) === true
            ? \sprintf(
                '指纹 %s · 规则 %d/%d 条%s',
                $finger === '' ? '—' : \substr($finger, 0, 12),
                \count($selection['rules'] ?? []),
                (int) ($selection['total'] ?? 0),
                isset($selection['minSeverity']) && $selection['minSeverity'] !== null
                    ? ' · ≥' . $selection['minSeverity']
                    : '',
            )
            : \sprintf(
                '指纹 %s · 规则 慢帧 %.1fms / 扇出 %d / 重复 %d%%',
                $finger === '' ? '—' : \substr($finger, 0, 12),
                $t->slowMs,
                $t->fanoutNodes,
                $t->repeatPercent,
            );

        return [
            Fit::clip($title, $width),
            Fit::clip($line, $width),
            Fit::rule($width),
        ];
    }

    /**
     * 明细：最贵帧的段账、最大写入点、最热节点、这一帧画了什么。四行封顶，多了也不是信息。
     *
     * @param array<string, mixed> $facts
     * @return list<string>
     */
    private static function detail(array $facts, int $width): array
    {
        $out = [];

        $explain = Facts::mapOf($facts, 'explain');
        $frameId = (int) ($explain['id'] ?? 0);
        if ($frameId > 0) {
            $parts = [];
            foreach (Facts::mapOf($explain, 'stageMs') as $stage => $ms) {
                $parts[] = \sprintf('%s %.1f', (string) $stage, (float) $ms);
                if (\count($parts) >= 3) {
                    break;
                }
            }
            $parts[] = \sprintf('段外 %.1f', (float) ($explain['unattributedMs'] ?? 0.0));
            $out[] = Fit::spread(
                \sprintf(
                    '#%d %.2fms  %s',
                    $frameId,
                    (float) ($explain['cost'] ?? 0.0),
                    \implode(' · ', $parts),
                ),
                \sprintf('重建 %d', \count(Facts::listOf($explain, 'rebuilt'))),
                $width,
            );
        }

        $hotspots = Facts::listOf($facts, 'hotspots');
        if ($hotspots !== []) {
            $top = $hotspots[0];
            $out[] = Fit::clip(\sprintf(
                '最大写入点 %s → %d 个节点（%d 帧里出现 %d 次）',
                (string) ($top['site'] ?? '?'),
                (int) ($top['subtree'] ?? 0),
                (int) ($top['frames'] ?? 0),
                (int) ($top['writes'] ?? 0),
            ), $width);
        }

        $nodes = Facts::listOf($facts, 'nodes');
        if ($nodes !== []) {
            $top = $nodes[0];
            // 路径从**尾部**裁：`… > Table > TableBox` 才认得出是哪个节点，
            // 从头裁只剩 `PageShell > Card > Column > …`，每个节点都长这样。
            $path = Fit::tailPath((string) ($top['path'] ?? '?'), \max(20, $width - 34));
            $out[] = Fit::clip(\sprintf(
                '最热节点 %s 重建 %d 次（自写 %d / 继承 %d）',
                $path,
                (int) ($top['rebuilds'] ?? 0),
                (int) ($top['self'] ?? 0),
                (int) ($top['inherited'] ?? 0),
            ), $width);
        }

        // 绘制：与"重建"分开报。重建少而重画多，正是缺重绘边界的形状 —— 两个数
        // 并排出现在一屏里，这个组合才看得出来。`paintedNodes` 是映射到节点的去重数，
        // `paintedTotal` 是真实绘制次数，两者之差就是没有 Element 认领的盒（浮层 / 内部盒）。
        $paint = Facts::mapOf($facts, 'paint');
        if (($paint['found'] ?? false) === true) {
            $out[] = Fit::spread(
                \sprintf(
                    '重画 %d 次（%d 个节点 / %d 个盒无主）',
                    (int) ($paint['paintedTotal'] ?? 0),
                    (int) ($paint['paintedNodes'] ?? 0),
                    (int) ($paint['unmapped'] ?? 0),
                ),
                \sprintf(
                    '层缓存命中 %d · 重录 %d',
                    (int) ($paint['hits'] ?? 0),
                    \count(Facts::mapOf($paint, 'misses')),
                ),
                $width,
            );
        }

        return $out;
    }

    /**
     * 绘制域的扫描范围，写进裁剪账。
     *
     * 绘制窗口只有 60 帧（帧窗口是 600），不写这一句，读者会把"没报重画问题"
     * 当成"全窗口都没有重画问题"。
     *
     * @param array<string, mixed> $facts
     */
    private static function paintScope(array $facts): string
    {
        $agg = Facts::mapOf($facts, 'repaints');
        if (($agg['captured'] ?? false) !== true) {
            return '绘制未采集';
        }
        $window = Facts::mapOf($agg, 'window');
        $capacity = (int) ($window['capacity'] ?? 0);
        if ((int) ($agg['frames'] ?? 0) === 0) {
            // 开了预算但一帧都还没画：`#0–#0` 会被读成一个真实帧号。
            return \sprintf('绘制 0/%d 帧', $capacity);
        }

        return \sprintf(
            // 比几何那句更短：裁剪账一行要装下四个域的范围，长一句就会把末尾的
            // "窗口已满 / 未满"挤掉，而那正是这一行最不能省的部分。容量直接写在
            // 斜杠后面（`#1–#7/60`），不再单独起一句。
            '绘制 #%d–#%d/%d',
            (int) ($window['from'] ?? 0),
            (int) ($window['to'] ?? 0),
            $capacity,
        ) . self::foldedNote($window);
    }

    /**
     * 帧窗口里被折叠掉的静止帧数。
     *
     * 空帧先于实帧淘汰（见 `Recorder::trim()`），`from–to` 的跨度于是可以远大于保留
     * 条数：`#12–#12573/600` 单看像"窗口有一万两千帧"。这个数把那段差说清楚。
     *
     * @param array<string, mixed> $window
     */
    private static function foldedNote(array $window): string
    {
        $folded = (int) ($window['folded'] ?? 0);

        return $folded > 0 ? \sprintf('（静止帧折叠 %d）', $folded) : '';
    }

    /**
     * 几何域的扫描范围，写进裁剪账。
     *
     * "窗口外"和"这一帧没变所以没记"不是同一件事：前者要明说，后者正常。
     *
     * @param array<string, mixed> $facts
     */
    private static function geometryScope(array $facts): string
    {
        $geometry = Facts::mapOf($facts, 'geometry');
        $rows = Facts::listOf($geometry, 'rows');
        if ($rows === []) {
            return '几何未采集';
        }

        $frame = (int) ($geometry['frame'] ?? 0);
        $window = Facts::mapOf($geometry, 'window');
        $from = (int) ($window['from'] ?? 0);
        $to = (int) ($window['to'] ?? 0);
        if ($frame > 0 && $from > $frame) {
            return \sprintf('几何窗口 #%d–#%d 不含 #%d', $from, $to, $frame);
        }

        return \sprintf('几何覆盖 #%d（%d 个盒 / %d 帧窗口）', $frame, \count($rows), (int) ($window['capacity'] ?? 0));
    }

    /**
     * 裁剪账：裁了什么、裁了多少、怎么取回。**这一行不能省**。
     *
     * @param array<string, mixed> $facts
     */
    private static function omissions(array $facts, array $cov, int $droppedFindings, int $droppedDetail, int $width): string
    {
        $stats = Facts::mapOf($facts, 'stats');
        $window = Facts::mapOf($facts, 'window');

        $parts = [];
        if ($droppedFindings > 0) {
            $parts[] = \sprintf('发现 %d 条未显示', $droppedFindings);
        }
        if ($droppedDetail > 0) {
            $parts[] = \sprintf('明细 %d 行未显示', $droppedDetail);
        }
        // 因果树的**扫描范围**必须写出来，哪怕没有节点被省：
        // "扫到第 2 层没发现问题"和"扫到第 12 层没发现问题"是两句不同的话。
        if ($cov['seen'] > 0) {
            // 不写 "seen/requested"：一个是节点层级（从 1 数），一个是展开次数
            // （从 0 数），并排写会让人以为 `13/12` 是"超了"。给请求值本身即可。
            $parts[] = $cov['complete']
                ? \sprintf('因果树扫到第 %d 层（完整）', $cov['seen'])
                : \sprintf(
                    // 两类裁剪分开写：深度到顶（`cut`）与同层分支被折叠（`nested`）
                    // 是不同的原因，调参的方向也不同（maxDepth vs maxChildren）。
                    '因果树扫到第 %d 层，%d 处未展开（深度 %d / 同层折叠 %d；请求 maxDepth=%d）',
                    $cov['seen'],
                    $cov['cut'] + (int) ($cov['nested'] ?? 0),
                    $cov['cut'],
                    (int) ($cov['nested'] ?? 0),
                    $cov['requested'],
                );
        }
        // 几何也要自报扫描范围：60 帧的几何窗口比 600 帧的因果窗口短，
        // 不写这一句，读者会把"没报布局问题"当成"全窗口都没有布局问题"。
        $parts[] = self::geometryScope($facts);
        // 绘制同理，且窗口同样是 60 帧 —— 两个短窗口各说各的，不合并成一句。
        $parts[] = self::paintScope($facts);

        // 帧级不完整（异常中断 / 因果边超限）必须在裁剪账里出现：这两种情况下
        // 树和规则都"看起来正常"，只有这一行能说明它们其实缺料。
        if ($cov['broken'] !== []) {
            $parts[] = \sprintf('帧级证据不完整（%s）', \implode('/', $cov['broken']));
        }

        $capacity = (int) ($stats['capacity'] ?? 0);
        $count = (int) ($window['count'] ?? 0);
        if ($capacity > 0 && $count < $capacity) {
            // 窗口没满时，"窗口外没有"是事实；满了就要明说更早的帧已被丢弃。
            $parts[] = \sprintf('窗口未满（%d/%d），更早的帧尚未产生', $count, $capacity);
        } elseif ($capacity > 0) {
            $parts[] = \sprintf('窗口已满（%d/%d），更早的帧已被丢弃', $count, $capacity);
        }
        if ($parts === []) {
            $parts[] = '无裁剪';
        }

        // 先保"裁了什么"，再保"怎么取回"：提示被截掉只是少一条指路，
        // 计数被截掉就是"裁剪账不完整"，而这一行唯一不能少的就是计数。
        $core = '裁剪：' . \implode('；', $parts);
        $hint = '（--budget=N 看更多，--json 取结构化）';

        return Fit::width($core . $hint) <= $width
            ? $core . $hint
            : Fit::clip($core, $width);
    }
}
