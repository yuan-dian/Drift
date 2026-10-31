<?php
declare(strict_types=1);

namespace Drift\App\Io;

/**
 * 非阻塞 I/O 的**每帧轮询器**（§17.4.4，P2）—— 把"等待"从线程里拿出来。
 *
 * ★ 它存在的前提是一条实测结论（`probes/RESULTS.md` §12.3）：**Swoole 协程不能用**
 *   （`Event::dispatch()` 阻塞到下一个事件 ⇒ 单帧 57ms，且 hook 是全局替换）、
 *   **PHP `Fiber` 不会让出阻塞 IO**。⇒ 正确形态是 **IO 先非阻塞 + 每帧 `select(0)`**：
 *   `stream_set_blocking($s, false)` → 读不到就返回 → 下一帧再问。**零线程、零扩展**。
 *
 * ★ **与成熟框架同构**：Qt 的 `QSocketNotifier`、WPF 的 IOCP 完成端口、Godot 的
 *   `MessageQueue` —— 都是"事件循环的一轮"，区别只在谁提供那一轮。这里提供者是
 *   `Application::frame()`（§17.2.7）。
 *
 * ★ **回调里可以做任何 UI 事**：`poll()` 在 UI 线程的帧内被调用（`settle()` 之前）⇒
 *   回调里 `setState()` / `notifyChanged()` 与 `TaskHost` 的回调完全同级。
 *
 * ★ **第一版刻意不做的事**（避免"没有消费者就发明机制"）：
 *   - **不做通用 Fiber 调度器**：`Awaitable::read()` 那种"顺序代码"包装等真有消费者再加；
 *   - **不做写缓冲 / 粘包处理**：那是协议层（谁定义消息边界谁负责），轮询器只管"可读了"。
 *
 * @api
 */
final class IoPoller
{
    /**
     * 已注册的流。
     *
     * @var array<int, array{stream: mixed, forRead: bool, callback: \Closure}>
     */
    private array $watchers = [];

    /** token 自增（`0` 保留给"无效"） */
    private int $next = 1;

    /**
     * 监听一个流。
     *
     * ★ 调用方**必须**先把流设成非阻塞（`stream_set_blocking($s, false)`）——
     *   否则 `fread` 在回调里仍会卡住整个线程（`poll()` 只保证"问一下就走"，
     *   保证不了你自己读的时候不阻塞）。
     *
     * @param mixed $stream `stream` 资源（`stream_socket_client` / `stream_socket_pair` / 管道）
     * @param bool $forRead true = 等可读，false = 等可写
     * @param callable(mixed): void $callback 就绪回调（收那个流；在 UI 线程的帧内执行）
     *
     * @return int token（交给 `unwatch()`）
     *
     * @api
     */
    public function watch(mixed $stream, bool $forRead, callable $callback): int
    {
        $token = $this->next;
        $this->next = $this->next + 1;

        $this->watchers[$token] = [
            'stream' => $stream,
            'forRead' => $forRead,
            'callback' => \Closure::fromCallable($callback),
        ];

        return $token;
    }

    /**
     * 取消监听。
     *
     * ★ 幂等：token 不存在（重复取消 / 流已关）⇒ 什么也不做。回调里取消自己也是安全的
     *   （`poll()` 遍历的是快照）。
     *
     * @api
     */
    public function unwatch(int $token): void
    {
        unset($this->watchers[$token]);
    }

    /** 当前监听数（测试与 devtools 用） */
    public function watchCount(): int
    {
        return count($this->watchers);
    }

    /**
     * 问一轮"谁就绪了"（**不阻塞**）。
     *
     * ★ `stream_select(..., 0)`：超时 0 ⇒ 立即返回。这正是它与 Swoole 的
     *   `Event::dispatch()` 的根本差别（后者阻塞到下一个事件，§17.2.6 实测 57ms/帧）。
     *
     * ★ 回调抛异常**不在这里吞**：让它冒到 `Application::pumpIo()` 的 try/catch
     *   （与 `pumpTasks()` 同一策略：S3 无边界 ⇒ Fatal 序列）。
     */
    public function poll(): void
    {
        if ($this->watchers === []) {
            return;
        }

        $read = [];
        $write = [];
        foreach ($this->watchers as $watcher) {
            if ($watcher['forRead']) {
                $read[] = $watcher['stream'];
            } else {
                $write[] = $watcher['stream'];
            }
        }

        $except = [];

        // ★ `@`：流被对端关掉时 `stream_select` 会发 warning。**不是吞错** ——
        //   返回 false 就跳过这一帧，真正的错误由回调里的 `fread` / `feof` 决定。
        $ready = @stream_select($read, $write, $except, 0);
        if ($ready === false || $ready === 0) {
            return;
        }

        // ★ 用 `===` 反查 token（resource 是身份比较），不走 `(int) $stream` ——
        //   后者在 PHP 8 上会发"resource 转 int"的 deprecated，且 tpc 的类型推断
        //   对 resource 转 int 没验过。监听数通常 < 10，O(n²) 无所谓。
        foreach (array_merge($read, $write) as $stream) {
            foreach ($this->watchers as $watcher) {
                if ($watcher['stream'] === $stream) {
                    ($watcher['callback'])($stream);

                    break; // 一个流一轮只回调一次
                }
            }
        }
    }
}
