<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Slider;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 滑杆 Slider。
 *
 * 形态对照 iOS `UISlider` / WinUI `Slider`：一条轨道 + 一个拇指，连续取值。
 * 四段演示各管一条：
 *
 *   ① 连续（0–100，step 0）：点轨道立即跳值、拖拇指跟手、方向键按 1% 走；
 *   ② 步长（0–10，step 1）：量化真的生效（读数只会是整数）；
 *   ③ 窄盒（width 100）：几何全按盒宽推，不写死像素；
 *   ④ 禁用：点不动、方向键不吃。
 *
 * ★ 这一页同时是**能力边界的如实记录**（页末）：没有悬停态、没有"拖动时浮出
 *   数值气泡"、没有双向（垂直）滑杆。这些都是"没有消费者"的形态，不是漏做。
 */
final class SliderPage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new SliderState();
    }
}

final class SliderState extends State
{
    /** 演示①：连续值（0–100） */
    private float $volume = 62.0;

    /** 演示②：步长值（0–10，step 1） */
    private float $level = 3.0;

    /** 演示③：窄盒 */
    private float $narrow = 25.0;

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('滑杆 Slider')->title(),
            Text::make('一条轨道 + 一个拇指，连续取值。受控：拖动/按键只回调新值，值住 State。')
                ->caption(),

            $this->sectionBar('连续 · 0–100（点轨道即跳值，拖拇指跟手）'),
            Card::make(Column::make()->spacing(10.0)->children([
                Slider::make()
                    ->range(0.0, 100.0)
                    ->value($this->volume)
                    ->label('音量')
                    ->onChanged(fn(float $v) => $this->pickVolume($v)),
                Text::make('当前值：' . $this->readout($this->volume))->caption(),
                Text::make('方向键 ±1（= 区间 1%）、Home/End 到两端；拖出控件外仍然跟手（指针被捕获）。')
                    ->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('步长 · 0–10（step 1，量化生效）'),
            Card::make(Column::make()->spacing(10.0)->children([
                Slider::make()
                    ->range(0.0, 10.0)
                    ->step(1.0)
                    ->value($this->level)
                    ->label('等级')
                    ->onChanged(fn(float $v) => $this->pickLevel($v)),
                Text::make('当前值：' . $this->readout($this->level) . '（只会是整数）')->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('窄盒 · width 100（几何按盒宽推，不写死像素）'),
            Card::make(Column::make()->spacing(10.0)->children([
                Slider::make()
                    ->range(0.0, 100.0)
                    ->width(100.0)
                    ->value($this->narrow)
                    ->onChanged(fn(float $v) => $this->pickNarrow($v)),
                Text::make('当前值：' . $this->readout($this->narrow))->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('禁用'),
            Card::make(Column::make()->spacing(10.0)->children([
                Slider::make()->range(0.0, 100.0)->value(35.0)->enabled(false),
                Text::make('不进 Tab 序（与 HTML / Win32 disabled 一致），点不动、方向键也不吃。')->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 悬停高亮 / 拖动时的数值气泡：今天没有 —— 后者要"跟随指针的第三棵树"，归 M4 悬停层。')
                ->caption(),
            Text::make('② 垂直滑杆：没有 —— 横向已覆盖今天的消费者，竖排只是旋转，没有新语义。')
                ->caption(),
            Text::make('③ 双拇指区间选择（Range Slider）：没有 —— 它是"两个受控值 + 交叉约束"，'
                . '与单值滑杆的模型不同，等有消费者再单独立。')
                ->caption(),
        ]);
    }

    private function pickVolume(float $value): void
    {
        $this->setState(function () use ($value): void {
            $this->volume = $value;
        });
    }

    private function pickLevel(float $value): void
    {
        $this->setState(function () use ($value): void {
            $this->level = $value;
        });
    }

    private function pickNarrow(float $value): void
    {
        $this->setState(function () use ($value): void {
            $this->narrow = $value;
        });
    }

    /** 读数：整数就显示整数，有小数保留一位（演示①的连续值会出现 .3 这种） */
    private function readout(float $value): string
    {
        if (\abs($value - \round($value)) < 0.05) {
            return \sprintf('%.0f', $value);
        }

        return \sprintf('%.1f', $value);
    }

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
