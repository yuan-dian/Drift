<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Frame;

use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\RenderSurface;

/**
 * @internal 冒烟夹具：把一帧录成 `DisplayList` 的渲染表面（**不画像素**）。
 *
 * ## 与 `SurfaceProbe` 的分工（2026-10-28 归位）
 *
 * 两者都实现 `RenderSurface`，区别只有一个：**什么时候画像素**。
 *
 * | | `RecordingSurface` | `SurfaceProbe` |
 * |---|---|---|
 * | 帧内 | 只录命令 | 录命令，**读者要时才回放** |
 * | 读像素 | `list()->replay($canvas)` 或 `raster()` | `raster()` |
 * | 额外记账 | 无 | `frames` / `ends` / `lastTimestamp` |
 *
 * ★ 它**原本住在 `Smoke\Overlay\`**：第一个用户是浮层的叠色断言（两层 scrim
 *   谁盖谁只能靠像素回答，字符串里换个顺序看不出来）。但"录制面"与浮层无关 ——
 *   `SurfaceProbe` 现在组合它（`Frame` 是底层夹具、`Overlay` 是场景，依赖不能反过来）
 *   ⇒ 搬到 `Frame`，与 `SurfaceProbe` 并列。
 *
 * ★★ **帧内画像素是测试里最贵的单笔开销**：1920×1017 = 195 万像素，光
 *   `new RasterCanvas` 就 0.26s，画满一帧 1.5s。所以"只读树"的断言绝不能持有
 *   一个会真画的面 —— 2026-10-28 的实测就是这么找出 `㊷b` 的 12.19s 的。
 */
final class RecordingSurface implements RenderSurface
{
    private int $width = 0;

    private int $height = 0;

    private DisplayList $list;

    /** 回放到光栅时的底色（`setBackground` 改它；默认白 = 沿用旧行为） */
    private Color $background;

    public function __construct()
    {
        $this->list = new DisplayList();
        $this->background = Color::white();
    }

    public function resize(int $width, int $height, float $dpr): void
    {
        $this->width = $width;
        $this->height = $height;
    }

    public function setBackground(Color $color): void
    {
        $this->background = $color;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->list = new DisplayList();

        // 默认能力 = `RasterCanvas` 的支持面（`RecordingCanvas` 构造函数已按
        // "离屏回放端"取默认，见它的注释）⇒ 不会录下 Optional op。
        return new RecordingCanvas($this->list);
    }

    public function endFrame(): void
    {
    }

    /** 上一帧录到的命令表 */
    public function list(): DisplayList
    {
        return $this->list;
    }

    /** 把上一帧画到光栅上（读像素用）；底色 = `setBackground` 设的那个 */
    public function raster(): RasterCanvas
    {
        $raster = new RasterCanvas(
            max(1, $this->width),
            max(1, $this->height),
            $this->background,
        );
        $this->list->replay($raster);

        return $raster;
    }
}
