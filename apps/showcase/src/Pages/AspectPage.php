<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Base\StatelessShowcasePage;


use Drift\Geometry\EdgeInsets;
use Drift\Widget\AspectRatio;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Grid;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 宽高比 AspectRatio。
 *
 * 四段演示：
 *
 *   ① 16:9 封面（定宽 640 ⇒ 高 360）；
 *   ② 同一宽度基准、三种比例（320 ⇒ 180 / 240 / 320）—— 高度差一眼可见；
 *   ③ 显式 `height(120)` 优先：宽由比例反推（2.5 × 120 = 300）；
 *   ④ 两轴都定死：比例**没有自由度**（240 × 80 保持不动，不被改成 120 高）。
 *
 * ★ ①② 都**显式给宽度**：`AspectRatio` 的规则是"两轴都松时先铺满有界那轴"，
 *   不给定宽它会铺满整页（1920 宽 ⇒ 1080 高），反而看不出比例。
 * ★ `Column` **不用** `cross(Stretch)`：拉伸会把比例盒的宽变成"父的紧宽"，
 *   比例就变成"高 = 整页宽 / 比例"，同样看不出三段之间的差别。
 */
final class AspectPage extends StatelessShowcasePage
{
    public function build(BuildContext $ctx): Widget
    {
        return ScrollView::make(
            Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('宽高比 AspectRatio')->title(),
                Text::make(
                    '比例是 `mods.aspect` 字段（与 width / height 同族）—— 布局管线第 ① 步解出，'
                    . '**没有新的渲染盒**。'
                )->caption(),
                // ★ `16 / 9` 在 AOT 下真会被截成 1；`16.0 / 9.0` 才是 1.7778，
                //   见 `docs/reference/aot-constraints.md` §1.2。
                // ★★ 这里不写运行期求值：那会让本页几何在 AOT 与解释器下差 94px，
                //   `tools/fingerprint.php` 逐值比对永远有脏行。该事实由 `probes/aot_micro`
                //   打印的「前提：int/int=3.0000」一行守着。
                Text::make('比例求值：16 / 9 = 1.7778（注意：AOT 下 `int / int` 会截断成 1）')
                    ->caption(),

                self::sectionBar('① 16 : 9 封面（定宽 640 ⇒ 高 360）'),
                AspectRatio::make(16.0 / 9.0, self::block('16 : 9'))->width(640.0),

                self::sectionBar('② 同一宽度、三种比例（格宽自适应 ⇒ 高 16:9 < 4:3 < 1:1）'),
                Grid::make(3)->spacing(12.0)->children([
                    AspectRatio::make(16.0 / 9.0, self::block('16 : 9')),
                    AspectRatio::make(4.0 / 3.0, self::block('4 : 3')),
                    AspectRatio::make(1.0, self::block('1 : 1')),
                ]),

                self::sectionBar('③ 显式 height 优先（高 120，比例 2.5 ⇒ 宽 300）'),
                AspectRatio::make(2.5, self::block('2.5 : 1'))->height(120.0),

                self::sectionBar('④ 两轴都定死 ⇒ 比例无自由度（240 × 80 不动）'),
                AspectRatio::make(2.0, self::block('2 : 1'))->width(240.0)->height(80.0),
            ])
        );
    }

    private static function block(string $label): Widget
    {
        return Card::make(Text::make($label)->body())->paddingAll(8.0);
    }

    private static function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
