<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\App\FrameClock;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;

/**
 * 通用延时能力（`Application::after()` / `cancelAfter()`）。
 *
 * 这是 Tooltip / Snackbar / ComboBox 远程搜索的**共同燃料**，所以从控件测试里
 * 摘出来单独立户：它测的是 `PumpHost` 的定时器泵，不是任何控件的语义。
 *
 * ★ `wantsFrame()` 是这条能力的一半：定时器排上了但帧时钟不上膛 ⇒ 永远不响；
 *   响完不回落 ⇒ 空闲时每 15ms 空转。两个方向都要钉。
 * ★ 时间基准取 `max(上一帧, 上一次输入)`：只取"上一帧"的话，**空闲应用**上排的
 *   400ms 会当场触发（真机实测：远程搜索的"正在加载…"一帧都没出现过）。
 *   这一条用"帧戳 1000、输入戳 5000"摆出来 —— 两者差 4 秒，不只取输入戳就必红。
 */
final class AfterSchedulerTest extends SmokeTestCase
{
    private const CONSTRAINTS_W = 320.0;
    private const CONSTRAINTS_H = 400.0;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(self::CONSTRAINTS_W, self::CONSTRAINTS_H));
    }

    private function app(): EchoApplication
    {
        $app = new EchoApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());

        return $app;
    }

    public function testScheduleTickAndIdle(): void
    {
        $app = $this->app();
        $c = $this->constraints();
        $ticks = [];
        $app->after(500, function () use (&$ticks): void {
            $ticks[] = 't500';
        });

        // ★★ 判据在 2026-11-02（技术债 D1）**换了形态**，守的不变式一个字没变：
        //   "排上定时器之后帧时钟必须为它醒来，否则永远不响"。
        //   旧形态问 `wantsFrame()`（那时定时器被当成"要帧" ⇒ 按 15ms 醒 33 次
        //   等一个 500ms 的定时器）。现在定时器回答的是"**什么时候**才要"，
        //   与"要不要"并列 ⇒ 判据必须问等待层给出的时长。
        $wait = (new FrameClock())->nextWaitMs($app, 0.0);
        check(
            '延时 · 排期后帧时钟会等到点（不是"要帧"，而是"到点才要"）',
            $wait !== -1.0 && $wait <= 500.0,
            'wait=' . var_export($wait, true) . '（-1 = 无限等 ⇒ 定时器永远不响）',
        );
        check(
            '延时 · 等的是 500ms 而不是一个帧间隔（否则 33 次唤醒等一个定时器）',
            $wait > (float) FrameClock::FRAME_MS,
            'wait=' . var_export($wait, true),
        );

        $app->frame(400.0, $c);
        check('延时 · 未到点不触发', $ticks === [], 'ticks=' . \implode(',', $ticks));

        $app->frame(600.0, $c);
        check('延时 · 到点触发一次', $ticks === ['t500'], 'ticks=' . \implode(',', $ticks));
        check(
            '延时 · 触发后 wantsFrame() 归假（空闲不空转）',
            !$app->wantsFrame(),
            'wantsFrame=true（会每 15ms 空转）',
        );
    }

    /**
     * 短延时**不向帧边界对齐** —— `after()` 的交付时刻是"到点"，不是"下一帧"。
     *
     * ★ 这条钉的是 2026-11-05 记在 `DEBT` §46.6 的一句话："`after()` 的交付粒度是帧边界
     *   （N < 16.667 仍等下一帧）"。那句话与 §46.1 自己的实测（1ms 请求 err_max 2.22ms）
     *   矛盾，也与本文件既有的「等的是 500ms 而不是一个帧间隔」矛盾 —— 是**记错了**。
     *   真正会那样的是"把短定时器拉齐到 `FRAME_MS`"的变异体（§46 牙检 A）。
     */
    public function testShortDelayIsNotRoundedToFrame(): void
    {
        $app = $this->app();
        $app->after(3, static function (): void {
        });

        $wait = (new FrameClock())->nextWaitMs($app, 0.0);
        check(
            '延时 · 3ms 的定时器等 3ms，不等一个帧间隔（交付按"到点"，不按帧边界）',
            $wait > 0.0 && $wait < (float) FrameClock::FRAME_MS,
            'wait=' . var_export($wait, true),
        );
    }

    public function testCancelAfterIsIdempotent(): void
    {
        $app = $this->app();
        $c = $this->constraints();
        $cancel = 0;
        $id = $app->after(100, function () use (&$cancel): void {
            $cancel++;
        });
        $app->cancelAfter($id);
        $app->frame(2000.0, $c);
        check('延时 · cancelAfter() 撤销后不触发（幂等）', $cancel === 0, 'cancel=' . $cancel);
    }

    public function testNestedAfterIsNotLost(): void
    {
        $app = $this->app();
        $c = $this->constraints();
        $nest = [];
        $app->after(10, function () use ($app, &$nest): void {
            $nest[] = 'outer';
            $app->after(10, function () use (&$nest): void {
                $nest[] = 'inner';
            });
        });
        $app->frame(2100.0, $c);
        check(
            '延时 · 回调里再 after() 不丢（先摘再调，遍历中不改进中的表）',
            $nest === ['outer'],
            'nest=' . \implode(',', $nest),
        );

        $app->frame(2200.0, $c);
        check(
            '延时 · 新排的回调下一帧照常触发',
            $nest === ['outer', 'inner'],
            'nest=' . \implode(',', $nest),
        );
    }

    /**
     * 基准取"输入时刻"而不是"上一帧"（真机缺陷判据，2026-10-24）。
     *
     * ★ 摆法要选"上一帧离得很远"：帧戳 1000、输入戳 5000 —— 只取上一帧的话
     *   `due=1400` 早就在过去了，两条判据会**同时**红成"已经触发过了"。
     */
    public function testDueBaseIsInputTimestamp(): void
    {
        $app = $this->app();
        $c = $this->constraints();
        $app->frame(1000.0, $c);
        $app->dispatchInput(PointerEvent::move(5.0, 5.0), 5000.0);

        $fired = 0;
        $app->after(400, function () use (&$fired): void {
            $fired++;
        });

        $app->frame(5100.0, $c);   // 距输入 100ms
        check(
            '延时 · 基准取输入时刻（+100ms 不触发；只取上一帧的话这里已经触发了）',
            $fired === 0,
            'fired=' . $fired,
        );

        $app->frame(5400.0, $c);   // 距输入 400ms
        check('延时 · 基准取输入时刻（+400ms 才触发）', $fired === 1, 'fired=' . $fired);
    }
}
