<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\App\Job;
use Drift\App\TaskHost;
use Drift\Async\Future;
use Drift\Errors;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Widget\Bind;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Drift\Widget\Text;
use Drift\Widget\Widget;

use App\Users\Store\MemoryUserSource;
use App\Users\Store\UserStore;

/**
 * **跨页共享**的那一半：`AsyncCommand` 住在 `Store` 里（不是页面 `State` 里）。
 *
 * ## 这一组为什么单独存在
 *
 * 上一轮命令的构造收 `State` ⇒ 它只能服务某一个页面 ⇒ `apps/users` 的
 * `UserStore` 把同一套状态机**手写第二遍**（`LoadStatus` + 三个字段 +
 * `$inflight` + `applyPage()`）。本轮命令改成 `Subject` 之后那条手写版被删掉，
 * 于是"共享路径"必须**自己有判据** —— 否则它只是在冒烟里被顺带跑到
 * （而冒烟里那几条还依赖 mock 端口能不能起来）。
 *
 * | # | 判据 | 漏了的表现 |
 * |---|---|---|
 * | 1 | 同步路径（无后台端口）当场 `data`，行**真的落地** | 界面永远空表 |
 * | 2 | 后台路径 `loading` → `data`，行**真的落地** | 同上（`hydrate` 那一步没人调） |
 * | 3 | 迟到的旧响应不覆盖新数据 | 列表显示上一页，**不报错** |
 * | 4 | 失败 ⇒ `error`，但**不清空**上一次的行 | 一次网络抖动 = 列表空白 |
 * | 5 | 在 `Bind` 里读命令 = 订阅命令（idle → loading → data 三帧都对） | 数据到了界面不动（白屏） |
 *
 * ★ 2 是这一组最值钱的一条：它是"命令住进 Store"之后**唯一**能把"原始数组 →
 *   `User`"这一步钉住的断言（`UserStore::absorb()`）。变异自检过：把那里的
 *   `hasData()` 改成 `false`，只有这条会红。
 */
final class SharedCommandTest extends SmokeTestCase
{
    /**
     * 手动结算的后端：提交的 `Future` 全部留在 `futures` 里，由测试决定**什么时候**、
     * **按什么顺序**结算（真实世界里的乱序就是这样造出来的）。
     */
    private static function manualHost(): TaskHost
    {
        return new class implements TaskHost {
            /** @var list<Future> 按提交顺序 */
            public array $futures = [];

            public function submit(Job $job): Future
            {
                $future = Future::pending();
                $this->futures[] = $future;

                return $future;
            }

            public function cancelSupport(): \Drift\App\CancelSupport
            {
                // 假后端同步交付（Future 要么已结算、要么由测试手动结算）⇒ 没有在跑的活
                return \Drift\App\CancelSupport::AlreadyDone;
            }

            public function pending(): int
            {
                return \count($this->futures);
            }

            public function needsPolling(): bool
            {
                return true; // 没有第二线程 ⇒ 只能靠 drain 拉（这里不拉，手动结算）
            }

            public function drain(callable $onEach, ?int $budgetMs = null): void
            {
            }
        };
    }

    public function testSyncPathLandsRowsImmediately(): void
    {
        $store = new UserStore(new MemoryUserSource());
        $store->load(1);

        // ★ 同步路径：任务闭包当场返回已结算的 Future ⇒ 没有 loading 这一帧
        check(
            '共享命令 · 同步路径（无后台端口）当场 data',
            $store->page()->value()->hasData(),
            'state=' . $store->page()->value()->stateName(),
        );

        check(
            '共享命令 · 行**真的落地**了（`User` 对象，15 行）',
            \count($store->rows()) === 15 && $store->rows()[0]->name === '张伟',
            'rows=' . \count($store->rows()),
        );

        $this->flushChecks();
    }

    public function testAsyncPathLandsRowsWhenSettled(): void
    {
        $host = self::manualHost();
        $store = new UserStore(new MemoryUserSource(), $host);

        $store->load(1);
        check(
            '共享命令 · 后台路径先 loading',
            $store->page()->value()->isLoading() && $store->page()->isRunning(),
            'state=' . $store->page()->value()->stateName(),
        );

        $host->futures[0]->resolve([['id' => 7, 'name' => '孙丽', 'email' => 'sunli@example.com']]);

        check(
            '共享命令 · 结算后是 data',
            $store->page()->value()->hasData(),
            'state=' . $store->page()->value()->stateName(),
        );

        // ★★ 这一条钉住 `UserStore::absorb()`：命令的 data 必须被转成行。
        //    改坏了的表现是"四态看着都对，列表却是空的"。
        check(
            '共享命令 · 后台回来的原始数组被 hydrate 成行（**真的落地**）',
            \count($store->rows()) === 1 && $store->rows()[0]->name === '孙丽' && $store->rows()[0]->id === 7,
            'rows=' . \count($store->rows()),
        );

        $this->flushChecks();
    }

    /**
     * 连发两次、旧的**迟到**（真实世界的顺序：第 1 页有缓存慢、第 2 页反而快）。
     *
     * ★ 这是"陈旧响应"这条经典静默故障的判据：界面一直正常，只是数据是上一页的。
     */
    public function testStaleResponseDoesNotOverwriteRows(): void
    {
        $host = self::manualHost();
        $store = new UserStore(new MemoryUserSource(), $host);

        $store->load(1);
        $store->load(2);

        check('共享命令 · 换请求时旧的那条被真的取消', $host->futures[0]->isCancelled());

        $host->futures[1]->resolve([['id' => 2, 'name' => '第二页', 'email' => 'b@example.com']]);
        $host->futures[0]->resolve([['id' => 1, 'name' => '第一页', 'email' => 'a@example.com']]); // 迟到

        check(
            '共享命令 · 迟到的旧响应不覆盖新数据',
            \count($store->rows()) === 1 && $store->rows()[0]->name === '第二页',
            'rows=' . \count($store->rows()),
        );

        $this->flushChecks();
    }

    public function testFailureKeepsPreviousRows(): void
    {
        $host = self::manualHost();
        $store = new UserStore(new MemoryUserSource(), $host);

        $store->load(1);
        $host->futures[0]->resolve([['id' => 3, 'name' => '王强', 'email' => 'w@example.com']]);

        $store->load(1);                     // 换一次请求（去重不挡：上一次已结算）
        $host->futures[1]->reject(new \RuntimeException('HTTP 500'));

        check(
            '共享命令 · 失败 ⇒ error，消息可画',
            $store->page()->value()->hasError() && $store->page()->value()->message() === 'HTTP 500',
            'state=' . $store->page()->value()->stateName(),
        );

        // ★ 失败不该把列表清空：用户看到的应该是"加载失败"，而不是"没有数据"
        check(
            '共享命令 · 失败不清空上一次的行（保留「王强」）',
            \count($store->rows()) === 1 && $store->rows()[0]->name === '王强',
            'rows=' . \count($store->rows()),
        );

        // ★ G2：命令必须自己挂 catch，否则 `Future` 走 Fatal 序列
        $diagnostics = Errors::recentDiagnostics();
        check(
            '共享命令 · 失败不产生诊断（没走 Fatal）',
            $diagnostics === [],
            \implode(' | ', $diagnostics),
        );

        $this->flushChecks();
    }

    /**
     * 读点即订阅：`Bind` 里读 `page()->value()` 就订上了命令（2026-11-22）。
     *
     * ★ 这条取代旧的「命令换值 ⇒ Store 广播」：以前页面只订 Store 一个，Store 在
     *   `absorb()` 里 `notifyChanged()` 转发一次。命令实现 `Readable` 之后那层
     *   中转没有存在理由 —— 而且它有个静默代价：**每个 Store 都要手写一遍**，
     *   漏写就是"值变了界面不动"。
     * ★ 判据必须跨帧（idle → loading → data）：只在结算后断言一次，分不开
     *   "读到的是浇筑那一刻的快照"与"订阅是活的"。
     */
    public function testCommandReadIsSubscription(): void
    {
        $host = self::manualHost();
        $store = new UserStore(new MemoryUserSource(), $host);
        $ctx = smokeContext();

        $element = Bind::make(
            static fn(): Widget => Text::make($store->page()->value()->stateName()),
        )->createElement();
        $element->mount(null, $ctx);
        self::settle($element);

        check(
            '读即订阅 · 首帧 idle',
            self::painted($element) === 'idle',
            'painted=' . self::painted($element),
        );

        $store->load(1);
        self::settle($element);
        check(
            '读即订阅 · 发起 ⇒ loading（读点真的订上了）',
            self::painted($element) === 'loading',
            'painted=' . self::painted($element),
        );

        $host->futures[0]->resolve([['id' => 1, 'name' => '张伟', 'email' => 'z@example.com']]);
        self::settle($element);
        check(
            '读即订阅 · 结算 ⇒ data',
            self::painted($element) === 'data',
            'painted=' . self::painted($element),
        );

        $this->flushChecks();
    }

    /**
     * 重建所有标脏的 `StatefulElement`（`Bind` 的 State 就在其中）。
     *
     * ★ 不递归：本用例的脏元素都是平行的一层，不存在父子同时脏。
     */
    private static function settle(Element $root): void
    {
        for ($i = 0; $i < 5; $i++) {
            $dirty = [];
            $walk = null;
            $walk = static function (Element $e) use (&$walk, &$dirty): void {
                if ($e instanceof StatefulElement && $e->state()?->needsBuild() === true) {
                    $dirty[] = $e;
                }
                $e->visitChildren($walk);
            };
            $walk($root);

            if ($dirty === []) {
                return;
            }

            foreach ($dirty as $element) {
                $element->rebuild();
            }
        }
    }

    /** 树上第一个 `Text` 的内容（本用例的整棵树就一个 `Text`） */
    private static function painted(Element $root): string
    {
        $found = $root->findElement(static fn (Element $e): bool => $e->widget() instanceof Text);
        $widget = $found?->widget();

        return $widget instanceof Text ? $widget->content() : '';
    }
}
