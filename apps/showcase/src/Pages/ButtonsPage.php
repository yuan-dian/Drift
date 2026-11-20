<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Input\CursorShape;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Card;
use Drift\Widget\ScrollView;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;
use Drift\Widget\Wrap;

/**
 * 按钮 Button—— 三变体 + 盒样式 + 悬停/光标（新落地的两个消费者）。
 *
 * ★ 本页是 **StatefulWidget**：悬停计数要住 State —— 不是页页都能 Stateless。
 *   `hover()`（§24.2 #16）与 `cursor()`（三方消费第 ③ 方）在这里
 *   有**真实消费者**：鼠标划过 ⇒ 计数 +1；命中 ⇒ 指针变手型。
 */
final class ButtonsPage extends StatefulWidget
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
        return new ButtonsState();
    }
}

final class ButtonsState extends State
{
    private int $enters = 0;

    private int $leaves = 0;

    private string $lastPressed = '（还没按过）';

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
            Text::make('按钮 Button')->title(),

            // ── 三变体 ──
            Text::make('三变体（主题 token 取色）')->heading(),
            // ★ 用 `Wrap` 而非 `Row`：窗口拖到最小时内容区只剩 180（见
            //   `tools/narrow-audit.php`），三个按钮共需 196。`Row` 会裁掉右边，
            //   而页面外壳是**竖向** ScrollView ⇒ 被裁的部分用户拿不回来。
            Wrap::make()->spacing(8.0)->runSpacing(8.0)->children([
                Button::make('默认')->variant(ButtonVariant::Default),
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
                    ->onPressed(function (): void {
                        // ★ 指针按下即激活（§24.2 #16 的 down 相位；键盘 Enter/Space 同终点）
                        $this->setState(function (): void {
                            $this->lastPressed = '点击我（此前 enters=' . $this->enters . '）';
                        });
                    }),
            ]),

            // ── 悬停 + 光标：两个已落地的消费者 ──
            Text::make('悬停 + 光标（§24.2 #16 的两个新消费者）')->heading(),
            Text::make('下面这块悬停计数：鼠标进入 enters +1、离开 leaves +1；命中链同时给出 PointingHand 光标。')
                ->caption(),
            Wrap::make()->spacing(8.0)->runSpacing(8.0)->children([
                Button::make('悬停计数区')
                    ->variant(ButtonVariant::Default)
                    ->background(Color::fromRgb(0xF2, 0xF2, 0xF2))
                    ->cursor(CursorShape::PointingHand)
                    ->hover(function (bool $entered): void {
                        // ★ 只在目标变化时派发（§9.1 语义 #5）⇒ 回调里可放心 setState
                        $this->setState(function () use ($entered): void {
                            if ($entered) {
                                $this->enters++;
                            } else {
                                $this->leaves++;
                            }
                        });
                    }),
                Text::make('enters=' . $this->enters . '  leaves=' . $this->leaves)->body(),
            ]),
            Wrap::make()->spacing(8.0)->runSpacing(8.0)->children([
                Text::make('最后按下：')->caption(),
                Text::make($this->lastPressed)->body(),
            ]),        ]);
    }
}
