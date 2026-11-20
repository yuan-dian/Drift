<?php
declare(strict_types=1);

namespace Drift\Platform\X11;

use Drift\Platform\AbstractPlatformHooks;

/**
 * X11 没有模态尺寸循环的同步回叫：`ConfigureNotify` 走正常事件泵，
 * 重绘由 `EventLoop` 驱动，所以 resize pump 这一槽是空实现。
 */
final class X11PlatformHooks extends AbstractPlatformHooks
{
    public function setResizePump(?\Closure $pump): void
    {
    }
}
