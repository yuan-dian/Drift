<?php
declare(strict_types=1);

namespace Drift\DevTools\Report;

use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;
use Drift\DevTools\App\Replay;
use Drift\DevTools\Host\Plan;
use Drift\DevTools\Host\ProcessTransport;
use Drift\DevTools\Host\Runner;
use Drift\DevTools\Protocol\RemoteSession;
use Drift\DevTools\Session\Digest;
use Drift\DevTools\View\Report;

/**
 * `debug report` 的编排层：跑一次、概括脚本、比对两次、取某一帧的归因。
 *
 * 这些以前是出口脚本里的四个全局函数。搬出来的理由不是行数，而是**调用面**：
 * 出口只负责"从命令行拿到一个 options 数组，再把结果呈现出来"，重放的语义
 * 单独有一处可以测、可以复用（面板若要 `--verify` 同款判据不必复制脚本）。
 *
 * 本地与 `--remote` 仍走同一段代码，差别只在"谁执行那串步骤"。
 */
final class ReportRunner
{
    /**
     * 跑一次：建应用 → 开会话 → 照脚本驱动 → 收报告。
     *
     * 一次调用 = 一次完整运行。`--verify` 因此只是"调两遍再比"，
     * 不需要另写一条"为了比对而跑的路径"—— 那种路径迟早和正常路径分叉。
     *
     * @param array{
     *     label: string, page: bool, rows: int,
     *     width: int, height: int, script: Script|null,
     *     steps: list<array{0: string, 1: string}>, frames: int, hold: int, at: int|null,
     *     top: int, depth: int, why: int|null, whyFrame: int|null, remote: bool
     * } $o
     *
     * @return array{report: array<string, mixed>, script: Script, digest: array<string, mixed>}
     */
    public static function runOnce(array $o): array
    {
        $meta = $o['rows'] > 0 ? ['rows' => $o['rows']] : [];
        // 目标是不是路由 id 也写进脚本：宿主进程只看脚本，没有 `--page` 这条命令行可依。
        if ($o['page']) {
            $meta['page'] = 1;
        }

        // 要驱动的那份：重放时是**原脚本**（不可变），否则由命令行规划出来。
        $drive = $o['script'] ?? Plan::build(
            $o['label'],
            (float) $o['width'],
            (float) $o['height'],
            $meta,
            $o['frames'],
            $o['hold'],
            $o['steps'],
        );

        // ★ 同一份脚本跑两遍，靠的是"脚本不被改动"。录制落在**分叉**上（同目标同视口、
        //   步骤为空），原脚本保持不可变；"录下来的"与"要驱动的"是否一致，就是 `--verify`
        //   的第一级判据。
        $skeleton = $drive->fork();

        /** @var list<array{0: int, 1: float}> $frameTimes 按推进顺序：帧号 + 整帧墙钟 */
        $frameTimes = [];
        $onFrame = static function (int $id, float $ms) use (&$frameTimes): void {
            $frameTimes[] = [$id, $ms];
        };

        $session = null;

        try {
            // ★ 驱动只有**一条**路径（脚本 + {@see Replay}），本地与 `--remote` 的差别只在
            //   "谁执行那串步骤"。以前本地是手写 pump 循环：`--at` 截断、动作后的 `--hold`、
            //   时间戳递进各写一遍，远端得再写一遍 —— 两处只要有一处不同，同一份脚本
            //   给出的报告就是两个故事，而两份报告看起来都正常。
            if ($o['remote']) {
                $session = new RemoteSession(ProcessTransport::spawn([
                    \PHP_BINARY,
                    \dirname(__DIR__) . '/bin/debug-host.php',
                ]));
                $mountMs = $session->open($skeleton);
            } else {
                $opened = Runner::open($o['label'], (float) $o['width'], (float) $o['height'], $meta, $skeleton);
                $session = $opened['session'];
                $mountMs = $opened['mountMs'];
            }

            Replay::apply($session, $drive, [], $o['at'], $onFrame);

            // 帧表：`limit` 就是**这一轮实际推了几帧**。原来按"--frames + 动作 × hold"推算，
            // 那是"打算推几帧"；`--at` 截断时两者不是一回事，报告会把没发生的帧也列进去。
            $recorded = $session->query('frames', ['limit' => \max(1, \count($frameTimes))])['frames'];
            $wall = [];
            foreach ($frameTimes as [$id, $ms]) {
                $wall[$id] = $ms;
            }
            foreach ($recorded as $i => $frame) {
                $recorded[$i]['wall'] = $wall[(int) $frame['id']] ?? (float) $frame['cost'];
            }

            // 逐帧归因走 `explain`：不另读 Recorder，免得 CLI 与面板各写一份"怎么展开一帧"。
            $attribution = [];
            foreach ($recorded as $frame) {
                if ((int) $frame['causes'] === 0) {
                    continue;
                }
                $detail = $session->query('explain', ['frame' => (int) $frame['id']]);
                $ids = [];
                foreach ($detail['rebuilt'] as $row) {
                    $ids[] = (int) $row['id'];
                }
                $attribution[] = [
                    'id' => (int) $detail['id'],
                    't' => (float) $detail['t'],
                    'rebuilt' => \count($ids),
                    // ★ 存 id 而不只存个数：`--verify` 要说出"第 N 帧两边差在哪几个节点"，
                    //   只有总数时只能说"有差"，把定位又退回给读者。
                    'ids' => $ids,
                    // 段名与跳过标记也在记录指纹里（摘要不比耗时，但比"跑了哪几段"），
                    // 所以它们必须能出现在差异报告里，否则"指纹不同而看不出不同"。
                    'stages' => $detail['stages'],
                    'skip' => [$detail['settleSkipped'], $detail['paintSkipped']],
                    'byKind' => $detail['byKind'],
                    'roots' => $detail['roots'],
                ];
            }

            $allHotspots = $session->query('hotspots', ['frames' => 0, 'limit' => 0]);
            $allNodes = $session->query('nodes', ['frames' => 0, 'limit' => 0]);

            // 因果森林只取最慢帧：读者问"为什么这么慢"问的是那一帧，不是平均。
            //
            // 但**最慢帧常常是挂载帧**（首次 layout 最贵，却一个节点都没重建），拿它做
            // 因果树只会得到"（没有重建节点）"，而"点了之后那一帧动的是什么"就在旁边
            // 几行里。所以退一格：优先"最慢的**有重建**的帧"，都没有才回到最慢帧
            // （那时"没有重建"是这一整段的事实，不是选错了帧）。
            $slowestId = Report::slowestFrame($recorded);
            $cascadeId = Report::slowestFrame($recorded, true);
            if ($cascadeId <= 0) {
                $cascadeId = $slowestId;
            }
            $cascade = $cascadeId > 0
                ? $session->query('cascade', ['frame' => $cascadeId, 'maxDepth' => $o['depth'], 'maxChildren' => 4])
                : null;

            $report = [
                'app' => $o['label'],
                'replayed' => $o['script'] !== null,
                'viewport' => [$o['width'], $o['height']],
                'mountMs' => $mountMs,
                // 跑这次会话的进程：同进程跑时为 null。报告要能说清"这份因果是谁跑出来的"，
                // 也是 `--remote` 真的交出去了的唯一可观测量。
                'hostPid' => $session instanceof RemoteSession ? $session->hostPid() : null,
                'stats' => $session->query('stats'),
                'frames' => $recorded,
                'attribution' => $attribution,
                // ★ 排行榜被 `--top` 截断时必须说还有多少：319 个节点各重建一次被切成
                //   十行，读者会以为"就这十个"。这正是本项目最不想要的"看起来完整"。
                //   `limit=0` = 全量，两次扫描算完再切，避免同一份聚合跑四遍。
                'hotspots' => \array_slice($allHotspots, 0, $o['top']),
                'hiddenHotspots' => \max(0, \count($allHotspots) - $o['top']),
                'nodes' => \array_slice($allNodes, 0, $o['top']),
                'hiddenNodes' => \max(0, \count($allNodes) - $o['top']),
                'why' => $o['why'] === null ? null : $session->query('why', $o['whyFrame'] === null ? ['node' => $o['why']] : ['node' => $o['why'], 'frame' => $o['whyFrame']]),
                'cascade' => $cascade,
                // 因果树取的是"最慢的**有重建**的帧"，与 `最慢帧` 可能不是一个：
                // 纯重绘的帧（动画 / 滚动插值）可以最慢却一个节点都没重建，拿它做因果树
                // 只会得到"（没有重建节点）"。报告要把这件事说出来，不能默默换帧。
                'slowestFrame' => $slowestId,
            ];

            /** @var array<string, mixed> $scriptRaw */
            $scriptRaw = $session->query('script');

            return [
                'report' => $report,
                // 脚本与摘要都走**查询**：本地与远端因此完全同形，不必给远端多加动词。
                'script' => Script::fromArray($scriptRaw),
                'digest' => $session->query('digest'),
            ];
        } finally {
            $session?->close();
        }
    }

    /** 脚本的一句话概览（报告头与 JSON 共用）。 */
    public static function scriptSummary(Script $script): array
    {
        return [
            'steps' => $script->count(),
            'frames' => $script->count(Step::FRAME),
            'inputs' => $script->count(Step::KEY) + $script->count(Step::POINTER) + $script->count(Step::SCROLL),
            'effects' => $script->count(Step::EFFECT),
            'digest' => Digest::short($script->digest()),
            'truncated' => $script->truncated(),
        ];
    }

    /**
     * "同一脚本跑两遍"的判据报告。
     *
     * 比较三级：驱动摘要（脚本是否一样）→ 节点表 → 逐帧因果。分开报是必要的：
     * 驱动相同而因果不同 ⇒ **引擎不确定**（真 bug）；驱动就不同 ⇒ 脚本读写出错，
     * 谈因果没意义。混成一句"不一致"，下次就得重新查是哪一种。
     *
     * @param array<string, mixed> $a
     * @param array<string, mixed> $b
     */
    public static function reportVerify(array $a, array $b, string $path, bool $json): bool
    {
        /** @var array<string, mixed> $sa */
        $sa = $a['script']->toArray();
        /** @var array<string, mixed> $sb */
        $sb = $b['script']->toArray();
        $scriptSame = $a['script']->digest() === $b['script']->digest();
        $nodesSame = $a['digest']['nodes'] === $b['digest']['nodes'];
        $digestSame = $a['digest']['digest'] === $b['digest']['digest'];

        /** @var array<int, string> $fa */
        $fa = $a['digest']['frames'];
        /** @var array<int, string> $fb */
        $fb = $b['digest']['frames'];
        $firstDiff = null;
        foreach ($fa as $id => $hash) {
            if (($fb[$id] ?? null) !== $hash) {
                $firstDiff = $id;
                break;
            }
        }
        $ok = $scriptSame && $nodesSame && $digestSame && $firstDiff === null;

        if ($json) {
            \fwrite(\STDOUT, \json_encode([
                'ok' => $ok,
                'script' => $path,
                'steps' => \count($sa['steps'] ?? []),
                'frames' => \count($fa),
                'scriptDigestSame' => $scriptSame,
                'nodesSame' => $nodesSame,
                'digestSame' => $digestSame,
                'firstDifferentFrame' => $firstDiff,
                'a' => ['nodes' => Digest::short((string) $a['digest']['nodes']), 'digest' => Digest::short((string) $a['digest']['digest'])],
                'b' => ['nodes' => Digest::short((string) $b['digest']['nodes']), 'digest' => Digest::short((string) $b['digest']['digest'])],
            ], \JSON_UNESCAPED_UNICODE | \JSON_PRETTY_PRINT) . "\n");

            return $ok;
        }

        \fwrite(\STDOUT, \sprintf("重放一致性（同一脚本跑两遍）  %s\n", $path));
        \fwrite(\STDOUT, \sprintf(
            "  脚本     %d 步 / %d 帧    驱动摘要 %s  %s\n",
            \count($sa['steps'] ?? []),
            \count($fa),
            $scriptSame ? '相同' : '不同',
            $scriptSame ? '✓' : '✗',
        ));
        \fwrite(\STDOUT, \sprintf(
            "  节点表   %s / %s  %s\n",
            Digest::short((string) $a['digest']['nodes']),
            Digest::short((string) $b['digest']['nodes']),
            $nodesSame ? '✓' : '✗',
        ));
        \fwrite(\STDOUT, \sprintf(
            "  逐帧     %s  %s\n",
            $firstDiff === null ? \sprintf('%d 帧全部相同', \count($fa)) : "第 {$firstDiff} 帧起不同",
            $firstDiff === null ? '✓' : '✗',
        ));
        \fwrite(\STDOUT, \sprintf(
            "  记录摘要 %s / %s  %s\n",
            Digest::short((string) $a['digest']['digest']),
            Digest::short((string) $b['digest']['digest']),
            $digestSame ? '✓' : '✗',
        ));

        if ($firstDiff !== null) {
            /** @var array<string, mixed>|null $rowA */
            $rowA = self::attributionOf($a, $firstDiff);
            /** @var array<string, mixed>|null $rowB */
            $rowB = self::attributionOf($b, $firstDiff);
            $idsA = \is_array($rowA) ? $rowA['ids'] : [];
            $idsB = \is_array($rowB) ? $rowB['ids'] : [];

            foreach ([['A', $rowA, $idsA], ['B', $rowB, $idsB]] as [$tag, $row, $ids]) {
                if ($row === null) {
                    \fwrite(\STDOUT, "    {$tag} #{$firstDiff}  这一帧没有重建（或不存在）\n");
                    continue;
                }
                \fwrite(\STDOUT, \sprintf(
                    "    %s #%d  重建 %d 个，段 %s，跳过 %s\n",
                    $tag,
                    $firstDiff,
                    \count($ids),
                    \implode('+', \array_map('strval', (array) $row['stages'])),
                    \implode('/', \array_map(static fn(bool $s): string => $s ? '1' : '0', (array) $row['skip'])),
                ));
                if ($ids !== []) {
                    \fwrite(\STDOUT, '      节点：' . \implode(',', \array_slice($ids, 0, 24)) . (\count($ids) > 24 ? '…' : '') . "\n");
                }
            }

            $onlyA = \array_values(\array_diff($idsA, $idsB));
            $onlyB = \array_values(\array_diff($idsB, $idsA));
            if ($onlyA !== [] || $onlyB !== []) {
                \fwrite(\STDOUT, '    只在 A：' . ($onlyA === [] ? '—' : \implode(',', \array_slice($onlyA, 0, 24))) . "\n");
                \fwrite(\STDOUT, '    只在 B：' . ($onlyB === [] ? '—' : \implode(',', \array_slice($onlyB, 0, 24))) . "\n");
            }

            \fwrite(\STDOUT, $scriptSame
                ? "  ⇒ 驱动相同而因果不同：这是引擎的不确定性（随机数 / 墙钟 / 对象 id 序），不是脚本的问题\n"
                : "  ⇒ 驱动本身就不同：先查脚本读写出错，谈因果没意义\n");
        }

        return $ok;
    }

    /**
     * 从报告里取某一帧的归因行（不存在时 `null`）。
     *
     * @param array<string, mixed> $run
     *
     * @return array<string, mixed>|null
     */
    public static function attributionOf(array $run, int $frame): ?array
    {
        foreach ($run['report']['attribution'] as $row) {
            if (\is_array($row) && (int) ($row['id'] ?? 0) === $frame) {
                return $row;
            }
        }

        return null;
    }
}
