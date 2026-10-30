<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Frame;

use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\TickerState;
use Drift\Dev\Smoke\Frame\TickerWidget;
use Drift\Dev\Smoke\Layout\LayoutCountBox;
use Drift\Dev\Smoke\Layout\LayoutCountWidget;
use Drift\Dev\Smoke\Frame\RecordingSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\DisplayList;
use Drift\Widget\Element;

/**
 * 合成参数与**跳过 layout**（arch-v2 `05` §5.4 / §12.2 #10–#12，W4）。
 *
 * ## 判据必须是**调用次数**，不能是"画面看起来变了"
 *
 * 画面在"重排了但结果一样"和"根本没重排"两种情况下**逐像素相同** ⇒ 只有探针
 * （`LayoutCountBox` 的计数器）能分辨。同理，"合成参数写进去了"要看**录到的命令**
 * （`OP_LAYER_PUSH` / `OP_TRANSLATE`），不是看 `composeOpacity()` 的读回 ——
 * 后者在"写了但不画"的实现上照样对。
 *
 * ## 三条判据各自挡什么
 *
 * | 判据 | 挡的退化 |
 * |---|---|
 * | 纯动画帧 `layoutCalls` 不涨 | 每帧全树重排（60fps 下等于没优化） |
 * | `setCompose()` 后 `layoutCalls` 不涨 | 把合成当成布局输入（改透明度触发重排） |
 * | `settle()` 返回"有没有 rebuild" | W4 跳过 layout 的唯一依据；恒 true ⇒ 永远不跳 |
 *
 * ★ 与 `Feature/App/WindowFrameTest` 分工：那边判**换尺寸**时的重排（`repaintForResize`
 *   / 零视口降级），这边判**不换尺寸**时该省的工作有没有真的省掉。
 */
final class ComposeSkipLayoutTest extends SmokeTestCase
{
    /** @return array{0: Element, 1: LayoutCountBox, 2: RecordingSurface} */
    private static function mountCounter(): array
    {
        $element = (new LayoutCountWidget(new Size(80.0, 40.0)))->createElement();
        $element->mount(null, smokeContext());
        $box = $element->effectiveBox();
        if (!$box instanceof LayoutCountBox) {
            self::fail('LayoutCountWidget 没造出 LayoutCountBox（夹具坏了）');
        }

        $surface = new RecordingSurface();
        $surface->resize(320, 240, 1.0);

        return [$element, $box, $surface];
    }

    /**
     * 纯动画帧跳过 layout；`setCompose()` 也不触发 layout；参数真的进了命令流。
     *
     * ★ 三条是**累加**的：首帧 1/1 → 动画帧 1/2 → `setCompose` 后 1/3。
     *   绝对值不重要，"layout 一直是 1、paint 一直在涨"才是判据。
     * ★ 首帧那条是**量具自检**：`layoutCalls` 若恒 0（探针没接上），后面两条全恒真。
     * ★ 命令流那条补的是"写了但不画"：`composeOpacity()` 读回 0.5 只证明字段被写，
     *   证明不了 `paint()` 真的开了层、真的平移了。
     */
    public function testComposeAndAnimationSkipLayout(): void
    {
        [$element, $box, $surface] = self::mountCounter();
        $frame = new Frame($surface);
        $tight = BoxConstraints::tight(new Size(320.0, 240.0));

        $frame->run($element, $tight, 0.0);
        check(
            '合成 · 首帧：layout 一次、paint 一次（量具本身不是空转）',
            $box->layoutCalls === 1 && $box->paintCalls === 1,
            \sprintf('layout=%d paint=%d', $box->layoutCalls, $box->paintCalls)
        );

        // #12：没有 rebuild、约束也没变 ⇒ 跳过 layout。
        // ★★ 2026-10-30（N1 泵帧分离）**这条的"paint 仍执行"半边不再成立**：
        //   什么都没动的一帧现在**整段跳过 paint + present**（否则空闲窗口每秒
        //   60 次整帧 blit，正是 N1 要治的病）。"动了就必须画"由下一条守着 ——
        //   两条合起来才是完整判据：**省该省的，不许省掉该画的**。
        $frame->run($element, $tight, 16.0);
        check(
            '合成 · 无变化帧：layout 与 paint 都整段跳过（N1 后判据从"无条件画"改为"按需"）',
            $box->layoutCalls === 1 && $box->paintCalls === 1,
            \sprintf('layout=%d paint=%d', $box->layoutCalls, $box->paintCalls)
        );

        // #10：setCompose 只改合成参数 ⇒ 不触发 layout，但**必须触发 paint**
        $box->setCompose(0.5, 3.0, 0.0);
        $frame->run($element, $tight, 32.0);
        check(
            '合成 · setCompose 不触发 layout、但必须重画（§12.2 #10 + N1）',
            $box->layoutCalls === 1 && $box->paintCalls === 2 && $box->composeOpacity() === 0.5,
            \sprintf('layout=%d paint=%d opacity=%.3f', $box->layoutCalls, $box->paintCalls, $box->composeOpacity())
        );

        $list = $surface->list();
        $ops = [];
        for ($i = 0, $n = $list->count(); $i < $n; $i++) {
            $ops[] = $list->opAt($i);
        }
        check(
            '合成 · 合成参数真被画出来（opacity 开层 + dx 平移各一条命令）',
            \in_array(DisplayList::OP_LAYER_PUSH, $ops, true) && \in_array(DisplayList::OP_TRANSLATE, $ops, true),
            '共 ' . \count($ops) . ' 条命令'
        );
    }

    /**
     * `settle()` 的返回值 = "本帧有没有 rebuild" —— W4 跳过 layout 的唯一判据。
     *
     * ★ 两条成对：只验"有脏 ⇒ true"会奖励"恒 true"（那样永远不跳过 layout，
     *   而功能全对、只是每帧全树重排）；只验"干净 ⇒ false"会奖励"恒 false"
     *   （跳过 layout 的判断永远成立，改了尺寸也不重排）。
     * ★ 前置 `needsBuild()` 不能省：`tick()` 没生效时 `settle()` 也可能返回 true
     *   （挂载那一轮的残留），那条判据就没落在"本次 tick"上。
     */
    public function testSettleReportsWhetherAnythingRebuilt(): void
    {
        $element = (new TickerWidget())->createElement();
        $element->mount(null, smokeContext());
        $element->settle(); // 清掉挂载那一轮的脏
        $state = $element->state();
        if (!$state instanceof TickerState) {
            self::fail('TickerWidget 没造出 TickerState（夹具坏了）');
        }

        $state->tick();
        check(
            '合成 · 前置：tick 之后确实脏了（否则下面"有脏 ⇒ true"无对照）',
            $state->needsBuild() === true && $state->step === 1,
            \sprintf('needsBuild=%s step=%d', $state->needsBuild() ? 'true' : 'false', $state->step)
        );
        check(
            '合成 · settle() 有脏 ⇒ true（W4 靠它判断能不能跳过 layout）',
            $element->settle() === true
        );
        check(
            '合成 · settle() 干净 ⇒ false（不脏还排 = 每帧全树重排，等于没优化）',
            $element->settle() === false
        );
    }
}
