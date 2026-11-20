<?php
declare(strict_types=1);

namespace Drift\Platform\Win32;

use Drift\Platform\AbstractPlatformHooks;

/**
 * Win32 独有的进程级槽：模态尺寸循环里主循环被系统挂起，壳在 `WM_SIZE` 里
 * 同步调回 PHP。静态槽按窗口句柄分发，这里只负责接上/卸掉。
 */
final class Win32PlatformHooks extends AbstractPlatformHooks
{
    public function setResizePump(?\Closure $pump): void
    {
        ResizeHookState::$pump = $pump;
    }
}
