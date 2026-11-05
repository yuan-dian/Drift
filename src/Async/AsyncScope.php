<?php
declare(strict_types=1);

namespace Drift\Async;

/**
 * 把一条**多步异步流水线**写成一段顺序代码。
 *
 * ★ 它解决的是什么：`apps/watermark` 的 5 步（签名→上传→建任务→轮询→下载）
 *   用 `AsyncCommand` + 订阅写，要维护 5 个状态槽、5 个 `absorb*`、3 个接力标志
 *   和一套轮询节奏 —— 一条线性的事被拆成三份（状态机 / 回调 / 跨进程序列化）。
 *   本类让这 5 步退回成一段 `await`，`AsyncCommand` 只留**一个**。
 *
 * ★ 底层是 PHP `Fiber`（用户态协程，**不是** Swoole 协程）。AOT `-m bin` 下已验：
 *   `probes/fiber_scope` 十四条边界（跨帧 / 操作 tpc 编译类 / throw+finally /
 *   回调栈恢复 / 多 Fiber / 嵌套 / 深栈 / 传对象 / 局部消化 / getCurrent）。
 *   ★ `--nano` 模式不支持 Fiber，但本仓**已明确不做 nano**（`DESIGN.md` §16.7.5）。
 *
 * ★ 它**不改变** `Future` / `AsyncCommand` 的任何语义：`task()` 返回的就是一条
 *   普通 `Future` ⇒ 去重 / 重试 / 取消 / 结算订阅 / `isRunning()` 全部照旧。
 *   所以「一条 Future 交给一个 AsyncCommand」这条契约仍然成立（`MultiStepCommandTest`）。
 *
 * ★ 取消是**异常**（`AsyncCancelled`），所以 body 里的 `catch (\Throwable)` 会连它
 *   一起接住。业务侧该写 `catch (AsyncCancelled $e) { throw $e; }` 放在前面；
 *   即使忘了，`await()` / `sleep()` 入口也会把取消重新抛出来（`guardCancelled()`），
 *   代价只是多跑几条同步语句。
 *
 * 用法：
 * ```php
 * $this->flow = $this->asyncCommand(fn (array $paths): Future => AsyncScope::task(
 *     function (AsyncScope $co) use ($paths): array {
 *         $sign = $co->await($this->host->submit(new SignJob()));
 *         $co->await($this->host->submit(new UploadJob($paths, $sign)));
 *
 *         return ['ok' => true];
 *     },
 *     $this->delay,   // 需要 sleep() 才给
 * ));
 * ```
 *
 * ★ 与「`$task` 内部 `then()` 组合」的分工（`MultiStepCommandTest` 的范式）：
 *   两步、无循环、中间值不用上界面 ⇒ 用 `then()` 更轻，不必开协程；
 *   **有循环（轮询）、有重试、每步都要更新界面** ⇒ 用本类。
 *
 * @api
 */
final class AsyncScope
{
    /**
     * 正在跑的协程。
     *
     * ★ 类型声明写 `\Fiber`（不是 `mixed`）：AOT 下属性类型是真实布局的一部分，
     *   `mixed` 会退化成装箱读取。
     */
    private \Fiber $fiber;

    /** 交给调用方的那条 Future —— `task()` 的返回值，也是取消的入口 */
    private Future $outer;

    /** 当前 `await()` 在等的那条（取消时要具体打到它，否则后端不会真停） */
    private ?Future $waiting = null;

    /**
     * 延时函数 `(毫秒, 回调) => void`。
     *
     * ★ 靠注入而不是直接调平台：`src/Async` **不许依赖 `Drift\Platform`**
     *   （§4.3 依赖方向）。应用侧把 `Application::after()` 或自己的 scheduler 传进来。
     */
    private ?\Closure $delay;

    private bool $cancelled = false;

    /**
     * 起一个作用域，返回它在跑的那条 Future。
     *
     * ★ `$body` 在**本栈上同步跑到第一个真正要等的点**就返回，不在这里等结果；
     *   之后每次结算由内部回调把它推进一步。全部同步完成时（如 `null` TaskHost），
     *   整段会在这里一口气跑完，**不产生额外帧** —— 与原来的同步路径时序一致。
     *
     * @param callable(self): mixed $body 顺序代码本体
     * @param null|callable(int, \Closure): void $delay 延时函数；不给 ⇒ `sleep()` 恒返回 `false`
     *
     * @api
     */
    public static function task(callable $body, ?callable $delay = null): Future
    {
        $scope = new self($delay);

        return $scope->launch(\Closure::fromCallable($body));
    }

    private function __construct(?callable $delay)
    {
        $this->delay = $delay === null ? null : \Closure::fromCallable($delay);
    }

    /**
     * 等一条 Future 结算：成功返回它的值，失败**原样抛出**。
     *
     * ★ 抛出来的异常可以像普通异常一样被 `try/catch` ——
     *   「第三步失败要回退到第一步重来」就是普通的 `try`，不必再造错误分支。
     * ★ 已结算的 Future 不会让出（`Future::resolved()` / 同步后端都是这条路），
     *   所以整段代码在同步场景下就是普通顺序代码。
     *
     * ★ `$onProgress` 收进来是**纪律**，不是语法糖：`Future::onProgress()` 不沿
     *   `then()` 派生链传递，且结算后再挂最多补发一次终值 —— 于是"挂晚了 / 挂错了
     *   那条"这两种错法都**不报错**，只表现为进度条不动。把它放进 `await()` 的入参，
     *   正确顺序由本方法保证，调用方不必再记住这条。
     *   ★ 但它只能挂到**传进来那条**。要让进度真的动起来，传的必须是
     *   `$host->submit($job)` 的返回值（或任何没派生过的 Future）—— 传
     *   `$f->then(...)` 的结果照样收不到，那是 `Future` 的既有契约，本方法改不了。
     *
     * @param null|callable(float): void $onProgress 进度回调（`0.0`–`1.0`）；
     *        本来就有进度的话会**立刻**收到一次当前值（`Future::onProgress()` 的行为）
     *
     * @api
     */
    public function await(Future $future, ?callable $onProgress = null): mixed
    {
        $this->assertInside();
        $this->guardCancelled();

        // ★ 排在 then/catch 之前：两条是独立通道，顺序不影响正确性，但先订进度
        //   才符合"看着它一点点走完"的直觉；也保证补发的那次进度不被结算回调抢先。
        if ($onProgress !== null) {
            $future->onProgress($onProgress);
        }

        $settled = false;
        $value = null;
        $error = null;

        // ★ `then()->catch()` 是**一条链**：原 Future 失败时错误透传到 `catch`。
        //   分两次挂（`$f->then(); $f->catch();`）会派生出一条没人接的 Future ⇒
        //   它一失败就走 Fatal 序列（`Future::rejectCore()` 的"链末端没人接"判据）。
        $future->then(function (mixed $v) use (&$settled, &$value): void {
            $settled = true;
            $value = $v;
            $this->wake();
        })->catch(function (\Throwable $e) use (&$settled, &$error): void {
            $settled = true;
            $error = $e;
            $this->wake();
        });

        if (!$settled) {
            // ★ 先记账再让出：让出期间后端可能恰好结算并回调 `wake()`
            $this->waiting = $future;
            \Fiber::suspend();
            $this->waiting = null;
        }

        if ($error !== null) {
            throw $error;
        }

        return $value;
    }

    /**
     * 等一段时间（`await` 的定时器版本）。
     *
     * ★ 轮询类流水线靠它写 `while` 循环，而不是靠外面排定时器 ——
     *   「每轮只排一个」这类记账就从应用里彻底消失（`apps/watermark` 曾被它咬过两次）。
     *
     * ★ **必须判返回值**：`false` = 本作用域没有定时器（`task()` 没给第二个参数），
     *   这一次**没有睡**。在 `while` 里忽略它，循环会退化成零延时重试 ——
     *   一轮一轮地发请求，而且不报错。
     *
     * @param int $milliseconds 毫秒（负数按 0 处理）
     *
     * @return bool 真的排上定时器并等完了吗（`false` ⇒ 什么都没发生，立刻返回）
     *
     * @api
     */
    public function sleep(int $milliseconds): bool
    {
        $this->assertInside();
        $this->guardCancelled();

        $delay = $this->delay;
        if ($delay === null) {
            // ★ 不报错：能力缺失用返回值表达，交给调用方决定零延时意味着什么
            //   （`apps/watermark` 的选择是"只查这一次"）。
            return false;
        }

        $timer = Future::pending();
        $delay($milliseconds < 0 ? 0 : $milliseconds, function () use ($timer): void {
            $timer->resolve(null);
        });

        $this->await($timer);

        return true;
    }

    /**
     * 本作用域已被取消。
     *
     * ★ 多数情况**不需要问**：取消会直接投异常进去，代码根本走不到下一行。
     *   它只给"必须在循环末尾做一次清理再退出"的场景用。
     *
     * @api
     */
    public function isCancelled(): bool
    {
        return $this->cancelled;
    }

    /** @param callable(self): mixed $body */
    private function launch(\Closure $body): Future
    {
        $this->outer = Future::pending(function (): void {
            $this->abort();
        });

        $this->fiber = new \Fiber(function () use ($body): void {
            try {
                $value = $body($this);
            } catch (\Throwable $e) {
                // ★ 取消时 outer 已被 cancel ⇒ `rejectCore()` 第一句就 return，
                //   不会喊"任务失败且没人 catch"。
                $this->outer->reject($e);

                return;
            }

            $this->outer->resolve($value);
        });

        $this->fiber->start();

        return $this->outer;
    }

    /**
     * 把控制权还给协程（**只在结算回调里调**）。
     *
     * ★ 必须查 `isSuspended()`：同步结算时回调发生在 `await()` 自己的栈上，
     *   此刻协程是 RUNNING，`resume()` 会抛 `FiberError`。而那种情况下
     *   `$settled` 已是 true，`await()` 根本不会让出 —— 忽略这次唤醒是正确的。
     */
    private function wake(): void
    {
        if ($this->fiber->isSuspended()) {
            $this->fiber->resume();
        }
    }

    /**
     * 取消：停掉在途的那条 + 让协程**真的终结**。
     *
     * ★ 不投异常就会永久挂在 `suspend()` 上 —— 协程栈连同它捕获的闭包一起泄漏，
     *   每次取消漏一份。
     * ★ 投 `AsyncCancelled` 而不是悄悄 return：只有走异常才能让 `finally` 执行，
     *   资源清理（临时文件、订阅）才不会漏。
     * ★ **只投一次**（2026-11-04 从"最多三次"简化）：`await()` / `sleep()` 入口有
     *   `guardCancelled()` —— 被 body 吞掉的取消会在**下一个让出点**原样重来。
     *   三次注入是没这道闸时的近似兜底，有了它就是重复：判据上两条互相掩盖，
     *   谁也测不出来（`build/_mutate_asyncscope.php` 的 D/F 两组就是这么暴露的）。
     *   一次注入 + 让出点闸门是**确定**的；三次是**猜**。
     */
    private function abort(): void
    {
        $this->cancelled = true;

        $waiting = $this->waiting;
        if ($waiting !== null) {
            $waiting->cancel();
        }

        if ($this->fiber->isTerminated()) {
            return;
        }

        if (!$this->fiber->isSuspended()) {
            return;   // 正在跑：它自己会看到 cancelled（同步结算的瞬间）
        }

        try {
            $this->fiber->throw(new AsyncCancelled('流水线已取消'));
        } catch (\Throwable $e) {
            // body 里的 catch 已经把 outer->reject 调过了（被 cancelled 挡住）；
            // 这里只兜住"它又把异常抛出来"这一种。
        }
    }

    /**
     * 已取消 ⇒ 立刻抛，**不排任何东西**。
     *
     * ★ 为什么需要：取消靠往协程里投一次 `AsyncCancelled`，而业务代码为了"这一步
     *   失败不该中断整条流水线"会写 `catch (\Throwable)` —— 那会**把取消一起接住**。
     *   没这道闸，接住之后的 `await()` 又是一个新的让出点 ⇒ 协程永远挂在上面
     *   （栈泄漏），或者循环继续、又排一个定时器（`apps/watermark` 的现场）。
     * ★ 有了它，取消在**每个让出点**都会被重新检查 —— 这是确定的，不靠试三次。
     *   业务侧仍建议写 `catch (AsyncCancelled $e) { throw $e; }`：那样连"吞掉之后
     *   的几条同步语句"都不跑，也不会把取消误写成一条错误消息。
     */
    private function guardCancelled(): void
    {
        if ($this->cancelled) {
            throw new AsyncCancelled('流水线已取消');
        }
    }

    /**
     * `await()` / `sleep()` 只能在自己的 body 里调。
     *
     * ★ 在协程外调会永远挂住（没人来唤醒）⇒ 当场抛，不要让它变成"界面不动了"
     *   这种查一天的问题。
     */
    private function assertInside(): void
    {
        if (\Fiber::getCurrent() === $this->fiber) {
            return;
        }

        throw new \LogicException('AsyncScope::await()/sleep() 只能在 task() 的 body 里调用');
    }
}
