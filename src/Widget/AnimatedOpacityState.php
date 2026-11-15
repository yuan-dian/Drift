<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * `AnimatedOpacity` 的节律（细节见那边的类注释）。
 *
 * 三件事：① 目标变了就 `toward()`；② 每帧把新值 `setCompose()` 到盒上；
 * ③ 返回"还要下一帧吗"，由帧末 `continueFrame()` 转成下一帧的欠账。
 *
 * ★★ **`tickFrame()` 不 `setState`**：一重建就前功尽弃（`setCompose()` 的整条
 *   设计就是"不重建不重排"，见 `RenderBox::setCompose()` 的注释）。所以这里
 *   直接写盒 —— 顺序是安全的：`Application::frame()` 在 `Frame::run()` **之前**
 *   推进动画 ⇒ 这一帧写的值当帧就被画出来，收口帧不需要"再要一帧"。
 *
 * ★ 返回值用 `isAnimating()`（"离目标还有距离"），不是"这一帧值变没变"：
 *   `AnimatedValue` **首帧只记起点、不推进** ⇒ 用"值变了没有"当判据的话，
 *   第一帧返回 false ⇒ 帧时钟当场落闸 ⇒ 动画永远停在起点（且不报错）。
 */
final class AnimatedOpacityState extends State
{
    /** 懒建：没被动画驱动过的包装不建（与 `ScrollViewState::motion()` 同构） */
    private ?AnimatedScalar $scalar = null;

    /**
     * 目标变了就起一条新曲线。
     *
     * ★★ 2026-10-30 之前这里写着「第一句必须 `parent::didUpdateWidget($widget)`」——
     *   **已经不需要了**：`StatefulElement` 在调本钩子之前就用 `State::adoptWidget()`
     *   落好了新描述，所以下面 `view()` 读到的必然是**新**目标，漏调 parent 也不会
     *   "签名恒等 ⇒ 跨面板永不更新"。保留那一句只是幂等兜底（无害）。
     */
    #[\Override]
    public function didUpdateWidget(Widget $widget): void
    {
        parent::didUpdateWidget($widget);

        $target = $this->view()->target();
        $scalar = $this->scalar();
        if ($target !== $scalar->target()) {
            $scalar->toward($target);
            // ★★ 必须叫醒帧时钟：本方法在 **settle 期间**跑，而
            //   `Application::frame()` 的 `tickAnimations()` 在 settle **之前**
            //   已经跑过了 ⇒ 它看到的目标还是旧的 ⇒ 没有欠账 ⇒
            //   帧时钟落闸 ⇒ 动画停在起点（真机现象：点「淡入」画面一动不动，
            //   再点一次才动）。
            $this->requestFrame();
        }
    }

    #[\Override]
    public function tickFrame(float $timestampMs): bool
    {
        $scalar = $this->scalar();
        $next = $scalar->tick($timestampMs);
        if ($next !== null) {
            $box = $this->element()?->effectiveBox();
            if ($box !== null) {
                // 只改合成槽：不 markDirty、不 markNeedsLayout
                $box->setCompose($next);
            }
        }

        return $scalar->isAnimating();
    }

    /**
     * ★ 交**当前动画值**（不是目标值）—— 理由见 `AnimatedOpacity` 的 ★★：
     *   声明目标值会让"动画中途重建"闪一下终点。
     */
    public function build(BuildContext $ctx): Widget
    {
        return Opacity::make($this->scalar()->value(), $this->view()->kid());
    }

    private function scalar(): AnimatedScalar
    {
        $scalar = $this->scalar;
        if ($scalar === null) {
            // 初值 = 目标值 ⇒ 首次挂载不播（"追目标"语义，不是"入场动画"）
            // ★ 半衰期由控件给（150ms），不是 `AnimatedValue` 的默认 55ms ——
            //   55ms 是给跟手的滚动定的，用在隐式动画上快到看不出是过渡。
            $scalar = new AnimatedScalar($this->view()->target(), AnimatedOpacity::HALF_LIFE_MS);
            $this->scalar = $scalar;
        }

        return $scalar;
    }

    private function view(): AnimatedOpacity
    {
        $widget = $this->widget();
        if (!$widget instanceof AnimatedOpacity) {
            // 框架不变量：StatefulElement 按 `createState()` 配对，配错不可能发生。
            // 但 AOT 下不窄化就取不到 `target()` ⇒ 这里必须写（与 `ScrollViewState::view()` 同形）。
            \Drift\Errors::report('AnimatedOpacityState 只能配 AnimatedOpacity（框架内部不变量被破坏）');
        }

        return $widget;
    }
}
