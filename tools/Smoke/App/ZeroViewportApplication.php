<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 零视口夹具：一棵含**长文本**的树，用来复现「最小化 ⇒ `tight(0,0)` 布局 ⇒ C6」。
 *
 * ★ 必须是 `Text`：宽 0 时它只能每字一行，高度暴涨，而可用高度是 0 ⇒
 *   `RenderText 实际 0.0 × 86.4，最多只能用 0.0 × 0.0`（用户 2026-10-25 的真机现象）。
 *   `TextField` / `Button` 在 0 宽下各有自己的处理，复现不出这条。
 *
 * @internal 测试夹具
 */
final class ZeroViewportApplication extends Application
{
    public function buildRoot(): Widget
    {
        // 不加 padding：多一层 deflate 会把 0 宽变成负空间，掩盖要测的那一点
        return Column::make()->children([
            Text::make('零视口下这段文本会被排成每字一行，从而复现最小化时的 C6 Fatal'),
        ]);
    }
}
