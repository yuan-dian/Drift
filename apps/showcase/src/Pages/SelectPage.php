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
 *   ③ 宽控件：`triggerWidth()` 定宽（标签靠左，没有下拉箭头）；
 *   ④ 长列表：30 项 ⇒ 面板被限高到 260 且**可以滚**（2026-10-24 起有了）。
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

    private string $many = '';

    private string $short = '';

    /** @return list<Option> 30 项（行高由主题定，这里不猜） */
    private static function manyOptions(): array
    {
        $out = [];
        for ($i = 1; $i <= 30; $i++) {
            $out[] = Option::make('选项 ' . sprintf('%02d', $i), 'v' . $i);
        }

        return $out;
    }

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

            $this->sectionBar('④ 长列表 · 30 项 ⇒ 面板限高 260 且可以滚（够短则贴着内容）'),
            Card::make(Row::make()->spacing(12.0)->children([
                Select::make(self::manyOptions())->value($this->many)->placeholder('请选择编号')
                    ->onChanged(fn(string $v) => $this->pick('many', $v)),
                Text::make($this->many === '' ? '还没选' : '收到值：' . $this->many)->caption(),
            ]))->paddingAll(12.0),
            Text::make('打开后把光标移进面板，用滚轮往下翻 —— 第 30 项一开始在面板下沿之外，'
                . '不限高就永远点不到它。')->caption(),

            $this->sectionBar('⑤ 映射短形式 · 键 = 值、值 = 显示名（`Option::normalize()`，2026-10-24）'),
            Card::make(Row::make()->spacing(12.0)->children([
                // ★ 键故意用**数字串**：PHP 会把 `'110000'` 转成 `int 110000`，全靠
                //   `Option::normalize()` 里的 `(string)` 收口才装得进 string 值域。
                Select::make([
                    '110000' => '北京市',
                    '310000' => '上海市',
                    '440100' => '广州市',
                ])->value($this->short)->placeholder('请选城市')
                    ->onChanged(fn(string $v) => $this->pick('short', $v)),
                Text::make($this->short === '' ? '还没选' : '收到值：' . $this->short)->caption(),
            ]))->paddingAll(12.0),
            Text::make('DB / HTTP 拿来就是 `id => name` 时不用再手写 `array_map`。'
                . '键是数字也照收（内部转回 string）。裸值（`[\'甲\', \'乙\']`）会 Fatal —— '
                . 'PHP 里 `[0 => \'x\']` 与 `[\'x\']` 是同一个数组，"值 0"与"标签列表"分不开。')->caption(),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 方向键换值：没有 —— 面板只在**打开那一刻**建一次，选中高亮会停在'
                . '旧值上 ⇒ 宁可不做，也不做一个会自相矛盾的。补齐要先让"面板随控件重建"。')->caption(),
            Text::make('② 下拉箭头：没有 —— `Button` 的标签位只收字符串，要画箭头得再包一层'
                . ' `Row`，那会多出一个焦点目标。')->caption(),
            Text::make('③ 滚动：有了（2026-10-24）—— 面板内容套 `ScrollView` + '
                . '`PopupPanel::maxHeight(260)`，"贴着内容还是滚动"由实测自然高决定，不按条目数猜。'
                . '超过一屏仍建议应用自己做搜索 / 分页（几百项靠滚屏找仍然慢，且没有虚拟化）。')->caption(),
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
            } elseif ($which === 'many') {
                $this->many = $value;
            } elseif ($which === 'short') {
                $this->short = $value;
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
