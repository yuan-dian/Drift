<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\WindowState;

use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Platform\WindowHost;
use Drift\Platform\WindowMaterial;
use Drift\Platform\WindowMaterialCapabilities;

/**
 * `WindowHost` 的测试替身（§5.4 `WindowStateStore` 的夹具底）。
 *
 * ★ **为什么要替身而不是真窗口**：`WindowStateStore` 的全部契约都在
 *   "读 → 校验 → 降级 / 写"这一段，而窗口由 `Win32WindowHost` 管（真机侧）。
 *   替身让 store 的每条分支在**解释器里**就能跑到，不依赖 cl.exe 构建 ——
 *   这正是 T1/T2 分级里"廉价闸门"的意思。
 * ★ `place()` 的调用被**逐个录下来**（含参数），断言的是"还原动作真的发生了"，
 *   而不是"没抛异常"。
 */
final class FakeWindowHost implements WindowHost
{
    /** @var list<array{0: Rect, 1: bool}> */
    public array $placed = [];

    public function __construct(
        private ?Point $position = null,
        private Size $client = new Size(0.0, 0.0),
        private bool $maximized = false,
    ) {
    }

    public function createWindow(string $title, int $width, int $height): int
    {
        return 7;   // 固定假句柄
    }

    public function show(int $windowHandle): void
    {
    }

    public function close(int $windowHandle): void
    {
    }

    public function setTitle(int $windowHandle, string $title): void
    {
    }

    /** 夹具：记录最后一次设的最小尺寸（真机行为由 WM_GETMINMAXINFO 挡，头侧只验接线） */
    public ?array $minSize = null;

    public function setMinSize(int $width, int $height): void
    {
        $this->minSize = [$width, $height];
    }

    /**
     * 夹具：记录**最后一次**应用的材质（P1 收敛，2026-10-26）。
     *
     * ★ 记一份而不是"列表"：材质是**一次性快照**（壳按值调和、与调用顺序无关），
     *   没有"先开再关"的真实用法 —— 要改外观就重新 `applyMaterial()` 一份新值对象。
     *   ★ `$windowHandle` 在这里被忽略（假窗不区分句柄）—— 与 `place()` 等夹具一致。
     */
    public ?WindowMaterial $material = null;

    public function applyMaterial(int $windowHandle, WindowMaterial $material): void
    {
        $this->material = $material;
    }

    /**
     * 夹具：报告一份"全能力"平台（opacity / 逐像素 / blur / acrylic / 圆角都支持）。
     *
     * ★ 替身的目的不是模拟 Win10 限制，而是让"应用的自适应分支"能跑 —— 返回全 true
     *   才能让测试覆盖到"能力齐全"那条路径。要测"降级"分支应由测试自行构造
     *   一个 `materialCapabilities()` 返回受限值的替身，而不是在这里分叉。
     */
    public function materialCapabilities(int $windowHandle): WindowMaterialCapabilities
    {
        return WindowMaterialCapabilities::fromBits(0x01 | 0x02 | 0x04 | 0x08 | 0x10);
    }

    /** 夹具：记录无边框请求的拖动带高度（逻辑像素；真机行为在壳里，头侧只验接线） */
    public ?int $framelessDragHeight = null;

    public function setFrameless(int $windowHandle, int $dragHeight): void
    {
        $this->framelessDragHeight = $dragHeight;
    }

    /** 夹具：`beginDrag()` 调用次数（AppBar 拖动接线的断言目标） */
    public int $dragStarts = 0;

    public function beginDrag(int $windowHandle): void
    {
        ++$this->dragStarts;
    }

    /** 夹具：窗口按钮调用次数（自绘最小化/最大化按钮的接线断言） */
    public int $minimizes = 0;

    public function minimize(int $windowHandle): void
    {
        ++$this->minimizes;
    }

    public int $maximizeToggles = 0;

    public function maximizeToggle(int $windowHandle): void
    {
        ++$this->maximizeToggles;
    }

    public function clientSize(int $windowHandle): Size
    {
        return $this->client;
    }

    public function isMaximized(int $windowHandle): bool
    {
        return $this->maximized;
    }

    public function setCursor(int $windowHandle, CursorShape $shape): void
    {
    }

    public function position(int $windowHandle): ?Point
    {
        return $this->position;
    }

    public function place(int $windowHandle, Rect $rect, bool $maximized): void
    {
        $this->placed[] = [$rect, $maximized];
    }

    /** @var list<array<string,mixed>> 记录 pushSemantics 收到的扁平节点（UIA 夹具断言，§9 / M5） */
    public array $pushedSemantics = [];

    public function pushSemantics(array $flatNodes): void
    {
        $this->pushedSemantics = $flatNodes;
    }
}
