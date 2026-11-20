<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;

use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Matrix2D;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\ScrollView;
use Drift\Widget\SizedBox;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 变换 Transform—— `->rotate()` / `->scale()` / `->transform()` 的第一个消费者。
 *
 * ★ **能力没有消费者 = 未实现**（§14.3）：`Canvas::transform()` 如果只被单测调到，
 *   它就只是一段"能算出矩阵的算术"；这一页才让"画出来的东西真的歪了"成为事实。
 *
 * ★ 这一页**故意全是静态的**（无状态、无动画）：变换是纯绘制期属性，
 *   "一帧就画对"才是它要证明的事。动画版在 Motion 页（`AnimatedOpacity`），
 *   两者组合（动画驱动矩阵）留到 A1c 后续，不在这一件里假装做完。
 */
#[Route(id: 'transform', title: '变换 Transform', icon: IconName::Square)]
final class TransformPage extends ShowcasePage
{
    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('变换 Transform')->title(),
            Text::make('绘制期仿射：不动布局、不动子盒尺寸，只改画出来的样子。命中测试跟着走（点在变换后的位置才中）。')
                ->caption(),

            Text::make('绕中心旋转')->heading(),
            Wrap::make()->runSpacing(16.0)->spacing(16.0)->children([
                $this->tile('0°', 0.0),
                $this->tile('8°', 8.0),
                $this->tile('-8°', -8.0),
                $this->tile('30°', 30.0),
            ]),
            Text::make('四个方块的外框完全一样（布局没变），歪的只是画出来的样子 —— 放大 30° 那块会盖到旁边的空白上。')
                ->caption(),

            Text::make('绕中心缩放')->heading(),
            Wrap::make()->runSpacing(16.0)->spacing(16.0)->children([
                $this->tile('×1.0', 0.0, 1.0),
                $this->tile('×1.3', 0.0, 1.3),
                $this->tile('×0.7', 0.0, 0.7),
            ]),
            Text::make('缩放同样不改布局：×1.3 的方块右边缘压到邻居身上，而它在布局里仍然只占 88×88。')
                ->caption(),

            Text::make('直接给矩阵')->heading(),
            Wrap::make()->runSpacing(16.0)->spacing(16.0)->children([
                $this->matrixTile('斜切', Matrix2D::identity()->then(Matrix2D::scaling(1.0, 1.0))),
                $this->matrixTile('转 45°', Matrix2D::rotation(45.0)),
            ]),
            Text::make('`Matrix2D` 是 2×3 仿射（平移/缩放/旋转/斜切都在这 6 个数里）；`then()` 是"先左后右"的链式读法。')
                ->caption(),
        ]);
    }

    /** 一个 88×88 的色块方片，标签画在片内 */
    private function tile(string $label, float $degrees, float $scale = 1.0): Widget
    {
        $tile = SizedBox::make(
            Card::make(Text::make($label)->body())->background(Color::fromHex('#4C7BF3'))
        )->size(88.0, 88.0);

        if ($degrees !== 0.0) {
            $tile->rotate($degrees);
        }

        if ($scale !== 1.0) {
            $tile->scale($scale);
        }

        return $tile;
    }

    private function matrixTile(string $label, Matrix2D $matrix): Widget
    {
        return SizedBox::make(
            Card::make(Text::make($label)->body())->background(Color::fromHex('#7A5AF8'))
        )->size(88.0, 88.0)->transform($matrix, true);
    }
}
