<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\BoxFrame;
use Drift\Widget\CardLike;
use Drift\Widget\ScrollView;
use Drift\Widget\StatefulBox;

/**
 * `nonbox-decoration` 的**正例**夹具：一条都不该响。
 *
 * 四件"看着像但不是"：
 *   ① 产盒控件（`BoxFrame`）；
 *   ② 自己覆写并转发的组合控件（`CardLike` —— 真实 `Card` 的形态，误报过一次）；
 *   ③ `StatefulWidget` 声明了 `createRenderObjectFromState()`（`StatefulBox`）；
 *   ④ 接收者是变量 ⇒ 静态类型不可确定，不猜。
 */
final class NonBoxDecorationGood
{
    public function build(): void
    {
        BoxFrame::make()->paddingAll(8.0);
        CardLike::make()->paddingAll(8.0);
        StatefulBox::make()->paddingAll(8.0);

        $view = ScrollView::make();
        $view->paddingAll(8.0);
    }
}
