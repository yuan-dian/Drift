<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use App\Showcase\Pages\Base\ShowcasePage;
use Drift\Core\Computed;
use Drift\Geometry\EdgeInsets;
use Drift\Input\CursorShape;
use Drift\Rendering\Color;
use Drift\Route\Route;
use Drift\State\Cell;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Card;
use Drift\Widget\Column;
use Drift\Widget\IconName;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 按钮 Button —— 三变体 + 盒样式 + 悬停/光标。
 *
 * 单类页面：本地态字段、`build()`、事件处理器都在这一个类里，不另设 State。
 *
 * ★ 本地态用 `Cell`（2026-11-21 自动依赖）：悬停计数与"最后按下"只被两行文字读，
 *   用 `Text::of()` 把读点收在文字自身 ⇒ 鼠标进出/点击**不重建整页**。这里不写任何
 *   一次性闭包：显示交给 `Text::of(Readable)`，有逻辑的处理器用一等可调用指向具名方法。
 */
#[Route(id: 'buttons', title: '按钮 Button', icon: IconName::Square)]
final class ButtonsPage extends ShowcasePage
{
    private Cell $enters;

    private Cell $leaves;

    private Cell $lastPressed;

    public function initState(): void
    {
        $this->enters = Cell::of(0)->named('enters');
        $this->leaves = Cell::of(0)->named('leaves');
        $this->lastPressed = Cell::of('（还没按过）')->named('lastPressed');
    }

    public function build(BuildContext $ctx): Widget
    {
        // 页面 = 一整块白色面板 + 面板内滚动：卡片撑满视口高，内容在卡片里滚。
        return Card::make(ScrollView::make($this->body()))->paddingAll(0.0);
    }

    /** 页面内容（`build()` 只负责套一层卡片外壳，与 `LayoutPage` 的"每块一张卡"互补） */
    private function body(): Widget
    {
        return Column::make()->spacing(12.0)->padding(EdgeInsets::all(20.0))->children([
            Text::make('按钮 Button')->title(),

            // ── 三变体 ──
            Text::make('三变体（主题 token 取色）')->heading(),
            // 用 `Wrap` 而非 `Row`：窗口拖到最小时内容区只剩 180，三个按钮共需 196。
            Wrap::make()->spacing(8.0)->runSpacing(8.0)->children([
                Button::make('默认')
                    ->variant(ButtonVariant::Default)
                    ->onPressed($this->lastPressed->setTo('默认按钮')),
                Button::make('主要')->variant(ButtonVariant::Primary),
                Button::make('危险')->variant(ButtonVariant::Danger),
            ]),

            // ── 盒样式修饰符 ──
            Text::make('盒样式：background / border / radius（§15.7）')->heading(),
            Wrap::make()->spacing(8.0)->runSpacing(8.0)->children([
                Button::make('品牌色')
                    ->background(Color::fromRgb(0x00, 0x96, 0x88))
                    ->border(Color::fromRgb(0x00, 0x6D, 0x62), 1.5)
                    ->radius(8.0),
                Button::make('幽灵')
                    ->background(Color::fromRgb(0xFF, 0xFF, 0xFF))
                    ->border(Color::fromRgb(0xE6, 0xE6, 0xE6), 1.0)
                    ->radius(6.0),
                Button::make('点击我')
                    ->variant(ButtonVariant::Primary)
                    ->onPressed($this->pressHandler(...)),
            ]),

            // ── 悬停 + 光标 ──
            Text::make('悬停 + 光标（§24.2 #16 的两个新消费者）')->heading(),
            Text::make('下面这块悬停计数：鼠标进入 enters +1、离开 leaves +1；命中链同时给出 PointingHand 光标。')
                ->caption(),
            Wrap::make()->spacing(8.0)->runSpacing(8.0)->children([
                Button::make('悬停计数区')
                    ->variant(ButtonVariant::Default)
                    ->background(Color::fromRgb(0xF2, 0xF2, 0xF2))
                    ->cursor(CursorShape::PointingHand)
                    ->hover($this->hoverHandler(...)),
                Text::of(Computed::of($this->hoverText(...)))->body(),
            ]),
            Wrap::make()->spacing(8.0)->runSpacing(8.0)->children([
                Text::make('最后按下：')->caption(),
                Text::of($this->lastPressed)->body(),
            ]),
        ]);
    }

    /** 有逻辑的处理器：具名方法 + 一等可调用，IDE 能跳转且参数有提示 */
    private function hoverHandler(bool $entered): void
    {
        if ($entered) {
            $this->enters->set((int) $this->enters->peek() + 1);
        } else {
            $this->leaves->set((int) $this->leaves->peek() + 1);
        }
    }

    private function pressHandler(): void
    {
        $this->lastPressed->set('点击我（此前 enters=' . $this->enters->peek() . '）');
    }

    /** 派生展示文本：依赖由 `Computed::of()` 自动收集，不手写切片 */
    private function hoverText(): string
    {
        return 'enters=' . $this->enters->get() . '  leaves=' . $this->leaves->get();
    }
}
