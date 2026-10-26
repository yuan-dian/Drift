<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Rendering\Color;

/**
 * 窗口外观材质 —— **一个值对象说全三件事**（2026-10-26 收敛裁决）。
 *
 * 收敛前它们散在两个对象的三个方法上：`RenderSurface::setBackground()`（底色 /
 * 逐像素 alpha）、`WindowHost::setOpacity()`（整窗 alpha）、`WindowHost::setBackdrop()`
 * （背后材质）。问题是三者的**耦合关系**应用完全看不见：
 *
 * ① **逐像素 alpha 要无边框** —— `UpdateLayeredWindow` 把位图贴在窗口左上角，
 *    有标题栏时客户区比窗口矩形矮一个标题栏 ⇒ 位图整体上移；壳在"帧尺寸 ≠
 *    窗口矩形"时回落 GDI。漏设不黑屏，但"透明没生效"且无任何报错。
 * ② **背后材质只能透过 alpha 看见** —— DWM 把模糊画在窗口**背后**，应用像素
 *    压在上面；不透明窗口设了等于没设（没有观察通道，不是失败）。
 * ③ **整窗 alpha（LWA）与逐像素 alpha（ULW）互斥** —— 壳侧要按完整快照调和，
 *    三个 setter 分开调就必须靠"调用顺序对了才对"。
 *
 * 收敛后这三条从"组合根里的注释"变成**可执行的契约**：① 由
 * `Win32WindowHost::applyMaterial()` 硬校验（没 `setFrameless()` 就 Fatal）；
 * ②③ 由壳一次拿到完整快照、按值调和，与调用顺序无关。
 *
 * ★ **底色像素仍归 `RenderSurface` 画**：`setBackground()` 那条"底色是'画出来
 *   是什么像素'、不是'合成器怎么合成'"的裁决不动 —— 材质只把**模式声明**
 *   （逐像素 on/off）带给壳，`Color` 本身不过桥。应用侧的接线：
 *
 * ```php
 * $material = WindowMaterial::make()
 *     ->background(Color::fromHex('#FFFFFFB0'))
 *     ->opacity(0.9)
 *     ->backdrop(Backdrop::Acrylic);
 * if ($material->backgroundColor() !== null) {
 *     $surface->setBackground($material->backgroundColor());   // 像素归表面
 * }
 * $window->applyMaterial($handle, $material);                  // 模式归壳
 * ```
 *
 * ★ **各字段 `null` / 中性值的语义是"不声明"**（与 `Backdrop` / `CursorShape`
 *   同一套约定）：`background(null)` = 不改底色（保持表面当前值）；`opacity(1.0)`
 *   = 不透明 = 整窗 alpha 的 no-op；`backdrop(null)` = 关。**没有**"全部必填"
 *   的形态 —— 只想开毛玻璃的应用不该被迫知道默认底色是什么。
 *
 * @api
 */
final class WindowMaterial
{
    private function __construct(
        private readonly ?Color $background,
        private readonly float $opacity,
        private readonly ?Backdrop $backdrop,
    ) {
    }

    /** 全中性：不改底色、不透明、关材质（等于对窗口外观零操作） */
    public static function make(): self
    {
        return new self(null, 1.0, null);
    }

    /**
     * 底色（`a < 255` ⇒ 该窗口走逐像素透明）。
     *
     * ★ 像素仍由 `RenderSurface::setBackground()` 画；这里声明的是**意图**，
     *   `applyMaterial()` 据它推出逐像素模式并校验无边框前提。
     */
    public function background(Color $color): static
    {
        return new self($color, $this->opacity, $this->backdrop);
    }

    /** 整窗不透明度（0..1；1 = 不透明 = no-op）。逐像素模式下由 present 的 `BLENDFUNCTION` 带上，语义一致 */
    public function opacity(float $value): static
    {
        return new self($this->background, $value, $this->backdrop);
    }

    /** 窗口背后的材质（`null` = 关）。正常用法搭配带 alpha 的底色，否则没有观察通道（见类注释 ②） */
    public function backdrop(?Backdrop $backdrop): static
    {
        return new self($this->background, $this->opacity, $backdrop);
    }

    /** `null` = 未声明底色（表面保持自己的默认/当前值） */
    public function backgroundColor(): ?Color
    {
        return $this->background;
    }

    public function opacityValue(): float
    {
        return $this->opacity;
    }

    public function backdropKind(): ?Backdrop
    {
        return $this->backdrop;
    }

    /**
     * 应用是否声明了逐像素透明（带 alpha 的底色）。
     *
     * ★ 这是跨桥的**唯一**底色衍生量：`Color` 不过桥（§5.1 只传原语），壳只需要
     *   "模式"这一个 bool —— 颜色是像素的事，模式才是合成器的事。
     */
    public function wantsPixelAlpha(): bool
    {
        return $this->background !== null && !$this->background->isOpaque();
    }
}
