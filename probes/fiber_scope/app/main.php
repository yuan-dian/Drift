<?php
declare(strict_types=1);

/**
 * 门 1 探针 —— **Fiber 在 tpc AOT(`-m bin`) 产物里到底能做什么**。
 *
 * 为什么需要这一枪：`tools/Lint/Rules/NanoHygiene.php` 把 `Fiber` 列为禁用类型，
 * 理由是「C++17 无栈切换，无法承载」；而 `probes/RESULTS.md` §12.2 的 `[I]` 又在
 * AOT 产物里记录了 `terminated=yes`。**两个结论直接冲突**，而线性异步
 * （`docs/DESIGN-LINEAR-ASYNC-2026-11-04.md`）整条路都压在 Fiber 上 ⇒ 不判清楚不能动 `src/`。
 *
 * ★ 探针 `[I]` 只验了「能 new / 能让出 / 能恢复」一轮。真正要落地 `AsyncScope`
 *   还需要下面九条边界，缺一条就可能让方案在真机上炸：
 *
 *   F1 跨帧多轮 suspend/resume（`[I]` 只有一轮）
 *   F2 Fiber 里操作 **tpc 编译出来的类**（不是内建类 —— 类表在协程里可见吗）
 *   F3 `throw()` 注入取消 ⇒ `finally` 必须跑（取消要能清理）
 *   F4 从**回调栈**里 `resume()`（真实形态：`Future::resolve()` / `drain()` 里恢复）
 *   F4b 同一个 Fiber 跨多趟回调持续让出（每一帧交付一次结果）
 *   F5 多个 Fiber 交替推进（多文件并发上传）
 *   F6 Fiber 内再开 Fiber（嵌套作用域）
 *   F7 深调用栈后再 suspend（VM 栈够不够、C++ 帧怎么退）
 *   F8 `suspend()` 传**对象**（传值边界：是不是同一身份）
 *   F9 Fiber 内 catch 注入的异常后**继续**让出（取消可被局部消化）
 *
 * ★ 打点同时写文件和 stdout：`no-console` 没开时 stdout 正常，但若将来加开，
 *   文件仍然留痕（`probes/RESULTS.md` §12 的教训 —— 段错误会吞掉未 flush 的 stdout）。
 */

namespace Probe\FiberScope {

    /**
     * F2 / F7 的被测类 —— 由 tpc 编译，不是解释执行的内建类。
     *
     * 验的是「Zend 类表在 Fiber 切换后仍然指向同一份编译期布局」。
     */
    final class Ledger
    {
        private int $sum = 0;

        public function add(int $by): int
        {
            $this->sum = $this->sum + $by;

            return $this->sum;
        }

        public function sum(): int
        {
            return $this->sum;
        }
    }

    /** F8 的载荷 —— 验对象能不能穿过 suspend 边界，且**是不是同一个身份**。 */
    final class Ticket
    {
        private string $id;

        private int $round;

        public function __construct(string $id, int $round)
        {
            $this->id = $id;
            $this->round = $round;
        }

        public function id(): string
        {
            return $this->id;
        }

        public function round(): int
        {
            return $this->round;
        }
    }
}

namespace {

    /** 汇总用（AOT 下没有全局变量表，用静态持有） */
    final class FsReport
    {
        public static int $pass = 0;

        public static int $fail = 0;

        public static string $log = '';
    }

    /**
     * F11–F13 的持有袋。
     *
     * ★ AOT 下没有全局变量表，闭包里改外部变量只能靠静态属性传（同 `FsReport`）。
     */
    final class FsScopeBox
    {
        public static $first = null;

        public static $second = null;

        public static string $trace = '';

        public static int $stopped = 0;

        public static int $cleaned = 0;
    }

    function fs_trace(string $msg): void
    {
        FsReport::$log = FsReport::$log . $msg . "\n";
        \file_put_contents(fs_logPath(), $msg . "\n", FILE_APPEND);
        echo $msg . "\n";
        \flush();
    }

    function fs_logPath(): string
    {
        $dir = __DIR__ . '/../../../_build';
        if (!\is_dir($dir)) {
            @\mkdir($dir, 0777, true);
        }

        return $dir . '/fiber-scope-probe.log';
    }

    function fs_ok(string $id, string $detail): void
    {
        FsReport::$pass = FsReport::$pass + 1;
        fs_trace('[PASS] ' . $id . ' — ' . $detail);
    }

    function fs_fail(string $id, string $detail): void
    {
        FsReport::$fail = FsReport::$fail + 1;
        fs_trace('[FAIL] ' . $id . ' — ' . $detail);
    }

    /** 深调用栈：F7 用 */
    function fs_deep(int $n): int
    {
        if ($n <= 0) {
            return 0;
        }

        return 1 + fs_deep($n - 1);
    }

    /**
     * 模拟「另一个调用栈把结果交回来」—— 真实形态是 `Application::frame()` 里
     * `TaskHost::drain($onEach)` 跑到 `Future::resolve()`，再进订阅回调。
     *
     * 这里包三层函数，确保 `resume()` 不是在 Fiber 自己的栈帧里调用。
     */
    function fs_deliver(callable $cb, mixed $value): void
    {
        fs_deliverInner($cb, $value, 3);
    }

    function fs_deliverInner(callable $cb, mixed $value, int $depth): void
    {
        if ($depth <= 0) {
            $cb($value);

            return;
        }

        fs_deliverInner($cb, $value, $depth - 1);
    }

    // ══════════════════ F1 跨帧多轮 ══════════════════

    function fs_f1(): void
    {
        $log = [];
        $fiber = new \Fiber(function () use (&$log): void {
            for ($i = 1; $i <= 5; $i++) {
                $got = \Fiber::suspend($i);
                $log[] = (string) $i . '<' . (string) $got;
            }
        });

        $fiber->start();
        $frames = 0;
        while (!$fiber->isTerminated()) {
            ++$frames;
            $fiber->resume('f' . (string) $frames);
        }

        $want = ['1<f1', '2<f2', '3<f3', '4<f4', '5<f5'];
        if ($frames === 5 && $log === $want && $fiber->isTerminated()) {
            fs_ok('F1', '5 轮跨帧 suspend/resume 正常，值往返一致');

            return;
        }

        fs_fail('F1', 'frames=' . (string) $frames . ' log=' . \implode(',', $log));
    }

    // ══════════════════ F2 tpc 编译类 ══════════════════

    function fs_f2(): void
    {
        $ledger = new \Probe\FiberScope\Ledger();
        $sums = [];
        $fiber = new \Fiber(function () use ($ledger, &$sums): void {
            for ($i = 1; $i <= 3; $i++) {
                $sums[] = $ledger->add(10);
                \Fiber::suspend();
            }
        });

        $fiber->start();
        while (!$fiber->isTerminated()) {
            $fiber->resume();
        }

        if ($sums === [10, 20, 30] && $ledger->sum() === 30) {
            fs_ok('F2', 'Fiber 内操作 tpc 编译类：累加 10/20/30 正确');

            return;
        }

        fs_fail('F2', 'sums=' . \implode(',', $sums) . ' sum=' . (string) $ledger->sum());
    }

    // ══════════════════ F3 throw + finally ══════════════════

    function fs_f3(): void
    {
        $cleaned = 0;
        $fiber = new \Fiber(function () use (&$cleaned): void {
            try {
                \Fiber::suspend('wait');
                $cleaned = -100;   // 不该走到这
            } finally {
                $cleaned = 7;
            }
        });

        $fiber->start();
        $escaped = '';
        try {
            $fiber->throw(new \RuntimeException('cancel'));
        } catch (\RuntimeException $e) {
            $escaped = $e->getMessage();
        }

        if ($cleaned === 7 && $fiber->isTerminated() && $escaped === 'cancel') {
            fs_ok('F3', 'throw() 注入取消：finally 执行、异常反弹给调用方、协程终结');

            return;
        }

        fs_fail(
            'F3',
            'cleaned=' . (string) $cleaned . ' terminated=' . ($fiber->isTerminated() ? 'yes' : 'no')
            . ' escaped=' . $escaped
        );
    }

    // ══════════════════ F4 回调栈里 resume ══════════════════

    function fs_f4(): void
    {
        $answer = '';
        $fiber = new \Fiber(function () use (&$answer): void {
            $v = \Fiber::suspend();
            $answer = 'got:' . (string) $v;
        });

        $fiber->start();
        fs_deliver(function (mixed $v) use ($fiber): void {
            if ($fiber->isSuspended()) {
                $fiber->resume($v);
            }
        }, 'io-ok');

        if ($answer === 'got:io-ok') {
            fs_ok('F4', '从回调栈（包三层函数）里 resume 成功，控制权回得去');

            return;
        }

        fs_fail('F4', 'answer=' . $answer);
    }

    // ══════════════════ F4b 跨多趟回调持续让出 ══════════════════

    function fs_f4b(): void
    {
        $rounds = 0;
        $fiber = new \Fiber(function () use (&$rounds): void {
            while (true) {
                $r = \Fiber::suspend();
                if ($r === 'done') {
                    return;
                }
                $rounds = $rounds + 1;
            }
        });

        $fiber->start();
        for ($i = 0; $i < 3; $i++) {
            fs_deliver(function (mixed $v) use ($fiber): void {
                if ($fiber->isSuspended()) {
                    $fiber->resume($v);
                }
            }, 'tick');
        }
        fs_deliver(function (mixed $v) use ($fiber): void {
            if ($fiber->isSuspended()) {
                $fiber->resume($v);
            }
        }, 'done');

        if ($rounds === 3 && $fiber->isTerminated()) {
            fs_ok('F4b', '4 趟回调把一个长任务推到底（3 趟 tick + 1 趟 done）');

            return;
        }

        fs_fail('F4b', 'rounds=' . (string) $rounds . ' terminated=' . ($fiber->isTerminated() ? 'yes' : 'no'));
    }

    // ══════════════════ F5 多 Fiber 交替 ══════════════════

    function fs_f5(): void
    {
        $a = new \Fiber(function (): void {
            for ($i = 1; $i <= 3; $i++) {
                \Fiber::suspend('a' . (string) $i);
            }
        });
        $b = new \Fiber(function (): void {
            for ($i = 1; $i <= 3; $i++) {
                \Fiber::suspend('b' . (string) $i);
            }
        });

        $order = [];
        $va = $a->start();
        if ($va !== null) {
            $order[] = (string) $va;
        }
        $vb = $b->start();
        if ($vb !== null) {
            $order[] = (string) $vb;
        }

        while (!$a->isTerminated() || !$b->isTerminated()) {
            if (!$a->isTerminated()) {
                $v = $a->resume();
                if (!$a->isTerminated()) {
                    $order[] = (string) $v;
                }
            }
            if (!$b->isTerminated()) {
                $v = $b->resume();
                if (!$b->isTerminated()) {
                    $order[] = (string) $v;
                }
            }
        }

        $want = ['a1', 'b1', 'a2', 'b2', 'a3', 'b3'];
        if ($order === $want) {
            fs_ok('F5', '两条 Fiber 交替推进，顺序 ' . \implode(',', $order));

            return;
        }

        fs_fail('F5', 'order=' . \implode(',', $order));
    }

    // ══════════════════ F6 Fiber 内嵌套 ══════════════════

    function fs_f6(): void
    {
        $trace = [];
        $outer = new \Fiber(function () use (&$trace): void {
            $inner = new \Fiber(function () use (&$trace): void {
                \Fiber::suspend('inner-1');
                $trace[] = 'inner-resumed';
            });

            $v = $inner->start();
            $trace[] = 'inner-start:' . (string) $v;

            \Fiber::suspend('outer-1');

            $inner->resume();
            $trace[] = 'inner-done:' . ($inner->isTerminated() ? 'yes' : 'no');
        });

        $v1 = $outer->start();
        $trace[] = 'outer-start:' . (string) $v1;
        // 外层在内层 suspend 之后又 suspend 了一次 ⇒ 这里 resume 会让它继续到终结
        $outer->resume();

        $want = ['inner-start:inner-1', 'outer-start:outer-1', 'inner-resumed', 'inner-done:yes'];
        if ($trace === $want) {
            fs_ok('F6', '嵌套 Fiber：内层可独立让出，外层恢复后能推完内层');

            return;
        }

        fs_fail('F6', 'trace=' . \implode(' | ', $trace));
    }

    // ══════════════════ F7 深栈后 suspend ══════════════════

    function fs_f7(): void
    {
        $depth = 0;
        $fiber = new \Fiber(function () use (&$depth): void {
            $d = fs_deep(60);
            \Fiber::suspend($d);
            $depth = $d + fs_deep(60);
        });

        $first = $fiber->start();
        $fiber->resume();

        if ($first === 60 && $depth === 120 && $fiber->isTerminated()) {
            fs_ok('F7', '递归 60 层后 suspend 再恢复，栈完好');

            return;
        }

        fs_fail('F7', 'first=' . (string) $first . ' depth=' . (string) $depth);
    }

    // ══════════════════ F8 suspend 传对象 ══════════════════

    function fs_f8(): void
    {
        $received = null;
        $fiber = new \Fiber(function () use (&$received): void {
            $received = \Fiber::suspend(new \Probe\FiberScope\Ticket('t-1', 3));
        });

        $sent = $fiber->start();
        $fiber->resume(new \Probe\FiberScope\Ticket('t-0', 1));

        $okOut = $sent instanceof \Probe\FiberScope\Ticket && $sent->id() === 't-1' && $sent->round() === 3;
        $okIn = $received instanceof \Probe\FiberScope\Ticket && $received->id() === 't-0' && $received->round() === 1;

        if ($okOut && $okIn) {
            fs_ok('F8', 'suspend/resume 双向传对象，字段完整');

            return;
        }

        fs_fail('F8', 'out=' . ($okOut ? 'ok' : 'bad') . ' in=' . ($okIn ? 'ok' : 'bad'));
    }

    // ══════════════════ F9 捕获后继续让出 ══════════════════

    function fs_f9(): void
    {
        $recovered = '';
        $fiber = new \Fiber(function () use (&$recovered): void {
            try {
                \Fiber::suspend('will-throw');
            } catch (\RuntimeException $e) {
                $recovered = 'caught:' . $e->getMessage();
                \Fiber::suspend();
            }
            $recovered = $recovered . ':after';
        });

        $fiber->start();
        $fiber->throw(new \RuntimeException('boom'));
        if ($fiber->isSuspended()) {
            $fiber->resume();
        }

        if ($recovered === 'caught:boom:after' && $fiber->isTerminated()) {
            fs_ok('F9', '异常被局部消化后仍可继续让出（取消可被业务接管）');

            return;
        }

        fs_fail('F9', 'recovered=' . $recovered . ' terminated=' . ($fiber->isTerminated() ? 'yes' : 'no'));
    }

    // ══════════════════ F10 getCurrent 身份判定 ══════════════════

    /**
     * `AsyncScope::await()` 要靠 `Fiber::getCurrent() === $this->fiber` 挡掉
     * 「在协程外调 await」这种必然挂死的误用。探针 `[I]` 从没验过这个 API，
     * 而它在 AOT 下要是返回 null 或别的实例，护栏就成了空壳。
     */
    function fs_f10(): void
    {
        $outside = \Fiber::getCurrent();
        $inside = null;

        $probe = new \Fiber(function () use (&$inside): void {
            $inside = \Fiber::getCurrent();
            \Fiber::suspend();
        });

        $probe->start();
        // 协程挂起期间，主栈取到的必须还是 null（身份是"当前栈"，不是"最近建的那条"）
        $mainCurrent = \Fiber::getCurrent();
        $probe->resume();

        if ($outside === null && $inside !== null && $mainCurrent === null) {
            fs_ok('F10', 'Fiber::getCurrent() 可用：协程内返回自身、主栈返回 null');

            return;
        }

        fs_fail(
            'F10',
            'outside=' . ($outside === null ? 'null' : \get_class($outside))
            . ' inside=' . ($inside === null ? 'null' : 'obj')
            . ' mainAfter=' . ($mainCurrent === null ? 'null' : \get_class($mainCurrent))
        );
    }

    // ══════════════════ F11–F13 真实 AsyncScope ══════════════════

    /**
     * F11 —— 跑**要上线的** `Drift\Async\AsyncScope`，不是另写一份样例。
     *
     * 验：三步流水线在 AOT 里跨栈推进、中途值不漏、终值正确。
     */
    function fs_f11(): void
    {
        FsScopeBox::$trace = '';
        FsScopeBox::$first = null;
        FsScopeBox::$second = null;

        $outer = \Drift\Async\AsyncScope::task(
            function (\Drift\Async\AsyncScope $co): string {
                $f1 = \Drift\Async\Future::pending();
                FsScopeBox::$first = $f1;
                $r1 = (string) $co->await($f1);
                FsScopeBox::$trace = FsScopeBox::$trace . 'a1,';

                $f2 = \Drift\Async\Future::pending();
                FsScopeBox::$second = $f2;
                $r2 = (string) $co->await($f2);
                FsScopeBox::$trace = FsScopeBox::$trace . 'a2,';

                return $r1 . '/' . $r2;
            }
        );

        $okStart = !$outer->isDone() && FsScopeBox::$trace === '';

        $f1 = FsScopeBox::$first;
        if ($f1 !== null) {
            $f1->resolve('A');
        }
        $okStep = !$outer->isDone() && FsScopeBox::$trace === 'a1,';

        $f2 = FsScopeBox::$second;
        if ($f2 !== null) {
            $f2->resolve('B');
        }

        $got = null;
        $outer->then(function (mixed $v) use (&$got): void {
            $got = $v;
        });

        if ($okStart && $okStep && $outer->isDone() && $got === 'A/B') {
            fs_ok('F11', '真实 AsyncScope 三步流水线在 AOT 里跨栈推进，终值 A/B');

            return;
        }

        fs_fail(
            'F11',
            'start=' . ($okStart ? 'ok' : 'bad') . ' step=' . ($okStep ? 'ok' : 'bad')
            . ' done=' . ($outer->isDone() ? 'yes' : 'no') . ' got=' . (string) $got
            . ' trace=' . FsScopeBox::$trace
        );
    }

    /** F12 —— 取消：打到在途 Future、`finally` 执行、协程终结 */
    function fs_f12(): void
    {
        FsScopeBox::$stopped = 0;
        FsScopeBox::$cleaned = 0;
        FsScopeBox::$second = null;

        $outer = \Drift\Async\AsyncScope::task(
            function (\Drift\Async\AsyncScope $co): string {
                try {
                    $f = \Drift\Async\Future::pending(function (): void {
                        FsScopeBox::$stopped = FsScopeBox::$stopped + 1;
                    });
                    FsScopeBox::$second = $f;
                    $co->await($f);

                    return 'never';
                } finally {
                    FsScopeBox::$cleaned = FsScopeBox::$cleaned + 1;
                }
            }
        );

        $outer->cancel();

        if (FsScopeBox::$stopped === 1 && FsScopeBox::$cleaned === 1 && $outer->isCancelled()) {
            fs_ok('F12', '取消在 AOT 里打到在途 Future，finally 执行，协程终结（不泄漏栈）');

            return;
        }

        fs_fail(
            'F12',
            'stopped=' . (string) FsScopeBox::$stopped
            . ' cleaned=' . (string) FsScopeBox::$cleaned
            . ' cancelled=' . ($outer->isCancelled() ? 'yes' : 'no')
        );
    }

    /** F13 —— 异常原样穿过协程边界（类型与消息都不变） */
    function fs_f13(): void
    {
        FsScopeBox::$second = null;

        $outer = \Drift\Async\AsyncScope::task(
            function (\Drift\Async\AsyncScope $co): string {
                $f = \Drift\Async\Future::pending();
                FsScopeBox::$second = $f;

                return (string) $co->await($f);
            }
        );

        $caught = '';
        $outer->catch(function (\Throwable $e) use (&$caught): void {
            $caught = \get_class($e) . ':' . $e->getMessage();
        });

        $f = FsScopeBox::$second;
        if ($f !== null) {
            $f->reject(new \DomainException('第二步失败'));
        }

        $hit = \strpos($caught, 'DomainException') !== false && \strpos($caught, '第二步失败') !== false;
        if ($hit) {
            fs_ok('F13', '异常原样穿过 AOT 协程边界：' . $caught);

            return;
        }

        fs_fail('F13', 'caught=' . $caught);
    }

    function main(): void
    {
        @\unlink(fs_logPath());
        fs_trace('=== 门 1 探针：Fiber on tpc AOT (-m bin) ===');
        fs_trace('php=' . \PHP_VERSION . ' fiber=' . (\class_exists('Fiber') ? 'yes' : 'no'));

        fs_f1();
        fs_f2();
        fs_f3();
        fs_f4();
        fs_f4b();
        fs_f5();
        fs_f6();
        fs_f7();
        fs_f8();
        fs_f9();
        fs_f10();
        fs_f11();
        fs_f12();
        fs_f13();

        fs_trace(
            '=== 汇总：PASS ' . (string) FsReport::$pass
            . ' / FAIL ' . (string) FsReport::$fail . ' ==='
        );

        if (FsReport::$fail > 0) {
            \exit(1);
        }
    }
}
