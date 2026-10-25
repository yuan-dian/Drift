<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\ScrollView;

/**
 * `nonbox-decoration` 的**反例**夹具：不产盒的控件套修饰 ⇒ 每条调用一个 error。
 *
 * ★ 这两种形态都是真事故形态：`ScrollView::make(…)->paddingAll()` 就是
 *   `showcase` 第 21 页那次 Fatal；`(new X())->shadow()` 是同一条链的另一种写法。
 */
final class NonBoxDecorationBad
{
    public function build(): void
    {
        ScrollView::make()->paddingAll(8.0);
        (new ScrollView())->shadow(0.2);
    }
}
