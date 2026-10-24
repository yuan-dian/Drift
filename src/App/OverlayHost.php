<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Text\TextMetricsProvider;
use Drift\Theme\ThemeData;
use Drift\Widget\Element;

/**
 * **浮层片的宿主要口**（S1 第 8 步）：`OverlayManager` 经这 6 个口子摸应用。
 *
 * ```text
 * focus() / root()      —— 现读树状态（挂载前为 null，片每次现取不缓存）
 * theme() / metrics()   —— 浮层渲染环境（复用主树的，同主题 / 同度量）
 * after() / cancelAfter()—— 时序件（Tooltip 500ms / Snackbar 自动消失）
 * ```
 *
 * ★ **为什么是 getter 而不是构造期传值**：焦点 / 根都是"挂载后才有、重建会换"
 *   的状态（同 `TextEditingHost` / `InputHost` 第 4/6 步立的口径）—— 构造期传
 *   一份副本就会陈旧。
 * ★ **为什么 `after` / `cancelAfter` 也走 host**：定时器队列是宿主的帧循环资产
 *   （`tickTimers()` 在 Application 里），片只表达"排一个 / 撤一个"的意图，
 *   不持队列副本（同 `InputHost::dispatch()` 的口径）。
 * ★ **浮层栈不在这里**：第 8 步起栈由 `OverlayManager` 自持，宿主与
 *   `OverlayLayout` / `InputRouter` 两片都经 `OverlayManager::overlays()` 现读 ——
 *   删掉第 7 步 `OverlayLayoutHost` 的两口子小接口，host 收窄成这一个。
 *
 * @internal S1 的拆分片接口，同 `TextEditingHost` / `InputHost` —— 不是应用契约
 */
interface OverlayHost
{
    public function focus(): ?FocusManager;

    public function root(): ?Element;

    public function theme(): ThemeData;

    public function metrics(): TextMetricsProvider;

    public function after(int $milliseconds, \Closure $callback): int;

    public function cancelAfter(int $id): void;
}
