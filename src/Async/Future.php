<?php
declare(strict_types=1);

namespace Drift\Async;

use Drift\Errors;

/**
 * **异步结果的唯一形状**（N3）—— 一个可以被组合的"将来会有值"。
 *
 * ## 为什么要有它（不是"多一层糖"）
 *
 * 回调模型的问题**不是啰嗦，是「组合」这个操作不存在**：
 *
 * ```php
 * // 回调（改造前）：同时取三个接口 —— 手工计数，嵌套两层就写不动
 * $pending = 3;
 * foreach ($urls as $i => $url) {
 *     $host->submit(new FetchJob($url), function ($r) use (&$all, &$pending, $i, $done): void {
 *         $all[$i] = $r;
 *         if (--$pending === 0) { $done($all); }
 *     });
 * }
 *
 * // Future：组合是类型自带的
 * Future::all([$api->get('/u'), $api->get('/o'), $api->get('/s')])
 *     ->then(fn (array $all) => $this->store->setData($all))
 *     ->catch(fn (\Throwable $e) => $this->store->fail($e));
 * ```
 *
 * `all` / `race` 在回调世界里都要手工计数，而手工计数就是 bug 温床（漏一个分支
 * 就是"永远转圈"）。这不是风格问题 —— 它是**能力缺口**。
 *
 * ## 与成熟框架对齐（语义逐条可对照）
 *
 * | 本仓 | Dart / Flutter | .NET / WPF | Rust |
 * |---|---|---|---|
 * | `then` | `Future.then` | `Task.ContinueWith` | `Future::map` |
 * | `catch` | `catchError` | `await` 的 `catch` | `Future::or_else` |
 * | `Future::all` | `Future.wait` | `Task.WhenAll` | `join!` |
 * | `Future::race` | `Future.any` | `Task.WhenAny` | `select!` |
 * | `cancel` | （无内建，靠 `CancelableOperation`） | `CancellationToken` | `drop` |
 *
 * ## 一条不可破的时序纪律
 *
 * **`resolve()` / `reject()` 只在 UI 线程的帧内被调用**（`PumpHost::pumpTasks()` /
 * `pumpSliced()`），所以 `then()` 的回调与改造前的 `$onDone` **完全同级**：
 * 都在 `settle()` 之前 ⇒ 回调里 `setState()` 同帧生效。
 * ⇒ 后端（`TaskHost`）**不许**在自己的线程上结算；它只能把结果入队。
 *
 * ## 失败不许静默（G2）
 *
 * 结算为失败时若**没有任何** `catch()`，本类直接走 `Errors::report()` 的 Fatal
 * 序列 —— 与改造前「`$onError === null` ⇒ Fatal」是同一条规矩。任务失败被吞掉
 * 就是"界面永远转圈"，而转圈**不报错**。
 *
 * @api
 */
final class Future
{
    private const PENDING = 0;
    private const FULFILLED = 1;
    private const REJECTED = 2;

    private int $state = self::PENDING;

    private mixed $value = null;

    private ?\Throwable $error = null;

    /**
     * 挂在后面的链（`then()` / `catch()` 各一条，`forwardTo()` 是空链）。
     *
     * ★ 结算时**整体取走再跑**（swap 语义）：回调里再 `then()` 同一个 Future
     *   不会让本帧无限循环 —— 与 `SyncTaskHost::drain()` 同一手法。
     *
     * @var list<array{ok: ?\Closure, err: ?\Closure, next: self}>
     */
    private array $links = [];

    /** 取消钩子（后端在这里停掉真正的活；`null` ⇒ 只能"忽略迟到的结果"） */
    private ?\Closure $onCancel = null;

    private bool $cancelled = false;

    /**
     * 是不是**任务**（`Future::pending()` 造的，或从它链出来的）。
     *
     * ★★ 这是"失败没人接 ⇒ Fatal"那条判据的**唯一开关**，也是踩过坑才加的：
     *   判据只能在"结算的那一刻"成立 —— 因为对任务而言，挂回调必然早于结算
     *   （`submit()` 返回后调用方立刻 `then()/catch()`，而结算发生在之后的
     *   `pumpTasks()` 帧内）。可对 `Future::rejected()` 造出来的**值**，结算
     *   发生在构造那一瞬，`$links` 必然是空的 —— 那不是"没人接"，是"还没轮到
     *   接"。实测：`Future::rejected($e)->catch(...)` 会被判成事故，直接把
     *   PHPUnit 进程 `exit(3)`（`Errors.log` 里那条 `异步任务失败且没有
     *   catch()` 就是它）。
     *   ⇒ 一句话：**任务没人接要喊，值不喊**。链下游继承这个标记（`chain()`），
     *     所以 `submit()->then()` 漏写 `catch()` 仍然在链末端喊 Fatal（G2 不变）。
     */
    private bool $task = false;

    /**
     * @internal 只有后端与 `all`/`race` 造 Future；应用侧从 `submit()` 拿
     *
     * @param null|\Closure(): void $onCancel 取消钩子（可空：不是所有后端都能停）
     */
    private function __construct(?\Closure $onCancel = null)
    {
        $this->onCancel = $onCancel;
    }

    /**
     * 造一个**还没结算的任务** —— 等后端（或组合器、或测试）来结算。
     *
     * ★ 应用侧不该调它：拿 Future 的唯一入口是 `TaskHost::submit()` /
     *   `submitSliced()`。测试与后端用它造"我手动结算"的替身。
     * ★ 只有这里造出来的 Future（以及它链下去的下游）在"没人接"时才走 Fatal。
     *
     * @param null|\Closure(): void $onCancel 取消钩子（可空：不是所有后端都能停）
     *
     * @api
     */
    public static function pending(?\Closure $onCancel = null): self
    {
        $future = new self($onCancel);
        $future->task = true;

        return $future;
    }

    /**
     * 已经成功的 Future（`then()` 立刻在当前栈上跑）。
     *
     * @api
     */
    public static function resolved(mixed $value): self
    {
        $future = new self();
        $future->resolve($value);

        return $future;
    }

    /**
     * 已经失败的 Future —— 这是个**值**，不是任务。
     *
     * ★ 构造时还没人来挂 `catch()`，所以**不**判"没人接"（见 `$task` 的注释）；
     *   典型写法就是 `Future::rejected($e)->catch(...)` 或塞进 `Future::all()`。
     *
     * @api
     */
    public static function rejected(\Throwable $error): self
    {
        $future = new self();
        $future->reject($error);

        return $future;
    }

    /**
     * 成功时跑 `$onFulfilled`，返回**新**的 Future（收它的返回值）。
     *
     * ★ 回调里返回另一个 `Future` ⇒ 自动摊平（`then()` 的返回值会等它）——
     *   这就是"取 A 再用 A 的结果取 B"不用嵌套的原因。
     * ★ 回调里抛异常 ⇒ 下游 Future 变成失败（`catch()` 能接住）。
     * ★ 在**已结算**的 Future 上挂 ⇒ 立刻在当前栈上执行。
     *
     * @param callable(mixed): mixed $onFulfilled
     *
     * @api
     */
    public function then(callable $onFulfilled): self
    {
        return $this->chain(\Closure::fromCallable($onFulfilled), null);
    }

    /**
     * 失败时跑 `$onRejected`，返回**新**的 Future。
     *
     * ★ 挂了它就算"这条链处理了失败"⇒ 不再走 Fatal 序列。
     *
     * @param callable(\Throwable): mixed $onRejected
     *
     * @api
     */
    public function catch(callable $onRejected): self
    {
        return $this->chain(null, \Closure::fromCallable($onRejected));
    }

    /**
     * 请求取消（**尽力而为**）。
     *
     * ★ 语义边界必须说清：`cancel()` 只做两件事 —— ① 调后端的取消钩子（能停就停）；
     *   ② 把本 Future 标成"结果不要了"，此后迟到的 `resolve()` 被忽略。
     *   它**不会**让 Future 变成失败（"取消"不是错误，不该逼调用方写 `catch`）。
     * ★ 生产者（**只有三处**，前两处真停、第三处只能不交付）：
     *   - `Future::all()` / `race()` —— 取消落选者；
     *   - `PumpHost::submitSliced()` —— 摘出队列，此后**不再 `step()`**（真停）；
     *   - `ProcessTaskHost` —— 摘出**还没发出去**的排队项。已发给 worker 的停不了
     *     （杀进程要重拉、还会丢在途状态），只做"不交付"；
     *   - `ThreadTaskHost` —— **只能"不交付"**：任务已经交给 C++ worker 了，
     *     摘不出来（`core/io_pool.cc` 的队列不在 PHP 侧）。
     *     ★ 但它**不减记账**：`pending()` 仍算这条，直到结果行真的回来 ——
     *       掉到 0 帧时钟就睡死，那条结果会一直躺在完成队列里。
     *   ★ 2026-10-30 之前这里写着"`ProcessTaskHost` 用它杀在途 worker 任务"——
     *     **没有任何后端传过钩子**，`cancel()` 在真实任务上是纯空操作。契约说谎
     *     比没有这个能力更糟：调用方以为停了，实际每帧还在烧预算。
     *
     * @api
     */
    public function cancel(): void
    {
        if ($this->state !== self::PENDING || $this->cancelled) {
            return;
        }

        $this->cancelled = true;

        $hook = $this->onCancel;
        if ($hook !== null) {
            $hook();
        }
    }

    /** 是否已经结算（成功或失败都算） */
    public function isDone(): bool
    {
        return $this->state !== self::PENDING;
    }

    /** 是否被 `cancel()` 过（后端可以据此跳过收尾工作） */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /**
     * 全部成功才算成功，结果是**与入参同键同序**的数组；总耗时 ≈ 最慢的那条。
     *
     * ★ 任一条失败 ⇒ 整条失败，且**其余被 `cancel()`**（不留无人认领的后台活）。
     * ★ 空数组 ⇒ 立刻成功（`[]`）—— 与 `Future.wait([])` / `Task.WhenAll([])` 一致。
     *
     * @param array<array-key, self> $futures
     *
     * @api
     */
    public static function all(array $futures): self
    {
        $all = new self();

        $list = [];
        foreach ($futures as $key => $future) {
            if (!$future instanceof self) {
                throw new \InvalidArgumentException('Future::all() 只接受 Future —— 下标 ' . (string) $key);
            }

            $list[$key] = $future;
        }

        if ($list === []) {
            $all->resolve([]);

            return $all;
        }

        $left = \count($list);
        $got = [];

        foreach ($list as $key => $future) {
            $future->then(static function (mixed $value) use (&$got, &$left, $key, $all, $list): void {
                $got[$key] = $value;
                $left = $left - 1;

                if ($left !== 0) {
                    return;
                }

                // ★ 按**入参顺序**重排：结果数组的键序是契约的一部分（`list` 更要紧）
                $ordered = [];
                foreach ($list as $k => $ignored) {
                    if (\array_key_exists($k, $got)) {
                        $ordered[$k] = $got[$k];
                    }
                }

                $all->resolve($ordered);
            })->catch(static function (\Throwable $error) use ($all, $list): void {
                foreach ($list as $other) {
                    $other->cancel();
                }

                $all->reject($error);
            });
        }

        return $all;
    }

    /**
     * 第一个结算的赢，其余被 `cancel()`。
     *
     * ★ 空数组 ⇒ **永不结算**（没有候选可赢）—— 与 `Promise.race([])` 一致。
     *   这比"立刻失败"好：调用方本来就该保证非空，静默永不结算会被帧时钟暴露
     *   （`pending()` 一直 > 0），而立刻失败会假装"跑过了"。
     *
     * @param array<array-key, self> $futures
     *
     * @api
     */
    public static function race(array $futures): self
    {
        $winner = new self();

        $list = [];
        foreach ($futures as $key => $future) {
            if (!$future instanceof self) {
                throw new \InvalidArgumentException('Future::race() 只接受 Future —— 下标 ' . (string) $key);
            }

            $list[$key] = $future;
        }

        foreach ($list as $future) {
            $future->then(static function (mixed $value) use ($winner, $list): void {
                if ($winner->isDone()) {
                    return;
                }

                foreach ($list as $other) {
                    $other->cancel();
                }

                $winner->resolve($value);
            })->catch(static function (\Throwable $error) use ($winner, $list): void {
                if ($winner->isDone()) {
                    return;
                }

                foreach ($list as $other) {
                    $other->cancel();
                }

                $winner->reject($error);
            });
        }

        return $winner;
    }

    /**
     * 结算为成功（**后端与 `all`/`race` 专用**）。
     *
     * ★ 只在 UI 线程的帧内调 —— 见类注释的时序纪律。
     * ★ 被 `cancel()` 过 ⇒ 忽略（"结果不要了"包含"别再来吵我"）。
     *
     * @internal
     */
    public function resolve(mixed $value): void
    {
        if ($this->state !== self::PENDING || $this->cancelled) {
            return;
        }

        $this->state = self::FULFILLED;
        $this->value = $value;

        $links = $this->links;
        $this->links = [];

        foreach ($links as $link) {
            $this->settleLink($link, true);
        }
    }

    /**
     * 结算为失败（**后端专用**）。没有任何 `catch()` ⇒ Fatal 序列（G2）。
     *
     * @internal
     */
    public function reject(\Throwable $error): void
    {
        $this->rejectCore($error, true);
    }

    /**
     * 内部结算为失败：`$report` 决定"没人接"时要不要喊。
     *
     * ★ 链式传播时必须是 `false`：喊一次就够，否则一个失败会刷 N 条 Fatal。
     */
    private function rejectCore(\Throwable $error, bool $report): void
    {
        if ($this->state !== self::PENDING || $this->cancelled) {
            return;
        }

        $this->state = self::REJECTED;
        $this->error = $error;

        $links = $this->links;
        $this->links = [];

        // ★★ 判"有没有人接"要**看整条链**，不能只看本 Future 的直连处理器。
        //   反例（第一版就这么错）：`$f->then($ok)->catch($err)` —— `$f` 自己没有
        //   err 处理器，可错误会**传到** `$n1`，而 `$n1` 有 `catch`。只看直连就会
        //   在 `$f` 上喊 Fatal，把一条正常处理的链判成事故。
        //   ⇒ 判据是"**链的末端**没人接"：没有链（没人挂过任何回调）⇒ 现在就喊；
        //     有链 ⇒ 让错误继续往下走，由末端的 `rejectCore()` 决定（见下面的
        //     `$links === []` 分支）。`$report` 只用来屏蔽"已经喊过"的重复传播。
        // ★★ 再加一条闸：**只有任务才喊**（`$task`）。值（`Future::rejected()`
        //   造的那个、`all`/`race` 的聚合体）在构造那一瞬 `$links` 必然为空，
        //   拿"没人接"去判它就是把正常写法判成事故。
        if ($links === [] && $report && $this->task) {
            Errors::report(\sprintf(
                '异步任务失败且没有 catch() —— %s: %s',
                \get_class($error),
                $error->getMessage(),
            ));
        }

        foreach ($links as $link) {
            $this->settleLink($link, false);
        }
    }

    /** 挂一条链（`then` / `catch` 的唯一落点） */
    private function chain(?\Closure $ok, ?\Closure $err): self
    {
        $next = new self();
        // ★ 下游继承"是任务" —— 否则 `submit()->then()` 漏 `catch()` 的那条
        //   会在链末端静默（判据只看链末端有没有人接，见 `rejectCore()`）
        $next->task = $this->task;

        if ($this->state === self::PENDING) {
            $this->links[] = ['ok' => $ok, 'err' => $err, 'next' => $next];

            return $next;
        }

        // 已结算：立刻跑（在**当前**栈上）—— 所以 then() 只在 UI 线程挂
        $this->settleLink(['ok' => $ok, 'err' => $err, 'next' => $next], $this->state === self::FULFILLED);

        return $next;
    }

    /**
     * 跑一条链。
     *
     * @param array{ok: ?\Closure, err: ?\Closure, next: self} $link
     */
    private function settleLink(array $link, bool $ok): void
    {
        $next = $link['next'];

        if ($ok) {
            $this->apply($link['ok'], $this->value, $next, true);

            return;
        }

        $error = $this->error;
        if ($error === null) {
            // 不可能发生（REJECTED ⇔ error !== null）；防 AOT 把可空读成 null 后静默
            $error = new \RuntimeException('Future 处于失败态但没有异常');
        }

        $this->apply($link['err'], $error, $next, false);
    }

    /** 跑一个回调并把结果落到 `$next`（返回 Future 则摊平） */
    private function apply(?\Closure $fn, mixed $arg, self $next, bool $ok): void
    {
        if ($fn === null) {
            // 没有对应回调 ⇒ 原样透传（`then` 不管失败、`catch` 不管成功）
            if ($ok) {
                $next->resolve($arg);

                return;
            }

            // ★ `true`：把"要不要喊 Fatal"的决定权交给链的末端（`links === []` 才喊）
            $next->rejectCore($arg instanceof \Throwable ? $arg : new \RuntimeException('未知失败'), true);

            return;
        }

        try {
            $result = $fn($arg);
        } catch (\Throwable $e) {
            // ★ **回调自己抛异常 = 调用方的 bug**，不是"任务失败" ⇒ 走 `true`：
            //   下游有 `catch()` 就交给它，没有就走 Fatal 序列（与改造前
            //   `PumpHost::pumpTasks()` 的 try/catch 同一条规矩：不许静默吞）。
            $next->rejectCore($e, true);

            return;
        }

        if ($result instanceof self) {
            // ★ 摊平：等内层结算再落到 next（"取 A 再用 A 取 B"不嵌套）
            $result->forwardTo($next);

            return;
        }

        $next->resolve($result);
    }

    /** 把本 Future 的结算原样转给 `$next`（摊平用；已结算则立刻转） */
    private function forwardTo(self $next): void
    {
        if ($this->state === self::PENDING) {
            $this->links[] = ['ok' => null, 'err' => null, 'next' => $next];

            return;
        }

        $this->settleLink(['ok' => null, 'err' => null, 'next' => $next], $this->state === self::FULFILLED);
    }
}
