<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\ScrollView;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Layout\FlexAlign;
use Drift\Widget\Offstage;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * 页 4「表单 Form」—— **LayUI 文档页（layui.dev/docs/2/form/）的结构复刻**。
 *
 * 原版结构：H1 主标题 + 引用导语 → H2「示例」→ H3 小节（综合演示 / 方框风格 /
 * 登录表单）→ 每个示例是**圆角白底卡片**（浅边框 + 浅灰预览底 + 右上 `</>` 源码
 * 切换）→ 表单元素是**水平排布**（标签固定宽 ~90px 右对齐，输入框撑满）。
 *
 * ★★★ **两处如实暴露的缺口**（示例台的价值就在这 —— 不假动作）：
 *   ① **通用卡片面板缺席**：盒样式（background/border/radius）只有
 *      `BoxWidget` 子类有，而它的子类今天只有 Button / AppBar / PopupPanel
 *      ⇒ LayUI 的圆角卡片**当前做不出通用容器**（要嘛把每块内容塞进 Button，
 *      要嘛新增控件）。本页用 AppBar 横条做分节标题来近似。
 *   ② **水平表单标签缺席**：`TextField` 的 label 在**字段上方**（caption），
 *      不是 LayUI 的左侧右对齐标签；且框架**没有 SizedBox** ⇒ 固定宽标签做不到
 *      ⇒ 本页用 `Row(cross:End)` + `Text(caption)` 近似"标签 + 输入框"一行。
 *
 * ★ 预览/代码切换（LayUI 的 `</>`）**做得到**：`Offstage`（保留状态、不画）
 *   + `setState` 切换可见性 —— 本页演示这一条。
 */
final class FormPage extends StatefulWidget
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
        return new FormState();
    }
}

final class FormState extends State
{
    private string $name = '';

    private string $email = '';

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
            Row::make()->spacing(8.0)->children([
                TextField::make()->label('手机号')->placeholder('11 位手机号'),
                TextField::make()->label('验证码')->placeholder('6 位数字'),
                Button::make('获取验证码')->variant(ButtonVariant::Default),
            ]),

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
        $kids = [Text::make($title)->heading()];
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
            Row::make()->spacing(8.0)->children([
                Button::make('确认提交')->variant(ButtonVariant::Primary),
                Button::make('重置')->variant(ButtonVariant::Default),
            ]),
        ]);
    }

    /**
     * 一行字段：`Row(cross:End)` 让标签与输入框**底部对齐**、标签用 caption
     * —— 对 LayUI"标签右对齐固定宽"的最接近近似（缺 SizedBox，见类 docblock ②）。
     */
    private function field(string $label, Widget $input): Widget
    {
        return Row::make()->spacing(8.0)->children([
            Text::make($label)->caption(),
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
