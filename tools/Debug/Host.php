<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\DebugSession;
use Drift\Debug\Protocol\Channel;
use Drift\Debug\Protocol\Methods;
use Drift\Debug\Protocol\ProtocolError;
use Drift\Debug\Protocol\Server;
use Drift\Debug\Protocol\Wire;
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

    public function session(): ?DebugSession
    {
        return $this->session;
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

        $params = $message['params'] ?? [];
        if (!\is_array($params)) {
            $this->fail($channel, $id, ProtocolError::BAD_REQUEST, 'params 必须是对象');

            return;
        }

        if ($method === Methods::CLOSE) {
            // 只关会话，不退进程：另一个观察者可能还在看，而新客户端也该能重新 open。
            // 客户端通常紧跟着断开，那时 `read()` 得到 null、通道被移除、循环自己结束。
            $this->session?->close();
            $this->session = null;
            $this->ok($channel, $id, null);

            return;
        }

        if ($method === Methods::OPEN) {
            // 带令牌的 open 是**接现有会话**，不是新建：应用已经在跑，重建一遍会把
            // 观察者看到的"那一次运行"换成另一次，而两次的帧号、节点号本来就不保证相同。
            $token = $params['token'] ?? null;
            if (\is_string($token) && $token !== '') {
                $this->attach($channel, $id, $this->session, $token);

                return;
            }

            if ($this->session !== null) {
                $this->fail($channel, $id, ProtocolError::ALREADY_OPEN, '这个宿主已经打开过会话：先 close 或新开一个宿主进程');

                return;
            }
            try {
                $result = $this->open($params);
                $this->session = $result['session'];
                $this->ok($channel, $id, $result['reply']);
            } catch (\Throwable $e) {
                $this->failFor($channel, $id, $e);
            }

            return;
        }

        if ($this->session === null) {
            $this->fail($channel, $id, ProtocolError::NOT_OPEN, "还没 open 就调用 {$method}");

            return;
        }

        if ($method === Methods::FINISH) {
            if ($this->drive !== null) {
                // 何时停由自走宿主自己判（`Drive::settle()`）；让它听客户端的
                // 就会出现两套"跑完了"的定义，而两边的状态会各自说一套。
                $this->fail(
                    $channel,
                    $id,
                    ProtocolError::NOT_APPLICABLE,
                    '这个宿主自己按帧循环判停：别告诉它什么时候结束',
                );

                return;
            }
            $at = $params['at'] ?? null;
            if ($at !== null && (!\is_int($at) || $at < 1)) {
                $this->fail($channel, $id, ProtocolError::BAD_REQUEST, 'finish 的 at 是正整数');

                return;
            }
            $this->ok($channel, $id, $this->session->finish($at));

            return;
        }

        if ($method === Methods::PAUSE || $method === Methods::RESUME) {
            $this->control($channel, $id, $this->session, $method, $params);

            return;
        }

        if ($method === Methods::EVAL) {
            // 默认关，在分派的最前面判：不开时连参数都不解析。任意代码执行
            // 是安全面，"参数写错了"和"这个宿主根本不允许求值"不是一回事。
            if (!$this->allowEval) {
                $this->fail(
                    $channel,
                    $id,
                    ProtocolError::NOT_APPLICABLE,
                    '这个宿主没开 eval：起 serve 时加 --allow-eval（任意代码执行，只绑 loopback）',
                );

                return;
            }
            // 与下面 match 那条路径同样要兜：eval 里的语法错 / 运行时异常都是
            // **结果**，不是让进程退出的故障。少了这层 try，一条 `1 +` 就把
            // 宿主打崩，客户端只看到管道断了，真正的错留在 stderr。
            try {
                $this->ok($channel, $id, $this->eval($this->session, $params));
            } catch (\Throwable $e) {
                $this->failFor($channel, $id, $e);
            }

            return;
        }

        if ($method === Methods::FRAME && $this->drive !== null) {
            // 两条时间轴：脚本里的 `t` 与客户端编的 `t` 混进同一条记录，而 `digest`
            // 不含时间（比不出这件事）、`t` 序列只有细看才发现断裂。单步走
            // `resume` 的 `frames`，那里推进的是脚本自己的下一帧。
            $this->fail(
                $channel,
                $id,
                ProtocolError::NOT_APPLICABLE,
                '这个宿主自己按帧循环推帧：不要再喂 t。要单步用 resume --frames=1',
            );

            return;
        }

        try {
            $this->ok($channel, $id, match ($method) {
                Methods::FRAME => $this->frame($this->session, $params),
                Methods::INPUT => $this->input($this->session, $params),
                Methods::EFFECT => $this->effect($this->session, $params),
                Methods::HIGHLIGHT => $this->highlight($this->session, $params),
                Methods::PICK => $this->pick($params),
                Methods::QUERY => $this->query($this->session, $params),
                // Methods::ALL 已在上面挡过，这里不可达；写成显式异常，
                // 让"加了方法名却忘了实现"在第一次调用时就撞红。
                default => throw new ProtocolError(
                    ProtocolError::UNKNOWN_METHOD,
                    "方法 {$method} 登记了但没有实现",
                ),
            });
        } catch (\Throwable $e) {
            $this->failFor($channel, $id, $e);
        }
    }

    // ------------------------------------------------------------------ 方法

    /**
     * 接上一个已经在跑的会话：发令牌、拿回会话身份与末帧号，**不重建应用**。
     *
     * 回执刻意不给 `mountMs`：这次没有挂载，填 0 会被读成"挂载花了 0 毫秒"。
     * 用 `reused` 明说是复用，调用方就不必靠字段有没有来猜。
     */
    private function attach(Channel $channel, int $id, ?DebugSession $session, string $token): void
    {
        if ($this->accessToken === null) {
            $this->fail(
                $channel,
                $id,
                ProtocolError::UNAUTHORIZED,
                '这个宿主没开访问令牌（stdio 通道本来只有父进程能说上话），接不了',
            );

            return;
        }

        // 定长比较，别让令牌在第一次不同字节处停下。
        if (!\hash_equals($this->accessToken, $token)) {
            $this->fail(
                $channel,
                $id,
                ProtocolError::UNAUTHORIZED,
                'token 对不上：去 .drift/session.json 拿当前那个（宿主重启后令牌会换）',
            );

            return;
        }

        if ($session === null) {
            $this->fail(
                $channel,
                $id,
                ProtocolError::NOT_OPEN,
                '这个宿主上没有会话可接：它可能已经 close 过，或者刚被重启过',
            );

            return;
        }

        $this->ok($channel, $id, [
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
        ]);
    }

    /**
     * 暂停 / 恢复。
     *
     * 回执**就是**一次 `state` 查询：控制面的输出形状与观察面完全一样，调用方
     * 不需要学两套。这也是唯一能让"动作生效了没有"被直接读出来的形状。
     *
     * @param array<array-key, mixed> $params
     */
    private function control(
        Channel $channel,
        int $id,
        DebugSession $session,
        string $method,
        array $params,
    ): void {
        if ($this->drive === null) {
            $this->fail(
                $channel,
                $id,
                ProtocolError::NOT_APPLICABLE,
                '这个宿主由客户端驱动（每帧都要客户端发）：暂停就是你别再发 frame，不用问它',
            );

            return;
        }

        if ($method === Methods::RESUME) {
            $frames = $params['frames'] ?? null;
            if ($frames !== null && (!\is_int($frames) || $frames < 1)) {
                $this->fail($channel, $id, ProtocolError::BAD_REQUEST, 'resume 的 frames 是正整数');
                return;
            }

            $name = $session->stateName();
            if ($name === 'finished' || $name === 'crashed') {
                // 让它回 running 会撒一秒的谎：下一轮立刻又 finished，而观察者已经
                // 读到"在跑"了。
                $this->fail(
                    $channel,
                    $id,
                    ProtocolError::NOT_APPLICABLE,
                    "会话已经 {$name}，恢复不了",
                );

                return;
            }

            $this->drive->resume($session, $frames);
        } else {
            $this->drive->pause($session);
        }

        $this->ok($channel, $id, $session->query('state'));
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

        $drawn = $session->recorder()->overlayDrawn();

        return [
            'node' => $session->recorder()->highlightedNode(),
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
        if ($this->armPick === null) {
            throw new ProtocolError(
                ProtocolError::NOT_APPLICABLE,
                '这个宿主没有可拾取的活窗口（pick 只对 live attach 有意义）',
            );
        }

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
