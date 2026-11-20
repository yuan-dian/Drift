<?php
declare(strict_types=1);

namespace Drift\Platform;

use Drift\Errors;

/**
 * 所有平台共用的那一半：fatal box 走平台端口，不碰具体 OS 类型。
 * 子类只接自己独有的静态槽（目前只有 Win32 的 resize pump）。
 */
abstract class AbstractPlatformHooks implements PlatformHooks
{
    public function __construct(private readonly Platform $platform)
    {
    }

    public function install(): void
    {
        Errors::installFatalBox(function (string $title, string $body): void {
            $this->platform->fatalBox($title, $body);
        });
    }

    public function release(): void
    {
        $this->setResizePump(null);
        Errors::installFatalBox(null);
    }
}
