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
     * @param Drive|null $drive
     *   自走驱动：给了它，宿主自己按脚本推帧，并且能被 `pause` / `resume` 叫停。
     *   没给（stdio 宿主 / 被 `--remote` 拉起的从属宿主）时每一帧都得客户端发，
     *   `pause` / `resume` 对这个宿主没有意义 —— 它们回 `not_applicable`，
     *   而 `frame` 反过来在有驱动时被拒。
     */
    public function __construct(
        private readonly Server $server,
        private readonly ?string $accessToken = null,
        private readonly ?Drive $drive = null,
    ) {
    }

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

        /** @var array<int, Channel> $channels */
        $channels = [];
        $session = null;

        try {
            if ($this->drive !== null) {
                // 自走：没有客户端来发 open，会话只能由宿主自己建。
                $session = $open($this->drive->script()->fork())['session'];
            }

            while (true) {
                // 会话在跑才零超时：扫一眼有没有待办消息，没有就推**一步**。
                // 停下之后必须阻塞等 —— 空转只会烧 CPU，而它连一帧都推不了。
                // 一步而不是一路推到底：后者让默认的 `--speed=0` 在跑完之前读不到
                // 任何消息，于是"暂停"要等脚本跑完才生效（等于没有）。
                //
                // 这个判断**只**决定要不要零超时，不决定推不推：那一层是
                // `Drive::advance` 自己的守卫。两处都判会让它们互为冗余 ——
                // 去掉任何一处都不出错，于是也没有判据能钉住任何一处。
                $advancing = $this->drive !== null && $session !== null && $session->canAdvance();
                $ready = $this->server->wait($channels, $advancing ? 0 : -1);

                if ($ready === []) {
                    if ($this->drive !== null && $session !== null) {
                        $this->drive->advance($session);
                    }

                    continue;
                }

                foreach ($ready as $channel) {
                    $key = \spl_object_id($channel);
                    $channels[$key] = $channel;

                    $line = $channel->read();
                    if ($line === null) {
                        unset($channels[$key]);

                        continue;
                    }

                    $this->dispatch($channel, $line, $open, $session);
                }

                // 没有连接了、也不自走 ⇒ 没人听了。自走中则继续跑（观察者随时可重连）；
                // 自走宿主跑完也继续等 —— "serve 完就悄悄退出"会让下一个人连上来时
                // 看到"连接被拒绝"，而他什么都没做错。
                if ($channels === [] && $this->drive === null) {
                    return;
                }
            }
        } finally {
            $session?->close();
        }
    }

    /**
     * 处理一条消息。所有失败都变成信封，不往外抛。
     *
     * @param callable(Script): array{session: DebugSession, mountMs: float} $open
     * @param ?DebugSession                                                 $session 引用：open 赋值、close 置空
     */
    private function dispatch(Channel $channel, string $line, callable $open, ?DebugSession &$session): void
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
            $session?->close();
            $session = null;
            $this->ok($channel, $id, null);

            return;
        }

        if ($method === Methods::OPEN) {
            // 带令牌的 open 是**接现有会话**，不是新建：应用已经在跑，重建一遍会把
            // 观察者看到的"那一次运行"换成另一次，而两次的帧号、节点号本来就不保证相同。
            $token = $params['token'] ?? null;
            if (\is_string($token) && $token !== '') {
                $this->attach($channel, $id, $session, $token);

                return;
            }

            if ($session !== null) {
                $this->fail($channel, $id, ProtocolError::ALREADY_OPEN, '这个宿主已经打开过会话：先 close 或新开一个宿主进程');

                return;
            }
            try {
                $result = $this->open($open, $params);
                $session = $result['session'];
                $this->ok($channel, $id, $result['reply']);
            } catch (\Throwable $e) {
                $this->failFor($channel, $id, $e);
            }

            return;
        }

        if ($session === null) {
            $this->fail($channel, $id, ProtocolError::NOT_OPEN, "还没 open 就调用 {$method}");

            return;
        }

        if ($method === Methods::PAUSE || $method === Methods::RESUME) {
            $this->control($channel, $id, $session, $method, $params);

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
                '这个宿主自己按脚本推帧：不要再喂 t。要单步用 resume --frames=1',
            );

            return;
        }

        try {
            $this->ok($channel, $id, match ($method) {
                Methods::FRAME => $this->frame($session, $params),
                Methods::INPUT => $this->input($session, $params),
                Methods::EFFECT => $this->effect($session, $params),
                Methods::QUERY => $this->query($session, $params),
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
     * @param callable(Script): array{session: DebugSession, mountMs: float} $open
     * @param array<array-key, mixed> $params
     *
     * @return array{session: DebugSession, reply: array<string, mixed>}
     */
    private function open(callable $open, array $params): array
    {
        $raw = $params['script'] ?? null;
        if (!\is_array($raw)) {
            throw new ProtocolError(ProtocolError::BAD_REQUEST, 'open 需要 script（脚本骨架）');
        }
        $script = Script::fromArray($raw);

        // 录制落在**分叉**上：脚本进、脚本出，客户端交上来的那份不被改动。
        $opened = $open($script->fork());

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
