<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use Drift\DevTools\Session\Session;
use Drift\Input\InputEvent;

/**
 * 一份"已经录好"的会话，用来把视图层的判据与真应用解耦。
 *
 * ## 为什么需要它
 *
 * 面板要证明"2000 行也只用一屏"。用真应用造 2000 个重建节点要跑布局、要等挂载，
 * 而判据关心的只有一件事：**行表有 2000 行时，输出还是 24 行**。夹具直接给一份
 * `cascade`，这条判据就与"这一页碰巧重建了多少"无关。
 *
 * 它同时是"面板只依赖 `Session` 接口"的活证明：真会话、远端会话、这份假会话，
 * 面板都跑得起来 —— 因为它只问那四个查询。
 */
final class FakePanelSession implements Session
{
    public int $pumps = 0;

    /** @var list<float> */
    public array $times = [];

    /** @var list<int> 收到的 `highlight()` 请求（按顺序） */
    public array $highlights = [];

    /**
     * @param list<array<string, mixed>> $frames
     * @param array<int, array<string, mixed>> $cascades 帧号 => cascade
     * @param array<int, array<string, mixed>> $whys     节点 id => why
     */
    public function __construct(
        private array $frames,
        private array $cascades,
        private array $whys = [],
        private int $capacity = 240,
        /** 每次 `pump()` 新帧的"重建数"：0 = 静止帧（真页面的常态），>0 = 像动画帧 */
        private int $pumpRebuilt = 0,
    ) {
    }

    /** @param array<string, mixed> $args */
    public function query(string $name, array $args = []): array
    {
        return match ($name) {
            'stats' => [
                'frames' => \count($this->frames),
                'nodes' => (int) ($this->cascades === [] ? 0 : 1),
                'capacity' => $this->capacity,
                'lastCostMs' => 0.0,
                'lastRebuilt' => 0,
            ],
            'frames' => [
                'frames' => $this->sliceFrames(
                    (int) ($args['limit'] ?? 30),
                    isset($args['since']) ? (int) $args['since'] : null,
                    (bool) ($args['rebuiltOnly'] ?? false),
                ),
                'window' => [
                    'from' => $this->frames === [] ? 0 : (int) $this->frames[0]['id'],
                    'to' => $this->frames === [] ? 0 : (int) $this->frames[\count($this->frames) - 1]['id'],
                    'capacity' => $this->capacity,
                    'frames' => \count($this->frames),
                ],
                'dropped' => 0,
                'since' => isset($args['since']) ? (int) $args['since'] : null,
            ],
            'cascade' => $this->cascades[(int) ($args['frame'] ?? 0)]
                ?? throw new \InvalidArgumentException('FakePanelSession 没有这一帧：' . (string) ($args['frame'] ?? '')),
            'why' => $this->whys[(int) ($args['node'] ?? 0)] ?? [
                'node' => (int) ($args['node'] ?? 0),
                'path' => 'Fake',
                'frame' => $args['frame'] ?? null,
                'rebuilt' => true,
                'causes' => [],
                'trail' => [],
            ],
            default => throw new \InvalidArgumentException("FakePanelSession 不认识查询：{$name}"),
        };
    }

    /** @return list<array<string, mixed>> */
    private function sliceFrames(int $limit, ?int $since, bool $rebuiltOnly): array
    {
        $out = [];
        foreach ($this->frames as $frame) {
            if ($since !== null && (int) $frame['id'] <= $since) {
                continue;
            }
            if ($rebuiltOnly && (int) $frame['rebuilt'] === 0) {
                continue;
            }
            $out[] = $frame;
        }

        return $limit > 0 ? \array_slice($out, -$limit) : $out;
    }

    public function pump(float $timestampMs): float
    {
        ++$this->pumps;
        $this->times[] = $timestampMs;
        $last = $this->frames === [] ? null : $this->frames[\count($this->frames) - 1];
        $id = $last === null ? 1 : (int) $last['id'] + 1;
        $this->frames[] = [
            'id' => $id,
            't' => $timestampMs,
            'cost' => 1.0,
            'rebuilt' => $this->pumpRebuilt,
            'causes' => $this->pumpRebuilt,
            'stageMs' => [],
            'unattributedMs' => 0.0,
            'settleSkipped' => false,
            'paintSkipped' => false,
        ];
        // 新帧沿用上一帧的树：面板在"跟着走"时会去问这一帧的 cascade，
        // 而夹具的判据只关心"它跟着走了没有"，不关心树的形状。
        if ($this->cascades !== []) {
            $lastId = (int) \array_key_last($this->cascades);
            $tree = $this->cascades[$lastId];
            $tree['frame'] = $id;
            $this->cascades[$id] = $tree;
        }

        return 1.0;
    }

    public function send(InputEvent $event, ?float $timestampMs = null): void
    {
    }

    public function effect(string $name, array $args = [], ?float $timestampMs = null): mixed
    {
        return null;
    }

    /**
     * 面板不消费高亮（它是"读记录"的消费者），而接口要求实现。
     *
     * 这里**记下**请求而不是空实现：真有人拿这个夹具测"驱动面接对了没"，
     * 空实现会让那种判据恒绿 —— 绿得毫无意义。
     */
    public function highlight(int $node, ?float $timestampMs = null): void
    {
        $this->highlights[] = $node;
    }

    public function finish(?int $at = null): array
    {
        return [
            'session' => 'fake',
            'state' => $at === null ? 'finished' : 'paused',
            'reason' => $at === null ? null : 'at',
            'lastFrame' => $this->lastFrameId(),
        ];
    }

    /** 假会话没有录制器要卸，也没有连接要断 —— 关它是空操作，但必须能关。 */
    public function close(): void
    {
    }

    public function lastFrameId(): int
    {
        return $this->frames === [] ? 0 : (int) $this->frames[\count($this->frames) - 1]['id'];
    }
}
