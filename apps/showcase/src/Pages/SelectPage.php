<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Geometry\EdgeInsets;
use Drift\Layout\FlexAlign;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Option;
use Drift\Widget\OptionStatus;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Select;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 下拉选择 Select。
 *
 * 形态对照 Win32 `ComboBox` / Material `DropdownButton`：一个"选择框"，
 * 点开是一列选项，选中一项后把值交给应用（**受控**）。
 *
 * 七段演示：
 *
 *   ① 基本：三个城市，选中项在下拉里用强调色底标出来；
 *   ② 值 ≠ 显示名：`Option::make('北京市', '110000')` —— 值域是身份、标签是给人看的字；
 *   ③ 宽控件：`triggerWidth()` 定宽（标签靠左，没有下拉箭头）；
 *   ④ 长列表：30 项 ⇒ 面板被限高到 260 且**可以滚**（已支持）；
 *   ⑤ 映射短形式：`['110000' => '北京市']`（键 = 值）；
 *   ⑥ 行数组：`Option::fromRows($rows, 'name', 'id')`（DB / HTTP 的形状）；
 *   ⑦ 远程三态：加载中 / 失败 / 空表 —— 外加 `onOpen()` 打开才取数。
 *
 * ★ 应用侧只有这几个链式方法：`options / value / onChanged` + 三态
 *   `loading / emptyText / errorText` + `onOpen`。开面板、落位、点外面关、ESC 关、
 *   控件卸载自动收尸全在框架 —— 这与 Tooltip 是**同一类**声明式浮层
 *   （`Widget::overlayPanel()`），不需要组合根接线（与 Snackbar 相反）。
 * ★ 方向键（已支持）：面板打开后 ↓/↑ 移高亮（环绕）、Enter 提交。
 *   高亮是**控件私有态**，应用不用为"光标停在第几行"再存一个字段。
 * ★ 页末如实记录能力边界。
 */
#[Route(id: 'select', title: '下拉 Select', icon: IconName::ChevronDown)]
final class SelectPage extends ShowcasePage
{
    private string $city = '310000';

    private string $plain = '';

    private string $wide = '';

    private string $many = '';

    private string $short = '';

    private string $row = '';

    // ── ⑦ 远程三态（受控：什么时候算加载完只有应用知道）──

    /** @var list<Option> 远程选项表（一开始是空的 —— 打开面板才取数） */
    private array $remote = [];

    private string $remoteValue = '';

    /** 选项表的就绪度 —— 三态**互斥**（`OptionStatus`） */
    private OptionStatus $remoteStatus;

    /** `onOpen` 被调了几次（证明它只在"要开面板"时触发，不是每次重建） */
    private int $openCount = 0;

    #[\Override]
    public function initState(): void
    {
        $this->remoteStatus = OptionStatus::empty('还没取数');
    }

    /**
     * 把远程那段状态切到某一态（示例台不联网，**直接摆姿势**）。
     *
     * ★ 刻意做成"点了就一直停在这一态"：若同一帧里立刻改回 `ready`，你就永远看不到
     *   "正在加载"那个面板 —— 而这一页要给人看的恰恰是那个面板。
     * ★ 真应用的写法是：`onOpen` 里 `status(loading())` + 发请求，`Job::onDone` 里
     *   `status(ready())` + 换选项表。控件侧一行都不用改（三态全是受控属性）。
     * ★ 三态是**一个值**，所以这里不存在"先清 error 再设 loading"那种顺序问题。
     */
    private function setRemoteState(string $state): void
    {
        $this->setState(function () use ($state): void {
            $this->remote = [];

            if ($state === 'loading') {
                $this->remoteStatus = OptionStatus::loading();
            } elseif ($state === 'error') {
                $this->remoteStatus = OptionStatus::error('连接超时（换一态再来）');
            } elseif ($state === 'empty') {
                $this->remoteStatus = OptionStatus::empty('没有匹配的城市');
            } else {
                $this->remoteStatus = OptionStatus::ready();
                $this->remote = [
                    Option::make('北京市', '110000'),
                    Option::make('上海市', '310000'),
                    Option::make('广州市', '440100'),
                ];
            }
        });
    }

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
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                Select::make([
                    Option::make('北京市', '110000'),
                    Option::make('上海市', '310000'),
                    Option::make('广州市', '440100'),
                    Option::make('深圳市', '440300'),
                ])->value($this->city)->onChanged(fn(string $v) => $this->pick('city', $v)),
                Text::make('当前值：' . $this->city)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('② 值 ≠ 显示名（Option 的 value 是身份，label 是给人看的字）'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                Select::make([
                    Option::make('一等座', 'A'),
                    Option::make('二等座', 'B'),
                    Option::make('商务座', 'C'),
                ])->value($this->plain)->placeholder('请选座席')
                    ->onChanged(fn(string $v) => $this->pick('plain', $v)),
                Text::make($this->plain === '' ? '还没选' : '收到值：' . $this->plain)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('③ 宽控件 · triggerWidth 定宽（标签靠左，没有下拉箭头）'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
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
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                Select::make(self::manyOptions())->value($this->many)->placeholder('请选择编号')
                    ->onChanged(fn(string $v) => $this->pick('many', $v)),
                Text::make($this->many === '' ? '还没选' : '收到值：' . $this->many)->caption(),
            ]))->paddingAll(12.0),
            Text::make('打开后把光标移进面板，用滚轮往下翻 —— 第 30 项一开始在面板下沿之外，'
                . '不限高就永远点不到它。')->caption(),

            $this->sectionBar('⑤ 映射短形式 · 键 = 值、值 = 显示名（`Option::normalize()`）'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
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

            $this->sectionBar('⑥ 行数组 · `Option::fromRows($rows, \'name\', \'id\')`（DB / HTTP 的形状）'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                // 这段 $rows 就是 DB fetchAll / HTTP JSON 解出来的东西，原样喂进去。
                Select::make(Option::fromRows(self::cityRows(), 'name', 'id'))
                    ->value($this->row)->placeholder('请选城市')
                    ->onChanged(fn(string $v) => $this->pick('row', $v)),
                Text::make($this->row === '' ? '还没选' : '收到值：' . $this->row)->caption(),
            ]))->paddingAll(12.0),
            Text::make('对应 WPF 的 `DisplayMemberPath` / antd 的 `fieldNames`。区别在失败形态：'
                . '那两家字段名拼错是**静默**的（整列空白），这里缺字段直接 Fatal 并报出'
                . '「哪一行、缺哪个字段、现有哪几列」。')->caption(),

            $this->sectionBar('⑦ 远程三态 · 加载中 / 失败 / 空表'),
            Card::make(Column::make()->spacing(10.0)->children([
                Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                    Select::make($this->remote)
                        ->value($this->remoteValue)
                        ->placeholder('请选择城市')
                        // ★ 定宽：不定宽时占位文字会把框撑成两行（Row 只给内容宽）
                        ->triggerWidth(200.0)
                        ->status($this->remoteStatus)
                        ->onOpen(function (): void {
                            // ★ 只在**要开面板**那一刻触发一次（不是每次应用重建）
                            $this->setState(function (): void {
                                $this->openCount++;
                            });
                        })
                        ->onChanged(fn(string $v) => $this->setState(function () use ($v): void {
                            $this->remoteValue = $v;
                        })),
                    Text::make($this->remoteValue === '' ? '还没选' : '收到值：' . $this->remoteValue)
                        ->caption(),
                    Text::make('onOpen 触发 ' . $this->openCount . ' 次')->caption(),
                ])->alignment(FlexAlign::Center),
                Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                    Button::make('加载中')->variant(ButtonVariant::Flat)
                        ->onPressed(fn() => $this->setRemoteState('loading')),
                    Button::make('加载完成（3 项）')->variant(ButtonVariant::Flat)
                        ->onPressed(fn() => $this->setRemoteState('ok')),
                    Button::make('加载失败')->variant(ButtonVariant::Flat)
                        ->onPressed(fn() => $this->setRemoteState('error')),
                    Button::make('空结果')->variant(ButtonVariant::Flat)
                        ->onPressed(fn() => $this->setRemoteState('empty')),
                ]),
            ]))->paddingAll(12.0),
            Text::make('三态是**一个值**（`OptionStatus::loading() / error() / empty() / '
                . 'ready()`），不是三个开关 —— 所以没有"同时成立时显示谁"这种问题。')->caption(),
            Text::make('三态全是受控属性（什么时候算加载完只有应用知道），'
                . '控件只负责"显示成什么样"。')->caption(),
            Text::make('★ 面板是**第二棵根渲染树**：主树重建不带动它 ⇒ 三态能跨树生效，'
                . '靠的是框架的 `overlaySignature()`（控件声明一个内容签名，框架在锚点'
                . 'Widget 换了之后比对，变了就标脏面板）。')->caption(),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① 方向键换值：**有了** —— 面板打开后 ↓/↑ 移高亮（到顶'
                . '环绕到末项）、Enter 提交。做法是"高亮 + Enter"而不是"方向键移焦点"：'
                . '后者要新开一条"复合控件内方向键"的框架缝，而焦点/手势是本仓出过最多'
                . ' bug 的地方，为一个 nice-to-have 动它不划算。')->caption(),
            Text::make('② 下拉箭头：没有 —— `Button` 的标签位只收字符串，要画箭头得再包一层'
                . ' `Row`，那会多出一个焦点目标。')->caption(),
            Text::make('③ 滚动：有了 —— 面板内容套 `ScrollView` + '
                . '`PopupPanel::maxHeight(260)`，"贴着内容还是滚动"由实测自然高决定，不按条目数猜。'
                . '超过一屏仍建议应用自己做搜索 / 分页（几百项靠滚屏找仍然慢，且没有虚拟化）。')->caption(),
            Text::make('④ 可编辑 / 多选：没有 —— 那是 `ComboBox` 与 `list<string>` 受控值，形状不同。')->caption(),
            Text::make('⑤ 面板宽度不与控件等宽：面板按内容 + 最小宽 180 自尺寸。要等宽得给'
                . ' `placeEntry()` 加最小宽约束（现在只有最大宽）。')->caption(),
        ]);
    }

    /** @return list<array{id: int, name: string}> 假装是 DB fetchAll 的结果 */
    private static function cityRows(): array
    {
        return [
            ['id' => 110000, 'name' => '北京市'],
            ['id' => 310000, 'name' => '上海市'],
            ['id' => 440100, 'name' => '广州市'],
        ];
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
            } elseif ($which === 'row') {
                $this->row = $value;
            } else {
                $this->wide = $value;
            }
        });
    }

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
