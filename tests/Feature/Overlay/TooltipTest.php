<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Overlay;

use Drift\App\OverlayPolicy;
use Drift\Dev\Smoke\App\TooltipApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Widget\Element;
use Drift\Widget\TooltipPanel;

/**
 * 提示（W8-2，声明式 + 框架托管浮层，2026-10-23）。
 *
 * 钉住：① 显示时序（悬停满 500ms，离开立即收，相邻切换立即换）；② 落位
 * （水平居中于锚点 + 垂直在上方）；③ 条目参数（不拦点击 / 不遮罩 / 不参与命中）；
 * ④ `hitTest=false` 的**理由**（提示盖在下面的按钮上时点击必须穿透）。
 *
 * ★ 三处反例是这块的核心，删掉就只剩"能显示"：`hitTest=true`（闪烁死循环）、
 *   悬停无提示控件（不该排期）、未满 500ms（不该弹）。
 * ★ 每个方法的 **move 之前先跑一帧** 对齐时间基准：`after()` 的 due 取
 *   `max(上一帧, 上一次输入)`，不对齐的话 due 可能落在过去、定时器"提前到期"。
 */
final class TooltipTest extends SmokeTestCase
{
    private const W = 320.0;
    private const H = 400.0;

    private function constraints(): BoxConstraints
    {
        return BoxConstraints::loose(new Size(self::W, self::H));
    }

    private function boot(): TooltipApplication
    {
        $app = new TooltipApplication();
        $app->attach(new SurfaceProbe(), $this->constraints());
        $app->root()?->effectiveBox()?->layout($this->constraints());

        return $app;
    }

    /**
     * 按钮顺序：0 = d（被 a 的提示盖住）、1 = a（主判据）、2 = b（相邻切换）、
     * 3 = c（反例：无提示）。
     *
     * @return list<Element|null>
     */
    private function buttons(TooltipApplication $app): array
    {
        $out = [];

        return \array_pad($app->buttons(), 4, null);
    }

    private function hover(TooltipApplication $app, ?Element $el): void
    {
        $box = $el?->effectiveBox();
        [$x, $y] = $box === null ? [0.0, 0.0] : boxOrigin($box);
        $w = $box?->size()?->width ?? 0.0;
        $h = $box?->size()?->height ?? 0.0;
        $app->dispatchInput(PointerEvent::move($x + $w / 2.0, $y + $h / 2.0));
    }

    /**
     * 悬停到第 `$index` 个按钮并等满 500ms ⇒ 提示弹出。
     *
     * ★ 先 `frame($t)` 再 move：`after()` 的 due 在 move 那一刻就定死了。
     */
    private function showTooltipAt(TooltipApplication $app, int $index, float $t): void
    {
        $app->frame($t, $this->constraints());
        $this->hover($app, $this->buttons($app)[$index]);
        $app->frame($t + 500.0, $this->constraints());
    }

    public function testHoverDelayThenShow(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $buttons = $this->buttons($app);

        // ★ 对齐时间基准再悬停：`after()` 的 due = max(上一帧, 上一次输入) + 500，
        //   在"还没跑过任何帧"的应用上它会是 500 ⇒ 下面 frame(2500) 会当场触发，
        //   那条反例就变成"测不到"。due 因此定在 2200 + 500 = 2700。
        $app->frame(2200.0, $c);
        $this->hover($app, $buttons[1]);
        check(
            'Tooltip · 悬停不立即弹（500ms 未到）',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );
        check('Tooltip · 排期后 wantsFrame() 为真', $app->wantsFrame(), 'wantsFrame=false');

        $app->frame(2500.0, $c);
        check(
            'Tooltip · 反例：未满 500ms 走帧仍不弹',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );

        $app->frame(2800.0, $c);
        check(
            'Tooltip · 满 500ms ⇒ 弹出且文字是声明的那句',
            $app->overlayCount() === 1 && $app->tooltipText() === '保存当前文档',
            'count=' . $app->overlayCount() . ' text=' . \var_export($app->tooltipText(), true),
        );
    }

    public function testCloseAllResetsShownFlag(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $this->showTooltipAt($app, 1, 2000.0);
        $buttons = $this->buttons($app);

        // ★ 这条盯 S1 第 8 步「E 碰 D」的那条不变量：`closeMenu()` 全清会顺手收掉
        //   提示层，"已显示"状态位（`tooltipShown`）若不跟着复位，下一次悬停会
        //   **跳过 500ms 立即弹** —— 而正常路径必须重新等满。
        $app->closeMenu();
        check(
            'Tooltip · closeMenu() 全清后栈空（提示层被顺手收掉）',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );

        $app->frame(3000.0, $c);
        $this->hover($app, $buttons[2]);
        $app->frame(3100.0, $c);
        check(
            'Tooltip · 反例：全清后悬停 b 不立即弹（状态位已复位，重新等 500ms）',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );

        $app->frame(3700.0, $c);
        check(
            'Tooltip · 再满 500ms ⇒ b 的提示弹出（文字是 b 声明的那句）',
            $app->overlayCount() === 1 && $app->tooltipText() === '永久删除，不可恢复',
            'count=' . $app->overlayCount() . ' text=' . \var_export($app->tooltipText(), true),
        );

        // 恢复现场：悬停回 a ⇒ 相邻切换立即换（已显示时不用再等 500ms）。
        $app->frame(4200.0, $c);
        $this->hover($app, $this->buttons($app)[1]);
        check(
            'Tooltip · 悬停回 a ⇒ 现场恢复（后续落位用例读 a 的提示）',
            $app->overlayCount() === 1 && $app->tooltipText() === '保存当前文档',
            'count=' . $app->overlayCount() . ' text=' . \var_export($app->tooltipText(), true),
        );
    }

    public function testPlacementCenteredAboveAnchor(): void
    {
        $app = $this->boot();
        $c = $this->constraints();

        // ★ 已显示时相邻切换立即换内容 ⇒ move 回 a 即弹，不用再等 500ms。
        $this->showTooltipAt($app, 1, 2000.0);
        $buttons = $this->buttons($app);
        $boxA = $buttons[1]?->effectiveBox();
        [$ax, $ay] = $boxA === null ? [0.0, 0.0] : boxOrigin($boxA);
        $aw = $boxA?->size()?->width ?? 0.0;
        $ah = $boxA?->size()?->height ?? 0.0;

        // —— 落位：水平居中于锚点 + 垂直在锚点上方（首选 ABOVE_CENTER）——
        $top = $app->topEntry();
        $panelBox = $top?->element?->effectiveBox();
        $panelW = ($panelBox?->size()?->width ?? 0.0) - 8.0;    // elevation 4 × 2
        $panelH = ($panelBox?->size()?->height ?? 0.0) - 8.0;
        // ★ 面板必须有非零尺寸：2026-10-23 真机踩过「位置完全正确、尺寸 0」而
        //   解释器侧全绿。成因（外部类常量求值成 0）已由上游修复；此断言保留作回归守卫。
        check(
            'Tooltip · 面板宽高非零（位置对但尺寸 0 = 画面一丝不变）',
            $panelW > 0.0 && $panelH > 0.0,
            'panelW=' . $panelW . ' panelH=' . $panelH,
        );

        $left = ($top?->x ?? -999.0) + 4.0;
        $topY = ($top?->y ?? -999.0) + 4.0;

        // ★ 期望值 = **夹取后**的居中值（`placeEntry()` ⑤ 会把负的 x 夹到 0）。
        //   不夹就写死 `ax + (aw−w)/2` 的话，锚点靠左时会误判成"没居中"——而实际是
        //   "居中后出界，被夹回来了"，那正是设计意图（`01` §4.2 不做通用避让）。
        $wantX = $ax + ($aw - $panelW) / 2.0;
        if ($wantX < 0.0) {
            $wantX = 0.0;
        }
        $maxX = self::W - $panelW;
        if ($wantX > $maxX) {
            $wantX = $maxX > 0.0 ? $maxX : 0.0;
        }
        check(
            'Tooltip · 水平居中于锚点（x = clamp(ax + (aw − w)/2, 0, maxW − w)）',
            \abs($left - $wantX) < 0.51 && $ax > 50.0,
            'left=' . $left . ' expect=' . $wantX . ' ax=' . $ax . ' panelW=' . $panelW,
        );

        // ★ 期望值按公式推：锚点上方放得下就上方，否则翻到下方（夹具顶部留了 120 白，
        //   所以这里走的正是**上方**分支 —— 不留白就永远测不到首选方向）。
        $wantY = $ay - $panelH >= 0.0 ? $ay - $panelH : $ay + $ah;
        check(
            'Tooltip · 垂直落在锚点上方（ABOVE_CENTER，不是贴着窗口顶翻转）',
            \abs($topY - $wantY) < 0.51 && $ay - $panelH >= 0.0,
            'y=' . $topY . ' expect=' . $wantY . ' ay=' . $ay . ' panelH=' . $panelH,
        );

        check(
            'Tooltip · 条目参数：不拦点击 / 不遮罩 / 不参与命中 / 限宽 320 / 不动焦点',
            $top !== null
                && $top->policy->blocksOutsidePointer() === false
                && $top->policy->paintsScrim() === false
                && $top->policy->participatesInHitTest() === false
                && $top->policy->dismissesOnTapOutside() === false
                && $top->policy->dismissesOnEsc() === false
                && \abs($top->maxWidth - TooltipPanel::MAX_WIDTH) < 0.01
                && $top->restoreFocus === null,
            $top === null ? 'no entry' : \json_encode([
                'barrier' => $top->policy->blocksOutsidePointer(),
                'hitTest' => $top->policy->participatesInHitTest(),
                'tapOutside' => $top->policy->dismissesOnTapOutside(),
                'maxWidth' => $top->maxWidth,
                'restoreFocus' => $top->restoreFocus === null ? 'null' : 'set',
            ]),
        );
    }

    public function testHitTestFalseLetsClicksThrough(): void
    {
        $app = $this->boot();
        $this->showTooltipAt($app, 1, 2000.0);
        $buttons = $this->buttons($app);
        $top = $app->topEntry();
        $panelBox = $top?->element?->effectiveBox();
        $panelW = ($panelBox?->size()?->width ?? 0.0) - 8.0;
        $panelH = ($panelBox?->size()?->height ?? 0.0) - 8.0;
        $panelCx = ($top?->x ?? 0.0) + 4.0 + $panelW / 2.0;
        $panelCy = ($top?->y ?? 0.0) + 4.0 + $panelH / 2.0;

        // 前提：面板真的盖住了 d 的盒（否则这条判据测的是空气）
        $dBox = $buttons[0]?->effectiveBox();
        [$dx, $dy] = $dBox === null ? [0.0, 0.0] : boxOrigin($dBox);
        $dw = $dBox?->size()?->width ?? 0.0;
        $dh = $dBox?->size()?->height ?? 0.0;
        $covers = $panelCx >= $dx && $panelCx <= $dx + $dw
            && $panelCy >= $dy && $panelCy <= $dy + $dh;

        $app->seen = [];
        $app->dispatchInput(PointerEvent::down($panelCx, $panelCy));
        check(
            'Tooltip · hitTest=false ⇒ 提示盖在 d 上时点击穿透到 d（提示不吃点击）',
            $covers && $app->seen === ['d'],
            'covers=' . ($covers ? 'yes' : 'no')
                . ' seen=' . \implode(',', $app->seen)
                . ' panel=(' . $panelCx . ',' . $panelCy . ')'
                . ' d=(' . $dx . ',' . $dy . ',' . $dw . ',' . $dh . ')',
        );
        $app->dispatchInput(PointerEvent::up($panelCx, $panelCy));

        // ★ 反例自检：把同一块面板的策略换成"参与命中" ⇒ 面板自己吃掉这次 down（下面的 d 收不到）。
        //   换 `snackbar()` 而不是随便找个预设：它与 `tooltip()` **只差这一位**，其余六项全同，
        //   于是这条对照只动了被测变量。这是"为什么必须有这个开关"的证据，不是补充说明。
        $top2 = $app->topEntry();
        if ($top2 !== null) {
            $top2->policy = OverlayPolicy::snackbar();
        }
        $app->seen = [];
        $app->dispatchInput(PointerEvent::down($panelCx, $panelCy));
        check(
            'Tooltip · 反例：参与命中 ⇒ 提示吃掉点击（d 收不到）—— 这就是必须有这个开关的理由',
            $top2 !== null && $app->seen === [],
            'seen=' . \implode(',', $app->seen),
        );
        $app->dispatchInput(PointerEvent::up($panelCx, $panelCy));
    }

    public function testLeaveAndReturnRestartsDelay(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $this->showTooltipAt($app, 1, 2000.0);
        $buttons = $this->buttons($app);

        // —— 离开 ⇒ 立即消失；回来 ⇒ 重新等 500ms ——
        // ★ 顺序很重要：**先离开再回来**。不离开的话 hover 目标没变 ⇒ `onHoverHit()`
        //   根本不会被调 ⇒ "重新弹出"会变成"它本来就没消失"的假通过。
        $this->hover($app, $buttons[3]);
        check(
            'Tooltip · 离开锚点 ⇒ 立即消失（不等延时）',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );

        // ★ 先把时间基准对齐到 3000：基准是 `lastTimestampMs`（上一帧），在帧外调用
        //   时它可能已经落后很久 ⇒ 不对齐的话 due 会落在过去、定时器"提前到期"。
        //   对齐后 due = 3000 + 500 = 3500 ⇒ 下面 3400 / 3600 是**推出来的**。
        $app->frame(3000.0, $c);
        $this->hover($app, $buttons[1]);
        $app->frame(3400.0, $c);
        check(
            'Tooltip · 离开再回来：未满 500ms 不弹（延时是真的在重新计时）',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount() . '（due=3500，此刻 3400）',
        );

        $app->frame(3600.0, $c);
        check(
            'Tooltip · 离开再回来、满 500ms ⇒ 再次弹出',
            $app->overlayCount() === 1 && $app->tooltipText() === '保存当前文档',
            'count=' . $app->overlayCount() . ' text=' . \var_export($app->tooltipText(), true),
        );
    }

    public function testHoverWithoutTooltipDoesNotSchedule(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $this->showTooltipAt($app, 1, 2000.0);
        $buttons = $this->buttons($app);

        // —— 反例：悬停**无提示**的控件 ⇒ 不排期、不弹 ——
        $this->hover($app, $buttons[3]);
        check(
            'Tooltip · 移到无提示控件 ⇒ 已显示的提示立刻消失',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );

        // ★ 这条原本判 `!wantsFrame()` —— 那是"没排期"的**代理判据**。悬停过渡（盒级
        //   `AnimatedValue`）上线后，指针刚移过来就有几百毫秒的合法动画在跑 ⇒ 代理判据
        //   失效。改成直接判本意：**时间过了也不弹**（真排了期的话 900ms 早该弹了）。
        $app->frame(4600.0, $c);
        check(
            'Tooltip · 反例：悬停无 tooltip() 的按钮 ⇒ 走满 500ms 也不弹',
            $app->overlayCount() === 0,
            'count=' . $app->overlayCount(),
        );
    }

    public function testAdjacentSwitchIsImmediate(): void
    {
        $app = $this->boot();
        $c = $this->constraints();
        $this->showTooltipAt($app, 1, 2000.0);

        // —— 已显示时移到相邻带提示的控件 ⇒ 立即换（不再等 500ms）——
        $buttons = $this->buttons($app);
        $this->hover($app, $buttons[2]);
        $app->frame(5400.0, $c);   // 距上次显示只有 100ms < 500ms
        check(
            'Tooltip · 已显示时移到相邻带提示的按钮 ⇒ 立即换内容（100ms < 500ms 也换）',
            $app->overlayCount() === 1 && $app->tooltipText() === '永久删除，不可恢复',
            'count=' . $app->overlayCount() . ' text=' . \var_export($app->tooltipText(), true),
        );
    }
}
