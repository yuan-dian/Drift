<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Option;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Select;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 15「下拉选择 Select」—— W8-2 的第五件（2026-10-23）。
 *
 * 形态对照 Win32 `ComboBox` / Material `DropdownButton`：一个"选择框"，
 * 点开是一列选项，选中一项后把值交给应用（**受控**）。
 *
 * 四段演示：
 *
 *   ① 基本：三个城市，选中项在下拉里用强调色底标出来；
 *   ② 值 ≠ 显示名：`Option::make('北京市', '110000')` —— 值域是身份、标签是给人看的字；
 *   ③ 未选中：`value()` 不匹配任何选项 ⇒ 显示 placeholder；
 *   ④ 长列表 + 宽控件：选项多到一屏放不下时**不会滚**（能力边界，见页末）。
 *
 * ★ 应用侧只有三个链式方法：`options / value / onChanged`。开面板、落位、点外面关、
 *   ESC 关、控件卸载自动收尸全在框架 —— 这与 Tooltip 是**同一类**声明式浮层
 *   （`Widget::overlayPanel()`），不需要组合根接线（与 Snackbar 相反）。
 * ★ 页末如实记录能力边界。
 */
final class SelectPage extends StatefulWidget
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
        return new SelectState();
    }
}

final class SelectState extends State
{
    private string $city = '310000';

    private string $plain = '';

    private string $wide = '';

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('下拉选择 Select')->title(),
            Text::make('受控：值住应用的 State，点选只回调新值；开面板 / 落位 / 点外面关 / ESC 关归框架。')
                ->caption(),

            $this->sectionBar('① 基本 · 选中项在下拉里用强调色标出'),
            Card::make(Row::make()->spacing(12.0)->children([
                Select::make([
                    Option::make('北京市', '110000'),
                    Option::make('上海市', '310000'),
                    Option::make('广州市', '440100'),
                    Option::make('深圳市', '440300'),
                ])->value($this->city)->onChanged(fn(string $v) => $this->pick('city', $v)),
                Text::make('当前值：' . $this->city)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('② 值 ≠ 显示名（Option 的 value 是身份，label 是给人看的字）'),
            Card::make(Row::make()->spacing(12.0)->children([
                Select::make([
                    Option::make('一等座', 'A'),
                    Option::make('二等座', 'B'),
                    Option::make('商务座', 'C'),
                ])->value($this->plain)->placeholder('请选座席')
                    ->onChanged(fn(string $v) => $this->pick('plain', $v)),
                Text::make($this->plain === '' ? '还没选' : '收到值：' . $this->plain)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('③ 宽控件 · triggerWidth 定宽（标签靠左，没有下拉箭头）'),
            Card::make(Row::make()->spacing(12.0)->children([
                Select::make([
                    Option::of('短'),
                    Option::of('中等长度的一项'),
                    Option::of('这是一项很长很长很长的选项，用来验证面板宽度上限'),
                ])->value($this->wide)->placeholder('请选一项')
                    ->triggerWidth(240.0)
                    ->onChanged(fn(string $v) => $this->pick('wide', $v)),
                Text::make($this->wide === '' ? '还没选' : '收到值：' . $this->wide)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 方向键换值：没有 —— 面板只在**打开那一刻**建一次，选中高亮会停在'
                . '旧值上 ⇒ 宁可不做，也不做一个会自相矛盾的。补齐要先让"面板随控件重建"。')->caption(),
            Text::make('② 下拉箭头：没有 —— `Button` 的标签位只收字符串，要画箭头得再包一层'
                . ' `Row`，那会多出一个焦点目标。')->caption(),
            Text::make('③ 滚动：没有 —— 与菜单（`Menu::panel()`）一致，都是 `PopupPanel(Column)`。'
                . '选项多到一屏放不下时，应用应自己做搜索 / 分页。')->caption(),
            Text::make('④ 可编辑 / 多选：没有 —— 那是 `ComboBox` 与 `list<string>` 受控值，形状不同。')->caption(),
            Text::make('⑤ 面板宽度不与控件等宽：面板按内容 + 最小宽 180 自尺寸。要等宽得给'
                . ' `placeEntry()` 加最小宽约束（现在只有最大宽）。')->caption(),
        ]);
    }

    private function pick(string $which, string $value): void
    {
        $this->setState(function () use ($which, $value): void {
            if ($which === 'city') {
                $this->city = $value;
            } elseif ($which === 'plain') {
                $this->plain = $value;
            } else {
                $this->wide = $value;
            }
        });
    }

    private function sectionBar(string $title): Widget
    {
        return Row::make()->spacing(8.0)->children([
            Text::make($title)->heading(),
            Spacer::make(),
        ]);
    }
}
