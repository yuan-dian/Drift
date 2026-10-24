<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Commands\CommandArg;
use Drift\Input\AcceleratorTable;
use Drift\Widget\Element;
use Drift\Widget\Widget;

/**
 * **输入路由片的宿主要口**（S1 第 6 步）：`InputRouter` 经这 8 个口子摸应用。
 *
 * ```text
 * focus() / root()      —— 现读树状态（挂载前为 null，片每次现取不缓存）
 * overlays()            —— 浮层栈（只读遍历；关走 dismissOverlay）
 * dismissOverlay()      —— 按条目关（removeOverlay 是应用的浮层策略，不直露）
 * showMenu()            —— 右键入口要开菜单（@api，应用已有）
 * dispatch()            —— 激活语义要派命令（@api，应用已有）
 * toggleWidgetPanel()   —— 声明式面板的开/关（W8-2 通用缝）
 * accelerators()        —— 加速键表（@api，应用已有）
 * ```
 *
 * ★ **为什么是 getter 而不是构造期传值**：焦点 / 根都是"挂载后才有、重建会换"
 *   的状态（同 `TextEditingHost` 第 4 步立的口径）—— 构造期传一份副本就会陈旧。
 *
 * ★ **为什么 `dismissOverlay` / `toggleWidgetPanel` 要单开口子**：浮层开合的
 *   策略（签名基线、`barrier`/`scrim`/`restoreFocus`、owner 清扫）整个住在
 *   Application，输入片只表达"关掉这个条目"/"切换这个锚点的面板"两个意图，
 *   不碰 `OverlayEntry` 的字段细节。
 *
 * @internal S1 的拆分片接口，同 `TextEditingHost` —— 不是应用契约
 */
interface InputHost
{
    public function focus(): ?FocusManager;

    public function root(): ?Element;

    public function overlays(): OverlayStack;

    public function dismissOverlay(OverlayEntry $e): void;

    public function showMenu(Widget $panel, ?Element $anchor = null): void;

    public function dispatch(string $name, ?CommandArg $arg = null): bool;

    public function toggleWidgetPanel(Element $anchor, \Closure $factory): void;

    public function accelerators(): AcceleratorTable;
}
