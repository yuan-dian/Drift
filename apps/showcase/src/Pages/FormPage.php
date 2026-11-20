<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\ScrollView;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Checkbox;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Layout\FlexAlign;
use Drift\Widget\Offstage;
use Drift\Widget\Padding;
use Drift\Widget\Radio;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\Text;
use Drift\Widget\TextArea;
use Drift\Widget\TextField;
use Drift\Widget\Toggle;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 表单 Form—— LayUI 文档页（layui.dev/docs/2/form/）的结构复刻。
 *
 * ★ 曾记的两处缺口今天都已闭合：通用圆角容器就是 `Card`（或 `SizedBox` 加
 *   `->background()` / `->radius()`；修饰挂在根 `Widget` 上，不是某个盒样式基类
 *   的特权）；固定宽标签在 `SizedBox` 到位后也做得出，本页 `field()` 仍是
 *   `Row(cross:End)` 的历史写法。仍缺的只有 label 位置，见页末对照。
 * ★ 预览/代码切换靠 `Offstage` + `setState`：保留状态、不画。
 */
#[Route(id: 'form', title: '表单 Form', icon: IconName::Check)]
final class FormPage extends ShowcasePage
{
    private string $name = '';

    private string $email = '';

    /** 文本编辑演示（#51）：源字段预填中英混排（复制过去不分家），目标字段接粘贴 */
    private string $editSrc = '张三 zhangsan@example.com';

    private string $editDst = '';

    /** 选择类控件的受控值（Checkbox / Radio / Toggle 的真实消费者） */
    private bool $agree = true;

    /** 多行输入的受控值（`TextArea` 的消费者） */
    private string $note = "第一行\n第二行\n第三行\n第四行\n第五行\n第六行\n第七行 —— 超过框高，右缘有滚动条";

    /** `Ctrl+Enter` 提交后回显的文本（提交与换行是两件事，本页演示这条分界） */
    private string $submittedNote = '';

    /** 单行框 `Enter` 提交（`onSubmitted()`）的受控值 / 回显 */
    private string $query = '';
    private string $submittedQuery = '';

    /** 字段校验演示：错误文案是**受控**的，应用给什么就显示什么 */
    private string $checkedName = '';
    private string $checkedEmail = '';
    private string $checkedNick = '';
    private string $checkedNameError = '';
    private string $checkedEmailError = '';

    private string $size = 'm';

    private bool $notify = false;

    /** true = 显示源码视图（LayUI 的 `</>` 切换） */
    private bool $showCode = false;

    public function build(BuildContext $ctx): Widget
    {
        // ★ 页面 = **一整块白色面板 + 面板内滚动**（LayUI 文档页的形态）：
        //   卡片撑满视口高、内容在卡片里滚。反过来（卡片只包内容）会让卡片只占
        //   上半屏，下半屏露出灰底 —— 看着像"没加载完"。
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    /** 页面内容（`build()` 只负责套一层卡片外壳，与 `LayoutPage` 的"每块一张卡"互补） */
    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('表单 Form')->title(),
            Text::make('复刻 LayUI 文档页（layui.dev/docs/2/form/）的结构：标题层级 + 分节 + 预览/源码切换。两处能力缺口见页末。')
                ->caption(),

            // ── H2「示例」：预览/源码切换（LayUI 的 </>）──
            $this->sectionBar('综合演示', trailing: $this->codeToggle()),
            Offstage::make($this->codeView(), !$this->showCode),
            Offstage::make($this->previewForm(), $this->showCode),

            // ── H3「并排短字段」：LayUI 的 layui-inline ──
            $this->sectionBar('并排短字段（LayUI 的 inline）'),
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                TextField::make()->label('手机号')->placeholder('11 位手机号'),
                TextField::make()->label('验证码')->placeholder('6 位数字'),
                Button::make('获取验证码')->variant(ButtonVariant::Default),
            ]),

            // ── H3「文本编辑命令」：选择 / 复制 / 剪切 / 粘贴 ──
            //   演示 §9.1 的编辑命令环 + 指针框选：Shift+方向键或**拖动**选择，
            //   Ctrl+A 全选 / Esc 取消，Ctrl+C/X/V 走系统剪贴板（组合根递端口）。
            //   源字段预填中英混排 —— 多字节选区复制过去不分家（按码位边界算）。
            $this->sectionBar('文本编辑（选择 / 复制 / 粘贴）'),
            Text::make('点进字段 ⇒ Shift+方向键或拖动选择 ⇒ Ctrl+C 复制 ⇒ 点另一个字段 ⇒ Ctrl+V 粘贴；Ctrl+A 全选、Esc 取消选择。')
                ->caption(),
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                TextField::make()->label('源（中英混排）')->value($this->editSrc)
                    ->onChanged(fn(string $v) => $this->editSrc = $v),
                TextField::make()->label('目标')->value($this->editDst)
                    ->onChanged(fn(string $v) => $this->editDst = $v),
            ]),

            // ── H3「多行输入」：TextArea ──
            //   与 TextField 是**两个控件**（不是 maxLines 开关）：单行盒的插入符
            //   几何靠 `advance(前缀)` 一次量得，多行必须逐字形累加 + 行号 + 垂直偏移。
            //   演示四件事：Enter 换行 / Ctrl+Enter 提交 / 上下键跨行保持列 /
            //   右缘滚动条（固定高度才看得见 —— 不限高时它会按内容长）。
            $this->sectionBar('多行输入（TextArea）'),
            Text::make('Enter 换行、Ctrl+Enter 提交；上下方向键跨行时保持列。内容超过框高 ⇒ 右缘出现滚动条（可拖、可滚轮，停手后淡下去）。')
                ->caption(),
            TextArea::make()
                ->label('备注')
                ->placeholder('多行文本')
                ->value($this->note)
                ->minLines(4)
                ->lines(5)
                ->onChanged(fn(string $v) => $this->note = $v)
                ->onSubmitted(function (string $v): void {
                    $this->setState(function () use ($v): void {
                        $this->submittedNote = $v;
                    });
                }),
            Text::make($this->submittedNote === '' ? 'Ctrl+Enter 之后这里显示提交的文本' : '已提交：' . str_replace("\n", ' / ', $this->submittedNote))
                ->caption(),

            // ── H3「单行输入」：Enter 提交 ──
            //   单行框里 Enter 没有本职 ⇒ 声明 onSubmitted() 即可提交；
            //   多行框的 Enter 是换行，提交才退到 Ctrl+Enter（上一节）。
            $this->sectionBar('单行输入：Enter 提交'),
            Text::make('单行框里 Enter 没有本职 ⇒ 声明 onSubmitted() 即可提交；不声明则不吞键（浮层开合、加速键还看得见它）。')
                ->caption(),
            TextField::make()
                ->label('搜索')
                ->placeholder('输入关键词后按 Enter')
                ->value($this->query)
                ->onChanged(function (string $v): void {
                    // UIA SetValue 与键盘输入走同一条 onChanged；不 setState 的话
                    // 受控值、语义树与 TextChanged 事件都不会更新。
                    $this->setState(function () use ($v): void {
                        $this->query = $v;
                    });
                })
                ->onSubmitted(function (string $v): void {
                    $this->setState(function () use ($v): void {
                        $this->submittedQuery = $v;
                    });
                })
                ->semanticsMeta(
                    hint: '输入关键词后按 Enter 提交',
                    automationId: 'form.search',
                ),
            Text::make($this->submittedQuery === '' ? '回车之后这里显示提交的词' : '已提交：' . $this->submittedQuery)
                ->caption(),

            // ── H3「字段校验 + 长度约束」──
            //   错误态是表现（红边 + 红字）；校验规则仍住应用（下面那个「校验」按钮）。
            $this->sectionBar('字段校验与长度约束'),
            Text::make('错误文案由应用给（受控）：点「校验」⇒ 空字段红边 + 下方出字；第三个字段上限 8 个码位，右下角实时计数。')
                ->caption(),
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                TextField::make()->label('姓名（必填）')
                    ->value($this->checkedName)
                    ->error($this->checkedNameError)
                    ->helper('登录后显示')
                    ->onChanged(fn(string $v) => $this->checkedName = $v),
                TextField::make()->label('邮箱（必填）')
                    ->value($this->checkedEmail)
                    ->error($this->checkedEmailError)
                    ->onChanged(fn(string $v) => $this->checkedEmail = $v),
                TextField::make()->label('昵称（最多 8 字）')
                    ->value($this->checkedNick)
                    ->maxLength(8)
                    ->onChanged(fn(string $v) => $this->checkedNick = $v),
            ]),
            Button::make('校验')->variant(ButtonVariant::Primary)
                ->onPressed(function (): void {
                    $this->setState(function (): void {
                        $this->checkedNameError = $this->checkedName === '' ? '姓名不能为空' : '';
                        $this->checkedEmailError = str_contains($this->checkedEmail, '@') ? '' : '邮箱格式不正确';
                    });
                }),

            // ── H3「选择类控件」：Checkbox / Radio / Toggle ──
            //   三个控件都是**受控**的：值住 State，点击只回调新值（与 TextField 同纪律）。
            $this->sectionBar('选择类控件（Checkbox / Radio / Toggle）'),
            Card::make(Column::make()->spacing(10.0)->children([
                Checkbox::make('我已阅读并同意服务条款')
                    ->checked($this->agree)
                    ->onChanged(function (bool $on): void {
                        $this->setState(function () use ($on): void {
                            $this->agree = $on;
                        });
                    }),
                Wrap::make()->runSpacing(20.0)->spacing(20.0)->children([
                    Radio::make('小号')->value('s')->group($this->size)
                        ->onChanged(function (string $v): void {
                            $this->setState(function () use ($v): void {
                                $this->size = $v;
                            });
                        }),
                    Radio::make('中号')->value('m')->group($this->size)
                        ->onChanged(function (string $v): void {
                            $this->setState(function () use ($v): void {
                                $this->size = $v;
                            });
                        }),
                    Radio::make('大号')->value('l')->group($this->size)
                        ->onChanged(function (string $v): void {
                            $this->setState(function () use ($v): void {
                                $this->size = $v;
                            });
                        }),
                ]),
                Toggle::make('接收版本更新通知')
                    ->checked($this->notify)
                    ->onChanged(function (bool $on): void {
                        $this->setState(function () use ($on): void {
                            $this->notify = $on;
                        });
                    }),
                Checkbox::make('禁用示例（点了没反应，也不进 Tab 序）')
                    ->checked(true)
                    ->enabled(false),
                Text::make(
                    '当前值：同意=' . ($this->agree ? '是' : '否')
                    . ' · 尺寸=' . $this->size
                    . ' · 通知=' . ($this->notify ? '开' : '关')
                )->caption(),
            ]))->paddingAll(12.0),

            // ── H3 诚实边界：本页做不到的两处 ──
            $this->sectionBar('当前能力边界（如实记录）'),
            Text::make('① 通用卡片面板：已补（2026-10-17 的 Card / RenderCard）—— 本页外层就是一张 Card；此前只能用 AppBar 横条近似分节。')
                ->caption(),
            Text::make('② 固定宽右对齐标签：已补（2026-10-17 的 SizedBox）—— 定尺寸原语到位，LayUI 的 90px 右对齐标签现在做得出；本页仍用 Row(cross:End) 是历史写法，未改。')
                ->caption(),
            Text::make('③ 仍缺：TextField 的 label 只有"字段上方"一种位置（§A.2.9 的形态），右侧标签要自己拼 Row。')
                ->caption(),
        ]);
    }

    /** 分节标题条（LayUI 的 H2/H3 + 卡片标题行的组合近似） */
    private function sectionBar(string $title, ?Widget $trailing = null): Widget
    {
        // ★ 标题用 `Expanded` 占满剩余宽并换行（窄屏不再把 trailing 挤出去）；
        //   `Spacer` 在 `Expanded` 之后自然塌成 0，等价于"标题贴左 / trailing 贴右"。
        $kids = [Expanded::make(Text::make($title)->heading())];
        if ($trailing !== null) {
            $kids[] = Spacer::make();
            $kids[] = $trailing;
        }

        return Row::make()->spacing(8.0)->children($kids)->cross(FlexAlign::Center);
    }

    /** LayUI 卡片右上角的 `</>` 按钮 */
    private function codeToggle(): Widget
    {
        return Button::make($this->showCode ? '看效果' : '</> 看源码')
            ->variant(ButtonVariant::Default)
            ->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->showCode = !$this->showCode;
                });
            });
    }

    /** 预览区：标签 + 输入框 + 按钮组（近似水平表单） */
    private function previewForm(): Widget
    {
        return Column::make()->spacing(10.0)->children([
            $this->field('姓名', TextField::make()
                ->placeholder('请输入姓名')
                ->value($this->name)
                ->onChanged(fn(string $v) => $this->name = $v)),
            $this->field('邮箱', TextField::make()
                ->placeholder('请输入邮箱')
                ->value($this->email)
                ->onChanged(fn(string $v) => $this->email = $v)),
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('确认提交')->variant(ButtonVariant::Primary),
                Button::make('重置')->variant(ButtonVariant::Default),
            ]),
        ]);
    }

    /**
     * 一行字段：`Row(cross:End)` 让标签与输入框**底部对齐**、标签用 caption
     * —— 对 LayUI"标签右对齐固定宽"的最接近近似（历史写法；SizedBox 已补，见类 docblock ②）。
     */
    private function field(string $label, Widget $input): Widget
    {
        return Row::make()->spacing(8.0)->children([
            Expanded::make(Text::make($label)->caption()),
            $input,
        ])->cross(FlexAlign::End);
    }

    /** 源码视图（LayUI `</>` 展开后那块：等宽 + 浅底的代码区） */
    private function codeView(): Widget
    {
        return Padding::make(
            Column::make()->spacing(4.0)->children([
                Text::make('Row(cross:End) + Text(caption) 近似水平表单：')->caption(),
                Text::make('01  $this->field(\'姓名\', TextField::make()')->caption(),
                Text::make('02      ->placeholder(\'请输入姓名\')')->caption(),
                Text::make('03      ->onChanged(fn(string $v) => $this->name = $v));')->caption(),
                Text::make('04  // label(\'\') 置空：标签在上方已由 field() 给，字段内不重复画')->caption(),
            ])
        )->all(8.0);
    }
}
