<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Padding;
use Drift\Widget\ScrollViewBox;
use Drift\Widget\SizedBox;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal `ScrollView` 滚动条的**形体**预览（§20 纪律 4）。
 *
 * ★ 三个偏移态并排：顶（offset 0）/ 中 / 底（maxScroll）—— 一次渲染核验
 *   "thumb 长度 = 视口占比、位置 = 偏移比例、不随内容平移"。
 *   视口用 `SizedBox` 钉死 96 高 ⇒ 三栏视口一致、thumb 可直接对比。
 *   ★ 中段那栏是 2026-10-17 真 bug 的判据态：thumb 曾画在内容 translate
 *     之后 ⇒ 被偏移带走 ⇒ 中段整条消失（只看顶/底两态未必发现）。
 */
final class ScrollPage extends StatelessWidget
{
    public function build(BuildContext $context): Widget
    {
        $rows = [];
        for ($i = 1; $i <= 24; $i++) {
            $rows[] = Text::make('第 ' . $i . ' 行');
        }

        // 三栏：offset 0 / 中段 / 到底（内容 24×22 = 528，视口 96 ⇒ maxScroll 432）
        $bars = [];
        foreach ([[0.0, 'offset 0（贴顶）'], [216.0, '中段'], [432.0, '到底 maxScroll']] as [$offset, $label]) {
            $bars[] = Column::make()->spacing(4)->children([
                Text::make($label)->caption(),
                SizedBox::make()->height(96.0)->child(
                    ScrollViewBox::make($offset, true, Column::make()->children($rows)),
                ),
            ]);
        }

        return Column::make()->spacing(12)->children([
            Padding::make(Text::make('滚动条形体：thumb = 视口占比（528 内容 / 96 视口 ⇒ 约 1/5 长）')->caption())->all(16.0),
            Column::make()->spacing(16)->children($bars),
            Text::make('横向（视口 200、内容 600 ⇒ thumb 占 1/3，贴底侧）')->caption(),
            SizedBox::make()->height(28.0)->child(
                ScrollViewBox::make(120.0, false, Text::make('横向滚动内容 → → →')),
            ),
        ]);
    }
}
