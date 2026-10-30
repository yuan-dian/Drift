<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Platform\Dialog;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 页 30「原生对话框 Dialog」—— A5-① 的唯一消费者。
 *
 * ★★ **为什么这一页必须存在**：`Dialog` 端口在框架里躺了一个多月
 *   （`Win32Platform::dialog()` 一直返回 `null`）。端口加方法而没人调用是
 *   "未实现"的一种伪装 —— 这一页是它唯一的调用点，也是 T2 真机取证的落点。
 *
 * ★ **四个方法各给一个按钮**，因为它们的行为**真不一样**：`openFile` /
 *   `saveFile` 返回路径（取消 ⇒ `null`），`alert` 无返回，`confirm` 返回 bool
 *   且默认按钮是"否"。合起来一个"对话框演示"会把三种返回值语义糊成一团。
 *
 * ★ **结果写在页面上而不是 Snackbar 上**：`Snackbar` 会自己消失，而这一页要
 *   证明的是"端口到底递回了什么"（尤其是**取消**这一支）。
 *
 * ★ **按钮回调里直接调**（同步阻塞）是**正确写法**，不是偷懒：原生对话框是模态的，
 *   它没有"完成事件"可挂。代价写在「能力边界」第 ① 条里。
 *
 * ★ `$dialog` **由组合根递进来**（`ShowcaseRoot` → 本页）：`State` 上没有
 *   `application()` 访问器（§4.3 禁 `Widget → App`），平台名只出现在组合根。
 *   ★ 形参**不可空**：`?Dialog` 字段在 AOT 侧退化 `php::Var`（PITFALLS 第 20 条）。
 */
final class DialogPage extends StatefulWidget
{
    /**
     * @param \Closure(bool): void $setCloseConfirm 「关闭前确认」开关（应用侧接线）
     */
    public function __construct(private Dialog $dialog, private \Closure $setCloseConfirm)
    {
        parent::__construct();
    }

    public static function make(Dialog $dialog, \Closure $setCloseConfirm): self
    {
        return new self($dialog, $setCloseConfirm);
    }

    public function createState(): State
    {
        return new DialogPageState($this->dialog, $this->setCloseConfirm);
    }
}

final class DialogPageState extends State
{
    /** 最近一次拿到的路径（空串 + `$hasPath` 代替 `?string` 字段，AOT 纪律） */
    private string $path = '';

    private bool $hasPath = false;

    /** 最近一次"取消"发生过（证明 `null` 这一支真的走到了） */
    private bool $canceled = false;

    /** `confirm()` 的两种答案各点了几次 */
    private int $yes = 0;

    private int $no = 0;

    /**
     * 「关闭前确认」的**显示用镜像**（真相在 `Application` 的字段里）。
     *
     * ★ 为什么这里要留一份：页面拿不到 `Application`（§4.3），而按钮文案与状态行
     *   需要知道当前是开还是关。★ 它只影响**显示** —— 真正决定"关不关"的是
     *   `Application::onCloseRequested()`，`setState()` 之后两边恒一致。
     */
    private bool $confirmClose = false;

    /**
     * @param \Closure(bool): void $setCloseConfirm
     */
    public function __construct(private Dialog $dialog, private \Closure $setCloseConfirm)
    {
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('原生对话框 Dialog')->title(),
            Text::make('四个方法全是**原生模态**：选已有文件 / 选保存位置 / 单键提示 / 是-否确认。')
                ->caption(),

            $this->sectionBar('① 选文件 · GetOpenFileNameW'),
            Card::make(Column::make()->spacing(10.0)->children([
                Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                    Button::make('打开一个文件')
                        ->variant(ButtonVariant::Primary)
                        ->onPressed(fn() => $this->pickFile()),
                    Button::make('选保存位置')
                        ->variant(ButtonVariant::Flat)
                        ->onPressed(fn() => $this->pickSaveTarget()),
                ]),
                Text::make($this->pickResult()),
            ]))->paddingAll(12.0),

            $this->sectionBar('② 提示与确认 · MessageBoxW'),
            Card::make(Column::make()->spacing(10.0)->children([
                Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                    Button::make('弹一个提示')
                        ->variant(ButtonVariant::Flat)
                        ->onPressed(fn() => $this->alert()),
                    Button::make('问一个确认')
                        ->variant(ButtonVariant::Flat)
                        ->onPressed(fn() => $this->ask()),
                ]),
                Text::make('确认的结果：是 ' . $this->yes . ' 次 · 否 ' . $this->no . ' 次'),
            ]))->paddingAll(12.0),
            Text::make('`confirm()` 的默认按钮是**「否」**（`MB_DEFBUTTON2`）：'
                . '破坏性动作的默认不能是「执行」。')
                ->caption(),

            $this->sectionBar('③ 关闭前确认 · setCloseGuard'),
            Card::make(Column::make()->spacing(10.0)->children([
                Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                    Button::make($this->confirmClose ? '关闭「关闭前确认」' : '启用「关闭前确认」')
                        ->variant(ButtonVariant::Flat)
                        ->onPressed(fn() => $this->toggleConfirmClose()),
                ]),
                Text::make('当前：' . ($this->confirmClose
                    ? '开 —— 点标题栏 X 会先问一句，点「否」窗口留着'
                    : '关 —— 点 X 直接退出（与 2026-10-30 之前一样）')),
            ]))->paddingAll(12.0),
            Text::make('关闭**请求**与应用**同意**是两件事：`WM_CLOSE` 入队一条 '
                . '`CloseRequestedEvent`，在**帧边界**问 `Application::onCloseRequested()`；'
                . '同意之后才由框架 `destroy()`。两条路径合一 ⇒ 用户点 X 与应用调 `close()` '
                . '的拦截行为一致。')->caption(),

            $this->sectionBar('能力边界（如实记录）'),
            Text::make('① **模态期间界面不重绘**：系统跑自己的内层消息循环，'
                . '框架一帧都跑不到（`Application::frame()` 不被调用）。这是原生对话框的'
                . '固有语义 ⇒ 不要在动画 / 滚动的回调里调它。')->caption(),
            Text::make('② **失败与取消不可区分**：端口只有 `?string` 两态，而 '
                . '`CommDlgExtendedError()` 非 0 是真失败（内存不足等）。眼下由壳自己'
                . '弹框提示，不新增第三个返回值 —— 没有调用方会为它写分支。')->caption(),
            Text::make('③ **筛选表的编码留在 PHP**（`Win32Dialog::filterText()`：'
                . '每行 `显示名` + `\t` + `通配符`，行间 `\n`）：双 NUL 由 C 侧最后一步拼，'
                . '因为 NUL 穿过 ABI 之后看不出来。')->caption(),
            Text::make('④ **没有多选 / 没有选目录**：`Dialog` 只有单文件两态；'
                . '多选要 `OFN_ALLOWMULTISELECT` + 一套路径分隔解包，没有消费者就不发明。')->caption(),
        ]);
    }

    /**
     * 筛选表：**照抄 `Dialog::openFile()` 文档里的形状**（`[显示名, 通配符]`）。
     *
     * ★ 不用类常量：AOT 对"外部类常量"有前科（求值成 0，见 MEMORY 第 18 条），
     *   而这里是同一个类内的常量 —— 仍然不值得为省两个括号去赌。
     *
     * @return list<array{string, string}>
     */
    private static function filters(): array
    {
        return [
            ['Markdown 文件 (*.md)', '*.md'],
            ['文本文件 (*.txt)', '*.txt'],
            ['所有文件 (*.*)', '*.*'],
        ];
    }

    private function pickFile(): void
    {
        $path = $this->dialog->openFile('打开一个文件', self::filters());

        if ($path === null) {
            $this->setState(function (): void {
                $this->hasPath = false;
                $this->canceled = true;
            });

            return;
        }

        $text = (string) $path;
        $this->setState(function () use ($text): void {
            $this->hasPath = true;
            $this->canceled = false;
            $this->path = $text;
        });
    }

    private function pickSaveTarget(): void
    {
        $path = $this->dialog->saveFile('选保存位置', '未命名.md', self::filters());

        if ($path === null) {
            $this->setState(function (): void {
                $this->hasPath = false;
                $this->canceled = true;
            });

            return;
        }

        $text = (string) $path;
        $this->setState(function () use ($text): void {
            $this->hasPath = true;
            $this->canceled = false;
            $this->path = $text;
        });
    }

    private function alert(): void
    {
        $this->dialog->alert('提示', '这是一个原生 MessageBoxW：它不依赖渲染栈，'
            . '所以应用画不出来的时候它还能弹。');
    }

    /**
     * 翻转「关闭前确认」：**先在应用侧生效，再刷新本页的显示镜像**。
     *
     * ★ 顺序不能反：如果先 `setState` 再接线，应用侧失败（不会发生，但假设）时
     *   页面会显示一个不存在的状态。现在的顺序里"显示"跟着"事实"走。
     * ★ 闭包参数是 `bool`（不是 `?bool` / 三态）：这是一个开关，不是"查询"。
     */
    private function toggleConfirmClose(): void
    {
        $next = !$this->confirmClose;
        ($this->setCloseConfirm)($next);

        $this->setState(function () use ($next): void {
            $this->confirmClose = $next;
        });
    }

    private function ask(): void
    {
        $yes = $this->dialog->confirm('确认', '要执行这个动作吗？默认按钮是「否」。');

        $this->setState(function () use ($yes): void {
            if ($yes) {
                $this->yes++;
            } else {
                $this->no++;
            }
        });
    }

    /** 结果三态：还没选 / 取消 / 拿到路径 —— 三态必须分开显示，混成两态等于没验 */
    private function pickResult(): string
    {
        if ($this->hasPath) {
            return '结果：' . $this->path;
        }

        if ($this->canceled) {
            return '结果：用户取消了（端口递回 `null`，不是空串）';
        }

        return '结果：还没选过';
    }

    private function sectionBar(string $title): Widget
    {
        return Text::make($title)->heading();
    }
}
