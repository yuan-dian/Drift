<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\App\Application;
use Drift\App\Job;
use Drift\App\TaskHost;
use Drift\Async\Future;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Text;

use App\Users\UsersApplication;
use App\Users\Store\MemoryUserSource;

/**
 * 「刷新」按钮的**可用态**—— 页面读 Store 的每一处都必须在订阅里读。
 *
 * ## 这条钉的是一个真机 bug（2026-10-31）
 *
 * `UsersPage` 是 `StatelessWidget`：`build()` 只在这棵子树被造出来时跑一次。原先
 * 按钮写成 `Button::primary('刷新')->enabled(!$this->store->page()->isRunning())`
 * —— 取的是**浇筑那一刻**的值。而本应用"进页面就取数"，那一瞬 `isRunning()` 必然
 * 是 `true` ⇒ 按钮**永久灰掉**，数据回来了也不恢复（真机探针：
 * `isRunning=false` 而 `enabled=false`）。
 *
 * 修法是把那段包进 `SubjectBuilder`（切片 = `isRunning()`）。判据因此必须**跨帧**：
 *  ① 首屏（在途）⇒ 灰 + 「刷新中…」；
 *  ② 结算后 ⇒ **亮**（这一条在修复前是红的，且永远不会自己变绿）；
 *  ③ 再发起 ⇒ 立刻又灰。
 *
 * ## 顺带钉住"刷新时列表不消失"
 *
 * 同一帧里断言用户名还在 —— 那是 `AsyncValue` 两维模型（载荷跨状态存活）在**页面
 * 层**的判据：只测 Store 的话，"值还在但页面被整块换成转圈"照样全绿。
 */
final class UsersRefreshButtonTest extends SmokeTestCase
{
    private const W = 900.0;

    private const H = 640.0;

    /**
     * 手动结算的后端：测试完全掌控"什么时候回来"。
     *
     * ★ 不用 `SyncTaskHost`：它当场结算，永远看不到"在途"这一帧（本组判据全在
     *   在途态与结算态的**对比**上）。
     */
    private static function manualHost(): TaskHost
    {
        return new class implements TaskHost {
            /** @var list<Future> */
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
                return true;
            }

            public function drain(callable $onEach, ?int $budgetMs = null): void
            {
            }
        };
    }

    /** @return array{0: UsersApplication, 1: object, 2: BoxConstraints} */
    private static function boot(): array
    {
        $host = self::manualHost();

        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        // ★ 构造器里就 `load()` ⇒ 首帧必然在途（这就是 bug 的触发条件）
        $app = new UsersApplication(new MemoryUserSource(), $host, 'http://127.0.0.1:1');
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        return [$app, $host, $constraints];
    }

    /** 树上那个「刷新」按钮（找不到 ⇒ null） */
    private static function refreshButton(Application $app): ?Button
    {
        $root = $app->root();
        if ($root === null) {
            return null;
        }

        $element = findElement($root, static function (Element $e): bool {
            $w = $e->widget();

            return $w instanceof Button && $w->label() === '刷新';
        });

        if ($element === null) {
            return null;
        }

        $widget = $element->widget();

        return $widget instanceof Button ? $widget : null;
    }

    /** 树上有没有这句话（按 `Text` 的内容精确比） */
    private static function paints(Application $app, string $content): bool
    {
        $root = $app->root();
        if ($root === null) {
            return false;
        }

        return findElement($root, static function (Element $e) use ($content): bool {
            $w = $e->widget();

            return $w instanceof Text && $w->content() === $content;
        }) !== null;
    }

    public function testRefreshButtonFollowsCommandState(): void
    {
        [$app, $host, $constraints] = self::boot();

        $button = self::refreshButton($app);
        check(
            '刷新按钮 · 首屏在途 ⇒ 禁用（点了也没用，画成灰的）',
            $button !== null && !$button->isEnabled(),
            $button === null ? '树上没有「刷新」按钮' : 'enabled=' . ($button->isEnabled() ? '1' : '0'),
        );

        check(
            '刷新按钮 · 在途时有「刷新中…」提示（列表不消失，得有人说一句）',
            self::paints($app, '刷新中…'),
        );

        // ── 结算：按钮必须自己亮回来（修复前这条永远红） ──
        $host->futures[0]->resolve([
            ['id' => 1, 'name' => '张伟', 'email' => 'a@example.com'],
        ]);
        $app->frame(32.0, $constraints);

        $settled = self::refreshButton($app);
        check(
            '刷新按钮 · 数据到了 ⇒ 自动恢复可点（不是浇筑时的快照）',
            $settled !== null && $settled->isEnabled(),
            $settled === null ? '树上没有「刷新」按钮' : 'enabled=' . ($settled->isEnabled() ? '1' : '0'),
        );

        check(
            '刷新按钮 · 结算后「刷新中…」消失',
            !self::paints($app, '刷新中…'),
        );

        check(
            '刷新按钮 · 列表画出来了（行数据经 worker 落到了界面）',
            self::paints($app, '张伟'),
        );

        // ── 再发起一次：立刻又灰（订阅是双向的，不是"亮一次就完了"） ──
        $app->userStore()->load(2);
        $app->frame(48.0, $constraints);

        $running = self::refreshButton($app);
        check(
            '刷新按钮 · 再次刷新 ⇒ 立刻禁用',
            $running !== null && !$running->isEnabled(),
            $running === null ? '树上没有「刷新」按钮' : 'enabled=' . ($running->isEnabled() ? '1' : '0'),
        );

        // ★ 关键：刷新中列表**还在**（旧值跨状态存活），只是上面多了「刷新中…」
        check(
            '刷新按钮 · 刷新中旧行仍在（列表不闪空）',
            self::paints($app, '张伟') && self::paints($app, '刷新中…'),
        );

        $this->flushChecks();
    }
}
