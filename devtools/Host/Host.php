<?php
declare(strict_types=1);

namespace Drift\DevTools\Host;

use Drift\DevTools\App\DebugSession;
use Drift\DevTools\Protocol\Capabilities;
use Drift\DevTools\Protocol\Channel;
use Drift\DevTools\Protocol\Methods;
use Drift\DevTools\Protocol\ProtocolError;
use Drift\DevTools\Protocol\Server;
use Drift\DevTools\Protocol\Wire;
use Drift\DevTools\Session\History;
use Drift\Debug\Session\InputScript;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;

/**
 * 宿主进程的循环：消息进、响应出；可以顺带自走。
 *
 * ## 它默认是**从属**的
 *
 * 不给 `$drive` 时，宿主自己不推帧、不读时钟、不做任何"定期"的事。每一次状态变化
 * 都来自一条消息。
 *
 * ## 但"从属"不是可判的原因
 *
 * 真正会让结果随机器漂的是**帧的 `t` 来自墙钟**：动画位置、`after()` 到期判断都会
 * 跟着机器速度变。所以给 `$drive` 的前提是它喂进去的仍是**脚本里的 `t`**——
 * 那时候谁按什么节奏调用 `pump()` 都不影响记录内容，可判性一点没丢。反过来，
 * 拿 `now()` 当 `t` 的自走，无论有没有驱动都不可判。
 *
 * 这条区分很重要：早先这里写的是"从属是可判的前提"，把手段写成了原因，于是
 * "宿主能不能自己跑"看起来成了路线问题。
 *
 * ## 能不能停，读会话的状态
 *
 * 宿主不自己记"暂停了没有"：`$session->canAdvance()` 是唯一判据。两套记账
 * （宿主一个布尔、会话一个 `state`）必然漂开，而漂开的表现是"查着是暂停、其实在动"。
 *
 * ## 一次失败不能带走整个进程
 *
 * 查询参数写错、页面抛异常、查询名拼错 —— 这些都是**结果**，回一个错误信封，
 * 会话继续。把异常抛到循环外，客户端只看到"管道断了"，而真正的原因（一条
 * 带 `frame: 999` 的查询）已经丢了。
 *
 * ## 收尾一定要卸录制器
 *
 * `finally` 里关会话：即使通道在半路断了，`Recorder::uninstall()` 也会跑。
 * 进程马上就要退，看起来无所谓 —— 但宿主还能被复用在测试里（同一个 PHP 进程
 * 里连着服务两个客户端），那时残留的录制器会让下一次 `open` 直接拒绝。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Host
{
    /**
     * @param string|null $accessToken
     *   访问令牌：**能不能连上这个宿主**。与 `DebugSession::sessionToken()` 不是一回事 ——
     *   那个是"这次录制的时间线身份"，每次 `open` 换一个，用来判跨会话的游标；
     *   这个是"这个进程的调试面开给谁"，进程活多久就多久。两者混用会让"宿主重启"
     *   与"重新录制"变成同一件事。
     *   `null` = 不校验（stdio 宿主：通道就是父进程的管道，本来没有"外人"这回事）。
     * @param DrivePort|null $drive
     *   谁在推帧：`Drive`（脚本自走）或 `LiveDrive`（真窗口由 `EventLoop` 推）。
     *   给了它就能被 `pause` / `resume` 叫停，`frame` 反过来被拒；没给
     *   （stdio 宿主 / 被 `--remote` 拉起的从属宿主）时每一帧都得客户端发。
     * @param bool $allowEval
     *   是否开 `eval`（任意代码执行）。**默认关**：要显式 `debug serve --allow-eval`
     *   才开。这是安全面不是功能开关 —— loopback 只挡住"外人连得上"，挡不住
     *   "连上的人能不能让宿主执行代码"，两者得分开判。
     * @param null|\Closure(bool): array{armed: bool, reason?: string} $armPick
     *   活窗口拾取的布防回调（A58）。`null` = 这个宿主没有可拾取的窗口，`PICK` 回
     *   `not_applicable`。状态必须存在回调那一侧 —— 宿主再存一份布尔必然与桥漂开。
     */
    public function __construct(
        private readonly Server $server,
        private readonly ?string $accessToken = null,
        private readonly ?DrivePort $drive = null,
        private readonly bool $allowEval = false,
        private readonly ?\Closure $armPick = null,
    ) {
    }

    /** @var array<int, Channel> */
    private array $channels = [];

    private ?DebugSession $session = null;

    private ?\Closure $open = null;

    /**
     * @param callable(Script): array{session: DebugSession, mountMs: float} $open
     *   由调用方提供"怎么造这一页"（组合根的事）：脚本进，会话与挂载耗时出。
     */
    public function serve(callable $open): void
    {
        if ($this->drive !== null && !$this->server->canPoll()) {
            throw new \LogicException(
                '这个传输不支持自走：它的 wait() 不能在"没有消息"时立即返回，'
                . '给了驱动会卡在等消息上、帧再也不推进。自走请用 socket 宿主。',
            );
        }

        $this->open = \Closure::fromCallable($open);

        try {
            if ($this->drive !== null) {
                // 自走：没有客户端来发 open，会话只能由宿主自己建。
                // live 宿主由 {@see self::adopt()} 在帧循环启动时放进来。
                if ($this->session === null) {
                    $this->session = ($this->open)($this->drive->script()->fork())['session'];
                }
            }

            while ($this->pollOnce($this->advancing() ? 0 : -1)) {
                // 等完一轮、处理一轮；退出条件在 `pollOnce()` 里只有一个。
            }
        } finally {
            $this->session?->close();
            $this->session = null;
        }
    }

    /**
     * 处理**一轮**消息；live 宿主由 `EventLoop` 每帧调一次，同一个分派路径。
     *
     * 返回 `false` = 没有连接也不自走，没人听了。`true` 不代表"做了事" ——
     * 空闲时它只是扫一眼消息、推一步，调用方不该据此安排后续动作。
     *
     * `$timeoutMs` 由调用方给：自走宿主在会话跑时用 `0`（否则消息要等脚本跑完），
     * live 宿主永远给 `0`（`EventLoop` 已经为 socket 留有等待上界，这里再等就是两次睡）。
     */
    public function pollOnce(int $timeoutMs): bool
    {
        $ready = $this->server->wait($this->channels, $timeoutMs);

        if ($ready === []) {
            // 唯一一次推进一步：两处都判（这里与 `Drive::advance`）会互为冗余，
            // 去掉任一个都不出错，于是没有判据能钉住任何一个。
            if ($this->drive !== null && $this->session !== null) {
                $this->drive->advance($this->session);
            }

            return $this->keepServing();
        }

        foreach ($ready as $channel) {
            $key = \spl_object_id($channel);
            $this->channels[$key] = $channel;

            $line = $channel->read();
            if ($line === null) {
                unset($this->channels[$key]);

                continue;
            }

            $this->dispatch($channel, $line);
        }

        return $this->keepServing();
    }

    /** live 宿主用：会话由帧循环开，宿主只接管消息面。 */
    public function adopt(DebugSession $session): void
    {
        $this->session = $session;
    }

    /** 已接受的通道：关监听之前先断开，避免观察者握着半死的连接。 */
    public function closeChannels(): void
    {
        foreach ($this->channels as $channel) {
            $channel->close();
        }
        $this->channels = [];
    }

    private function advancing(): bool
    {
        return $this->drive !== null && $this->session !== null && $this->session->canAdvance();
    }

    private function keepServing(): bool
    {
        // 自走中继续跑（观察者随时可重连）；跑完也继续等 —— "serve 完就悄悄退出"
        // 会让下一个人连上来时看到"连接被拒绝"，而他什么都没做错。
        return $this->channels !== [] || $this->drive !== null;
    }

    /**
     * 处理一条消息。所有失败都变成信封，不往外抛。
     *
     * @internal 只由 {@see self::pollOnce()} 调；会话与连接都住在宿主的字段里，
     *   不再经参数传来传去（两份真相在 live 宿主里必然漂）。
     */
    private function dispatch(Channel $channel, string $line): void
    {
        try {
            $message = Wire::decode($line);
        } catch (\InvalidArgumentException $e) {
            $this->fail($channel, null, ProtocolError::BAD_REQUEST, $e->getMessage());

            return;
        }

        $id = $message['id'] ?? null;
        if (!\is_int($id)) {
            $this->fail($channel, null, ProtocolError::BAD_REQUEST, 'id 必须是整数：' . $line);

            return;
        }

        $method = $message['method'] ?? null;
        if (!\is_string($method) || !\in_array($method, Methods::ALL, true)) {
            $this->fail($channel, $id, ProtocolError::UNKNOWN_METHOD, '不认识的方法：' . \json_encode($method));

            return;
        }

        // 版本在信封上，不在 params 里：每条消息都带，才能挡住"先握手成功、后来
        // 用了新语义"这种漂移。老宿主忽略这个额外键，所以新客户端发过来不会断。
        $version = $message['protocol'] ?? null;
        if ($version !== null && !\is_int($version)) {
            $this->fail($channel, $id, ProtocolError::BAD_REQUEST, 'protocol 必须是整数');

            return;
        }
        if ($version !== null && !Capabilities::supports($version)) {
            $this->fail(
                $channel,
                $id,
                ProtocolError::UNSUPPORTED_VERSION,
                "协议版本 {$version} 不受支持（支持 " . Capabilities::MIN_VERSION . '..' . Capabilities::VERSION . '）',
            );

            return;
        }

        $params = $message['params'] ?? [];
        if (!\is_array($params)) {
            $this->fail($channel, $id, ProtocolError::BAD_REQUEST, 'params 必须是对象');

            return;
        }

        // ── 分派：一张表决定"谁处理、要不要已有会话" ────────────────────────
        // 原先这里是七个前置 if 块 + 一个 match，于是"某个动词在哪被处理"这个
        // 问题在 `Methods::ALL` 里找不到答案。现在答案只有一处：`HANDLERS`。
        try {
            $entry = self::HANDLERS[$method] ?? null;
            if ($entry === null) {
                // `Methods::ALL` 里有、表里没有：加了动词却忘了接线。错误码与原先
                // match 的 default 相同 —— 客户端看到的是"没实现"，而第一次调用
                // 就会撞到，不会等到某个特定参数组合才现形。
                throw new ProtocolError(
                    ProtocolError::UNKNOWN_METHOD,
                    "方法 {$method} 登记了但没有实现",
                );
            }
            [$handler, $needsSession] = $entry;

            // 可用性检查对**每个**动词都做一次，不在各个处理函数里各写一遍。
            // 目录（`capabilities()` 那栏）与分派共用 `unavailable()`，两处因此
            // 不会漂成"目录说能用、一调就 not_applicable"。
            $why = $this->unavailable($method);
            if ($why !== null) {
                throw new ProtocolError(ProtocolError::NOT_APPLICABLE, $why);
            }
            if ($needsSession && $this->session === null) {
                throw new ProtocolError(ProtocolError::NOT_OPEN, "还没 open 就调用 {$method}");
            }

            $this->ok($channel, $id, $this->{$handler}($method, $params, $version));
        } catch (\Throwable $e) {
            $this->failFor($channel, $id, $e);
        }
    }

    /**
     * 动词 => [处理函数, 要不要已有会话]。
     *
     * 这张表回答"某个动词在哪被处理"，{@see Methods::ALL} 回答"有哪些动词"，
     * 两者必须双向闭合：少一条是加了动词忘了接线（这里抛），多一条是退役了动词
     * 没删接线（{@see Capabilities} 那边抛）。
     *
     * `capabilities` / `open` / `close` 不需要已有会话——发现要在 open 之前可用，
     * 而 `open` 本身就是"把会话建起来"，`close` 要能在没有会话时也答一声。
     *
     * 处理函数签名统一 `(method, params, version): mixed`，失败一律抛
     * {@see ProtocolError}，**不直接写通道**——"回什么"只有 `dispatch` 一处，
     * 它才能保证每条消息都有且只有一个回执。
     *
     * @var array<string, array{0: string, 1: bool}>
     */
    private const array HANDLERS = [
        Methods::CAPABILITIES => ['answerCapabilities', false],
        Methods::OPEN => ['answerOpen', false],
        Methods::CLOSE => ['answerClose', false],
        Methods::FRAME => ['answerFrame', true],
        Methods::INPUT => ['answerInput', true],
        Methods::EFFECT => ['answerEffect', true],
        Methods::QUERY => ['answerQuery', true],
        Methods::EVAL => ['answerEval', true],
        Methods::PAUSE => ['answerControl', true],
        Methods::RESUME => ['answerControl', true],
        Methods::HIGHLIGHT => ['answerHighlight', true],
        Methods::PICK => ['answerPick', true],
        Methods::FINISH => ['answerFinish', true],
    ];

    // ------------------------------------------------------------------ 处理函数
    //
    // 这十三个函数把统一签名接到已有实现上。存在的理由只有一个：让 `HANDLERS`
    // 能按名字找到"这个动词由谁处理"，同时把失败统一成抛 `ProtocolError`。
    // 纯转发的那些不写段落式 docblock —— 它们没有第二个事实要交代。

    /** @param array<array-key, mixed> $params @return array<array-key, mixed> */
    private function answerCapabilities(string $method, array $params, ?int $version): array
    {
        return $this->capabilities($version);
    }

    /**
     * `open` 有两种意思：带令牌是**接现有会话**，不带是新建。
     *
     * 合成一个动词是刻意的（客户端先说要 open，再说带不带令牌），但两条路径的
     * 前置条件相反：新建要求宿主上还没有会话，接现有要求已经有。所以这条判据
     * 留在分支里，不进 `HANDLERS`。
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function answerOpen(string $method, array $params, ?int $version): array
    {
        $token = $params['token'] ?? null;
        if (\is_string($token) && $token !== '') {
            return $this->attach($token);
        }

        if ($this->session !== null) {
            throw new ProtocolError(
                ProtocolError::ALREADY_OPEN,
                '这个宿主已经打开过会话：先 close 或新开一个宿主进程',
            );
        }

        $opened = $this->open($params);
        $this->session = $opened['session'];

        return $opened['reply'];
    }

    /** @param array<array-key, mixed> $params */
    private function answerClose(string $method, array $params, ?int $version): null
    {
        // 只关会话，不退进程：另一个观察者可能还在看，而新客户端也该能重新 open。
        // 客户端通常紧跟着断开，那时 `read()` 得到 null、通道被移除、循环自己结束。
        $this->session?->close();
        $this->session = null;

        return null;
    }

    /** @param array<array-key, mixed> $params @return array<array-key, mixed> */
    private function answerFinish(string $method, array $params, ?int $version): array
    {
        $at = $params['at'] ?? null;
        if ($at !== null && (!\is_int($at) || $at < 1)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'finish 的 at 是正整数');
        }

        return $this->session->finish($at);
    }

    /** @param array<array-key, mixed> $params @return array<array-key, mixed> */
    private function answerControl(string $method, array $params, ?int $version): array
    {
        return $this->control($method, $params);
    }

    /** @param array<array-key, mixed> $params @return array{id: int, wall: float} */
    private function answerFrame(string $method, array $params, ?int $version): array
    {
        return $this->frame($this->session, $params);
    }

    /** @param array<array-key, mixed> $params */
    private function answerInput(string $method, array $params, ?int $version): null
    {
        return $this->input($this->session, $params);
    }

    /** @param array<array-key, mixed> $params @return array{value: mixed} */
    private function answerEffect(string $method, array $params, ?int $version): array
    {
        return $this->effect($this->session, $params);
    }

    /** @param array<array-key, mixed> $params @return array<array-key, mixed> */
    private function answerQuery(string $method, array $params, ?int $version): array
    {
        return $this->query($this->session, $params);
    }

    /** @param array<array-key, mixed> $params @return array<array-key, mixed> */
    private function answerEval(string $method, array $params, ?int $version): array
    {
        return $this->eval($this->session, $params);
    }

    /** @param array<array-key, mixed> $params @return array<array-key, mixed> */
    private function answerHighlight(string $method, array $params, ?int $version): array
    {
        return $this->highlight($this->session, $params);
    }

    /** @param array<array-key, mixed> $params @return array<array-key, mixed> */
    private function answerPick(string $method, array $params, ?int $version): array
    {
        return $this->pick($params);
    }

    /**
     * 这个宿主此刻为什么不能做这个方法；能做为 `null`。
     *
     * ★ 分派与能力目录共用的**唯一**一处判断。把它拆成两处（调用处一个 bool、
     *   目录里另写一份）在写的时候看不出问题，跑起来则会出现"目录说能用，
     *   一调就 not_applicable"——客户端明明先问过，还是得靠试探。
     *
     * 这里只说**宿主配置**层面的不可用。会话已经结束、还没 open 这类时间状态
     * 由分派自己判：它们下一秒就可能变，写进能力目录只会让读者以为能力没了。
     */
    private function unavailable(string $method): ?string
    {
        return match ($method) {
            Methods::EVAL => $this->allowEval
                ? null
                : '这个宿主没开 eval：起 serve 时加 --allow-eval（任意代码执行，只绑 loopback）',
            Methods::PICK => $this->armPick === null
                ? '这个宿主没有可拾取的活窗口（pick 只对 live attach 有意义）'
                : null,
            Methods::FRAME => $this->drive !== null
                ? '这个宿主自己按帧循环推帧：不要再喂 t。要单步用 resume --frames=1'
                : null,
            Methods::PAUSE, Methods::RESUME => $this->drive === null
                ? '这个宿主由客户端驱动（每帧都要客户端发）：暂停就是你别再发 frame，不用问它'
                : null,
            Methods::FINISH => $this->drive !== null
                ? '这个宿主自己按帧循环判停：别告诉它什么时候结束'
                : null,
            default => null,
        };
    }

    /**
     * 能力发现回执：协议目录 + 这个宿主的配置事实 + 每个方法的当前可用性。
     *
     * `open` 之前就能问，所以这里不能读 `$this->session` 的方法面；只报一个
     * `sessionOpen` 布尔，让客户端知道下一步是该 `open` 还是能直接查。
     */
    private function capabilities(?int $version): array
    {
        // 先验分派表：目录能列出的动词必须都能被分派到。放在这里而不是构造时，
        // 是因为 `Host` 在 live 宿主上是被 `adopt()` 进来的、不走 `serve()`，
        // 而"问能力"是每个客户端都会做的第一件事。
        self::assertHandlerCoverage();

        $reply = Capabilities::describe($version);
        $reply['host'] = [
            'sessionOpen' => $this->session !== null,
            'driven' => $this->drive !== null,
            'allowEval' => $this->allowEval,
            'pickArmable' => $this->armPick !== null,
            // 只说"开没开令牌"，不说令牌是什么 —— 能力发现不需要变成第二道门。
            'tokenRequired' => $this->accessToken !== null,
            'live' => $this->drive?->live() ?? null,
        ];
        foreach ($reply['methods'] as &$one) {
            $why = $this->unavailable((string) $one['name']);
            $one['available'] = $why === null;
            $one['reason'] = $why;
        }
        unset($one);

        return $reply;
    }

    /**
     * `HANDLERS` 与 {@see Methods::ALL} 双向闭合。
     *
     * 少一条 = 加了动词忘了接线；多一条 = 退役了动词没删接线。两种都不该等到
     * "某个客户端恰好调用了那个动词"才现形 —— 那时看到的是 `unknown_method`，
     * 会把人引向"版本不对"而不是"代码漏了一行"。
     *
     * @throws \LogicException 两张表对不上
     */
    private static function assertHandlerCoverage(): void
    {
        foreach (self::HANDLERS as $method => $_entry) {
            if (!\in_array($method, Methods::ALL, true)) {
                throw new \LogicException(
                    "分派表里有未登记的动词 {$method}：Methods::ALL 里没有它",
                );
            }
        }
        foreach (Methods::ALL as $method) {
            if (!\array_key_exists($method, self::HANDLERS)) {
                throw new \LogicException("动词 {$method} 没有处理函数：HANDLERS 里缺一条");
            }
        }
    }

    // ------------------------------------------------------------------ 方法

    /**
     * 接上一个已经在跑的会话：发令牌、拿回会话身份与末帧号，**不重建应用**。
     *
     * 回执刻意不给 `mountMs`：这次没有挂载，填 0 会被读成"挂载花了 0 毫秒"。
     * 用 `reused` 明说是复用，调用方就不必靠字段有没有来猜。
     *
     * @return array<array-key, mixed>
     */
    private function attach(string $token): array
    {
        if ($this->accessToken === null) {
            throw new ProtocolError(
                ProtocolError::UNAUTHORIZED,
                '这个宿主没开访问令牌（stdio 通道本来只有父进程能说上话），接不了',
            );
        }

        // 定长比较，别让令牌在第一次不同字节处停下。
        if (!\hash_equals($this->accessToken, $token)) {
            throw new ProtocolError(
                ProtocolError::UNAUTHORIZED,
                'token 对不上：去 .drift/session.json 拿当前那个（宿主重启后令牌会换）',
            );
        }

        $session = $this->session;
        if ($session === null) {
            throw new ProtocolError(
                ProtocolError::NOT_OPEN,
                '这个宿主上没有会话可接：它可能已经 close 过，或者刚被重启过',
            );
        }

        return [
            'reused' => true,
            'session' => $session->sessionToken(),
            // 末帧一起给：观察者的游标要有个起点，否则它得先问一次 state 才知道从哪读。
            'lastFrame' => $session->lastFrameId(),
            'pid' => \getmypid(),
            // 帧是谁推的。客户端拿到 true 就别发 frame（会被拒），拿到 false 就知道
            // 自己得推。不发一次 frame 去试探，省一次往返，也不必从错误码去猜。
            'driven' => $this->drive !== null,
            // ★ live 的 `t` 来自墙钟，不能重放。客户端要拿这份记录做复算时必须先看
            //   这一个字段 —— 只看 `driven` 会以为它跟脚本自走宿主一样可复算。
            'live' => $this->drive?->live() ?? false,
            'replayable' => !($this->drive?->live() ?? false),
        ];
    }

    /**
     * 暂停 / 恢复。
     *
     * 回执**就是**一次 `state` 查询：控制面的输出形状与观察面完全一样，调用方
     * 不需要学两套。这也是唯一能让"动作生效了没有"被直接读出来的形状。
     *
     * 可用性检查（"这个宿主能不能被暂停"）在 `dispatch` 里统一做过，与能力目录
     * 同源；这里只管参数与状态。
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function control(string $method, array $params): array
    {
        if ($method === Methods::RESUME) {
            $frames = $params['frames'] ?? null;
            if ($frames !== null && (!\is_int($frames) || $frames < 1)) {
                throw new ProtocolError(ProtocolError::BAD_REQUEST, 'resume 的 frames 是正整数');
            }

            $name = $this->session->stateName();
            if ($name === 'finished' || $name === 'crashed') {
                // 让它回 running 会撒一秒的谎：下一轮立刻又 finished，而观察者已经
                // 读到"在跑"了。
                throw new ProtocolError(
                    ProtocolError::NOT_APPLICABLE,
                    "会话已经 {$name}，恢复不了",
                );
            }

            $this->drive->resume($this->session, $frames);
        } else {
            $this->drive->pause($this->session);
        }

        return $this->session->query('state');
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{session: DebugSession, reply: array<string, mixed>}
     */
    private function open(array $params): array
    {
        $raw = $params['script'] ?? null;
        if (!\is_array($raw)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'open 需要 script（脚本骨架）');
        }
        $script = Script::fromArray($raw);
        $optionsRaw = $params['options'] ?? null;
        if ($optionsRaw !== null && !\is_array($optionsRaw)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'open 的 options 必须是对象');
        }
        $options = $optionsRaw === null ? RecordOptions::default() : RecordOptions::fromManifest($optionsRaw);

        // 录制落在**分叉**上：脚本进、脚本出，客户端交上来的那份不被改动。
        $opened = ($this->open)($script->fork(), $options);

        return [
            'session' => $opened['session'],
            'reply' => [
                'mountMs' => $opened['mountMs'],
                'target' => $script->target,
                'viewport' => [$script->width, $script->height],
                // ★ 令牌：帧号与节点号都是每会话重发的，跨进程的观察者只能靠它判断
                //   "时间线换了"。它同时是"会话真的交出去了"的可观测量之一。
                'session' => $opened['session']->sessionToken(),
                // ★ 报告要能说出"这份因果是哪个进程跑出来的"。没有它，`--remote`
                //   悄悄退化成同进程跑，两份报告也会"逐值相同"。
                'pid' => \getmypid(),
                // 与 attach 同一个字段：`open` 出来的一定是客户端驱动（自走宿主上
                // `open` 会被 already_open 挡住），但形状统一比"这里没有、那里有"好记。
                'driven' => $this->drive !== null,
            ],
        ];
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{id: int, wall: float}
     */
    private function frame(DebugSession $session, array $params): array
    {
        $t = $params['t'] ?? null;
        if (!\is_int($t) && !\is_float($t)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'frame 需要数字 t');
        }
        $wall = $session->pump((float) $t);

        // 帧号由这里（被驱动方）给：客户端自己数会和"容量裁剪""跳过 settle"错开。
        return ['id' => $session->lastFrameId(), 'wall' => $wall];
    }

    /** @param array<array-key, mixed> $params */
    private function input(DebugSession $session, array $params): null
    {
        $raw = $params['step'] ?? null;
        if (!\is_array($raw)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'input 需要 step');
        }
        $step = Step::fromArray($raw);
        $session->send(InputScript::unpack($step), $step->t);

        return null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array{value: mixed}
     */
    private function effect(DebugSession $session, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'effect 需要字符串 name');
        }
        $args = $params['args'] ?? [];
        if (!\is_array($args)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'effect 的 args 必须是对象');
        }

        // `t` 可缺：老客户端不发，那就用宿主自己的 `lastT`（旧行为）。
        $t = $params['t'] ?? null;
        if ($t !== null && !\is_int($t) && !\is_float($t)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'effect 的 t 必须是数字');
        }

        /** @var array<string, scalar|null> $args */
        return ['value' => $session->effect($name, $args, $t === null ? null : (float) $t)];
    }

    /**
     * 高亮一个节点（`node = 0` 清除）。
     *
     * 回执里的 `drawnFrame` 取宿主侧记录的"最近一次真的上屏的帧号"。刚请求、
     * 还没跑过一帧时它是上一帧或 `null` —— 这是**如实**的：客户端据此知道
     * "要再推一帧才看得见"，而不必猜"是不是高亮没生效"。
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function highlight(DebugSession $session, array $params): array
    {
        $node = $params['node'] ?? null;
        if (!\is_int($node) || $node < 0) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'highlight 需要非负整数 node（0 表示清除）');
        }

        $t = $params['t'] ?? null;
        if ($t !== null && !\is_int($t) && !\is_float($t)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'highlight 的 t 必须是数字');
        }

        // 解析失败（号写错 / 已卸载）⇒ `DebugSession` 抛，`failFor()` 翻成 `call_failed`，
        // 与本地调用抛的异常是同一件事。
        $session->highlight($node, $t === null ? null : (float) $t);

        // 高亮的两个事实都在读面：`overlayDrawn()` 答"真上屏了吗"，`highlightedNode()`
        // 答"现在请求的是谁"。两者分开记，正是为了不把请求当成证据。
        $history = new History($session->recorder());
        $drawn = $history->overlayDrawn();

        return [
            'node' => $history->highlightedNode(),
            'drawnFrame' => $drawn === null ? null : $drawn['frame'],
        ];
    }

    /**
     * 布防 / 撤防活窗口拾取（A58）。宿主不自己存布尔：状态归桥所有，宿主只做协议翻译。
     * 两边各存一份迟早会漂 —— 表现是"回执说布防了，可点击照旧进应用"。
     *
     * @param array<array-key, mixed> $params
     * @return array<array-key, mixed>
     */
    private function pick(array $params): array
    {
        $enabled = $params['enabled'] ?? null;
        if (!\is_bool($enabled)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'pick 需要布尔 enabled');
        }
        // 布防能力（这个宿主有没有活窗口）在 `dispatch` 里统一判过，与能力目录同源。

        return ($this->armPick)($enabled);
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function query(DebugSession $session, array $params): array
    {
        $name = $params['name'] ?? null;
        if (!\is_string($name) || $name === '') {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'query 需要字符串 name');
        }
        $args = $params['args'] ?? [];
        if (!\is_array($args)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'query 的 args 必须是对象');
        }

        return $session->query($name, $args);
    }

    /**
     * 在活应用上求值。参数与回执都交给 {@see Evaluator}，这里只做协议形状检查。
     *
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function eval(DebugSession $session, array $params): array
    {
        $expression = $params['expression'] ?? null;
        if (!\is_string($expression)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'eval 需要字符串 expression');
        }
        $node = $params['node'] ?? 0;
        if (!\is_int($node) || $node < 0) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'eval 的 node 是非负整数');
        }

        return (new Evaluator())->evaluate($session, $expression, $node);
    }

    // ------------------------------------------------------------------ 信封

    private function ok(Channel $channel, int $id, mixed $result): void
    {
        try {
            $channel->write(Wire::encode(['id' => $id, 'ok' => true, 'result' => $result]));
        } catch (\InvalidArgumentException $e) {
            // 结果编不成 JSON（副作用返回了对象之类）。此时**必须**回一条错误信封：
            // 什么都不写会让客户端一直等；写半句会让分帧从此错位。
            $this->fail($channel, $id, ProtocolError::CALL_FAILED, '结果编不成 JSON：' . $e->getMessage());
        }
    }

    private function failFor(Channel $channel, int $id, \Throwable $e): void
    {
        $this->fail(
            $channel,
            $id,
            $e instanceof ProtocolError ? $e->errorCode : ProtocolError::CALL_FAILED,
            $e->getMessage(),
        );
    }

    private function fail(Channel $channel, ?int $id, string $code, string $message): void
    {
        $channel->write(Wire::encode([
            'id' => $id,
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ]));
    }
}
