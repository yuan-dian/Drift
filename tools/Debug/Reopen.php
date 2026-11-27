<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\DevTools\App\Replay;
use Drift\Debug\Session\Script;
use Drift\DevTools\Session\Session;

/**
 * 把同一份脚本**重开 N 次**，逐层比对，回答"重开这一页是不是等价的"。
 *
 * ## 为什么重开的是应用，不是重放脚本
 *
 * 状态在树里，不在脚本里。复用同一个 `Application` 只重开会话时，第二轮从第一轮
 * 的终点起步：实测同一脚本的第 1 轮与第 2 轮 digest 不同，而第 2、3 轮**彼此相同**
 * —— 一个"看后两轮一致就收工"的实现会恒绿，漏掉的正是"第一轮把页面推走了"。
 * 所以每一轮都必须由工厂造一个新的应用（`Runner::open`），`Reopen` 只负责"每轮
 * 关掉再开下一个"，不猜怎么造。
 *
 * ## 为什么比较要分三层
 *
 * 「结构相同、因果不同」指向状态被带了进来；「结构不同」指向重建顺序变了。两种
 * 修复动作完全不同，而一个整体哈希只能说"不一样"。三层是：节点表 → 逐帧因果 →
 * 整体，报**第一处**不同。
 *
 * ## 为什么内存要 GC 之后才读
 *
 * 裸 `memory_get_usage()` 连跑 50 轮给出 145 → 283 → 159 → 297 MB 的锯齿 ——
 * 它测的是 PHP 什么时候想起来回收，不是有没有东西留下来。`gc_collect_cycles()`
 * 之后同一组运行是 20.832 → 20.834 MB。不 GC 的判据会恒红，且修不了。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Reopen
{
    /** 上限：手滑 `--runs=100000` 要跑半天，而它不比 100 轮多给出任何信息。 */
    public const int MAX_RUNS = 100;

    /**
     * @param callable(int): array{session: Session, mountMs: float} $open 每轮造一个新会话
     *   （必须是新应用）。`$round` 从 1 起，只用于它自己的报错定位。
     * @param int|null $untilFrame 每轮只推进到第 N 帧（`null` = 整份脚本）
     * @param null|callable(int, Session): void $watch 每轮取完快照、**关闭之前**调一次。
     *   给"导出第 1 轮"用：证据只能在会话活着时写出去，关掉之后录制器就卸了。
     *
     * @return array{
     *     runs: list<array<string, mixed>>,
     *     consistent: bool|null,
     *     divergence: array<string, mixed>|null,
     *     mount: array{median: float, spread: float},
     *     firstFrame: array{median: float, spread: float},
     *     memory: array{first: float, last: float, deltaPercent: float, rising: bool}
     * }
     *
     * @throws \InvalidArgumentException 轮数越界
     * @throws \Throwable 任一轮跑不起来时原样抛出（会话已在 finally 里关掉）
     */
    public static function run(
        callable $open,
        Script $drive,
        int $runs,
        ?int $untilFrame = null,
        ?callable $watch = null,
    ): array {
        if ($runs < 1 || $runs > self::MAX_RUNS) {
            throw new \InvalidArgumentException('--runs 要在 1..' . self::MAX_RUNS . " 之间，实际 {$runs}");
        }

        $rows = [];
        for ($round = 1; $round <= $runs; ++$round) {
            $opened = $open($round);
            $session = $opened['session'];
            $row = null;

            try {
                $firstMs = null;
                $frames = Replay::apply(
                    $session,
                    $drive,
                    [],
                    $untilFrame,
                    static function (int $id, float $ms) use (&$firstMs): void {
                        $firstMs ??= $ms;
                    },
                );
                $row = self::snapshot($round, $session, $drive, (float) $opened['mountMs'], $firstMs, $frames);
                if ($watch !== null) {
                    $watch($round, $session);
                }
            } finally {
                // 录制器是进程内单例：这一轮不关，下一轮的 `open` 会直接抛
                // 「已有录制器在运行」。中途抛异常也必须走到这里。
                $session->close();
                unset($session, $opened);

                // 采样放在关闭之后：会话还在时量到的是"这一轮的工作集"，
                // 不是"这一轮有没有留下什么"。
                gc_collect_cycles();
                if ($row !== null) {
                    $row['memory'] = \memory_get_usage();
                    $rows[] = $row;
                }
            }
        }

        return [
            'runs' => $rows,
            // 单轮时是 `null` 而不是 `true`：没有可比对象时的"一致"是空话，
            // 而 `true` 会让 `--assert` 在一轮时打勾通过。
            'consistent' => \count($rows) < 2 ? null : self::divergence($rows) === null,
            'divergence' => self::divergence($rows),
            'mount' => self::spread(\array_column($rows, 'mountMs')),
            'firstFrame' => self::spread(\array_column($rows, 'firstFrameMs')),
            'memory' => self::memory($rows),
        ];
    }

    /**
     * 一轮的全部可比量。**只走查询面**，不摸 `Recorder` —— 与 `brief` 同一条纪律：
     * 同进程与跨进程读到的必须是同一份实现。
     *
     * @return array<string, mixed>
     */
    private static function snapshot(
        int $round,
        Session $session,
        Script $drive,
        float $mountMs,
        ?float $firstFrameMs,
        int $frames,
    ): array {
        $digest = $session->query('digest');
        $stats = $session->query('stats');
        $state = $session->query('state');
        $recorded = $session->query('script');

        $scriptDigest = $recorded === [] ? '' : Script::fromArray($recorded)->digest();

        return [
            'round' => $round,
            'session' => (string) ($state['session'] ?? ''),
            'digest' => (string) ($digest['digest'] ?? ''),
            'nodesDigest' => (string) ($digest['nodes'] ?? ''),
            'frames' => $digest['frames'] ?? [],
            'nodeCount' => (int) ($stats['nodes'] ?? 0),
            'frameCount' => $frames,
            'lastFrame' => (int) ($state['lastFrame'] ?? 0),
            'mountMs' => $mountMs,
            'firstFrameMs' => $firstFrameMs ?? 0.0,
            // 脚本指纹比的是"录下来的是不是喂进去的那份"：重放丢步、多步、
            // 或者时刻被改过，都会在这里显形，而帧因果可能一帧都不差。
            'scriptMatches' => $scriptDigest === $drive->digest(),
            'scriptDigest' => $scriptDigest,
        ];
    }

    /**
     * 第一处不同，以及受影响的全部轮次。
     *
     * 顺序就是"从结构到因果"：节点数 → 节点表 → 脚本 → 逐帧。前面一层不同时，
     * 后面那层的差异多半是它的下游，报出来只会让人多查一遍。
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array<string, mixed>|null
     */
    private static function divergence(array $rows): ?array
    {
        // 单轮没有可比对象。这不叫"通过"，叫"没测"——出口负责把这句话说出来。
        if (\count($rows) < 2) {
            return null;
        }

        $base = $rows[0];
        $rounds = [];
        $found = null;

        foreach (\array_slice($rows, 1) as $row) {
            $part = self::firstDiff($base, $row);
            if ($part === null) {
                continue;
            }
            $rounds[] = $row['round'];
            $found ??= ['round' => $row['round']] + $part;
        }

        if ($found === null) {
            return null;
        }

        return $found + ['rounds' => $rounds];
    }

    /**
     * @param array<string, mixed> $base
     * @param array<string, mixed> $row
     *
     * @return array{part: string, frame?: int, detail: string}|null
     */
    private static function firstDiff(array $base, array $row): ?array
    {
        if ($row['nodeCount'] !== $base['nodeCount']) {
            return [
                'part' => 'nodes',
                'detail' => "节点数 {$base['nodeCount']} → {$row['nodeCount']}",
            ];
        }
        if ($row['nodesDigest'] !== $base['nodesDigest']) {
            return ['part' => 'nodes', 'detail' => '节点表不同（同数量、不同树，或同树、不同号）'];
        }
        if ($row['scriptMatches'] === false) {
            return ['part' => 'script', 'detail' => '录下的脚本与喂进去的不是同一份'];
        }
        if ($row['frameCount'] !== $base['frameCount']) {
            return [
                'part' => 'frames',
                'detail' => "推进帧数 {$base['frameCount']} → {$row['frameCount']}",
            ];
        }

        /** @var array<int, string> $baseFrames */
        $baseFrames = $base['frames'];
        /** @var array<int, string> $rowFrames */
        $rowFrames = $row['frames'];
        foreach ($baseFrames as $id => $hash) {
            if (($rowFrames[$id] ?? null) !== $hash) {
                return ['part' => 'frame', 'frame' => (int) $id, 'detail' => "第 {$id} 帧的因果不同"];
            }
        }

        if ($row['digest'] !== $base['digest']) {
            // 三层都同而整体不同：只可能是节点表与帧之外的东西进了指纹。
            // 到这一步说明 Digest 的定义变了，值得人看一眼，而不是静默通过。
            return ['part' => 'digest', 'detail' => '各层都相同但整体指纹不同'];
        }

        return null;
    }

    /**
     * @param list<float> $values
     *
     * @return array{median: float, spread: float}
     */
    private static function spread(array $values): array
    {
        if ($values === []) {
            return ['median' => 0.0, 'spread' => 0.0];
        }
        \sort($values);
        $n = \count($values);
        $mid = \intdiv($n, 2);
        $median = $n % 2 === 1 ? $values[$mid] : ($values[$mid - 1] + $values[$mid]) / 2;

        return ['median' => $median, 'spread' => $values[$n - 1] - $values[0]];
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array{first: float, last: float, deltaPercent: float, rising: bool}
     */
    private static function memory(array $rows): array
    {
        $values = \array_column($rows, 'memory');
        if ($values === []) {
            return ['first' => 0.0, 'last' => 0.0, 'deltaPercent' => 0.0, 'rising' => false];
        }

        $first = (float) $values[0];
        $last = (float) $values[\count($values) - 1];

        // "单调上升"与"涨了一点"是两件事：GC 后的锯齿已经被钳掉了，剩下的单调
        // 上升才是"每轮都留下一点"。只看首末差会把 50 轮里的一次抖动也算成泄漏。
        $rising = \count($values) > 2;
        for ($i = 1, $n = \count($values); $i < $n; ++$i) {
            if ((float) $values[$i] <= (float) $values[$i - 1]) {
                $rising = false;
                break;
            }
        }

        return [
            'first' => $first,
            'last' => $last,
            'deltaPercent' => $first > 0 ? ($last - $first) / $first * 100 : 0.0,
            'rising' => $rising,
        ];
    }
}
