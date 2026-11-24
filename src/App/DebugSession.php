<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Debug\Session\Query;
use Drift\Debug\Session\QueryRouter;
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
     * 能力面与形状都住在 {@see QueryRouter}：会话只负责"驱动"，而查询分派要能脱离
     * 活应用使用（导入的证据包没有应用），所以那份代码不能长在这里。
     *
     * 这里留常量别名，是因为 CLI 帮助与判据一直按 `DebugSession::QUERIES` 取能力面；
     * 换名字不带来任何好处，只会让调用处多一处要改。
     *
     * @var array<string, string>
     */
    public const QUERIES = QueryRouter::QUERIES;

    /** @var array<string, array{0: string, 1: array<string, array{0: string, 1?: mixed}>}> */
    public const QUERY_SPECS = QueryRouter::QUERY_SPECS;

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
        private readonly QueryRouter $router,
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
        bool $redactBodies = true,
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

        $recorder = Recorder::install($capacity, $script, $redactBodies);
        $queries = new Query($recorder);

        // 活树根走闭包注入：`QueryRouter` 住在 `Debug\Session`，那一层不该知道有"应用"。
        $router = new QueryRouter(
            $recorder,
            $queries,
            static fn(): array => $app->treeRoots(),
            static fn(): array => $app->treeRootOrigins(),
        );

        return new self($app, $constraints, $recorder, $queries, $router, $script);
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
     * 按名字分派查询。名字与形状都见 {@see QueryRouter}。
     *
     * 会话只做转发：分派要能脱离活应用使用（导入的证据包没有应用），所以那份代码
     * 住在 `Debug\Session`。转发而不是复制，是为了让"离线复算与在线逐值相同"
     * 这条判据证明的是**同一份实现**，而不是两份碰巧一致。
     *
     * @param array<string, mixed> $args
     * @return array<string, mixed>|list<array<string, mixed>>
     */
    public function query(string $name, array $args = []): array
    {
        return $this->router->query($name, $args);
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
}
