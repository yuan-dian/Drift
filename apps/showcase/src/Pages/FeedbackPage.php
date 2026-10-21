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
use Drift\Widget\Divider;
use Drift\Widget\Expanded;
use Drift\Widget\Progress;
use Drift\Widget\ErrorPlaceholder;
use Drift\Layout\FlexAlign;
use Drift\Widget\Offstage;
use Drift\Widget\Row;
use Drift\Widget\Spinner;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 6「反馈 Feedback」—— 三态（Loading / Error / Empty）+ Spinner。
 *
 * 对照 LayUI 的提示体系（提示条 / 加载态 / 空数据）。Drift2 的三态用
 * **封闭域 match 无 default**（§12.1 第 2 条：加一个状态编译期就叫）——
 * 本页把三种状态摆在同一屏，用 `Offstage` 切换（保留各自状态，不销毁）。
 */
final class FeedbackPage extends StatefulWidget
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
        return new FeedbackState();
    }
}

/** 三态枚举（页内封闭域 —— 不设 default， match 穷尽） */
enum StubState
{
    case Loading;
    case Error;
    case Empty;
}

final class FeedbackState extends State
{
    private StubState $tab = StubState::Loading;

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
            Text::make('加载三态（§11.5）：Loading 走 Spinner，Error 走文案 + 重试，Empty 走空态提示。切 tab 用 Offstage ⇒ 状态保活。')
                ->caption(),

            // ── 状态切换条 ──
            Row::make()->spacing(8.0)->children([
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
        ]);
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
