<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;

/**
 * **隐式动画**的不透明度：只写目标值，值变了它自己滑过去。
 *
 * ```php
 * AnimatedOpacity::make($this->hovered ? 0.55 : 1.0, $card)
 * //                        ↑ 目标值。没有时长、没有曲线、没有控制器
 * ```
 *
 * ## 隐式 vs 显式
 *
 * | | 显式（`AnimationController`） | 隐式（本控件） |
 * |---|---|---|
 * | 谁持有进度 | 你自己建控制器并持有 | 控件内部（`AnimatedScalar`） |
 * | 谁起停 | 你调 `forward()` / `reverse()` | 没人调 —— 目标变了就动 |
 * | 应用侧代码 | 建控制器 + 监听 + `setState` + `dispose` | **只改一个属性** |
 *
 * 显式那套仍然有用（转场、多段时序、要中途反转），两者的分工见 `AnimatedScalar`。
 *
 * ## 每帧走 `setCompose()`，**不 rebuild、不 relayout**
 *
 * 这是本控件存在的全部意义。`tickFrame()` 拿到新值后直接写盒的合成槽：
 *
 * ```text
 * 一帧 = tickFrame()（写盒的 compose）→ Frame::run()（settle → layout → paint）
 * ```
 * ① 不 `setState` ⇒ 不重建子树；② 合成参数不是布局输入 ⇒ 不重排
 * （`tests/Unit/Frame/ComposeSkipLayoutTest.php` 把这两条钉住）。
 * 于是"整棵子树一起变淡"的代价只是 `paintComposed()` 里一次 `pushLayer()`。
 *
 * ★★ **`build()` 交的是"当前动画值"，不是目标值** —— 这条看着反直觉，但反过来
 *   就会跳：rebuild 时 `setMods()` 会把盒的合成槽重置成 `build()` 声明的值，
 *   若声明目标值，那么"动画跑到一半时父控件重建"会让画面**瞬间跳到终点**，
 *   下一帧再被拉回中间值 —— 一次肉眼可见的闪烁。声明当前值就没有这一跳。
 *
 * ★ **首次挂载不播**：初值就是目标值（没有"从 1.0 淡入"的惊喜）。要淡入就让它
 *   先以 `0.0` 挂载、再改成目标值 —— 隐式动画的语义是"追目标"，不是"入场效果"。
 *
 * ★ 不叠加子控件自己的 `->opacity()`：本控件有自己的盒（`Opacity` → `RenderProxy`），
 *   合成槽各归各的 ⇒ 嵌套时是**相乘**，不会互相覆盖。
 *
 * @api
 */
final class AnimatedOpacity extends StatefulWidget
{
    /**
     * 趋近半衰期（毫秒）：每过这么久，离目标的**剩余距离**减半。
     *
     * ★ **故意不是 `AnimatedValue` 的默认 55ms**。55 是给"跟手的滚动"定的
     *   （滚轮/滚动条淡出必须一停就到位），用在隐式动画上太快：1.0 → 0.25
     *   只要 0.37s，而且前 100ms 就走完 2/3，肉眼读成"一帧跳过去"，于是
     *   用户会以为"点一次没反应"、再点一次（2026-10-29 真机反馈）。
     *
     * ★ 150ms ⇒ 1.0 → 0.25 约 **0.93s**，其中前 450ms 是看得清的主体
     *   （剩下的是 0.01 以内的收口，本来就看不见）。这个量级能让人明确
     *   看到"正在变"，又不至于等得不耐烦。
     *
     * ★ 别把它挪进 `AnimatedValue` 的默认值：那会同时把滚动拖慢三倍。
     */
    public const HALF_LIFE_MS = 150.0;

    private function __construct(
        private readonly float $target,
        private readonly Widget $kid,
    ) {
        parent::__construct();
    }

    public static function make(float $target, Widget $kid): self
    {
        // ★ 在这里校验而不是等 `Opacity::make()`：目标值可能在动画中被改成非法值，
        //   那时报错点会落在 `build()` 里，与"谁写错了"隔着一层。
        if ($target < 0.0 || $target > 1.0) {
            Errors::report(\sprintf('AnimatedOpacity 目标应在 0.0..1.0，收到 %.3f', $target));
        }

        return new self($target, $kid);
    }

    /** 目标不透明度（`State` 拿它判断"要不要开始追"） */
    public function target(): float
    {
        return $this->target;
    }

    /** 被包装的孩子 */
    public function kid(): Widget
    {
        return $this->kid;
    }

    public function createState(): State
    {
        return new AnimatedOpacityState();
    }
}
