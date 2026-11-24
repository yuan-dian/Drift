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
 * 不给 `$tick` 时，宿主自己不推帧、不读时钟、不做任何"定期"的事。每一次状态变化
 * 都来自一条消息。
 *
 * ## 但"从属"不是可判的原因
 *
 * 真正会让结果随机器漂的是**帧的 `t` 来自墙钟**：动画位置、`after()` 到期判断都会
 * 跟着机器速度变。所以开 `$tick` 的前提是 tick 喂进来的仍是**脚本里的 `t`**——
 * 那时候谁按什么节奏调用 `pump()` 都不影响记录内容，可判性一点没丢。反过来，
 * 拿 `now()` 当 `t` 的自走，无论有没有 tick 都不可判。
 *
 * 这条区分很重要：早先这里写的是"从属是可判的前提"，把手段写成了原因，于是
 * "宿主能不能自己跑"看起来成了路线问题。
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
    public function __construct(private readonly Server $server)
    {
    }

    /**
     * @param callable(Script): array{session: DebugSession, mountMs: float} $open
     *   由调用方提供"怎么造这一页"（组合根的事）：脚本进，会话与挂载耗时出。
     * @param null|callable(DebugSession): bool $tick
     *   每轮"没有消息可读"时调用一次，返回 `false` 表示不再推进（脚本跑完 / 到了
     *   `--at`）。**它必须喂脚本里的 `t`**，拿墙钟当 `t` 会让记录不可判。
     *   给了 tick 就要求 `Server::canPoll()`，否则会卡在等消息上、帧再也不动。
     *   还没 `open` 时不调 tick（没有会话可推进），此时 `wait()` 回到阻塞。
     */
    public function serve(callable $open, ?callable $tick = null): void
    {
        if ($tick !== null && !$this->server->canPoll()) {
            throw new \LogicException(
                '这个传输不支持自走：它的 wait() 不能在"没有消息"时立即返回，'
                . '给了 tick 会卡在等消息上、帧再也不推进。自走请用 socket 宿主。',
            );
        }

        /** @var array<int, Channel> $channels */
        $channels = [];
        $session = null;

        try {
            while (true) {
                // 有 tick 且已经有会话时零超时：扫一眼有没有待办消息，没有就去跑一帧。
                // 还没 open 时必须阻塞等（否则会空转烧 CPU，而它连帧都推不了）。
                $timeout = ($tick !== null && $session !== null) ? 0 : -1;
                $ready = $this->server->wait($channels, $timeout);

                if ($ready === []) {
                    if ($tick !== null && $session !== null && $tick($session) === false) {
                        // 跑完了不再推进，但会话保活：观察者还可以继续问。
                        // 置 null 而不是 break —— break 会把"跑完了"变成"没人听了"。
                        $tick = null;
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

                // 没有连接了、也不自走 ⇒ 没人听了。自走中则继续跑（观察者随时可重连）。
                if ($channels === [] && $tick === null) {
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

        /** @var array<string, scalar|null> $args */
        return ['value' => $session->effect($name, $args)];
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
