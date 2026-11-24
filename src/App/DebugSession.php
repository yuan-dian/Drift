<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Debug\Session\Digest;
use Drift\Debug\Session\Query;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Session;
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
 * 不管传输：帧记录仍是内存对象，搬出进程由
 * {@see \Drift\Debug\Protocol\RemoteSession} 这个**镜像**负责 —— 它实现同一对接口
 * （{@see Session}），所以消费者的代码不需要知道
 * 记录是本地的还是隔着一条管道。**驱动脚本**是另一回事 —— 它只存标量、能存成
 * JSON，因为"再跑一遍"需要的只是输入与时钟，不需要把记录搬过去。
 *
 * 不管什么该被重放：驱动方给什么就记什么。异步结算这类**副作用**由调用方用
 * {@see self::defineEffect()} 注册名字，{@see self::effect()} 记一笔并执行 ——
 * 会话不猜"你这一页怎么取数"。
 *
 * @api
 */
final class DebugSession implements Session
{
    /**
     * 查询名 => 一句话说明。`query()` 的 `match` 分支必须与这里一一对应：
     * 名单是可遍历的能力面，`match` 是唯一的执行路径，两者漂了由判据咬红。
     *
     * @var array<string, string>
     */
    public const QUERIES = [
        'stats' => '录制概览：帧数 / 节点数 / 容量 / 最近帧',
        'frames' => '最近 N 帧摘要（可给 since 增量游标），可按“有重建”过滤',
        'explain' => '单帧展开：重建节点（带路径）与根因分布',
        'why' => '某节点在某帧为什么重建',
        'hotspots' => '跨帧聚合根因（kind=state）：调用点 + State 类',
        'nodes' => '跨帧聚合节点：重建次数、自身写入与父传播',
        'cascade' => '单帧因果森林：结构根、同形兄弟折叠、子树规模、state 写入点、按类型构成',
        'boxModel' => '单节点盒模型：margin / border / padding / content 四层与位置（几何快照）',
        'constraints' => '单节点约束链：逐跳复算并与快照交叉验证，标出归不到基类默认的跳',
        'digest' => '记录指纹：节点表 + 逐帧因果哈希（不含时间，可跨进程比对）',
        'script' => '本次会话录下的驱动脚本：目标 / 视口 / 环境 / 步骤',
    ];

    private bool $closed = false;

    /** 最近一次 `pump()` 的时间戳：给"帧外副作用"一个可重放的时刻。 */
    private float $lastT = 0.0;

    /** @var array<string, callable(array<string, scalar|null>): mixed> 具名副作用 */
    private array $effects = [];

    private function __construct(
        private readonly Application $app,
        private readonly BoxConstraints $constraints,
        private readonly Recorder $recorder,
        private readonly Query $queries,
        private readonly Script $script,
    ) {
    }

    /**
     * 开启会话；调用前应用必须已经 `attach()`。
     *
     * 已有录制器在跑时**拒绝**，不覆盖：那可能是另一个会话或测试夹具的记录，
     * 静默接管会让两边的结论都不可信。
     *
     * @param Script|null $script 脚本骨架（目标名 / 视口 / 环境）。不给则按
     *   `应用类名 + 当前约束` 现建一份 —— 这样"录一份脚本"是默认行为，
     *   不需要调用方记得开。
     */
    public static function open(
        Application $app,
        BoxConstraints $constraints,
        int $capacity = Recorder::DEFAULT_CAPACITY,
        ?Script $script = null,
    ): self {
        if (Recorder::installed()) {
            throw new \LogicException('已有录制器在运行：先关闭原会话，避免覆盖它的缓冲');
        }
        if ($app->root() === null) {
            throw new \LogicException('应用还没有挂表面：先 $app->attach($surface, $constraints)');
        }

        $script ??= new Script(
            $app::class,
            \is_finite($constraints->maxWidth) ? $constraints->maxWidth : 0.0,
            \is_finite($constraints->maxHeight) ? $constraints->maxHeight : 0.0,
        );

        $recorder = Recorder::install($capacity, $script);

        return new self($app, $constraints, $recorder, new Query($recorder), $script);
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
        $this->lastT = $timestampMs;
        $t0 = \hrtime(true);
        $this->app->frame($timestampMs, $this->constraints);

        return (\hrtime(true) - $t0) / 1e6;
    }

    /**
     * 注册一个具名副作用（`resolve` / `next-page` / `scroll-to` …）。
     *
     * 名字 + 扁平参数是**脚本里能表达**的全部；回调本身留在驱动方 ——
     * 会话不该知道"这一页怎么取数"，那会把测试夹具焊进框架。
     *
     * @param callable(array<string, scalar|null>): mixed $run
     */
    public function defineEffect(string $name, callable $run): void
    {
        $this->effects[$name] = $run;
    }

    /**
     * 执行并**记下**一个副作用。
     *
     * 记的是名字与参数，不是"它做了什么"：重放方用同一份注册表再执行一遍，
     * 结果是否一致由记录摘要判定。这就是为什么异步页的脚本必须带 `resolve` ——
     * 少了它，重放会得到一整段静止画面，而报告本身看不出少了什么。
     *
     * @param array<string, scalar|null> $args
     *
     * @return mixed 回调的返回值
     */
    public function effect(string $name, array $args = []): mixed
    {
        $run = $this->effects[$name] ?? null;
        if ($run === null) {
            throw new \LogicException(
                "未注册的驱动效果：{$name}（已注册：" . ($this->effects === [] ? '无' : \implode(' / ', \array_keys($this->effects))) . '）',
            );
        }

        // 先记后跑：回调抛异常时，脚本里仍如实留下"这一步被尝试过"。
        $this->recorder->noteEffect($name, $args, $this->lastT);

        return $run($args);
    }

    /** 本次会话的脚本（帧边界 + 输入 + 副作用）。 */
    public function script(): Script
    {
        return $this->script;
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
                self::optIntArg($name, $args, 'since'),
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
            'boxModel' => $this->queries->boxModel(
                self::needInt($name, $args, 'node'),
                isset($args['frame']) ? (int) $args['frame'] : null,
            ),
            'constraints' => $this->queries->constraints(
                self::needInt($name, $args, 'node'),
                isset($args['frame']) ? (int) $args['frame'] : null,
                self::intArg($name, $args, 'maxDepth', 12),
            ),
            'digest' => Digest::of($this->recorder),
            // 脚本走查询而不是另开一条命令：它同样是"读会话状态"，而且远端镜像
            // 因此不必为它加一个动词 —— 一次会话里"怎么驱动的"和"为什么这样"
            // 从同一个管道取，顺序也就天然一致。
            'script' => $this->script->toArray(),
            default => throw new \InvalidArgumentException(
                "未知查询：{$name}（可用：" . \implode(' / ', \array_keys(self::QUERIES)) . '）',
            ),
        };
    }

    /** @return list<array<string, mixed>> */
    public function frames(int $limit = 30, bool $rebuiltOnly = false, ?int $since = null): array
    {
        return $this->queries->frames($limit, $rebuiltOnly, $since);
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

    public function lastFrameId(): int
    {
        return $this->recorder->lastFrameId();
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

    /**
     * 可选整型：**缺省与 0 是两件事**。
     *
     * 游标 `since=0` 是"从头给"，而"没给 since"是"给最近 N 帧"。用 `intArg` 的默认值
     * 把两者合并，会让"从头取"变成"取最近的"——差集正好是历史帧，而报告看起来完整。
     *
     * @param array<string, mixed> $args
     */
    private static function optIntArg(string $name, array $args, string $key): ?int
    {
        if (!isset($args[$key])) {
            return null;
        }
        if (!\is_int($args[$key]) && !\is_string($args[$key])) {
            throw new \InvalidArgumentException("查询 {$name} 的参数 {$key} 必须是整数");
        }

        return (int) $args[$key];
    }
}
