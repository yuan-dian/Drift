<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\ScrollView;
use Drift\Widget\SegmentedControl;
use Drift\Widget\State;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 分段控件 Segmented—— W8 控件增补的第一个控件。
 *
 * 形态对照 iOS `UISegmentedControl` / WinUI `Segmented`：一组平级的视图切换，
 * **选中恰好一段**。三段演示各管一条：
 *
 *   ① 三段 + 受控值：点了只回调新序号，值住 State（与 Checkbox / TextField 同纪律）；
 *   ② 两段：最短形态（等宽段在标签长短不齐时也齐）；
 *   ③ 整组禁用：整组不进 Tab 序、点不动。
 *
 * ★ 这一页同时是**能力边界的如实记录**：方向键在段间移动焦点今天没有
 *   （Tab 一段一格，与 Win32 单选组一致）；悬停高亮没有视觉态。见页末。
 */
#[Route(id: 'segmented', title: '分段 Segmented', icon: IconName::Menu)]
final class SegmentedPage extends ShowcasePage
{
    /** 受控值：当前选中的段序号（-1 = 全不选，演示越界选中） */
    private int $range = 1;

    /** 演示②的两段控件 */
    private int $mode = 0;

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('分段控件 Segmented')->title(),
            Text::make('一组平级的视图切换，选中恰好一段。受控：点了只回调新序号，值住 State。')
                ->caption(),

            $this->sectionBar('三段 · 受控选中'),
            Card::make(Column::make()->spacing(10.0)->children([
                SegmentedControl::make(['日', '周', '月'])
                    ->selected($this->range)
                    ->onChanged(fn(int $i) => $this->pick($i)),
                Text::make('当前值：' . $this->readout())->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('两段 · 最短形态'),
            Card::make(Column::make()->spacing(10.0)->children([
                SegmentedControl::make(['列表', '网格'])
                    ->selected($this->mode)
                    ->onChanged(fn(int $i) => $this->pickMode($i)),
                Text::make('标签长短不齐也等宽 ⇒ 选中块尺寸恒定，视觉只表达"选了哪段"。')
                    ->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('整组禁用'),
            Card::make(Column::make()->spacing(10.0)->children([
                SegmentedControl::make(['开', '关'])
                    ->selected(0)
                    ->enabled(false),
                Text::make('整组不进 Tab 序（与 HTML / Win32 disabled 一致），段也激活不响应。')
                    ->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 方向键在段间移动焦点：今天没有 —— Tab 一段一格；方向键路由是键盘子系统的活。')
                ->caption(),
            Text::make('② 悬停高亮：没有视觉态（Button 也没有）；hover() 回调框架照常派发，要自己做。')
                ->caption(),
            Text::make('③ 越界选中（selected 超出标签表）：全不选，不夹到第一项。')
                ->caption(),
        ]);
    }

    private function pick(int $index): void
    {
        $this->setState(function () use ($index): void {
            $this->range = $index;
        });
    }

    private function pickMode(int $index): void
    {
        $this->setState(function () use ($index): void {
            $this->mode = $index;
        });
    }

    /** 当前值的直读（应用侧受控值的形状：段序号 → 业务语义） */
    private function readout(): string
    {
        if ($this->range === 0) {
            return '日';
        }
        if ($this->range === 1) {
            return '周';
        }
        if ($this->range === 2) {
            return '月';
        }

        return '（全不选）';
    }

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
