<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Preview;

use Drift\Dev\Preview\DemoPage;
use Drift\Dev\Preview\Renderer;
use Drift\Dev\Preview\SvgSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Theme\ThemeData;

/**
 * drift-preview：无头渲染一页到 SVG（§16.3 / §A.5）。
 *
 * ## 判据盯的是"渲染树被正确接手"，不是"SVG 字符串好看"
 *
 * - SVG 里有 `<rect` run ⇒ 真的画了东西，不是空白页；
 * - 根盒尺寸 == 视口 ⇒ 组合节点对渲染树**透明**（页面根是 `StatelessWidget`，
 *   自己没盒，其下的 Column 必须被外层视口收到）—— 少了这一步画面整块消失且不报错：
 *   0×0 在 tight 下合法，C6 不会报；
 * - dpr=2 ⇒ 设备像素翻倍（§6.1 唯一量化点）。
 */
final class RendererTest extends SmokeTestCase
{
    public function testHeadlessPageRendersToSvg(): void
    {
        $context = smokeContext();
        $previewSurface = new SvgSurface();
        $preview = new Renderer($previewSurface, $context->metrics(), ThemeData::light());
        $svg = $preview->render(new DemoPage(), 320.0, 240.0, 1.0);
        check(
            'Preview · SVG 有内容（像素 run > 0，不是空白页）',
            substr_count($svg, '<rect x=') > 0,
            'run 数 ' . (string) substr_count($svg, '<rect x=')
        );
        check(
            'Preview · 根盒排开到视口尺寸（320 × 240 紧约束）',
            $preview->effectiveRoot()?->size()->width === 320.0,
            (string) ($preview->effectiveRoot()?->size()->width ?? -1.0)
        );
        // ★ 组合节点对渲染树透明：页面根是 StatelessWidget（无盒），但其下的 Column
        //   必须被外层视口收到 —— 少了这一步画面整块消失且不报错（0×0 在 tight 下合法）
        check(
            'Preview · 组合根（无盒）下的渲染盒被外层收到（中间层透明）',
            $preview->effectiveRoot()?->size()->height === 240.0
        );
        check(
            'Preview · dpr=2 ⇒ 设备像素翻倍（§6.1 唯一量化点）',
            substr_count($preview->render(new DemoPage(), 320.0, 240.0, 2.0), 'viewBox="0 0 640 480"') === 1
        );

        $this->flushChecks();
    }
}
