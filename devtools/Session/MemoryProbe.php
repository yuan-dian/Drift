<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

use Drift\Widget\Element;
use Drift\Widget\StatefulElement;

/**
 * 进程内存 + 活树对象的跨帧内省（A55：L2，只在 dev、只在当下、不进包）。
 *
 * ## 为什么不是"每对象占多少字节"
 *
 * PHP 没有取单个对象堆大小的 API —— 任何号称"Widget 占 3.2KB"的数字都是编的。
 * 能诚实回答的是**另一件事**：这个对象从哪一帧起一直活着。于是这里报的是存活
 * 跨度（当前帧 − 首次见到帧），而不是字节数：一个跨越 200 帧还在的对象，比一个
 * 刚创建的对象的泄漏嫌疑大 —— 这才是 PHP 里能站得住的内存线索。
 *
 * ## 为什么用 WeakMap
 *
 * 台账本身不能成为泄漏源。普通 `array<object, int>` 会强持有每个对象，让本该被
 * 回收的对象永远留着；`WeakMap<object, int>` 的键是弱引用，对象一出活树就能被
 * GC，台账不会把它钉住。代价是"已消失对象"的个数**不可靠**（GC 什么时候跑不由
 * 我们定），所以这里不报它 —— 宁可少报一个数，也不报一个会说谎的数。
 *
 * ## 状态挂在实例上
 *
 * 一个 {@see Query} 实例活一个会话（见 `DebugSession::open()`），本探针由它持有，
 * 所以跨多次 `memory()` 查询累积的是同一本台账。换会话就是新探针，帧号从 1 重来，
 * 不会把上一轮的对象算进新一轮。
 *
 * @internal L2 内省；不进证据包、不进指纹、不进 AOT 产物
 */
final class MemoryProbe
{
    /** 每个类型最多列多少行的缺省值，其余计入 omitted（防止一棵异构树刷屏）。 */
    private const int TYPE_LIMIT = 40;

    /** @var \WeakMap<object, int> 对象 => 首次见到的帧号 */
    private \WeakMap $seen;

    public function __construct()
    {
        $this->seen = new \WeakMap();
    }

    /** 能力探测：任缺一件就整域自报不可用，不返回一份"看起来正常"的假数据。 */
    public static function available(): bool
    {
        return \class_exists(\WeakMap::class)
            && \function_exists('gc_status')
            && \function_exists('memory_get_usage');
    }

    /**
     * 采一次样：进程标量 + 活树对象按类型的存活跨度。
     *
     * @param list<mixed> $roots 活树根（由会话注入；非 Element 跳过）
     *
     * @return array<string, mixed>
     */
    public function sample(array $roots, int $frame, int $limit = self::TYPE_LIMIT, bool $reset = false): array
    {
        $limit = \max(1, \min(400, $limit));
        if ($reset) {
            // 显式重置：帧号归零时用（重开一轮）。不自动判 frame 变小 ——
            // 会话自己管生命周期，探针猜"帧号倒退 = 新会话"会误伤 --replay。
            $this->seen = new \WeakMap();
        }

        $usage = \memory_get_usage(false);
        $peak = \memory_get_peak_usage(false);
        $gc = \gc_status();

        /** @var array<string, array{kind: string, objects: int, oldest: int, maxAge: int, fresh: int}> $agg */
        $agg = [];
        $visited = 0;
        $tracked = 0;

        $record = function (object $object, string $kind, int $frame) use (&$agg, &$tracked): void {
            ++$tracked;
            $first = $this->seen[$object] ?? $frame;
            $this->seen[$object] = $first;
            $type = self::shortClass($object::class);
            $key = $kind . "\0" . $type;
            $age = \max(0, $frame - $first);
            if (!isset($agg[$key])) {
                $agg[$key] = ['kind' => $kind, 'type' => $type, 'objects' => 0, 'oldest' => $frame, 'maxAge' => 0, 'fresh' => 0];
            }
            ++$agg[$key]['objects'];
            $agg[$key]['oldest'] = \min($agg[$key]['oldest'], $first);
            $agg[$key]['maxAge'] = \max($agg[$key]['maxAge'], $age);
            if ($first === $frame) {
                ++$agg[$key]['fresh'];
            }
        };

        $walk = function (Element $element) use (&$walk, $record, $frame, &$visited): void {
            ++$visited;
            $record($element, 'element', $frame);
            $record($element->widget(), 'widget', $frame);
            if ($element instanceof StatefulElement) {
                $record($element->state(), 'state', $frame);
            }
            $element->visitChildren($walk);
        };
        foreach ($roots as $root) {
            if ($root instanceof Element) {
                $walk($root);
            }
        }

        $rows = \array_values($agg);
        // 最老的先显示：找泄漏就是找"谁从很早就一直在"。同跨度按对象数、类型名兜底，输出稳定。
        \usort($rows, static fn(array $a, array $b): int => ($b['maxAge'] <=> $a['maxAge'])
            ?: ($b['objects'] <=> $a['objects'])
            ?: \strcmp($a['type'], $b['type']));
        $total = \count($rows);
        $shown = \array_slice($rows, 0, $limit);

        return [
            'frame' => $frame,
            // 进程标量：如实标"进程级"，不是"本应用占用"—— 一个进程可能有两个窗口。
            'process' => [
                'usage' => $usage,
                'peak' => $peak,
                'gc' => [
                    'running' => (bool) ($gc['running'] ?? false),
                    'roots' => (int) ($gc['roots'] ?? 0),
                    'collected' => (int) ($gc['collected'] ?? 0),
                    'threshold' => (int) ($gc['threshold'] ?? 0),
                ],
            ],
            'live' => [
                'objects' => $tracked,
                'elements' => $visited,
                'types' => $total,
                'rows' => $shown,
                'omitted' => \max(0, $total - \count($shown)),
            ],
            // 明确不是证据：调用方据此决定要不要写进视图的免责行。
            'isEvidence' => false,
        ];
    }

    private static function shortClass(string $class): string
    {
        $pos = \strrpos($class, '\\');

        return $pos === false ? $class : \substr($class, $pos + 1);
    }
}
