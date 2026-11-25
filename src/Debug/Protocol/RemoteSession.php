<?php
declare(strict_types=1);

namespace Drift\Debug\Protocol;

use Drift\Debug\Session\InputScript;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Script;
use Drift\Debug\Session\Session;
use Drift\Input\InputEvent;

/**
 * 另一个进程里的会话，接到本地来用。
 *
 * ## 它是 `DebugSession` 的镜像，不是另一种东西
 *
 * 方法、参数、返回值的形状与 {@see \Drift\App\DebugSession} 逐一对齐（同一对接口
 * {@see Session}）。这不是巧合，是要求：消费者——重放循环、
 * 报告组装、将来的面板——只写一份，换执行器不需要改一行。判据也由此而来：
 * **同样的动作，本地跑与远端跑，读回来的值必须逐值相同**。
 *
 * ## 一次驱动，多次追问
 *
 * 这才是引入传输的真实理由。没有它时，每个问题都要重跑一遍页面（挂载 + 几百帧），
 * 而两次运行的时间戳、异步交付顺序本来就不保证相同 —— "为什么这一帧慢"和
 * "为什么这棵树重建了"取自两次不同的运行，合起来就是拼凑的证据。留在宿主里，
 * 十几次查询都对着**同一次运行**。
 *
 * ## 不做什么
 *
 * 不重连、不缓存、不批量。管道断了就是断了：`read()` 返回 `null` 时抛
 * {@see ProtocolError}，调用方要么重开宿主，要么放弃 —— 静默重发会让"发了两遍"
 * 变成一条**推了两帧**的会话，而报告看不出来。
 *
 * @api
 */
final class RemoteSession implements Session
{
    private int $seq = 0;

    private int $lastFrameId = 0;

    /** 宿主进程号；`open` 之后才有。 */
    private ?int $hostPid = null;

    private bool $opened = false;

    private bool $closed = false;

    /** 帧是不是这个宿主自己推的（`attach` 才知道）；没接过为 `null`。 */
    private ?bool $driven = null;

    public function __construct(private readonly Transport $transport)
    {
    }

    /**
     * 让宿主建应用、挂表面、开会话。
     *
     * 脚本在这里就交上去（目标 + 视口 + 环境），因为"怎么造这一页"完全由它决定；
     * 随后的 `frame` / `input` / `effect` 只是照着脚本推进，不必每条再带一遍目标。
     *
     * @return float 挂载墙钟毫秒（与本地 `attach()` 同口径）
     */
    public function open(Script $script, ?RecordOptions $options = null): float
    {
        if ($this->opened) {
            throw new \LogicException('这个远端会话已经打开过：一个宿主进程只服务一次运行');
        }
        $this->opened = true;

        $params = ['script' => $script->toArray()];
        if ($options !== null) {
            $params['options'] = $options->toManifest();
        }
        $result = $this->callObject(Methods::OPEN, $params);
        $mount = $result['mountMs'] ?? null;
        if (!\is_int($mount) && !\is_float($mount)) {
            throw new ProtocolError(ProtocolError::BAD_RESPONSE, 'open 的响应缺少 mountMs');
        }
        $this->hostPid = \is_int($result['pid'] ?? null) ? $result['pid'] : null;
        $this->driven = \is_bool($result['driven'] ?? null) ? $result['driven'] : null;

        return (float) $mount;
    }

    /**
     * 接上宿主里**已经在跑**的会话，不重建应用。返回末帧号（观察者游标的起点）。
     *
     * 与 {@see self::open()} 同一个方法名会更好看，但两者的前置条件相反：`open` 要求
     * 宿主上还没有会话，`attach` 要求已经有。合成一个方法就得靠"带没带 script"分支，
     * 而那种分支的错误表现是"本来想接上，结果把别人的会话重建了"。
     *
     * @throws ProtocolError `unauthorized`（令牌不对）/ `not_open`（宿主上没有会话可接）
     */
    public function attach(string $token): int
    {
        if ($this->opened) {
            throw new \LogicException('这个远端会话已经打开过：一个宿主进程只服务一次运行');
        }
        $this->opened = true;

        $result = $this->callObject(Methods::OPEN, ['token' => $token]);
        $this->hostPid = \is_int($result['pid'] ?? null) ? $result['pid'] : null;
        $this->driven = \is_bool($result['driven'] ?? null) ? $result['driven'] : null;
        if (\is_int($result['lastFrame'] ?? null)) {
            $this->lastFrameId = $result['lastFrame'];
        }

        return $this->lastFrameId;
    }

    /**
     * 帧是不是这个宿主自己推的。
     *
     * `true` ⇒ 别发 `frame`（会被拒），要单步用 {@see self::resume()}
     * 带 `frames: 1`。`false` ⇒ 自己推。`null` ⇒ 没 attach 过，不知道。
     */
    public function driven(): ?bool
    {
        return $this->driven;
    }

    /**
     * 让自走的宿主停一下。回执与 `query('state')` 同形。
     *
     * @return array<array-key, mixed>
     *
     * @throws ProtocolError `not_applicable`（这个宿主不由自己推帧）
     */
    public function pause(): array
    {
        return $this->callObject(Methods::PAUSE);
    }

    /**
     * 继续跑；`$frames` 给了就只走这么多帧（`1` = 单步），走完回 `paused` + `reason=step`。
     *
     * 单步不在这里给时间戳：推进的是**脚本自己的下一帧**，而 `t` 属于脚本。
     * 客户端编一个 `t` 进来会在一条时间轴里插进另一条，而摘要比不出这件事。
     *
     * @return array<array-key, mixed>
     *
     * @throws ProtocolError `not_applicable`（不由自己推帧 / 已经 finished 或 crashed）
     */
    public function resume(?int $frames = null): array
    {
        return $this->callObject(Methods::RESUME, $frames === null ? [] : ['frames' => $frames]);
    }

    /**
     * 告诉宿主这次驱动结束了。只发"我因为 `--at=N` 停了"这个事实，
     * 状态名由宿主按唯一规则翻译 —— 客户端写状态等于让两边各有一套语义。
     */
    public function finish(?int $at = null): array
    {
        return $this->callObject(Methods::FINISH, $at === null ? [] : ['at' => $at]);
    }

    /** 跑这次会话的宿主进程号；没打开时为 `null`。 */
    public function hostPid(): ?int
    {
        return $this->hostPid;
    }

    /** @return float 整帧墙钟毫秒（由宿主量，与 `FrameClock` 同口径） */
    public function pump(float $timestampMs): float
    {
        $result = $this->callObject(Methods::FRAME, ['t' => $timestampMs]);
        $id = $result['id'] ?? null;
        $wall = $result['wall'] ?? null;
        if (!\is_int($id) || (!\is_int($wall) && !\is_float($wall))) {
            throw new ProtocolError(ProtocolError::BAD_RESPONSE, 'frame 的响应缺少 id / wall');
        }
        // 帧号由**宿主**给，客户端只记：自己数会与"容量裁剪""跳过 settle"这些事实错开。
        $this->lastFrameId = $id;

        return (float) $wall;
    }

    public function send(InputEvent $event, ?float $timestampMs = null): void
    {
        // 与脚本同一个编码：一条输入在管道上和一份脚本里必须是同一种形状，
        // 否则"录下来的"和"发出去的"会分两套，而差异只在跨进程时才显形。
        $this->call(Methods::INPUT, ['step' => InputScript::pack($event, $timestampMs)->toArray()]);
    }

    /**
     * @param array<string, scalar|null> $args
     *
     * @return mixed 宿主侧回调的返回值；只能是标量 / 数组 / null
     */
    public function effect(string $name, array $args = [], ?float $timestampMs = null): mixed
    {
        $params = ['name' => $name, 'args' => $args];
        if ($timestampMs !== null) {
            // 只在给了的情况下发：老宿主不认识 `t`，而缺省（null）与"用 lastT"
            // 在那边是同一件事，多发一个键只是让报文变长。
            $params['t'] = $timestampMs;
        }

        $result = $this->callObject(Methods::EFFECT, $params);

        return $result['value'] ?? null;
    }

    /**
     * 高亮一个节点（`0` = 清除）。与本地同一件事、同一个时刻口径。
     *
     * ★ 不在这里更新"当前高亮"的本地缓存：那是宿主里的事实，`query('highlight')`
     *   才有资格回答。客户端自己记一份，就会出现"宿主已经把高亮清掉了、客户端还
     *   以为在"这类两份真相 —— 而跨进程时这种偏差比本地更容易发生。
     */
    public function highlight(int $node, ?float $timestampMs = null): void
    {
        $params = ['node' => $node];
        if ($timestampMs !== null) {
            $params['t'] = $timestampMs;
        }

        $this->callObject(Methods::HIGHLIGHT, $params);
    }

    public function query(string $name, array $args = []): array
    {
        $result = $this->call(Methods::QUERY, ['name' => $name, 'args' => $args]);
        if (!\is_array($result)) {
            throw new ProtocolError(ProtocolError::BAD_RESPONSE, "查询 {$name} 的响应不是数组");
        }

        return $result;
    }

    public function lastFrameId(): int
    {
        return $this->lastFrameId;
    }

    /**
     * 关会话并关管道（宿主会在 `close` 之后退出）。
     *
     * 关闭阶段的异常**不上抛**：到这一步调用方已经没有补救动作，把"对端先死了"
     * 抛给报告组装只会让一次成功的运行以异常收尾。管道仍然会被关掉。
     */
    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;

        try {
            if ($this->opened) {
                $this->call(Methods::CLOSE);
            }
        } catch (\Throwable) {
            // 对端已经没了：要做的只有关管道。
        } finally {
            $this->transport->close();
        }
    }

    /**
     * 只断开，**不**关会话：接别人的会话时退出，不该把人家正在看的那次运行结束掉。
     *
     * 宿主发现这条通道没了只是把它从等待集合里摘掉；会话与录制都还在，下一个人
     * 随时可以再接上来（`CLOSE` 才是"这次运行到此为止"）。
     */
    public function detach(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $this->transport->close();
    }

    // ------------------------------------------------------------------ 收发

    /**
     * 发一条、等一条，返回原始 `result`。
     *
     * @param array<array-key, mixed> $params
     */
    private function call(string $method, array $params = []): mixed
    {
        if ($this->closed) {
            throw new \LogicException('远端会话已关闭');
        }

        $id = ++$this->seq;
        $this->transport->write(Wire::encode(['id' => $id, 'method' => $method, 'params' => $params]));

        $line = $this->transport->read();
        if ($line === null) {
            throw new ProtocolError(ProtocolError::CLOSED, "等 {$method} 的响应时对端关掉了管道");
        }

        $message = Wire::decode($line);
        if (($message['id'] ?? null) !== $id) {
            // 一条响应配错请求就说明通道上的顺序已经不可信，继续读下去只会串味。
            throw new ProtocolError(
                ProtocolError::BAD_RESPONSE,
                "响应的 id 不是 {$id}：" . Wire::encode($message),
            );
        }
        if (($message['ok'] ?? false) !== true) {
            $error = $message['error'] ?? [];
            throw new ProtocolError(
                \is_array($error) && \is_string($error['code'] ?? null) ? $error['code'] : ProtocolError::CALL_FAILED,
                \is_array($error) && \is_string($error['message'] ?? null) ? $error['message'] : '对端返回了失败但没有说明原因',
            );
        }

        return $message['result'] ?? null;
    }

    /**
     * @param array<array-key, mixed> $params
     *
     * @return array<array-key, mixed>
     */
    private function callObject(string $method, array $params = []): array
    {
        $result = $this->call($method, $params);
        if (!\is_array($result)) {
            throw new ProtocolError(ProtocolError::BAD_RESPONSE, "{$method} 的响应不是对象");
        }

        return $result;
    }
}
