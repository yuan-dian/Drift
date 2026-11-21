<?php
declare(strict_types=1);

namespace App\Showcase\Pages;
use App\Showcase\Pages\Enums\StubState;
use Drift\Route\Route;
use Drift\Widget\IconName;
use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\ScrollView;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\Environment;
use Drift\Widget\Divider;
use Drift\Widget\Expanded;
use Drift\Widget\Modal;
use Drift\Widget\Progress;
use Drift\Widget\ErrorPlaceholder;
use Drift\Layout\FlexAlign;
use Drift\Widget\Offstage;
use Drift\Widget\Row;
use Drift\Widget\Spinner;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 反馈 Feedback—— 三态（Loading / Error / Empty）+ Spinner + 模态对话框。
 *
 * 对照 LayUI 的提示体系（提示条 / 加载态 / 空数据）。Drift 的三态用
 * **封闭域 match 无 default**（加一个状态编译期就叫）——
 * 本页把三种状态摆在同一屏，用 `Offstage` 切换（保留各自状态，不销毁）。
 *
 * ★ **模态对话框演示后来补上**：`Modal` 的关闭按钮（`X`）落地后，
 *   而示例台此前**没有任何一处** `Modal::make()` ⇒ 新机制在真机上不可见。
 *   示例台的职责就是"覆盖现有控件面"（见 `ShowcaseRoot` 类注释），所以这里
 *   给它一个入口，顺带把 `dismissible(false)` 的对照也摆上。
 */
#[Route(id: 'feedback', title: '反馈 Feedback', icon: IconName::Bell)]
final class FeedbackPage extends ShowcasePage
{
    private StubState $tab = StubState::Loading;

    /** 三个弹窗都走应用级模态槽 —— 槽位是本窗唯一的，所以不需要另传句柄 */
    public function __construct(private readonly Environment $env)
    {
    }

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
            Text::make('反馈 Feedback')->title(),
            Text::make('加载三态：Loading 走 Spinner，Error 走文案 + 重试，Empty 走空态提示。切 tab 用 Offstage ⇒ 状态保活。')
                ->caption(),

            // ── 状态切换条 ──
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('Loading')
                    ->variant($this->tab === StubState::Loading ? ButtonVariant::Primary : ButtonVariant::Default)
                    ->onPressed(fn() => $this->pick(StubState::Loading)),
                Button::make('Error')
                    ->variant($this->tab === StubState::Error ? ButtonVariant::Primary : ButtonVariant::Default)
                    ->onPressed(fn() => $this->pick(StubState::Error)),
                Button::make('Empty')
                    ->variant($this->tab === StubState::Empty ? ButtonVariant::Primary : ButtonVariant::Default)
                    ->onPressed(fn() => $this->pick(StubState::Empty)),
            ]),

            // ── 三态面板（Offstage = 保留状态不画）──
            $this->panel(StubState::Loading, Column::make()->spacing(8.0)->children([
                Row::make()->spacing(8.0)->children([Spinner::make()])->cross(FlexAlign::Center),
                Text::make('加载中，请候候…')->caption(),
            ])),
            $this->panel(StubState::Error, Column::make()->spacing(8.0)->children([
                Text::make('加载失败')->error(),
                Button::make('重试')->variant(ButtonVariant::Primary),
            ])),
            $this->panel(StubState::Empty, Column::make()->spacing(8.0)->children([
                Text::make('暂无数据')->body(),
                Text::make('空态也是"有信息"的状态，不是白屏。')->caption(),
            ])),

            // ── 错误边界占位（错误边界的视觉半，§A.2.6）──
            Text::make('错误边界占位（RenderErrorBoundary 捕获后换上来的那棵子树）')->heading(),
            ErrorPlaceholder::make('发生了未捕获的错误'),

            // ── 分节原语（Divider / Progress）——
            Text::make('分节原语（Divider / Progress）')->heading(),
            Text::make('Divider 是装饰：不给语义角色，屏幕阅读器不该念它；Progress 给 role=progress + 「58%」读数，值由调用方给，不自持状态。')->caption(),

            Column::make()->spacing(8.0)->children([
                Text::make('账号安全')->body(),
                Divider::make(),
                Text::make('已开启两步验证')->caption(),
            ]),

            Divider::make()->thickness(2.0)->width(240.0),

            Column::make()->spacing(8.0)->children([
                Row::make()->spacing(8.0)->children([
                    Text::make('磁盘占用'),
                    Expanded::make(Progress::make(0.58)),
                    Text::make('58%'),
                ]),
                Row::make()->spacing(8.0)->children([
                    Text::make('同步中'),
                    Expanded::make(Progress::make(0.24)),
                    Text::make('24%'),
                ]),
            ]),

            Row::make()->spacing(8.0)->children([
                Text::make('0%'),
                Expanded::make(Progress::make(0.0)),
                Text::make('100%'),
                Expanded::make(Progress::make(1.0)),
            ]),

            // ── 模态对话框（§A.3.7）──
            // ★ 右上角那个 X 的处理器由 `OverlayManager::showModal()` **注入**
            //   （`Modal::bindDismiss()`），不是控件自己关自己 —— 与
            //   `SnackbarPanel::make($s, $onDismiss)` 同一条模式。
            Text::make('模态对话框 Modal')->heading(),
            Text::make('左：可关闭（X + ESC + 点遮罩三条路）。右：dismissible(false) —— '
                . '三条路全被吞掉，且**不画 X**（画一个点了没反应的 X 比没有更糟）。')
                ->caption(),
            Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                Button::make('打开对话框')
                    ->variant(ButtonVariant::Primary)
                    ->onPressed(fn() => $this->openConfirm()),
                Button::make('打开不可关闭的对话框')
                    ->onPressed(fn() => $this->openStubborn()),
                Button::make('打开带输入的对话框')
                    ->onPressed(fn() => $this->openRename()),
            ]),
            Text::make('面板内按钮只派发命令 / 回调，**不自动关模态** —— 关不关归应用（§A.3.7）。')
                ->caption(),
        ]);
    }

    /** 可关闭的确认框（默认 `dismissible=true` ⇒ 画 X） */
    private function openConfirm(): void
    {
        $this->env->showModal(
            Modal::make()
                ->title('确认删除')
                ->child(Column::make()->spacing(12.0)->children([
                    Text::make('删除后不可恢复，确定吗？')->body(),
                    Wrap::make()->runSpacing(8.0)->spacing(8.0)->children([
                        Button::make('取消'),
                        Button::make('删除')->variant(ButtonVariant::Danger),
                    ]),
                ]))
        );
    }

    /** 反例：`dismissible(false)` ⇒ 没有 X、ESC 与遮罩都被吞 */
    private function openStubborn(): void
    {
        $this->env->showModal(
            Modal::make()
                ->title('必须处理')
                ->dismissible(false)
                ->child(Column::make()->spacing(12.0)->children([
                    Text::make('这个对话框没有关闭按钮：ESC 被吞、点遮罩也被吞，'
                        . '只能点下面的按钮（那条路永远有效）。')->body(),
                    Button::make('知道了')->variant(ButtonVariant::Primary),
                ]))
        );
    }

    /** 打开就能打字：焦点陷阱把旧焦点清空，`autoFocus()` 接住 */
    private function openRename(): void
    {
        $this->env->showModal(
            Modal::make()
                ->title('重命名')
                ->child(Column::make()->spacing(12.0)->children([
                    TextField::make()
                        ->label('新名称')
                        ->placeholder('输入一个名字')
                        ->autoFocus(),
                    Row::make()->spacing(8.0)->children([
                        Button::make('取消'),
                        Button::make('确定')->variant(ButtonVariant::Primary),
                    ]),
                ]))
        );
    }

    private function panel(StubState $which, Widget $kid): Widget
    {
        return Offstage::make($kid, $this->tab === $which);
    }

    private function pick(StubState $tab): void
    {
        $this->setState(function () use ($tab): void {
            $this->tab = $tab;
        });
    }
}
