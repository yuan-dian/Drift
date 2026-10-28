<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Overlay;

use Drift\App\OverlayEntry;
use Drift\Dev\Smoke\App\SnackbarApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Widget\Snackbar;

/**
 * 底部通知（W8-2，2026-10-23）。
 *
 * 钉住三件事：① 落位（底部居中 + 贴底留白）；② 队列语义（第一条立即、后面排队、
 * 队首到点后上位）；③ `duration=0` = 永不自动消失。
 *
 * ★ 反例自检四条：未到点不消失 / duration=0 跑满仍在 / 无 action() 就没按钮 /
 *   `hitTest` 与 Tooltip **相反**（这里是 true，动作按钮要能点）。
 * ★ 每个方法都先跑一帧对齐 `after()` 的时间基准（它读 `max(上一帧, 上一次输入)`），
 *   否则 due 会落在过去、定时器"提前到期"（Tooltip 块踩过同一个坑）。
 */
final class SnackbarTest extends SmokeTestCase
{
    private const W = 600.0;
    private const H = 400.0;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(self::W, self::H));
    }

    private function boot(): SnackbarApplication
    {
        $app = new SnackbarApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->root()?->effectiveBox()?->layout($this->constraints());

        return $app;
    }

    public function testFirstShowsImmediatelyAndSitsAtBottomCenter(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $app->frame(1000.0, $c);
        $app->push(Snackbar::make('已保存'));   // due = 1000 + 4000 = 5000
        check(
            'Snackbar · 第一条立即显示（不等队列）',
            $app->hasSnackbar() && $app->snackbarQueueLength() === 0,
            'has=' . ($app->hasSnackbar() ? 'yes' : 'no') . ' queue=' . $app->snackbarQueueLength(),
        );

        $app->frame(1100.0, $c);   // 落位（placeOverlays 在这一帧跑）
        $entry = $app->snackbarEntry();
        $box = $entry?->element?->effectiveBox();
        $panelW = ($box?->size()?->width ?? 0.0) - 12.0;    // elevation 6 × 2
        $panelH = ($box?->size()?->height ?? 0.0) - 12.0;

        check(
            'Snackbar · 文字是声明的那句',
            $app->snackText() === '已保存',
            'text=' . \var_export($app->snackText(), true),
        );
        check(
            'Snackbar · 面板宽高非零（位置对但尺寸 0 = 画面一丝不变）',
            $panelW > 0.0 && $panelH > 0.0,
            'panelW=' . $panelW . ' panelH=' . $panelH,
        );

        $left = ($entry?->x ?? -999.0) + 6.0;
        $top = ($entry?->y ?? -999.0) + 6.0;
        check(
            'Snackbar · 水平居中（x = (maxW − w)/2）',
            \abs($left - (self::W - $panelW) / 2.0) < 0.51,
            'left=' . $left . ' expect=' . ((self::W - $panelW) / 2.0) . ' panelW=' . $panelW,
        );
        check(
            'Snackbar · 贴底留白（y = maxH − h − Snackbar::MARGIN）',
            \abs($top - (self::H - $panelH - Snackbar::MARGIN)) < 0.51,
            'top=' . $top . ' expect=' . (self::H - $panelH - Snackbar::MARGIN) . ' panelH=' . $panelH,
        );
        check(
            'Snackbar · 条目参数：hitTest=true（动作按钮要能点，与 Tooltip 相反）/ 不拦层外 / 不动焦点',
            $entry !== null
                && $entry->hitTest === true
                && $entry->barrier === false
                && $entry->scrim === false
                && $entry->dismissOnTapOutside === false
                && $entry->dismissOnEsc === false
                && $entry->prefer === OverlayEntry::BOTTOM_CENTER
                && \abs($entry->maxWidth - Snackbar::MAX_WIDTH) < 0.01
                && $entry->restoreFocus === null,
            $entry === null ? 'no entry' : \json_encode([
                'hitTest' => $entry->hitTest,
                'barrier' => $entry->barrier,
                'prefer' => $entry->prefer,
                'maxWidth' => $entry->maxWidth,
                'restoreFocus' => $entry->restoreFocus === null ? 'null' : 'set',
            ]),
        );
    }

    public function testAutoDismissAtDueTime(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $app->frame(1000.0, $c);
        $app->push(Snackbar::make('已保存'));   // due = 5000

        $app->frame(2000.0, $c);
        check(
            'Snackbar · 反例：未到点不消失',
            $app->hasSnackbar(),
            'has=' . ($app->hasSnackbar() ? 'yes' : 'no') . '（due=5000，此刻 2000）',
        );

        $app->frame(5100.0, $c);
        check(
            'Snackbar · 到点自动消失',
            !$app->hasSnackbar(),
            'has=' . ($app->hasSnackbar() ? 'yes' : 'no') . '（due=5000，此刻 5100）',
        );
    }

    public function testQueueIsFifo(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $app->frame(6000.0, $c);
        $app->push(Snackbar::make('第一条'));   // due = 10000
        $app->push(Snackbar::make('第二条'));   // 入队
        check(
            'Snackbar · 第二条进队列（不抢当前这条）',
            $app->hasSnackbar() && $app->snackbarQueueLength() === 1 && $app->snackText() === '第一条',
            'queue=' . $app->snackbarQueueLength() . ' text=' . \var_export($app->snackText(), true),
        );

        $app->frame(10100.0, $c);
        check(
            'Snackbar · 第一条到点 ⇒ 队首立刻上位（FIFO）',
            $app->hasSnackbar() && $app->snackbarQueueLength() === 0 && $app->snackText() === '第二条',
            'queue=' . $app->snackbarQueueLength() . ' text=' . \var_export($app->snackText(), true),
        );
    }

    public function testDurationZeroNeverExpires(): void
    {
        $app = $this->boot();
        $c = $this->constraints();

        $app->frame(9000.0, $c);
        $app->push(Snackbar::make('第一条'));
        $app->dismissSnackbar();
        check(
            'Snackbar · dismiss 之后没有通知、队列也空',
            !$app->hasSnackbar() && $app->snackbarQueueLength() === 0,
            'has=' . ($app->hasSnackbar() ? 'yes' : 'no'),
        );

        $app->frame(11000.0, $c);
        $app->push(Snackbar::make('错误：保存失败')->duration(0));
        check(
            'Snackbar · duration=0 ⇒ 不排定时器（wantsFrame 为假 = 不会自己消失）',
            $app->hasSnackbar() && !$app->wantsFrame(),
            'has=' . ($app->hasSnackbar() ? 'yes' : 'no') . ' wantsFrame=' . ($app->wantsFrame() ? 'true' : 'false'),
        );

        $app->frame(999999.0, $c);
        check(
            'Snackbar · 反例：duration=0 跑满任意时长仍在',
            $app->hasSnackbar() && $app->snackText() === '错误：保存失败',
            'has=' . ($app->hasSnackbar() ? 'yes' : 'no'),
        );
    }

    public function testActionButtonInvokesHandlerAndDismisses(): void
    {
        $app = $this->boot();
        $c = $this->constraints();

        $app->frame(1000000.0, $c);
        $hits = 0;
        $app->push(
            Snackbar::make('已删除')->duration(0)->action('撤销', function () use (&$hits): void {
                $hits++;
            })
        );
        $app->frame(1000100.0, $c);   // 落位
        check(
            'Snackbar · action() ⇒ 面板里有动作按钮',
            $app->actionButton() !== null,
            $app->actionButton() === null ? 'no button' : 'ok',
        );

        $btn = $app->actionButton();
        $btnBox = $btn?->effectiveBox();
        [$bx, $by] = $btnBox === null ? [0.0, 0.0] : boxOrigin($btnBox);
        $bw = $btnBox?->size()?->width ?? 0.0;
        $bh = $btnBox?->size()?->height ?? 0.0;
        $app->dispatchInput(PointerEvent::down($bx + $bw / 2.0, $by + $bh / 2.0));
        $app->dispatchInput(PointerEvent::up($bx + $bw / 2.0, $by + $bh / 2.0));
        check(
            'Snackbar · 点动作按钮 ⇒ 调 handler 且收掉这条',
            $hits === 1 && !$app->hasSnackbar(),
            'hits=' . $hits . ' has=' . ($app->hasSnackbar() ? 'yes' : 'no'),
        );

        $app->frame(1000200.0, $c);
        $app->push(Snackbar::make('无动作')->duration(0));
        $app->frame(1000300.0, $c);
        check(
            'Snackbar · 反例：没有 action() 就没有动作按钮',
            $app->actionButton() === null,
            $app->actionButton() === null ? 'ok' : 'unexpected button',
        );
    }

    public function testDismissIsIdempotent(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $app->frame(1000.0, $c);
        $app->push(Snackbar::make('已保存'));

        $app->dismissSnackbar();
        $app->dismissSnackbar();
        check(
            'Snackbar · dismiss 幂等（空队列再调无事发生）',
            !$app->hasSnackbar() && $app->snackbarQueueLength() === 0,
            'has=' . ($app->hasSnackbar() ? 'yes' : 'no'),
        );
    }
}
