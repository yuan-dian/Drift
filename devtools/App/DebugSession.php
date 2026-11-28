<?php
declare(strict_types=1);

namespace Drift\DevTools\App;

use Drift\App\Application;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Recorder;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\SessionState;
use Drift\DevTools\Inspect\Inspector;
use Drift\DevTools\Session\History;
use Drift\DevTools\Session\Query;
use Drift\DevTools\Session\QueryRouter;
use Drift\DevTools\Session\Session;
use Drift\Geometry\BoxConstraints;
use Drift\Input\InputEvent;
use Drift\Input\SemanticsTree;
use Drift\Widget\Element;

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
 * {@see \Drift\DevTools\Protocol\RemoteSession} 这个**镜像**负责 —— 它实现同一对接口
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

    private readonly History $history;

    /**
     * 真机检查器叠加（`null` = 会话已关 / 没装）。
     *
     * ★ 挂在这里而不是宿主：会话是唯一知道"这个窗口装了录制器、也真的在画"的地方。
     *   宿主、出口、Web 三条路都经由会话获取，所以叠加层的存在不取决于谁连上来。
     */
    private ?Inspector $inspector = null;

    private function __construct(
        private readonly Application $app,
        private readonly BoxConstraints $constraints,
        private readonly Recorder $recorder,
        private readonly Query $queries,
        private readonly QueryRouter $router,
        private readonly Script $script,
        private readonly SessionState $state,
    ) {
        // 读语义在 History（审查面）；写侧仍直接走 Recorder。
        $this->history = new History($recorder);
    }

    /**
     * 开启会话；调用前应用必须已经 `attach()`。
     *
     * **这个窗口**已经有会话时拒绝，不覆盖：两路写进同一份记录，静默丢数据的
     * 是后来那一份，而两份报告都会显得正常。
     *
     * ★ 拒的粒度是"窗口"不是"进程"：一个进程可以有多个窗口，每个窗口一份录制器
     *   （见 {@see Recorder::bindTo()}）。进程级的拒会把多窗口挡在门外，却挡不住
     *   真正的污染源 —— 第二个窗口的帧从来不走这里。
     *
     * @param RecordOptions|int|null $options 采集预算；整数是旧容量参数的兼容入口
     * @param Script|null $script 脚本骨架（目标名 / 视口 / 环境）。不给则按
     *   `应用类名 + 当前约束` 现建一份 —— 这样"录一份脚本"是默认行为，
     *   不需要调用方记得开。
     */
    public static function open(
        Application $app,
        BoxConstraints $constraints,
        RecordOptions|int|null $options = null,
        ?Script $script = null,
        bool $redactBodies = true,
    ): self {
        $options = RecordOptions::fromLegacy($options, $script, $redactBodies);
        // ★ 拒的是"**这个窗口**已经有会话"，不是"这个进程里已经有录制器"。
        //   后者会把多窗口挡在门外（一个进程两个窗口是常态），而挡不住真正的
        //   污染源 —— 第二个窗口的帧从来不走 `open()`，它只要开始跑帧就会写进
        //   进程里那一份录制器。真正的隔离靠 `Recorder::bindTo()` + 帧内压栈。
        if ($app->debugRecorder() !== null) {
            throw new \LogicException('这个窗口已经有会话在跑：先关闭它，避免两路写进同一份记录');
        }
        if ($app->root() === null) {
            throw new \LogicException('应用还没有挂表面：先 $app->attach($surface, $constraints)');
        }

        $script = $options->script() ?? new Script(
            $app::class,
            \is_finite($constraints->maxWidth) ? $constraints->maxWidth : 0.0,
            \is_finite($constraints->maxHeight) ? $constraints->maxHeight : 0.0,
        );
        $options = $options->withScript($script);

        $recorder = Recorder::install($options);
        // 认主 + 绑到窗口：从这一刻起这个应用的帧只写这一份，别的窗口借不到。
        $recorder->bindTo($app);
        $app->setDebugRecorder($recorder);
        $queries = new Query($recorder);

        // 令牌每次 open 新生成：帧号与节点号都是每会话重发的，观察者只能靠它
        // 判"时间线换了"。放在 open 而不是进程启动，是因为 close 之后再 open
        // 是另一个会话，沿用旧令牌会让"换了时间线"这件事看不出来。
        $state = new SessionState(\bin2hex(\random_bytes(8)));

        // 活树根走闭包注入：`QueryRouter` 住在 `Debug\Session`，那一层不该知道有"应用"。
        $router = new QueryRouter(
            $recorder,
            $queries,
            static fn(): array => $app->treeRoots(),
            static fn(): array => $app->treeRootOrigins(),
            static fn(): SessionState => $state,
            // 语义树在查询发生时才现建（与 UIA 推送同一构建器），不写记录。
            static fn(): SemanticsTree => $app->semanticsTree(),
        );

        return new self($app, $constraints, $recorder, $queries, $router, $script, $state);
    }

    /** 本会话的令牌：跨进程观察时用它判断"是不是同一条时间线"。 */
    public function sessionToken(): string
    {
        return $this->state->token;
    }

    /**
     * 还能不能推进。
     *
     * 这是"宿主该不该调驱动"的唯一判据 —— 不要另存一个 `bool $paused`：那套记账
     * 与 `state` 必然漂开（查询报 `paused` 而宿主还在推，或者反过来）。
     */
    public function canAdvance(): bool
    {
        return $this->state->state === 'running';
    }

    /** 当前状态名。要区分 `finished` 与 `crashed` 的人读这个，不要读 `state` 对象。 */
    public function stateName(): string
    {
        return $this->state->state;
    }

    /**
     * 标记会话状态。`reason` 是"为什么停"，而不是"谁让停的"的近义词：
     * `at`（到了 `--at`）与 `requested`（客户端按了暂停）对观察者的含义相反——
     * 前者是"你要看的就在这"，后者是"一会儿还动"。
     *
     * `crashed` 留给常驻黑匣子（S2）：它和 `finished` 的区别正是"跑完了"与"炸了"。
     */
    public function markState(string $state, ?string $reason = null): void
    {
        if (!\in_array($state, ['running', 'paused', 'finished', 'crashed'], true)) {
            throw new \InvalidArgumentException("不认识的状态：{$state}");
        }
        $this->state->state = $state;
        $this->state->reason = $reason;
    }

    /**
     * 拾取模式开关：写给 `/api/state` 与 Web 顶栏看的镜像。
     *
     * 真正的输入闸门在 `LiveTicker`；这里只保证"宿主怎么接着输入"这件事
     * 在状态面上读得到，而不是只有 CLI 自己知道。
     */
    public function markPicking(bool $picking): void
    {
        $this->state->picking = $picking;
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
     * 时刻默认取 `lastT`（最近一帧），重放时会显式给脚本里的那个 —— 见
     * {@see \Drift\DevTools\Session\SessionDriver::effect()} 的"为什么时刻要由调用方给"。
     *
     * @param array<string, scalar|null> $args
     *
     * @return mixed 回调的返回值
     */
    public function effect(string $name, array $args = [], ?float $timestampMs = null): mixed
    {
        $run = $this->effects[$name] ?? null;
        if ($run === null) {
            throw new \LogicException(
                "未注册的驱动效果：{$name}（已注册：" . ($this->effects === [] ? '无' : \implode(' / ', \array_keys($this->effects))) . '）',
            );
        }

        // 先记后跑：回调抛异常时，脚本里仍如实留下"这一步被尝试过"。
        $this->recorder->noteEffect($name, $args, $timestampMs ?? $this->lastT);

        return $run($args);
    }

    /**
     * 高亮一个节点（`0` = 清除）。**不改结构**：只让下一次绘制多画一层描边。
     *
     * ## 为什么解析不到就抛，而不是记一笔了事
     *
     * 脚本是给人读、给机器重放的。记下一个"高亮了不存在的节点"，重放时会**同样**
     * 解析不到 —— 那时失败发生在重放里，看起来像"重放坏了"，而真正的错误是当初
     * 那个号。所以在请求处就拒绝，报错指向写错的号。
     *
     * ## 为什么清除不解析
     *
     * `0` 是"什么都不高亮"，它不需要存在于树里。让清除也走解析，等于要求调用方
     * 先确认树上有个节点才允许取消高亮。
     */
    public function highlight(int $node, ?float $timestampMs = null): void
    {
        if ($node < 0) {
            throw new \InvalidArgumentException("高亮的节点号不能为负：{$node}（0 表示清除）");
        }

        $element = null;
        if ($node > 0) {
            $element = Element::findInRoots(
                $this->app->treeRoots(),
                $node,
                fn(Element $e): bool => $this->recorder->owns($e),
            );
            if ($element === null) {
                // 与 `props` 同一套词汇：写错号 / 号发过但节点已卸载 / 已被回收窗口挤掉。
                $reason = match ($this->history->nodeStatus($node)) {
                    'evicted' => '节点已被回收（超出存活窗口）',
                    'active' => '节点在登记表里但活树里已经没有它（已卸载）',
                    default => '这个号从没发过',
                };
                throw new \RuntimeException("高亮失败：节点 #{$node} 查不到 —— {$reason}");
            }
        }

        $this->recorder->noteHighlight($node, $timestampMs ?? $this->lastT);
        $this->app->setDebugHighlight($element);
    }

    /**
     * 开 / 关真机检查器的叠加层（覆盖式：`[]` = 全关）。
     *
     * ★ 与 {@see self::highlight()} 同形，是**干预**组：改的是"下一次绘制画什么"。
     *   "现在开着哪几层"由 `query('state')['inspector']` 回答，两者不合并 ——
     *   请求与上屏混成一个字段，就再也分不出"开了但没画出来"。
     * ★ 回执带 `drawnFrame`：与 `highlight` 一样把"上屏了没"直接答掉。
     *   注意它可能停在上一次成功那帧（例如 `box-model` 开着但没选中节点）——
     *   那正是要暴露的事实，不是错误。
     *
     * @param list<string> $layers
     * @return array{layers: list<string>, drawnFrame: int|null}
     */
    public function inspect(array $layers): array
    {
        $inspector = $this->inspector();
        $on = $inspector->setLayers($layers);
        $drawn = $this->history->overlayDrawn('inspect');

        return [
            'layers' => $on,
            'drawnFrame' => $drawn === null ? null : $drawn['frame'],
            // 与屏幕同一份：HUD 画什么，这里就报什么。判据拿它与 stats 逐值比。
            'hud' => $inspector->hudValues(),
        ];
    }

    /**
     * 惰性建叠加层：第一次有人开它才装。
     *
     * ★ 不在 `open()` 里装。装会 `scheduleFrame()` 一帧，而"装了"
     *   这件事本身就成了被测状态的一部分 —— `DebugSessionTest` 拿"会话驱动的帧"
     *   与"直接驱动的帧"逐值比，多排的那一帧会让 `paint` 记账在两边分叉
     *   （实测：直接驱动 `paint: true`，会话驱动 `paint: false`）。
     *   不用检查器的会话，因此与没有这个功能时一模一样。
     *
     * @throws \LogicException 会话已关闭
     */
    private function inspector(): Inspector
    {
        if ($this->closed) {
            throw new \LogicException('会话已关闭：叠加层已经摘掉了');
        }

        if ($this->inspector === null) {
            $inspector = new Inspector();
            // 装的是闭包，`src` 因此不认识 `Inspector` —— 结构断言仍成立。
            $inspector->attach($this->app, $this->recorder);
            $this->inspector = $inspector;
        }

        return $this->inspector;
    }

    /** @return list<string> 现在开着的叠加层 */
    public function inspectorLayers(): array
    {
        return $this->inspector?->enabled() ?? [];
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
        $answer = $this->router->query($name, $args);

        // `state` 补一层叠加开关：Web 要镜像"真机现在开着哪几层"，而这几层是会话
        // 状态、不是记录 —— 放进 `Recorder` 就会跟着证据包一起走（A81 明确不进包）。
        // 离线包没有叠加层，字段缺席，读者按"没有"处理。
        // ★ `inspectorDrawn` 单独给：`inspect` 的回执读在画之前，只能是"上一次"，
        //   而读者真正要问的是"现在屏上那一层是哪一帧"——那个只能在帧后读。
        if ($name === 'state') {
            $answer['inspector'] = $this->inspectorLayers();
            $drawn = $this->history->overlayDrawn('inspect');
            $answer['inspectorDrawn'] = $drawn === null ? null : $drawn['frame'];
        }

        return $answer;
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
        return $this->history->lastFrameId();
    }

    public function app(): Application
    {
        return $this->app;
    }

    /**
     * 这次驱动跑完了（或到了 `--at`）。返回与 `query('state')` 同一份形状，
     * 让控制面与观察面读到的是同一个事实，而不是两次组装。
     */
    public function finish(?int $at = null): array
    {
        $settled = SessionState::afterDrive($at);
        $this->markState($settled['state'], $settled['reason']);

        return $this->query('state');
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

        // 叠加层随会话一起撤：会话结束了还留着描边，等于把一个已结束的会话
        // 的最后状态当成"现在正看着它"。
        $this->inspector?->detach();
        $this->inspector = null;
        $this->app->setDebugHighlight(null);

        // ★ 解绑而不是 `uninstall()`：`uninstall()` 是进程级硬重置，同进程里可能还有
        //   另一个窗口在录 —— 它清的是"这个世界"，不是"这个会话"。
        $this->app->setDebugRecorder(null);
        $this->recorder->unbind();
    }
}
