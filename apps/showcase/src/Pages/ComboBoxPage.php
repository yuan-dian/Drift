<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\ComboBox;
use Drift\Widget\Option;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 16「可编辑下拉 ComboBox」—— W8-2 的第六件（2026-10-24）。
 *
 * 与 `Select` 的差别只有一条：**框里能打字**。点一下 ⇒ 框变搜索框、列出全部候选；
 * 打字 ⇒ 实时过滤；Enter 或点一项 ⇒ 选中，框回到新标签。
 *
 * 四段演示：
 *
 *   ① 基本：四个城市，打字过滤（"北" ⇒ 北京市 / 北京南站两项）；
 *   ② 值 ≠ 显示名：`Option::make('北京市', '110000')`；
 *   ③ 宽控件：`triggerWidth()` 定宽；
 *   ④ 30 项：面板限高 260 + 可滚 + 打字过滤（几百项也能靠打字收敛）。
 *
 * ★ 应用侧只有四个链式方法：`options / value / placeholder / onChanged`。
 *   开面板、落位、点外面关、ESC 关、控件卸载自动收尸全在框架
 *   （`Widget::overlayPanel()`，与 `Select` / Tooltip 同一类声明式浮层）。
 * ★ 页末如实记录能力边界 —— 尤其是"方向键换高亮没有"以及为什么。
 */
final class ComboBoxPage extends StatefulWidget
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
        return new ComboBoxPageState();
    }
}

final class ComboBoxPageState extends State
{
    private string $city = '';

    private string $plain = '';

    private string $wide = '';

    private string $many = '';

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
            Text::make('可编辑下拉 ComboBox')->title(),
            Text::make('点一下框 ⇒ 框变成搜索框并列出全部候选；打字实时过滤；'
                . 'Enter 取第一项、或直接点一项。受控：值住应用的 State。')->caption(),

            $this->sectionBar('① 基本 · 打字过滤（试试打「北」：剩北京市 / 北京南站）'),
            Card::make(Row::make()->spacing(12.0)->children([
                ComboBox::make([
                    Option::make('北京市', '110000'),
                    Option::make('上海市', '310000'),
                    Option::make('广州市', '440100'),
                    Option::make('北京南站', '110100'),
                ])->value($this->city)->placeholder('输入城市名')
                    ->onChanged(fn(string $v) => $this->pick('city', $v)),
                Text::make($this->city === '' ? '还没选' : '当前值：' . $this->city)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('② 值 ≠ 显示名（Option 的 value 是身份，label 是给人看的字）'),
            Card::make(Row::make()->spacing(12.0)->children([
                ComboBox::make([
                    Option::make('一等座', 'A'),
                    Option::make('二等座', 'B'),
                    Option::make('商务座', 'C'),
                ])->value($this->plain)->placeholder('请选座席')
                    ->onChanged(fn(string $v) => $this->pick('plain', $v)),
                Text::make($this->plain === '' ? '还没选' : '收到值：' . $this->plain)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('③ 宽控件 · triggerWidth 定宽'),
            Card::make(Row::make()->spacing(12.0)->children([
                ComboBox::make([
                    Option::of('短'),
                    Option::of('中等长度的一项'),
                    Option::of('这是一项很长很长很长的选项，用来验证面板宽度上限'),
                ])->value($this->wide)->placeholder('请选一项')
                    ->triggerWidth(240.0)
                    ->onChanged(fn(string $v) => $this->pick('wide', $v)),
                Text::make($this->wide === '' ? '还没选' : '收到值：' . $this->wide)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('④ 30 项 · 面板限高 260 且可以滚（打字比滚屏快得多）'),
            Card::make(Row::make()->spacing(12.0)->children([
                ComboBox::make(self::manyOptions())->value($this->many)->placeholder('输入编号')
                    ->onChanged(fn(string $v) => $this->pick('many', $v)),
                Text::make($this->many === '' ? '还没选' : '收到值：' . $this->many)->caption(),
            ]))->paddingAll(12.0),
            Text::make('打「2」⇒ 只剩选项 02 / 12 / 20…29；再打「9」⇒ 只剩 29。'
                . '候选多到一屏装不下时，打字过滤比滚屏快。')->caption(),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 方向键换高亮：没有 —— 焦点只能由框架移动（Tab / Shift+Tab），'
                . '控件侧的闭包拿不到 FocusManager（§4.3 禁 Widget → App）。'
                . '键盘模型是 type-ahead：Enter 取**过滤后的第一项**。'
                . '所以面板里也**不标已选项**（标了会让"回车会选谁"变含糊）。')->caption(),
            Text::make('② Tab 可以进面板：候选行是 Button（可聚焦），Tab 进去后用 Enter 选那一行；'
                . '在面板里打字仍然进输入框（控件把按键转发给编辑器）。')->caption(),
            Text::make('③ 打开时框会清空：框从"显示标签"切成"搜索框"。不清空的话打字会追加在'
                . '标签后面（选中"北京市"再打开、打"上"⇒ 字段成了"北京市上"）。'
                . 'Esc 或点面板外 ⇒ 框回到旧标签（取消语义，丢弃打了一半的词）。')->caption(),
            Text::make('④ 下拉箭头：没有 —— 内置字模缺 164 个字符（含 ▾），理由同 Select。')->caption(),
            Text::make('⑤ 自定义过滤：没有 —— v1 是"标签的大小写不敏感子串匹配"，'
                . '覆盖"选城市 / 选用户"这两类真实场景；要按拼音 / 按 value 匹配得自己过滤后传进来。')->caption(),
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
