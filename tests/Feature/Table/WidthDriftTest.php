<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Element;

/**
 * 首帧**视口宽漂移**的自查（真机踩到的坑，2026-10-26）。
 *
 * ## 现象与根因
 *
 * `BuildContext::$width` 是**窗口**宽（根 context 全树共用），而表格的可用宽还要
 * 减掉导航栏 / 卡片 padding。首帧没有盒 ⇒ 只能拿窗口宽当上界 ⇒ flex 列按偏大的
 * 宽分完，行宽超出表盒 ⇒ **右边的列被裁掉**（真机现象：5 列只看见前 3 列，滚一下才对）。
 *
 * 修法是"多要一帧 + 下一帧按真实盒宽重算 + 收敛后不再空转"，这三件事就是下面
 * 三条判据。缺了第三条会变成每帧都重建（比原来的 bug 更贵）。
 *
 * ★ 夹具必须让"窗口宽 ≠ 盒宽"：mount 时 context 宽 320（= 窗口），layout 用 200。
 *   两者相等时三条断言全都验不出东西（首帧就对了，没有漂移可查）。
 * ★ 数值是**推**出来的：200 − 60(fixed) = 140，按 flex 2:1 分 ⇒ 93.33 / 46.67。
 */
final class WidthDriftTest extends SmokeTestCase
{
    /** 挂树但按**给定盒宽**布局（`Fixtures::mount` 固定 320，这里要 200） */
    private static function mountAt(float $boxWidth): Element
    {
        $element = Fixtures::build(5)->createElement();
        $element->mount(null, smokeContext());   // ctx 宽 320 = 窗口宽
        $element->effectiveBox()?->layout(BoxConstraints::tight(new Size($boxWidth, Fixtures::VIEW_H)));

        return $element;
    }

    public function testDetectsDriftThenConverges(): void
    {
        $root = self::mountAt(200.0);
        $state = Fixtures::stateOf($root);

        // ① 首帧按窗口宽 320 分完，与实际盒宽 200 不符 ⇒ 自查必须发现并要一帧
        $wanted = $state?->tickFrame(0.0) === true;
        check(
            'Table · 首帧视口宽用了窗口宽 ⇒ 下一帧自查并要一帧',
            $wanted,
            $wanted ? '要了' : '没要（差异没被看到 ⇒ 列会一直溢出）',
        );

        // ② 重建后按**真实盒宽**重算：200 − 60 = 140 ⇒ 93.33 / 46.67
        $root->rebuild();
        $root->effectiveBox()?->layout(BoxConstraints::tight(new Size(200.0, Fixtures::VIEW_H)));
        $rows = Fixtures::rowElements($root);
        $widths = isset($rows[0]) ? Fixtures::widthsOf($rows[0]) : [];
        check(
            'Table · 重建后列宽按真实盒宽分配（200 − 60 = 140 ⇒ 93.33 / 46.67）',
            \count($widths) === 3
                && \abs($widths[0] - 60.0) < 0.5
                && \abs($widths[1] - 93.33) < 1.0
                && \abs($widths[2] - 46.67) < 1.0,
            \json_encode($widths),
        );

        // ③ 收敛后不再要帧 —— 没有这条，"修好了"会退化成"每帧都重建"
        $again = $state?->tickFrame(16.0) === true;
        check(
            'Table · 宽差收敛后不再要帧（不会每帧空转）',
            $again === false,
            $again ? '仍在要帧' : '已收敛',
        );
    }
}
