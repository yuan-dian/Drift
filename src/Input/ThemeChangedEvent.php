<?php
declare(strict_types=1);

namespace Drift\Input;

/**
 * 系统主题变更（§9.2：`WM_SETTINGCHANGE`（`lParam == "ImmersiveColorSet"`）⇒
 * 系统由浅色翻深色或反之）。
 *
 * ★ **载荷为空**（空值对象即可）：新主题由消费者自己查 `Screen::systemTheme()`
 *   —— Win32 侧查询是**实时**的（`Win32Screen` 不缓存，每次读注册表
 *   `AppsUseLightTheme`），事件里再带一份快照反而多一个"事件值 vs 查询值"
 *   的分歧面。事件只回答"变了"这一事实。
 *
 * ★ 与 `DpiChanged` **同通道**（§457）：后果相同 —— 环境变了 ⇒ 重建
 *   （不是 relayout）。DPI 侧的 `WM_DPICHANGED` 已入队（`kEvDpi`），本条补上
 *   主题侧的对应物。
 *
 * ★ 今天框架侧**只做显式消费**（`Application::dispatchInput` 认下它、不落
 *   default 丢弃）：`Win32Screen::systemTheme()` 查询实时 + `Frame::run` 每帧
 *   `settle()` ⇒ 主题值变化下一帧自然重建。显式的"标全树脏"重建机制按 A15
 *   （没有消费者不预先发明）留待应用真需要跟随系统主题时，与 §A.2.5 的
 *   `onSystemThemeChanged()` 钩子一并对齐（§24.1 已记：该钩子在
 *   `Application` 上尚不存在）。
 *
 * @api
 */
final readonly class ThemeChangedEvent implements InputEvent
{
}
