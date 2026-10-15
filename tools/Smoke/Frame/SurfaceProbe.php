<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Frame;

use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RenderSurface;

/**
 * @internal 冒烟夹具：一个**可观测**的渲染表面。
 *
 * `Drift\App\Frame` 需要 `RenderSurface`，而真实实现要 cpp-src 的 Skia 桥（M1）。
 * 这个夹具的价值正是 §7.2 裁决 4 说的那件事：**表面与树互不认识** ⇒ 测试里手搓
 * 一个表面就能跑完整渲染，不编译、不开窗口。
 *
 * `frames` / `lastTimestamp` 是给断言用的：beginFrame/endFrame 的**次数与顺序**
 * 从外面看得见，`Frame` 少调一次 / 多调一次都会立刻红。
 */
final class SurfaceProbe implements RenderSurface
{
    public int $frames = 0;
    public int $ends = 0;
    public int $resizes = 0;
    public ?float $lastTimestamp = null;
    public ?RasterCanvas $canvas = null;

    private int $width = 0;
    private int $height = 0;

    public function resize(int $width, int $height, float $dpr): void
    {
        $this->width = (int) round($width * $dpr);
        $this->height = (int) round($height * $dpr);
        $this->resizes++;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->frames++;
        $this->lastTimestamp = $timestampMs;
        $this->canvas = new RasterCanvas($this->width, $this->height, Color::white(), Scale::of(1.0));

        return $this->canvas;
    }

    public function endFrame(): void
    {
        $this->ends++;
    }
}
