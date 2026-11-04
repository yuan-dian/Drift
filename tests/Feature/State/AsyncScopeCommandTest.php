<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\Async\AsyncScope;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\Dev\Smoke\App\AsyncCommandApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * `AsyncScope` 装进**一个** `AsyncCommand` 后的真实帧循环判据（2026-11-04）。
 *
 * ## 与 `MultiStepCommandTest` 的关系
 *
 * 那个文件钉的是「`$task` 内部 `then()` 组合」的四条契约。本文件钉的是**同一组
 * 契约在 `AsyncScope` 下仍然成立** —— 这是它敢替代手写状态机的前提：
 *
 * | # | 契约 | 为什么必须保住 |
 * |---|---|---|
 * | ① | 界面只见**一次** `loading → data`，中间值不漏 | 5 步流水线不能闪 5 次 |
 * | ② | 某步失败 ⇒ 整条 `error`，不是"半个页面" | 半成品状态比失败更难查 |
 * | ③ | 取消打到**正在跑的那一步** | 否则后端继续跑，用户以为停了 |
 * | ④ | 仍然只是**一条** `Future` 交给**一个**命令 | 去重 / 重试 / 卸载兜底照旧 |
 *
 * ★ 额外钉一条 `AsyncScope` 独有的：**每步之间能改外部状态**（中途上报进度）。
 *   `then()` 链做不到这点 —— 闭包里改字段不会触发重建（还在 loading），
 *   而 `await` 之间是普通语句，想改就改。
 */
final class AsyncScopeCommandTest extends SmokeTestCase
{
    private const W = 400.0;

    private const H = 300.0;

    /**
     * @param \Closure(mixed): Future $task
     *
     * @return array{0: AsyncCommandApplication, 1: BoxConstraints}
     */
    private static function boot(\Closure $task): array
    {
        $surface = new SurfaceProbe();
        $surface->resize((int) self::W, (int) self::H, 1.0);
        $constraints = BoxConstraints::tight(new Size(self::W, self::H));

        $app = new AsyncCommandApplication($task);
        $app->attach($surface, $constraints);
        $app->frame(16.0, $constraints);

        return [$app, $constraints];
    }

    private static function last(AsyncCommandApplication $app): ?AsyncValue
    {
        $snapshots = $app->snapshots;

        return $snapshots === [] ? null : $snapshots[\count($snapshots) - 1];
    }

    /** snapshots 里出现过几次 `data` */
    private static function dataCount(AsyncCommandApplication $app): int
    {
        $n = 0;
        foreach ($app->snapshots as $v) {
            if ($v->hasData()) {
                $n++;
            }
        }

        return $n;
    }

    /** 三步流水线：界面只见一次 data，中间每步都能改外部状态 */
    public function testThreeStepsProduceOneDataAndCanReportBetweenSteps(): void
    {
        $box = ['f' => [], 'trace' => []];

        [$app, $constraints] = self::boot(
            static function (mixed $arg) use (&$box): Future {
                return AsyncScope::task(
                    static function (AsyncScope $co) use (&$box, $arg): string {
                        $box['trace'][] = 'start';

                        $f1 = Future::pending();
                        $box['f'][1] = $f1;
                        $r1 = (string) $co->await($f1);
                        $box['trace'][] = 'after-1';

                        $f2 = Future::pending();
                        $box['f'][2] = $f2;
                        $r2 = (string) $co->await($f2);
                        $box['trace'][] = 'after-2';

                        return $arg . ':' . $r1 . '/' . $r2;
                    },
                );
            },
        );

        $command = $app->pageState()->command();
        $command->run('job');
        $app->frame(32.0, $constraints);

        check(
            '三步 · 起手停在第一步（trace 只有 start）',
            $box['trace'] === ['start'] && self::last($app)->isLoading(),
            'trace=' . \implode(',', $box['trace']) . ' state=' . self::last($app)->stateName(),
        );

        $box['f'][1]->resolve('A');
        $app->frame(48.0, $constraints);

        check(
            '三步 · 第一步结算后只推进到第二步（trace 到 after-1）',
            $box['trace'] === ['start', 'after-1'] && self::last($app)->isLoading(),
            'trace=' . \implode(',', $box['trace']) . ' state=' . self::last($app)->stateName(),
        );
        check(
            '三步 · 中途**没有** data 漏给界面（仍在 loading）',
            self::dataCount($app) === 0,
            'dataCount=' . (string) self::dataCount($app),
        );

        $box['f'][2]->resolve('B');
        $app->frame(64.0, $constraints);

        check(
            '三步 · 全部结算后一次 data，值是最后一步的返回值',
            self::last($app)->hasData() && self::last($app)->value() === 'job:A/B' && !$command->isRunning(),
            'state=' . self::last($app)->stateName() . ' value=' . \var_export(self::last($app)->value(), true),
        );
        check(
            '三步 · 全程只出现过一次 data（中间值没漏）',
            self::dataCount($app) === 1,
            'dataCount=' . (string) self::dataCount($app),
        );

        $this->flushChecks();
    }

    /** ② 中间某步失败 ⇒ 整条 error，不留"半个页面" */
    public function testMiddleStepFailureBecomesWholeError(): void
    {
        Errors::reset();
        $box = ['f' => []];

        [$app, $constraints] = self::boot(
            static function (mixed $arg) use (&$box): Future {
                return AsyncScope::task(static function (AsyncScope $co) use (&$box): string {
                    $f1 = Future::pending();
                    $box['f'][1] = $f1;
                    $co->await($f1);

                    $f2 = Future::pending();
                    $box['f'][2] = $f2;
                    $co->await($f2);

                    return 'never';
                });
            },
        );

        $command = $app->pageState()->command();
        $command->run(null);
        $app->frame(32.0, $constraints);

        $box['f'][1]->resolve('ok');
        $app->frame(48.0, $constraints);

        $box['f'][2]->reject(new \RuntimeException('第二步失败'));
        $app->frame(64.0, $constraints);

        $last = self::last($app);
        check(
            '三步 · 中间失败 ⇒ 整条 error（没有"第一步的 data + 一条没人看的错"）',
            $last->hasError() && !$last->hasValue() && $last->message() === '第二步失败',
            'state=' . $last->stateName() . ' message=' . $last->message(),
        );
        check('三步 · 失败不产生诊断（命令的 catch 接住了整条）', Errors::recentDiagnostics() === []);

        $this->flushChecks();
    }

    /** ③ 取消打到**正在跑的那一步**，且协程被收掉 */
    public function testCancelReachesRunningStepAndStopsScope(): void
    {
        $box = ['f' => [], 'stopped' => 0, 'cleaned' => 0];

        [$app, $constraints] = self::boot(
            static function (mixed $arg) use (&$box): Future {
                return AsyncScope::task(static function (AsyncScope $co) use (&$box): string {
                    try {
                        $f1 = Future::pending();
                        $box['f'][1] = $f1;
                        $co->await($f1);

                        $f2 = Future::pending(static function () use (&$box): void {
                            $box['stopped']++;
                        });
                        $box['f'][2] = $f2;
                        $co->await($f2);

                        return 'never';
                    } finally {
                        $box['cleaned']++;
                    }
                });
            },
        );

        $command = $app->pageState()->command();
        $command->run(null);
        $app->frame(32.0, $constraints);

        $box['f'][1]->resolve('ok');
        $app->frame(48.0, $constraints);

        check('取消前 · 第二步在途', $command->isRunning() && isset($box['f'][2]));

        $command->cancel();
        $app->frame(64.0, $constraints);

        check(
            '取消 · 打到**正在跑的那一步**（第二步的取消钩子被调到）',
            $box['stopped'] === 1,
            'stopped=' . (string) $box['stopped'],
        );
        check(
            '取消 · 协程被收掉（finally 执行，不是挂在 suspend 上泄漏）',
            $box['cleaned'] === 1,
            'cleaned=' . (string) $box['cleaned'],
        );
        check(
            '取消 · 命令回到 idle（不是永远转圈）',
            !$command->isRunning() && self::last($app)->isIdle(),
            'state=' . self::last($app)->stateName(),
        );

        // ★ 取消之后迟到的结算不该再落地
        $box['f'][2]->resolve('late');
        $app->frame(80.0, $constraints);

        check(
            '取消 · 迟到的结果不落地（仍是 idle）',
            self::last($app)->isIdle(),
            'state=' . self::last($app)->stateName(),
        );

        $this->flushChecks();
    }
}
