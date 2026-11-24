<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Debug\Session\Query;
use Drift\Debug\Session\Recorder;
use Drift\Geometry\BoxConstraints;
use Drift\Input\InputEvent;

/**
 * 调试会话：装录制器、推进帧、分派查询、卸载。
 *
 * ## 为什么需要它
 *
 * "装 Recorder + 手写帧循环 + 查记录"这三步谁都要写一遍就会各写一版：测试里一套、
 * 脚本里一套、将来真窗口里再一套。三套一旦分叉，"headless 与 live 的结论一致"就
 * 没有保证。会话把驱动方式收进一个对象，查询代码对所有形态相同。
 *
 * ## 为什么住在 `App` 而不是 `Debug`
 *
 * 它要驱动 `Application`、注入 `InputEvent`——这是应用层职责。`Debug\Session`
 * 只读记录、不驱动应用，所以那层不反向依赖 `App`；门面塞进 `Debug` 会凭空造出
 * `Debug → App` 的环，而记录层不该知道有"应用"这个东西。
 *
 * ## 它不管什么
 *
 * 不管表面：调用方必须先 `attach()`。headless 测试要读像素时用 `SurfaceProbe`
 * 这类夹具，`src/` 不能依赖 `tools/`，所以由调用方决定用什么表面。
 *
 * 不管序列化：记录是内存对象，D1 只做进程内查询。跨进程传输是下一期的事，
 * 提前把 wire 格式定下来只会得到一份没人验证的 schema。
 *
 * @api
 */
final class DebugSession
{
    /**
     * 查询名 => 一句话说明。`query()` 的 `match` 分支必须与这里一一对应：
     * 名单是可遍历的能力面，`match` 是唯一的执行路径，两者漂了由判据咬红。
     *
     * @var array<string, string>
     */
    public const QUERIES = [
        'stats' => '录制概览：帧数 / 节点数 / 容量 / 最近帧',
        'frames' => '最近 N 帧摘要，可按“有重建”过滤',
        'explain' => '单帧展开：重建节点（带路径）与根因分布',
        'why' => '某节点在某帧为什么重建',
        'hotspots' => '跨帧聚合根因（kind=state）：调用点 + State 类',
        'nodes' => '跨帧聚合节点：重建次数、自身写入与父传播',
        'cascade' => '单帧因果森林：结构根、子树规模、state 写入点、按类型构成',
    ];

    private bool $closed = false;

    private function __construct(
        private readonly Application $app,
        private readonly BoxConstraints $constraints,
        private readonly Recorder $recorder,
        private readonly Query $queries,
    ) {
    }

    /**
     * 开启会话；调用前应用必须已经 `attach()`。
     *
     * 已有录制器在跑时**拒绝**，不覆盖：那可能是另一个会话或测试夹具的记录，
     * 静默接管会让两边的结论都不可信。
     */
    public static function open(
        Application $app,
        BoxConstraints $constraints,
        int $capacity = Recorder::DEFAULT_CAPACITY,
    ): self {
        if (Recorder::installed()) {
            throw new \LogicException('已有录制器在运行：先关闭原会话，避免覆盖它的缓冲');
        }
        if ($app->root() === null) {
            throw new \LogicException('应用还没有挂表面：先 $app->attach($surface, $constraints)');
        }

        $recorder = Recorder::install($capacity);

        return new self($app, $constraints, $recorder, new Query($recorder));
    }

    /**
     * 推进一帧，返回**整帧墙钟**（毫秒）。
     *
     * 这个数与记录里的 `cost` 不是一个口径：记录里的 `cost` 只包住 `shell->frame()`，
     * 应用若 override 了 `frame()` 并在 `parent::frame()` 之后继续干活，那段不在其中。
     * 对外报告帧耗时用这个返回值（与 `FrameClock` 同口径），分析段内结构用记录里的 `cost`。
     */
    public function pump(float $timestampMs): float
    {
        $t0 = \hrtime(true);
        $this->app->frame($timestampMs, $this->constraints);

        return (\hrtime(true) - $t0) / 1e6;
    }

    /**
     * 喂一条输入。走的是真实派发链（加速键 → IME → 焦点链），不是直接调 State：
     * 直接调 State 只能验证记录器，验证不了"用户这样做时到底会不会重建"。
     */
    public function send(InputEvent $event, ?float $timestampMs = null): void
    {
        $this->app->dispatchInput($event, $timestampMs);
    }

    /**
     * 连推 N 帧，帧间隔固定（默认 16ms）。
     *
     * @return list<float> 每帧墙钟毫秒，与 {@see self::pump()} 同口径
     */
    public function pumpTimes(int $count, float $stepMs = 16.0, float $startMs = 0.0): array
    {
        $wall = [];
        for ($i = 0; $i < $count; ++$i) {
            $wall[] = $this->pump($startMs + $i * $stepMs);
        }

        return $wall;
    }

    /**
     * 按名字分派查询。名字必须在 {@see self::QUERIES} 里。
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function query(string $name, array $args = []): array
    {
        return match ($name) {
            'stats' => $this->queries->stats(),
            'frames' => $this->queries->frames(
                self::intArg($name, $args, 'limit', 30),
                self::boolArg($args, 'rebuiltOnly'),
            ),
            'explain' => $this->queries->explain(self::needInt($name, $args, 'frame')),
            'why' => $this->queries->why(
                self::needInt($name, $args, 'node'),
                isset($args['frame']) ? (int) $args['frame'] : null,
            ),
            'hotspots' => $this->queries->hotspots(
                self::intArg($name, $args, 'frames', 300),
                self::intArg($name, $args, 'limit', 10),
            ),
            'nodes' => $this->queries->nodes(
                self::intArg($name, $args, 'frames', 300),
                self::intArg($name, $args, 'limit', 10),
            ),
            'cascade' => $this->queries->cascade(
                self::needInt($name, $args, 'frame'),
                self::intArg($name, $args, 'maxDepth', 5),
                self::intArg($name, $args, 'maxChildren', 8),
            ),
            default => throw new \InvalidArgumentException(
                "未知查询：{$name}（可用：" . \implode(' / ', \array_keys(self::QUERIES)) . '）',
            ),
        };
    }

    /** @return list<array<string, mixed>> */
    public function frames(int $limit = 30, bool $rebuiltOnly = false): array
    {
        return $this->queries->frames($limit, $rebuiltOnly);
    }

    /** @return array<string, mixed> */
    public function stats(): array
    {
        return $this->queries->stats();
    }

    /** @return list<array<string, mixed>> */
    public function hotspots(int $frames = 300, int $limit = 10): array
    {
        return $this->queries->hotspots($frames, $limit);
    }

    /** @return list<array<string, mixed>> */
    public function nodes(int $frames = 300, int $limit = 10): array
    {
        return $this->queries->nodes($frames, $limit);
    }

    /** @return array<string, mixed> */
    public function cascade(int $frameId, int $maxDepth = 5, int $maxChildren = 8): array
    {
        return $this->queries->cascade($frameId, $maxDepth, $maxChildren);
    }

    public function recorder(): Recorder
    {
        return $this->recorder;
    }

    public function app(): Application
    {
        return $this->app;
    }

    /**
     * 关闭会话并卸下录制器。
     *
     * 只在当前录制器**仍是自己装的**那个时才卸：别把后来者的缓冲摘掉。
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        if (Recorder::current() === $this->recorder) {
            Recorder::uninstall();
        }
    }

    // ------------------------------------------------------------------ 参数

    /** @param array<string, mixed> $args */
    private static function needInt(string $name, array $args, string $key): int
    {
        if (!isset($args[$key]) || (!\is_int($args[$key]) && !\is_string($args[$key]))) {
            throw new \InvalidArgumentException("查询 {$name} 需要整型参数 {$key}");
        }

        return (int) $args[$key];
    }

    /** @param array<string, mixed> $args */
    private static function intArg(string $name, array $args, string $key, int $default): int
    {
        if (!isset($args[$key])) {
            return $default;
        }
        if (!\is_int($args[$key]) && !\is_string($args[$key])) {
            throw new \InvalidArgumentException("查询 {$name} 的参数 {$key} 必须是整数");
        }

        return (int) $args[$key];
    }

    /** @param array<string, mixed> $args */
    private static function boolArg(array $args, string $key): bool
    {
        return (bool) ($args[$key] ?? false);
    }
}
