<?php
declare(strict_types=1);

namespace Drift\Async;

use Drift\Diagnostics;
use Drift\Errors;

/**
 * 异步结果 —— 可以被组合的"将来会有值"。
 *
 * ★ `resolve()` / `reject()` 只在 UI 线程的帧内调，后端不许在自己线程上结算、
 *   只能入队：否则 `then()` 回调不再与结算同级，`setState()` 晚一帧。
 * 失败时没有任何 `catch()` ⇒ 走 Fatal 序列：失败被吞掉 = 界面永远转圈且不报错。
 *
 * @api
 */
final class Future
{
    private const int PENDING = 0;
    private const int FULFILLED = 1;
    private const int REJECTED = 2;

    private int $state = self::PENDING;

    private mixed $value = null;

    private ?\Throwable $error = null;

    /**
     * ★ 结算时整体取走再跑：回调里再 `then()` 同一个 Future 不会本帧无限循环。
     *
     * @var list<array{ok: ?\Closure, err: ?\Closure, next: self}>
     */
    private array $links = [];

    /** 取消钩子（后端在这里停掉真正的活；`null` ⇒ 只能"忽略迟到的结果"） */
    private ?\Closure $onCancel = null;

    private bool $cancelled = false;

    /**
     * 是不是**任务**（`pending()` 造的，或从它链出来的）。
     *
     * ★★ "失败没人接 ⇒ Fatal" 的唯一开关。判据只在结算那一刻看 `$links`，而
     *   `rejected()` 造的**值**在构造时 `$links` 必然为空 —— 那是"还没轮到接"，
     *   不是"没人接"。任务没人接要喊，值不喊；下游经 `chain()` 继承标记。
     */
    private bool $task = false;

    /** 由 `chain()` 派生出来的节点 —— 进度只喂根任务，挂在这里的 sink 收不到 */
    private bool $derived = false;

    /**
     * 进度（`0.0`~`1.0`）与"有没有报过"。
     * ★ 必须单独一个 `bool`：拿 `$progress !== null` 判会把"进度正好 0.0"与"从没
     *   报过"混成一种（前者画 0% 的条，后者画不定长转圈）。进度不是状态，`isDone()`
     *   不看它。
     */
    private float $progress = 0.0;

    private bool $hasProgress = false;

    /**
     * 进度订阅者。进度与结算不是同一事件（进度必在结算之前），两套回调不冲突。
     * ★ 进度**不沿 `chain()` 传递**；要进度就订阅根任务那一条。挂错链的错法
     *   由 `onProgress()` 留 `progress/derived-chain` 诊断，不再只是"进度条不动"。
     *
     * @var list<\Closure>
     */
    private array $progressSinks = [];

    /**
     * 结算观察者（`onSettle()` 的订阅者）。
     *
     * ★ 与 `then()` 的本质区别：观察者**不派生** Future、不改变值与失败传播，
     *   只是在结算那一刻看一眼。因此它也不会把 Fatal 判据的 `$links` 撑非空。
     * ★ 触发顺序是刻意的：在 `$links` 跑之前（见 `resolve()` / `rejectCore()`）。
     *   观察者在那里压下的"当前事件"标记，能被同帧 `then()` 里的 `setState`
     *   读到 —— 这是"网络请求落地后谁重建了"能被归因到同一条边的前提。
     *
     * @var list<\Closure(bool, mixed): void> 成功 ⇒ (true, 值)；失败 ⇒ (false, \Throwable)
     */
    private array $settleSinks = [];

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
     * 造一个**还没结算的任务**，等后端 / 组合器 / 测试来结算。
     *
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
     * ★ 构造时没人挂 `catch()`，不要拿"没人接"去判它。
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
     * 成功时跑 `$onFulfilled`，返回**新**的 Future。
     *
     * ★ 回调返回另一个 `Future` ⇒ 自动摊平；抛异常 ⇒ 下游变失败。
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
     * 失败时跑 `$onRejected`，返回**新**的 Future；挂了它就算"这条链处理了失败"，
     * 不再走 Fatal 序列。
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
     * 订阅**进度**（`0.0`~`1.0`）。不派生新 Future（进度不改变结果），返回 `$this`。
     *
     * ★ 已经有过进度时立刻补发一次：订阅晚于第一个进度包是常态。
     * ★ 只有 `ProcessTaskHost` 会喂进度；拿不到不是错误，是那个后端没这个能力。
     * ★ 挂在派生链下游会留一条 `progress/derived-chain` 诊断 —— 那种错法以前
     *   只表现为"进度条不动"，不报错也不留痕。
     *
     * @param callable(float): void $onProgress
     *
     * @api
     */
    public function onProgress(callable $onProgress): self
    {
        if ($this->derived) {
            Diagnostics::degraded(
                'progress/derived-chain',
                'onProgress() 挂在 then()/catch() 派生的 Future 上 —— 进度只喂根任务，'
                . '这条订阅永远收不到；挂到 submit() 返回的那条，或用 AsyncScope::await() 的进度参数'
            );
        }

        $sink = \Closure::fromCallable($onProgress);
        $this->progressSinks[] = $sink;

        if ($this->hasProgress) {
            $sink($this->progress);
        }

        return $this;
    }

    /**
     * 订阅**结算**（成功或失败），不派生新 Future，返回 `$this`。
     *
     * ★ 与 `then()` 的分工：`then()` 要变换值、要派生链、要接失败；`onSettle()`
     *   只是"结算那一刻看一眼"，用于观测（网络域记 settledAtFrame 与归因标记）。
     *   观察者抛错不影响结算 —— 观测不许反过来改被观测的语义。
     * ★ 在**已结算**的 Future 上挂 ⇒ 当前栈上补发一次（与 `onProgress()` 同款：
     *   订阅晚于结算是常态）。补发时 `$links` 已经跑完，所以"压标记给 then 读"
     *   只对挂得早的订阅成立 —— 网络域在 `submit()` 返回的当帧就挂，正是早的那种。
     *
     * @param callable(bool, mixed): void $onSettled 成功 ⇒ (true, 值)；失败 ⇒ (false, \Throwable)
     *
     * @api
     */
    public function onSettle(callable $onSettled): self
    {
        $this->settleSinks[] = \Closure::fromCallable($onSettled);
        if ($this->state === self::FULFILLED) {
            $this->notifySettled(true, $this->value);
        } elseif ($this->state === self::REJECTED) {
            $this->notifySettled(false, $this->error);
        }

        return $this;
    }

    /**
     * 通知所有结算观察者；先取局部副本（回调里可能再 onSettle，不改本次这轮）。
     *
     * ★ 每个观察者单独隔离：观测者出错只留诊断，绝不让"装了观测"把结算链打断
     *   （否则一次网络记录异常就让业务 `then()` 永不执行，比不观测还糟）。
     */
    private function notifySettled(bool $ok, mixed $payload): void
    {
        $sinks = $this->settleSinks;
        foreach ($sinks as $sink) {
            try {
                $sink($ok, $payload);
            } catch (\Throwable $e) {
                Diagnostics::degraded(
                    'future/settle-observer',
                    '结算观察者抛异常，已隔离；结算与下游链照常继续：' . $e->getMessage(),
                );
            }
        }
    }

    /**
     * 喂一个进度值。**只有后端该调它**（`ProcessTaskHost::deliver()`）。
     *
     * 丢弃：已结算 / 已取消 / 比当前小或相等。★ 最后一条要留 —— 乱序或重发的
     * 进度包在 IPC 上完全可能，进度条不能往回跳。
     *
     * @param float $fraction `0.0`~`1.0`（越界会被夹住，不抛 —— 这是**数据**不是参数）
     *
     * @internal
     */
    public function reportProgress(float $fraction): void
    {
        if ($this->state !== self::PENDING || $this->cancelled) {
            return;
        }

        $f = $fraction;
        if ($f < 0.0) {
            $f = 0.0;
        } elseif ($f > 1.0) {
            $f = 1.0;
        }

        if ($this->hasProgress && $f <= $this->progress) {
            return;
        }

        $this->progress = $f;
        $this->hasProgress = true;

        // ★ 先取一份局部副本再迭代：回调里可能再 `onProgress()` ⇒ 改数组
        $sinks = $this->progressSinks;
        foreach ($sinks as $sink) {
            $sink($f);
        }
    }

    /** 有没有收到过进度（`false` ⇒ 这个后端/这个任务不报进度） */
    public function hasProgress(): bool
    {
        return $this->hasProgress;
    }

    /**
     * 是不是 `then()` / `catch()` 派生出来的节点。
     *
     * ★ 进度只喂根任务，派生节点上的订阅永远收不到（`onProgress()` 会为此留一条
     *   诊断）。框架内部有"明知收不到就别挂"的场合（`AsyncCommand` 转发进度），
     *   所以把标记开出来 —— 没有它，框架自己就得靠"挂一个空订阅然后报 DX"来表达。
     */
    public function isDerived(): bool
    {
        return $this->derived;
    }

    /** 当前进度（没报过 ⇒ `$fallback`）。不返回 `?float`：调用点要的是一个能画的数 */
    public function progressOr(float $fallback = 0.0): float
    {
        return $this->hasProgress ? $this->progress : $fallback;
    }

    /**
     * 请求取消（**尽力而为**）：调后端钩子 + 标记"结果不要了"，此后迟到的
     * `resolve()` 被忽略。它**不会**让 Future 变成失败 —— "取消"不是错误。
     *
     * ★ 取消沿派生链上溯到根任务，代价是**树的剪枝**：同一个根被两处扇出，取消
     *   一处另一处也收不到结果 —— 要复用就复用**结果**，不要复用 Future。
     *   这条纪律在 2026-11-16 起有运行时兜底：被取消的节点若有 >1 条派生链，
     *   报 `future/fanout-cancel` 诊断，把静默剪枝变红（见下方 cancel 内注释）。
     * ★ `ThreadTaskHost` 只能"不交付"且**不减 `pending()` 记账**：结果行没回来就
     *   掉不到 0，早了会让帧时钟睡死。
     *
     * @api
     */
    public function cancel(): void
    {
        if ($this->state !== self::PENDING || $this->cancelled) {
            return;
        }

        // ★ 扇出取消的静默剪枝：一条根有 >1 条独立派生链时，取消会连另一条消费者
        //   一起剪掉，而那条链既不 cancelled 也不 resolve —— 界面永久停在 loading，
        //   且不报错（实验见 review 2026-11-16）。合法接线根恒为 1 条链：
        //   `then()->catch()` 串成一条，`all()/race()` 给每条入参也只挂一条链。
        //   ⇒ links > 1 是"一根多主"的可靠信号。只诊断不改语义：后端停活是刚需。
        if (\count($this->links) > 1) {
            Diagnostics::degraded(
                'future/fanout-cancel',
                '一条 Future 被多处 then()/catch() 后又 cancel() —— 取消会剪掉所有派生链，'
                . '另一处消费者将永远收不到结果且不报错。要复用就复用结果（resolved 值），不要复用 Future。'
            );
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
     * `all()` / `race()` 的共同前置：校验 + 收集 + 有没有真任务。
     *
     * ★ 键必须原样保留（`all()` 按入参键序返回）；`$anyTask` 只看入参本身。
     *
     * @return array{0: array<array-key, self>, 1: bool}
     */
    private static function collect(array $futures, string $method): array
    {
        $list = [];
        $anyTask = false;

        foreach ($futures as $key => $future) {
            if (!$future instanceof self) {
                throw new \InvalidArgumentException(
                    'Future::' . $method . '() 只接受 Future —— 下标 ' . (string) $key
                );
            }

            $list[$key] = $future;
            $anyTask = $anyTask || $future->task;
        }

        return [$list, $anyTask];
    }

    /** 把取消转给每一条入参 */
    private static function cancelAll(array $list): void
    {
        foreach ($list as $other) {
            $other->cancel();
        }
    }

    /**
     * 按**入参键序**重排结果。
     * ★ 键序是**契约**：按完成序排列会让 `$result[0]` 张冠李戴，且不报错。
     *
     * @return array<array-key, mixed>
     */
    private static function orderedByInput(array $list, array $got): array
    {
        $ordered = [];
        foreach ($list as $key => $ignored) {
            if (\array_key_exists($key, $got)) {
                $ordered[$key] = $got[$key];
            }
        }

        return $ordered;
    }

    /**
     * 全部成功才算成功，结果是**与入参同键同序**的数组；总耗时 ≈ 最慢的那条。
     *
     * ★ 任一条失败 ⇒ 整条失败，其余被 `cancel()`；空数组 ⇒ 立刻成功（`[]`）。
     * ★ 取消传播给入参，否则子任务继续跑、结果没人接，且不报错。
     * ★ 有一条真任务就是任务（见 `$task`），失败没人接要喊 Fatal，否则"组合"成了
     *   吞掉失败的暗渠。
     *
     * @api
     */
    public static function all(array $futures): self
    {
        [$list, $anyTask] = self::collect($futures, 'all');

        // ★ 引用捕获：钩子可能在 `$list` 建好之后才被调（`cancel()` 永远是之后的事）
        $all = new self(static function () use (&$list): void {
            self::cancelAll($list);
        });

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
                $all->resolve(self::orderedByInput($list, $got));
            })->catch(static function (\Throwable $error) use ($all, $list): void {
                // ★ 任一条失败 ⇒ 其余全部取消（不留无人认领的后台活）
                self::cancelAll($list);

                $all->reject($error);
            });
        }

        // ★★ 标记放在**接线之后**：入参若在 `all()` 之前就已失败，聚合体在接线时
        //   当场结算 —— 那一刻调用方还不可能挂过 `catch()`，当场喊 Fatal 是误报。
        //   "挂了 `then` 却没人接失败"仍然会喊（标记由链末端继承）。
        $all->task = $anyTask;

        return $all;
    }

    /**
     * 每一条**各自**成败，结果与入参同键同序，元素为
     * `['ok' => bool, 'value' => mixed, 'error' => ?string, 'code' => string]`；**永不失败**。
     *
     * ★ 没有 `task` 标记，子任务的 `catch()` 在这里接，所以连子任务也不会喊 Fatal。
     * ★ **`error` 是消息字符串**，不是 `\Throwable`：载荷会被 `json_encode`，异常
     *   对象进去只会变成 `{}`。`code` 同理由 `Failure::codeOf()` 归一成字符串，
     *   让"哪一条因为什么挂了"在组合链上也能机器判，不必回头匹配消息文字。
     * ★ 取消仍传播给全部入参，取消后聚合体永不结算。
     *
     * @api
     */
    public static function allSettled(array $futures): self
    {
        [$list] = self::collect($futures, 'allSettled');

        $settled = new self(static function () use (&$list): void {
            self::cancelAll($list);
        });

        if ($list === []) {
            $settled->resolve([]);

            return $settled;
        }

        $left = \count($list);
        $got = [];

        foreach ($list as $key => $future) {
            // ★ 两个分支的收尾逐字相同，抽成闭包：抄两遍时"少减一次 `$left`"会让
            //   聚合体永不结算，症状只是页面一直转圈。
            $take = static function (array $entry) use (&$got, &$left, $key, $settled, $list): void {
                $got[$key] = $entry;
                $left = $left - 1;

                if ($left !== 0) {
                    return;
                }

                $settled->resolve(self::orderedByInput($list, $got));
            };

            $future->then(static function (mixed $value) use ($take): void {
                $take(['ok' => true, 'value' => $value, 'error' => null, 'code' => '']);
            })->catch(static function (\Throwable $error) use ($take): void {
                // ★ 不取消兄弟、不冒泡：这一条失败就是这一条的事
                $take(['ok' => false, 'value' => null, 'error' => $error->getMessage(), 'code' => Failure::codeOf($error)]);
            });
        }

        return $settled;
    }

    /**
     * 第一个结算的赢，其余被 `cancel()`。
     *
     * ★ 空数组 ⇒ **永不结算**：静默不结算会被帧时钟暴露（`pending()` 一直 > 0），
     *   而立刻失败会假装"跑过了"。
     * ★ 取消传播给全部入参；任务标记同 `all()`。
     *
     * @api
     */
    public static function race(array $futures): self
    {
        [$list, $anyTask] = self::collect($futures, 'race');

        $winner = new self(static function () use (&$list): void {
            self::cancelAll($list);
        });

        foreach ($list as $future) {
            $future->then(static function (mixed $value) use ($winner, $list): void {
                if ($winner->isDone()) {
                    return;
                }

                self::cancelAll($list);

                $winner->resolve($value);
            })->catch(static function (\Throwable $error) use ($winner, $list): void {
                if ($winner->isDone()) {
                    return;
                }

                self::cancelAll($list);

                $winner->reject($error);
            });
        }

        // ★ 同 `all()`：标记在接线之后（构造期结算不喊，之后没人接才喊）
        $winner->task = $anyTask;

        return $winner;
    }

    /**
     * 结算为成功（**后端与 `all`/`race` 专用**）。
     *
     * ★ 只在 UI 线程的帧内调；被 `cancel()` 过 ⇒ 忽略（"结果不要了"）。
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

        // ★ 观察者在 links 之前：它压下的归因标记要能被同帧 then() 里的 setState 读到。
        $this->notifySettled(true, $value);

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
        $this->rejectCore($error);
    }

    /**
     * 内部结算为失败（`reject()` 与链上传播的唯一落点）。
     *
     * ★ 喊 Fatal 的判据只有两个：**`$links === []`（没人挂过任何回调）** 且
     *   **`$this->task`**。链式传播不会重复喊，因为中间节点必然有 `$links`。
     */
    private function rejectCore(\Throwable $error): void
    {
        if ($this->state !== self::PENDING || $this->cancelled) {
            return;
        }

        $this->state = self::REJECTED;
        $this->error = $error;

        $links = $this->links;
        $this->links = [];

        // ★★ 判"有没有人接"要看**整条链**：`$f->then($ok)->catch($err)` 里 `$f` 没有
        //   err 处理器，但错误会传到下游由 `catch` 接住 —— 只看直连会把正常链判成
        //   事故。⇒ 判据是"链的末端没人接"，由末端自己判，天然只喊一次。
        // ★★ 只有任务才喊：值在构造那一瞬 `$links` 必然为空。
        if ($links === [] && $this->task) {
            Errors::report(\sprintf(
                '异步任务失败且没有 catch() —— %s: %s',
                \get_class($error),
                $error->getMessage(),
            ));
        }

        // ★ 同 resolve()：观察者在 links 之前，失败也要记结算（否则失败的请求永远挂在途）。
        $this->notifySettled(false, $error);

        foreach ($links as $link) {
            $this->settleLink($link, false);
        }
    }

    /** 挂一条链（`then` / `catch` 的唯一落点） */
    private function chain(?\Closure $ok, ?\Closure $err): self
    {
        // ★ 取消要回到上游：链上每个节点都不是任务的持有者，真正的活在上游的根上。
        $parent = $this;
        $next = new self(static function () use ($parent): void {
            $parent->cancel();
        });
        // ★ 下游继承"是任务" —— 否则 `submit()->then()` 漏 `catch()` 的那条
        //   会在链末端静默（判据只看链末端有没有人接，见 `rejectCore()`）
        $next->task = $this->task;
        $next->derived = true;   // 进度只喂根；派生节点挂 onProgress() 要留诊断

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

            // ★ 决定权在链的末端：`rejectCore()` 只在 `links === []` 时喊。
            $next->rejectCore($arg instanceof \Throwable ? $arg : new \RuntimeException('未知失败'));

            return;
        }

        try {
            $result = $fn($arg);
        } catch (\Throwable $e) {
            // ★ 回调自己抛异常 = 调用方的 bug，与任务失败走同一条：下游有 `catch()`
            //   就交给它，没有就走 Fatal 序列 —— 不许静默吞。
            $next->rejectCore($e);

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
        // ★★ 摊平 ⇒ 取消的**目标要改指内层**：`then` 的回调返回另一条 Future 时，
        //   真正在跑的是它，而外层此刻已结算（钩子空转）。此刻 `$next` 还没交回给
        //   调用方，覆盖是安全的。
        $inner = $this;
        $next->onCancel = static function () use ($inner): void {
            $inner->cancel();
        };

        if ($this->state === self::PENDING) {
            $this->links[] = ['ok' => null, 'err' => null, 'next' => $next];

            return;
        }

        $this->settleLink(['ok' => null, 'err' => null, 'next' => $next], $this->state === self::FULFILLED);
    }
}
