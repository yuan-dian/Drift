<?php
declare(strict_types=1);

namespace Drift\Dev\Debug;

use Drift\App\DebugSession;
use Drift\Debug\Protocol\Methods;
use Drift\Debug\Protocol\ProtocolError;
use Drift\Debug\Protocol\Transport;
use Drift\Debug\Protocol\Wire;
use Drift\Debug\Session\InputScript;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Step;

/**
 * 宿主进程的循环：一条消息进，一条响应出。
 *
 * ## 它是**从属**的
 *
 * 宿主自己不推帧、不读时钟、不做任何"定期"的事。每一次状态变化都来自客户端的一条
 * 消息。这不是懒，是**可判**的前提：异步驱动、后台定时器、后台预热都会让"这一帧
 * 什么时候发生的"取决于机器，而调试器要给的是同一份结果。
 *
 * ## 一次失败不能带走整个进程
 *
 * 查询参数写错、页面抛异常、查询名拼错 —— 这些都是**结果**，回一个错误信封，
 * 会话继续。把异常抛到循环外，客户端只看到"管道断了"，而真正的原因（一条
 * 带 `frame: 999` 的查询）已经丢了。
 *
 * 唯一会终止循环的是 `close` 与对端关闭：前者是约定的结束，后者是没人听了。
 *
 * ## 收尾一定要卸录制器
 *
 * `finally` 里关会话：即使管道在半路断了，`Recorder::uninstall()` 也会跑。
 * 进程马上就要退，看起来无所谓 —— 但宿主还能被复用在测试里（同一个 PHP 进程
 * 里连着服务两个客户端），那时残留的录制器会让下一次 `open` 直接拒绝。
 *
 * @internal 手工工具；不进 V2 判据清单
 */
final class Host
{
    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * @param callable(Script): array{session: DebugSession, mountMs: float} $open
     *   由调用方提供"怎么造这一页"（组合根的事）：脚本进，会话与挂载耗时出。
     */
    public function serve(callable $open): void
    {
        $session = null;

        try {
            while (($line = $this->transport->read()) !== null) {
                try {
                    $message = Wire::decode($line);
                } catch (\InvalidArgumentException $e) {
                    $this->fail(null, ProtocolError::BAD_REQUEST, $e->getMessage());

                    continue;
                }

                $id = $message['id'] ?? null;
                if (!\is_int($id)) {
                    $this->fail(null, ProtocolError::BAD_REQUEST, 'id 必须是整数：' . $line);
                    continue;
                }
                $method = $message['method'] ?? null;
                if (!\is_string($method) || !\in_array($method, Methods::ALL, true)) {
                    $this->fail($id, ProtocolError::UNKNOWN_METHOD, '不认识的方法：' . \json_encode($method));
                    continue;
                }
                $params = $message['params'] ?? [];
                if (!\is_array($params)) {
                    $this->fail($id, ProtocolError::BAD_REQUEST, 'params 必须是对象');
                    continue;
                }

                if ($method === Methods::CLOSE) {
                    $session?->close();
                    $this->ok($id, null);

                    return;
                }

                if ($method === Methods::OPEN) {
                    if ($session !== null) {
                        $this->fail($id, ProtocolError::ALREADY_OPEN, '这个宿主已经打开过会话：新开一个宿主进程');
                        continue;
                    }
                    try {
                        $result = $this->open($open, $params);
                        $session = $result['session'];
                        $this->ok($id, $result['reply']);
                    } catch (\Throwable $e) {
                        $this->failFor($id, $e);
                    }

                    continue;
                }

                if ($session === null) {
                    $this->fail($id, ProtocolError::NOT_OPEN, "还没 open 就调用 {$method}");
                    continue;
                }

                try {
                    $this->ok($id, match ($method) {
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
                    $this->failFor($id, $e);
                }
            }
        } finally {
            $session?->close();
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
                // ★ 报告要能说出"这份因果是哪个进程跑出来的"。它同时是"会话真的
                //   交出去了"的唯一可观测量 —— 没有它，`--remote` 悄悄退化成同进程
                //   跑，两份报告也会"逐值相同"（因为它们本来就是同一次代码路径）。
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

    private function ok(int $id, mixed $result): void
    {
        try {
            $this->transport->write(Wire::encode(['id' => $id, 'ok' => true, 'result' => $result]));
        } catch (\InvalidArgumentException $e) {
            // 结果编不成 JSON（副作用返回了对象之类）。此时**必须**回一条错误信封：
            // 什么都不写会让客户端一直等；写半句会让分帧从此错位。
            $this->fail($id, ProtocolError::CALL_FAILED, '结果编不成 JSON：' . $e->getMessage());
        }
    }

    private function failFor(int $id, \Throwable $e): void
    {
        $this->fail(
            $id,
            $e instanceof ProtocolError ? $e->errorCode : ProtocolError::CALL_FAILED,
            $e->getMessage(),
        );
    }

    private function fail(?int $id, string $code, string $message): void
    {
        $this->transport->write(Wire::encode([
            'id' => $id,
            'ok' => false,
            'error' => ['code' => $code, 'message' => $message],
        ]));
    }
}
