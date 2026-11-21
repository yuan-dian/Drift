<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;
use App\Showcase\Stores\RemoteSearchStore;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\ComboBox;
use Drift\Widget\Option;
use Drift\Widget\OptionStatus;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 可编辑下拉 ComboBox。
 *
 * 与 `Select` 的差别只有一条：**框里能打字**。点一下 ⇒ 框变搜索框、列出全部候选；
 * 打字 ⇒ 实时过滤；Enter 或点一项 ⇒ 选中，框回到新标签。
 *
 * 五段演示：
 *
 *   ① 基本：四个城市，打字过滤（"北" ⇒ 北京市 / 北京南站两项）；
 *   ② 值 ≠ 显示名：`Option::make('北京市', '110000')`；
 *   ③ 宽控件：`triggerWidth()` 定宽；
 *   ④ 30 项：面板限高 260 + 可滚 + 打字过滤（几百项也能靠打字收敛）；
 *   ⑤ **远程搜索**：`onSearch` + `status` —— 选项表由"服务端"给，本地过滤让位。
 *
 * ★ 应用侧只有五个链式方法：`options / value / placeholder / onChanged`，
 *   远程模式再加 `status / onOpen / onSearch`。开面板、落位、点外面关、ESC 关、
 *   控件卸载自动收尸全在框架（`Widget::overlayPanel()`，与 `Select` / Tooltip
 *   同一类声明式浮层）。
 * ★ 页末如实记录能力边界 —— 尤其是"方向键换高亮没有"以及为什么。
 */
#[Route(id: 'combo', title: '可编辑 Combo', icon: IconName::Search)]
final class ComboBoxPage extends ShowcasePage
{
    private string $city = '';

    private string $plain = '';

    private string $wide = '';

    private string $many = '';

    // ── ⑤ 远程搜索那一段的状态 ──

    private string $remote = '';

    /** @var list<Option> "服务端"返回的结果（示例台不联网，到达时刻由 `after()` 模拟） */
    private array $remoteOptions = [];

    private OptionStatus $remoteStatus;

    /** `onSearch` 最后一次收到的词 */
    private string $remoteQuery = '';

    /** `onSearch` 被调了几次（证明"每改一次词回调一次"） */
    private int $remoteCalls = 0;

    /** 查询仲裁：只有最后一次查询的响应能落地（页面级 UI 态，随页销毁） */
    private RemoteSearchStore $remoteSearch;

    #[\Override]
    public function initState(): void
    {
        $this->remoteStatus = OptionStatus::ready();
        $this->remoteSearch = new RemoteSearchStore();
    }

    /** @return list<Option> 四个城市（"北京市" / "北京南站" 都含"北"） */
    private static function cities(): array
    {
        return [
            Option::make('北京市', '110000'),
            Option::make('上海市', '310000'),
            Option::make('广州市', '440100'),
            Option::make('北京南站', '110100'),
        ];
    }

    /**
     * 发起一次"远程搜索"：立刻进加载态，**400ms 后**结果到达（模拟服务端）。
     *
     * ★ 这就是真应用的形状：`onSearch` 里 `status(loading())` + 发请求，
     *   `Job::onDone` 里 `status(ready())` + 换选项表。控件侧一行都不用改。
     * ★ 示例台用组合根递进来的 `after()` 排那 400ms —— 控件自己做不到（§4.3）。
     * ★ 每次查询先向 Store 领一个 attempt，定时器闭包捕获它；快连打时旧 attempt
     *   的响应到达会被 `arrive()` 挡掉，只剩最后一次落地 —— 兑现本页文案承诺的
     *   "应用比对请求序号丢弃过期响应"。
     */
    private function search(string $query): void
    {
        $attempt = $this->remoteSearch->query();
        $this->setState(function () use ($query): void {
            $this->remoteQuery = $query;
            $this->remoteCalls++;
            $this->remoteStatus = OptionStatus::loading();
        });

        ($this->delayClosure())(400, function () use ($attempt): void {
            $this->deliver($attempt);
        });
    }

    /**
     * 模拟"服务端响应到达"。
     *
     * ★ `$attempt` 决定是否落地：号已被后续查询作废 ⇒ 直接丢弃，绝不覆盖界面上
     *   更新的查询（也就是本页文案里承诺的"丢弃过期响应"，过去只是文字）。
     *
     * 注意过滤发生**在应用侧**：`onSearch` 一旦声明，控件就不再按标签筛
     * （服务端可能按拼音 / 按 value 匹配，本地再筛一遍是二次收缩）。
     * 这里故意照抄"标签子串"的算法，好让"两边筛的结果一样"不至于骗过眼睛 ——
     * 真要验证"本地没再筛"，看 smoke 里那条含不匹配项的用例。
     */
    private function deliver(int $attempt): void
    {
        if (!$this->remoteSearch->arrive($attempt)) {
            return;
        }

        $needle = \mb_strtoupper($this->remoteQuery, 'UTF-8');
        $out = [];
        foreach (self::cities() as $option) {
            if ($needle === '' || strpos(\mb_strtoupper($option->label, 'UTF-8'), $needle) !== false) {
                $out[] = $option;
            }
        }

        $this->setState(function () use ($out): void {
            $this->remoteOptions = $out;
            $this->remoteStatus = $out === [] ? OptionStatus::empty('没有匹配的城市') : OptionStatus::ready();
        });
    }

    /**
     * ⑤ 段用它排 400ms 延时模拟"服务端响应"。
     *
     * 控件侧够不到定时器（§4.3 禁 `Widget → App`）⇒ 由组合根把 scheduler 递到
     * 能力束，这里只把它取成闭包。
     */
    private function delayClosure(): \Closure
    {
        $caps = $this->caps;
        return static function (int $milliseconds, \Closure $callback) use ($caps): void {
            $caps->delay($milliseconds, $callback);
        };
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
            Text::make('可编辑下拉 ComboBox')->title(),
            Text::make('点一下框 ⇒ 框变成搜索框并列出全部候选；打字实时过滤；'
                . 'Enter 取第一项、或直接点一项。受控：值住应用的 State。')->caption(),

            $this->sectionBar('① 基本 · 打字过滤（试试打「北」：剩北京市 / 北京南站）'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
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
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                ComboBox::make([
                    Option::make('一等座', 'A'),
                    Option::make('二等座', 'B'),
                    Option::make('商务座', 'C'),
                ])->value($this->plain)->placeholder('请选座席')
                    ->onChanged(fn(string $v) => $this->pick('plain', $v)),
                Text::make($this->plain === '' ? '还没选' : '收到值：' . $this->plain)->caption(),
            ]))->paddingAll(12.0),

            $this->sectionBar('③ 宽控件 · triggerWidth 定宽'),
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
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
            Card::make(Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                ComboBox::make(self::manyOptions())->value($this->many)->placeholder('输入编号')
                    ->onChanged(fn(string $v) => $this->pick('many', $v)),
                Text::make($this->many === '' ? '还没选' : '收到值：' . $this->many)->caption(),
            ]))->paddingAll(12.0),
            Text::make('打「2」⇒ 只剩选项 02 / 12 / 20…29；再打「9」⇒ 只剩 29。'
                . '候选多到一屏装不下时，打字过滤比滚屏快。')->caption(),

            $this->sectionBar('⑤ 远程搜索 · onSearch + status（示例台不联网：服务端响应由 after() 延时模拟）'),
            Card::make(Column::make()->spacing(8.0)->children([
                Wrap::make()->runSpacing(12.0)->spacing(12.0)->children([
                    ComboBox::make($this->remoteOptions)
                        ->value($this->remote)
                        ->placeholder('输入城市名搜索')
                        ->triggerWidth(220.0)
                        ->status($this->remoteStatus)
                        // ★ 开面板那一刻取**初始列表**（真应用在这里发请求）——
                        //   等价于"搜索空串"，示例台照样走那 400ms 的延时。
                        ->onOpen(fn() => $this->search(''))
                        // ★ 每改一次搜索词回调一次（控件**不去抖**，调度归应用）
                        ->onSearch(fn(string $query) => $this->search($query))
                        ->onChanged(function (string $value): void {
                            $this->setState(function () use ($value): void {
                                $this->remote = $value;
                            });
                        }),
                    Text::make($this->remote === '' ? '还没选' : '当前值：' . $this->remote)->caption(),
                ]),
                Text::make('onSearch 已收到 ' . $this->remoteCalls . ' 次，最后一次的词是「'
                    . $this->remoteQuery . '」。')->caption(),
            ]))->paddingAll(12.0),
            Text::make('点开面板 ⇒ 立刻"正在加载…"、400ms 后列出全部 4 项（`onOpen` 取初始列表）；'
                . '打字「北」⇒ 又进加载态、400ms 后只剩北京市 / 北京南站。'
                . '那 400ms 是组合根递进来的 `Application::after()` —— 控件自己够不到定时器（§4.3）。')->caption(),
            Text::make('远程模式下**本地过滤让位**：选项表就是服务端的结果，控件不再按标签'
                . '筛一遍（服务端可能按拼音 / 按 value 匹配，再筛是二次收缩）。')->caption(),
            Text::make('★ 控件**不做去抖** —— 定时器只有 Application 有（§4.3 禁 Widget → App）。'
                . '契约定为"每改一次词回调一次、且带上当时的词"，应用自己比对词来丢弃'
                . '过期响应（Job + 请求序号）。')->caption(),

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
            Text::make('⑤ 自定义过滤：本地过滤固定是"标签的大小写不敏感子串匹配"，换不了算法；'
                . '要按拼音 / 按 value 匹配就走 `onSearch`（选项表整个由应用给），见上面第 ⑤ 段。'
                . '—— 2026-10-24 之前这里写的是"得自己过滤后传进来"，那是绕路，现在是正式形状。')->caption(),
            Text::make('⑥ 打字时**不会**自动弹出面板：开面板只有"点击 / Enter"两条路（浮层栈只有'
                . '框架拿得到，控件侧够不着）。所以"Tab 进来直接打字"时看不到候选，'
                . '得再点一下或按 Enter。')->caption(),
            Text::make('⑦ 去抖：没有，见第 ⑤ 段最后一条（分工：控件报告时机与事实，调度归应用）。')->caption(),
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
        return Text::make($title)->heading();
    }
}
