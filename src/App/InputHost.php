<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Commands\CommandArg;
use Drift\Input\AcceleratorTable;
use Drift\Widget\Element;

/**
 * **输入路由片的宿主要口**（S1 第 6 步）：`InputRouter` 经这 4 个口子摸应用。
 *
 * ```text
 * focus() / root()   —— 现读树状态（挂载前为 null，片每次现取不缓存）
 * dispatch()         —— 激活语义要派命令（@api，应用已有）
 * accelerators()     —— 加速键表（@api，应用已有）
 * ```
 *
 * ★ 第 6 步立时有 8 个口子；**第 8 步起浮层四口子移除**（`overlays()` /
 *   `dismissOverlay()` / `showMenu()` / `toggleWidgetPanel()`）—— 浮层栈的
 *   Owner 换成 {@see OverlayManager}，InputRouter 改经构造参数直连它
 *   （片间组合，同 `ScrollInputRouter` / `TextEditingBridge` 的口径）。
 *   少了"InputRouter → InputHost → Application → OverlayManager"的壳中壳。
 *
 * ★ **为什么是 getter 而不是构造期传值**：焦点 / 根都是"挂载后才有、重建会换"
 *   的状态（同 `TextEditingHost` 第 4 步立的口径）—— 构造期传一份副本就会陈旧。
 *
 * @internal S1 的拆分片接口，同 `TextEditingHost` —— 不是应用契约
 */
interface InputHost
{
    public function focus(): ?FocusManager;

    public function root(): ?Element;

    public function dispatch(string $name, ?CommandArg $arg = null): bool;

    public function accelerators(): AcceleratorTable;
}
