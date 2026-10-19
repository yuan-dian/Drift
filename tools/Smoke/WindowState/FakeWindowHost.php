<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\WindowState;

use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Platform\WindowHost;

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
