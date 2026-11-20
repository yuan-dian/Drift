<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\Alignment;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Widget\Align;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 对齐 Align—— `Alignment` 与 `->alignContent()` 的第一个消费者。
 *
 * ★ **这一页要证明的事只有一件**：同一个盒、同一个子，换锚点就换位置，而
 *   **盒与子的尺寸一个字节都不变**。所以九格里每一格的盒尺寸完全相同
 *   （120×84），变的只有小方块贴在哪。
 *
 * ★ 第二段用分数（-1 / -0.5 / 0 / 0.5 / 1）证明它是**连续**的 —— 如果实现
 *   静默把值取整成九宫格，-0.5 和 0.5 会跑到 0 上去，两排就会一模一样。
 *
 * ★ 全静态（无状态、无动画）：对齐是布局期属性，"一帧就排对"才是它要证明的事。
 */
final class AlignPage extends StatelessWidget
{
    public static function make(): self
    {
        return new self();
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('对齐 Align')->title(),
            Text::make('把内容钉在盒内的某个锚点。九宫格是九个点，Alignment 是整条线（分数 -1 起点 / 0 居中 / +1 终点）。')
                ->caption(),

            Text::make('九宫格（九个盒尺寸完全一样，变的只有小方块贴在哪）')->heading(),
            Column::make()->spacing(12.0)->children([
                Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                    $this->frame('TL', Alignment::topLeft()),
                    $this->frame('TC', Alignment::topCenter()),
                    $this->frame('TR', Alignment::topRight()),
                ]),
                Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                    $this->frame('CL', Alignment::centerLeft()),
                    $this->frame('C', Alignment::center()),
                    $this->frame('CR', Alignment::centerRight()),
                ]),
                Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                    $this->frame('BL', Alignment::bottomLeft()),
                    $this->frame('BC', Alignment::bottomCenter()),
                    $this->frame('BR', Alignment::bottomRight()),
                ]),
            ]),
            Text::make('九格的灰底盒都是 120×84（盒尺寸不受锚点影响），变的只有小方块贴的角落。')
                ->caption(),

            Text::make('任意分数（-1 / -0.5 / 0 / +0.5 / +1）')->heading(),
            Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                $this->frame('-1', new Alignment(-1.0, 0.0), 96.0, 64.0),
                $this->frame('-0.5', new Alignment(-0.5, 0.0), 96.0, 64.0),
                $this->frame('0', new Alignment(0.0, 0.0), 96.0, 64.0),
                $this->frame('+0.5', new Alignment(0.5, 0.0), 96.0, 64.0),
                $this->frame('+1', new Alignment(1.0, 0.0), 96.0, 64.0),
            ]),
            Text::make('五格的横轴落点依次是 0 / 1/4 / 1/2 / 3/4 / 全 —— 中间两格如果跑到正中，说明值被取整成了九宫格。')
                ->caption(),

            Text::make('三个入口是同一个机制')->heading(),
            Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                $this->frame('Align', new Alignment(1.0, 1.0), 120.0, 84.0),
                $this->frame('Center', Alignment::center(), 120.0, 84.0),
                $this->frame('centerContent', Alignment::center(), 120.0, 84.0),
            ]),
            Text::make('`Align` / `Center` / `->centerContent()` 写的都是同一个对齐字段，由基类 layout() 的收尾步施加 —— 没有第二套布局代码。')
                ->caption(),
        ]);
    }

    /** 一个灰底盒 + 一个贴在某锚点上的小方块 */
    private function frame(
        string $label,
        Alignment $alignment,
        float $width = 120.0,
        float $height = 84.0,
    ): Widget {
        // ★ 小方块**不写死尺寸**：写死的话 `centerContent` 那个 13 字符的标签
        //   会撑破 40 宽的盒子 ⇒ C6 Fatal（"画到盒外面去"）。让它随文字走，
        //   九格里的标签都是 1~2 个字符 ⇒ 九块的尺寸天然一致。
        $chip = Card::make(Text::make($label)->caption())
            ->background(Color::fromHex('#4C7BF3'))
            ->radius(4.0)
            ->paddingAll(6.0);

        return Align::make($chip, $alignment)
            ->boxSize($width, $height)
            ->background(Color::fromHex('#F1F3F8'))
            ->radius(6.0);
    }
}
