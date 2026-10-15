<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal drift-preview 的演示页：**不编译、不依赖 OS** 跑完整 layout + paint。
 *
 * 这就是 §16.3 想要的"内环"：改一页只跑一页，秒级。SVG 走的是与生产同一条路径
 * （Frame → DisplayList → RasterCanvas），不是另一套近似。
 *
 * ★ 页面文件**必须**给出一个 Widget 子类；`drift-preview` 自己 new 它。
 */
final class DemoPage extends StatelessPage
{
    public function build(BuildContext $context): Widget
    {
        return Column::make()->children([
            Text::make('你好 Drift')->title(),
            Text::make('第二行正文，用来验证断行与行高。')->body(),
            Button::make('确定'),
        ]);
    }
}
