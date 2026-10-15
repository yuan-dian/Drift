<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 托盘图标（壳服务，§5.1 纪律 2）。
 *
 * ★ 只有**一个**托盘图标（应用级，不是每窗口一个）⇒ 方法不收句柄：多图标是
 *   "每个通知一个图标"的形态，而 Drift 的通知模型（M5+）还没定，先不发明。
 *
 * ★ `install()` 返回 `bool` 而不是 `void`：托盘创建会因为"资源管理器还没起来"
 *   而失败（Windows 上有名的一类时序问题），调用方需要能决定"重试 / 降级到无托盘"。
 *
 * ⏳ **M1 不实现**：`Win32Platform::tray()` 返回 `null`。
 *
 * @api platform
 */
interface Tray
{
    /**
     * 装上（或更新）托盘图标。
     *
     * @param string $icon 内置图标名（`app` / `info` / `warn` / `error`）——
     *        不收文件路径：托盘图标必须随 exe 走，指向外部文件会在安装后失效
     */
    public function install(string $tooltip, string $icon = 'app'): bool;

    /** 移除；**未装过不是错误**（幂等） */
    public function remove(): void;
}
