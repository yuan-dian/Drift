<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\Semantics;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderIcon;
use Drift\Rendering\Color;
use Drift\Widget\Column;
use Drift\Widget\Icon;
use Drift\Widget\IconName;
use Drift\Widget\IconSet;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Text;

/**
 * 图标控件（Icon / IconName / IconSet）。
 *
 * 判据（与 `tools/smoke.php` 图标段一致）：
 *   ① 形状只用 Path ⇒ 软光栅也画得出；
 *   ② 固定正方形 16×16，不撑满父约束（最容易的失败是"被父约束撑满"）；
 *   ③ 像素：Menu 画出了墨（三条横线）；
 *   ④ 枚举与数据表一一对应：漏一项 ⇒ 空 Path ⇒ 静默不画（最隐蔽的失败）；
 *   ⑤ 装饰性叶子：不进语义树。
 *
 * ★ 像素走共享 `spinCanvas()`；语义走共享 `Semantics::element()`。
 */
final class IconTest extends SmokeTestCase
{
    public function testIconMountsRenderIconAndKeepsSquareSize(): void
    {
        $ctx = smokeContext();
        $icRoot = Icon::make(IconName::Menu, 16.0)->createElement();
        $icRoot->mount(null, $ctx);
        $icBox = $icRoot->effectiveBox();
        check('Icon · 挂载即建出 RenderIcon', $icBox instanceof RenderIcon);
        $icBox?->layout(BoxConstraints::loose(new Size(80.0, 80.0)));
        check(
            'Icon · 固定正方形 16×16（不撑满父约束）',
            $icBox?->size()?->width === 16.0 && $icBox?->size()?->height === 16.0,
        );

        $this->flushChecks();
    }

    public function testIconMenuDrawsInk(): void
    {
        $ctx = smokeContext();
        $icBox = Icon::make(IconName::Menu, 16.0)->createElement();
        $icBox->mount(null, $ctx);
        $icBox->effectiveBox()?->layout(BoxConstraints::loose(new Size(80.0, 80.0)));

        $icCanvas = spinCanvas($icBox->effectiveBox(), 16, 16);
        $icInk = 0;
        for ($yy = 0; $yy < 16; $yy++) {
            for ($xx = 0; $xx < 16; $xx++) {
                if (!$icCanvas->pixelAt($xx, $yy)->equals(Color::white())) {
                    $icInk++;
                }
            }
        }
        check('Icon · Menu 画出了墨（三条横线）', $icInk > 0, 'ink=' . $icInk);

        $this->flushChecks();
    }

    public function testIconEveryNameHasPath(): void
    {
        // 枚举与数据表一一对应：漏一项 ⇒ 空 Path ⇒ 静默不画（最隐蔽的失败）
        $icMissing = [];
        foreach (IconName::cases() as $icName) {
            if (IconSet::path($icName, 16.0)->isEmpty()) {
                $icMissing[] = $icName->value;
            }
        }
        check(
            'Icon · 每个 IconName 都有图形数据（空 Path = 静默不画）',
            $icMissing === [],
            'missing=' . implode(',', $icMissing),
        );

        $this->flushChecks();
    }

    public function testIconNotInSemanticsTree(): void
    {
        $icTree = SemanticsBuilder::build(
            Semantics::element(
                Column::make()->children([
                    Icon::make(IconName::Search),
                ]),
            ),
        );
        check('Sem · Icon 不进语义树（装饰性叶子）', $icTree->count() === 0, 'count=' . $icTree->count());

        $this->flushChecks();
    }
}
