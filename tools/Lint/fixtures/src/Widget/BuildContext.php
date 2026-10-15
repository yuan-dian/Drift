<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Theme\ThemeData;

/**
 * 夹具：唯一的协议例外文件 —— 它可以引用 Theme 而不该响。
 *
 * 真正的理由在规则里（`FILE_EXCEPTIONS`）；这里只证明"登记过的文件确实放行"。
 */
final class BuildContext
{
    public function __construct(private ThemeData $theme)
    {
    }

    public function theme(): ThemeData
    {
        return $this->theme;
    }
}
