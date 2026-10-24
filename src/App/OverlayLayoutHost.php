<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * `OverlayLayout` 的 host 契约（S1 第 7 步）—— **只有两个口子**。
 *
 * ★ 与 {@see InputHost} 同形同义（`overlays()` 读 / `dismissOverlay()` 收窄）：
 *   布局片只表达"关掉这个条目"一个意图，卸载 / 焦点回锚策略整个住 Application。
 * ★ 不复用 `InputHost`：那个有 8 个口子（focus/dispatch/accelerators…），
 *   几何段一个都不该看见。
 *
 * @internal S1 的拆分片接口，同 `InputHost` —— 不是应用契约
 */
interface OverlayLayoutHost
{
    public function overlays(): OverlayStack;

    public function dismissOverlay(OverlayEntry $e): void;
}
