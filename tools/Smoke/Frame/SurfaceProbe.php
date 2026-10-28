<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\Frame;

use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
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
 *
 * ## ★★ 帧内**只录命令**，读像素时才回放（2026-10-28）
 *
 * 旧实现在 `beginFrame()` 里 `new RasterCanvas(w, h, …)` 真开一块像素缓冲 ——
 * 视口一大就是灾难：1920×1017 = 195 万像素，**光分配 0.26s**，画满一帧 1.5s。
 * 而绝大多数调用方**根本不读像素**（它们只 `findElement` 看树）。
 * 实测代价：`apps/showcase` 那一段 4 帧 + 一次 `inkCount()` = **12.19s**，
 * 只换了 10 条断言 —— 全表最贵、最不值的一段。
 *
 * ⇒ 现在帧内录 `DisplayList`（635 条命令 ~1ms），`raster()` 才回放**一次**并缓存。
 * 同一段降到 ~2.6s（仍读像素）或 ~0.2s（不读像素）。
 *
 * ★ 语义**没有变**：`raster()` 返回的就是"最后一帧画完的样子"，与旧实现逐位同源
 *   （同一个 `RasterCanvas` + 同一份命令流）。变的只是"什么时候算"。
 * ★ `raster()` 在**没跑过帧**时返回 `null`：那是旧 `$canvas === null` 的语义，
 *   一堆断言靠它判"这一帧到底跑了没"。
 * ★ 一次回放**缓存**到下一帧开始：`inkCount()` 在一条断言里常被调两次
 *   （条件 + 失败详情），不缓存就是双倍。
 */
final class SurfaceProbe implements RenderSurface
{
    public int $frames = 0;
    public int $ends = 0;
    public int $resizes = 0;
    public ?float $lastTimestamp = null;

    private int $width = 0;
    private int $height = 0;

    private RecordingSurface $recorder;

    /** 懒回放的缓存（每帧作废一次） */
    private ?RasterCanvas $rasterCache = null;

    public function __construct()
    {
        $this->recorder = new RecordingSurface();
    }

    public function resize(int $width, int $height, float $dpr): void
    {
        $this->width = (int) round($width * $dpr);
        $this->height = (int) round($height * $dpr);
        // 录制面只关心像素口径（dpr 已经在上两行乘掉了）
        $this->recorder->resize($this->width, $this->height, 1.0);
        $this->resizes++;
    }

    /** 清屏色（`setBackground` 改它）—— 断言"底色真的落到像素上"就看它 */
    public function setBackground(Color $color): void
    {
        $this->recorder->setBackground($color);
        $this->rasterCache = null;
    }

    public function beginFrame(float $timestampMs): Canvas
    {
        $this->frames++;
        $this->lastTimestamp = $timestampMs;
        // 新的一帧 ⇒ 上一帧的像素作废（但**不**在新帧开头分配它）
        $this->rasterCache = null;

        return $this->recorder->beginFrame($timestampMs);
    }

    public function endFrame(): void
    {
        $this->ends++;
    }

    /**
     * 最后一帧的像素（`null` = **一帧都还没跑过**）。
     *
     * ★ 调用它就是声明"我要 195 万像素"——在 1920×1017 上等于 1.5s。
     *   只判树/只判命令的断言**不该**调它：用 `list()` 或直接 `findElement()`。
     */
    public function raster(): ?RasterCanvas
    {
        if ($this->frames === 0) {
            return null;
        }

        if ($this->rasterCache === null) {
            $this->rasterCache = $this->recorder->raster();
        }

        return $this->rasterCache;
    }

    /** 最后一帧录到的命令表（**不花像素钱**；判"画了什么"用它） */
    public function list(): DisplayList
    {
        return $this->recorder->list();
    }
}
