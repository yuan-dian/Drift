<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

/**
 * 一个节点的聚合读模型：一次给全，但每个 section 独立自报状态。
 *
 * 这里只组合已有查询，不新增采集；`properties` / `source` 是 dev 内省，带 `live=true`。
 *
 * @internal 读面投影；不进证据包、不进指纹
 */
final class NodeContext
{
    public const array SECTIONS = [
        'identity', 'geometry', 'layout', 'causality', 'paint', 'network', 'properties', 'source',
    ];

    public function __construct(
        private readonly Query $queries,
        private readonly History $history,
    ) {
    }

    /** @return array{node: int, frame: int, sections: array<string, array<string, mixed>>} */
    public function of(int $node, ?int $frame = null, string $sections = '', array $roots = []): array
    {
        $wanted = $this->wanted($sections);
        $frame = $this->frame($frame);
        $geometryFrame = $this->geometryFrame($frame);

        $align = null;
        $alignError = null;
        if ($frame > 0 && \array_intersect(array_keys($wanted), ['causality', 'network']) !== []) {
            try {
                $align = $this->queries->align($node, $frame);
            } catch (\Throwable $e) {
                $alignError = $e;
            }
        }

        $out = ['node' => $node, 'frame' => $frame, 'sections' => []];
        foreach (self::SECTIONS as $name) {
            if (!isset($wanted[$name])) {
                continue;
            }
            $out['sections'][$name] = match ($name) {
                'identity' => $this->ok($this->identity($node), $frame),
                'geometry' => $this->geometry($node, $geometryFrame, $frame),
                'layout' => $this->layout($node, $geometryFrame, $frame),
                'causality' => $this->causality($node, $align, $alignError, $frame),
                'paint' => $this->paint($node, $frame),
                'network' => $this->network($node, $align, $alignError, $frame),
                'properties' => $this->properties($node, $roots),
                'source' => $this->source($node, $frame),
            };
        }

        return $out;
    }

    /** @return array<string, true> */
    private function wanted(string $sections): array
    {
        if ($sections === '' || $sections === 'all') {
            return \array_fill_keys(self::SECTIONS, true);
        }

        $out = [];
        foreach (\explode(',', $sections) as $name) {
            $name = \trim($name);
            if ($name === '') {
                continue;
            }
            if (!\in_array($name, self::SECTIONS, true)) {
                throw new \InvalidArgumentException(
                    '未知 section：' . $name . '（可用：' . \implode(' / ', self::SECTIONS) . '）',
                );
            }
            $out[$name] = true;
        }

        return $out === [] ? \array_fill_keys(self::SECTIONS, true) : $out;
    }

    private function frame(?int $frame): int
    {
        if ($frame !== null) {
            return \max(0, $frame);
        }
        $last = $this->history->lastFrameId();

        return $last > 0 ? $last : (int) ($this->history->geometryCoverage()['to'] ?? 0);
    }

    /** 几何按变化存：帧已发生但几何没重采样时，用最近一条并标 stale。 */
    private function geometryFrame(int $frame): int
    {
        $to = (int) ($this->history->geometryCoverage()['to'] ?? 0);
        $last = $this->history->lastFrameId();

        return $to > 0 && $frame > $to && $frame <= $last ? $to : $frame;
    }

    /** @return array<string, mixed> */
    private function identity(int $node): array
    {
        return [
            'node' => $node,
            'type' => $this->history->typeOf($node),
            'path' => $this->history->pathOf($node),
            'status' => $this->history->nodeStatus($node),
        ];
    }

    private function geometry(int $node, int $at, int $requested): array
    {
        if ($requested <= 0) {
            return $this->unavailable('no-frame', $requested);
        }
        try {
            $data = $this->queries->boxModel($node, $at);
        } catch (\Throwable $e) {
            return $this->error($e);
        }
        if (($data['found'] ?? false) === true) {
            $data['requestedFrame'] = $requested;
            $data['stale'] = $at !== $requested;

            return $this->ok($data, $requested, false, (int) ($data['frame'] ?? $at));
        }

        return $this->miss($data, $requested, ['frame-out-of-window', 'geometry-not-captured']);
    }

    private function layout(int $node, int $at, int $requested): array
    {
        if ($requested <= 0) {
            return $this->unavailable('no-frame', $requested);
        }
        try {
            $data = $this->queries->constraints($node, $at, 12);
        } catch (\Throwable $e) {
            return $this->error($e);
        }
        if (($data['found'] ?? false) === true) {
            $data['requestedFrame'] = $requested;
            $data['stale'] = $at !== $requested;

            return $this->ok($data, $requested, false, (int) ($data['frame'] ?? $at));
        }

        return $this->miss($data, $requested, ['frame-out-of-window']);
    }

    private function causality(int $node, ?array $align, ?\Throwable $error, int $frame): array
    {
        if ($frame <= 0) {
            return $this->unavailable('no-frame', $frame);
        }
        if ($error !== null) {
            return $this->error($error);
        }
        $data = $align['causal'] ?? ['found' => false, 'reason' => 'unknown-node'];
        $data['node'] ??= $node;

        return ($data['found'] ?? false) === true
            ? $this->ok($data, $frame)
            : $this->miss($data, $frame, []);
    }

    private function paint(int $node, int $frame): array
    {
        if ($frame <= 0) {
            return $this->unavailable('no-frame', $frame);
        }
        try {
            $data = $this->queries->paint($frame);
        } catch (\Throwable $e) {
            return $this->error($e);
        }
        if (($data['found'] ?? false) !== true) {
            return $this->unavailable((string) ($data['reason'] ?? 'paint-not-captured'), $frame);
        }

        $painted = \array_map('\intval', (array) ($data['painted'] ?? []));
        if (\in_array($node, $painted, true)) {
            return $this->ok([
                'painted' => true,
                'frame' => $frame,
                'layerHits' => (int) ($data['layerHits'] ?? 0),
                'paintedTotal' => (int) ($data['paintedTotal'] ?? 0),
            ], $frame);
        }
        if (($data['truncated'] ?? false) === true) {
            return $this->unavailable('paint-truncated', $frame);
        }

        return $this->empty('not-painted', $frame);
    }

    private function network(int $node, ?array $align, ?\Throwable $error, int $frame): array
    {
        if ($frame <= 0) {
            return $this->unavailable('no-frame', $frame);
        }
        if ($error !== null) {
            return $this->error($error);
        }
        $data = $align['network'] ?? ['found' => false, 'reason' => 'no-landed-request'];
        $data['node'] ??= $node;

        return ($data['found'] ?? false) === true
            ? $this->ok($data, $frame)
            : $this->miss($data, $frame, []);
    }

    private function properties(int $node, array $roots): array
    {
        try {
            $data = $this->queries->props($node, $roots);
        } catch (\Throwable $e) {
            return $this->error($e);
        }
        if (($data['found'] ?? false) === true) {
            return $this->ok($data, null, true);
        }

        return ($data['reason'] ?? '') === 'reflection-unavailable'
            ? $this->unavailable('reflection-unavailable', null, true)
            : $this->empty((string) ($data['reason'] ?? 'node-unmounted'), null, true);
    }

    private function source(int $node, int $frame): array
    {
        if ($frame <= 0) {
            return $this->unavailable('no-frame', $frame);
        }
        try {
            $why = $this->queries->why($node, $frame);
        } catch (\Throwable $e) {
            return $this->error($e);
        }

        $site = '';
        foreach (['trail', 'causes'] as $key) {
            foreach ((array) ($why[$key] ?? []) as $row) {
                $candidate = (string) ($row['site'] ?? '');
                if ($candidate !== '') {
                    $site = $candidate;
                    break 2;
                }
            }
        }
        if ($site === '') {
            return $this->empty('no-callsite', $frame);
        }

        try {
            $data = $this->queries->source($site);
        } catch (\Throwable $e) {
            return $this->error($e);
        }
        if (($data['found'] ?? false) !== true) {
            return ($data['reason'] ?? '') === 'reflection-unavailable'
                ? $this->unavailable('reflection-unavailable', $frame)
                : $this->empty((string) ($data['reason'] ?? 'source-not-found'), $frame);
        }

        $data['editorUri'] = SourceLink::uri((string) $data['file'], (int) $data['line']);

        return $this->ok($data, $frame);
    }

    /** @return array<string, mixed> */
    private function ok(array $data, ?int $frame = null, bool $live = false, ?int $actualFrame = null): array
    {
        return [
            'status' => 'ok',
            'reason' => null,
            'frame' => $actualFrame ?? $frame,
            'live' => $live,
            'data' => $data,
        ];
    }

    /** @return array<string, mixed> */
    private function empty(string $reason, ?int $frame = null, bool $live = false): array
    {
        return ['status' => 'empty', 'reason' => $reason, 'frame' => $frame, 'live' => $live, 'data' => null];
    }

    /** @return array<string, mixed> */
    private function unavailable(string $reason, ?int $frame = null, bool $live = false): array
    {
        return ['status' => 'unavailable', 'reason' => $reason, 'frame' => $frame, 'live' => $live, 'data' => null];
    }

    /** @return array<string, mixed> */
    private function error(\Throwable $e): array
    {
        return ['status' => 'error', 'reason' => $e->getMessage(), 'frame' => null, 'live' => false, 'data' => null];
    }

    /** @return array<string, mixed> */
    private function miss(array $data, int $frame, array $unavailable): array
    {
        $reason = (string) ($data['reason'] ?? 'not-found');
        $status = \in_array($reason, $unavailable, true) ? 'unavailable' : 'empty';

        return ['status' => $status, 'reason' => $reason, 'frame' => $frame, 'live' => false, 'data' => $data];
    }
}
