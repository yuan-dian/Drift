<?php
declare(strict_types=1);

namespace Drift\Dev\Preview;

use Drift\Input\CursorShape;
use Drift\Rendering\Color;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Center;
use Drift\Widget\Column;
use Drift\Widget\Expanded;
use Drift\Widget\Padding;
use Drift\Widget\Row;
use Drift\Widget\Spinner;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal M2 示例页 —— 布局系统 + 盒样式 + 光标 的**契约演示**（§20 纪律 4）。
 *
 * 它把 M2 真正落地的几项能力摆在一起，用 `drift-preview` 渲染即可肉眼/脚本核对：
 *   · Column / Row 的 flex 布局 + `spacing()`
 *   · `Expanded` 占剩余空间（验证 flex 确实把剩余高度分出去）
 *   · `Center` 居中
 *   · **盒样式修饰符** `Button::make(...)->background()->border()->radius()` ——
 *     这是 2026-10-16 修好的 §15.7 缺口（之前 `BoxWidget::$box` 被 `createRenderObject` 忽略，是死代码）
 *   · **光标** `->cursor(CursorShape::PointingHand)`（命中三方消费的第 ③ 方，M2 已落地）
 *
 * ```bash
 * php tools/drift-preview.php --page=tools/Preview/M2LayoutPage.php --width=360 --height=260
 * ```
 *
 * ★ 它同时是 M2 出口判据"布局 conformance 夹具"的雏形（§19）：目前 M2 的能力已齐，
 *   缺的是夹具本身的沉淀；这一页就是可渲染、可断言的夹具起点。
 */
final class M2LayoutPage extends StatelessPage
{
    public function build(BuildContext $context): Widget
    {
        return Padding::make(
            Column::make()
                ->spacing(12.0)
                ->children([
                    Text::make('M2 · 布局 / 盒样式 / 光标')->title()->bold(),
                    Row::make()
                        ->spacing(8.0)
                        ->children([
                            // ★ 盒样式修饰符：用户色覆盖主题默认盒（§15.7 修正后才会真的画出来）
                            Button::make('主按钮')
                                ->background(Color::fromRgb(60, 130, 220))
                                ->border(Color::fromRgb(30, 90, 180), 1.5)
                                ->radius(8.0),
                            Button::make('危险')
                                ->variant(ButtonVariant::Danger),
                            Button::make('指向我')
                                ->background(Color::fromRgb(80, 200, 120))
                                ->radius(6.0)
                                ->cursor(CursorShape::PointingHand),
                        ]),
                    Center::make()->child(
                        Text::make('居中文本 · 命中时变手型光标')
                            ->cursor(CursorShape::PointingHand),
                    ),
                    // ★ Spinner（§11.5 三态 Loading 分支）：8 点环 + alpha 尾迹。
                    //   相位由 SpinnerState::tick() 驱动（FrameClock 属 M4）——
                    //   静态页渲染出来的是初相位（头点在正上方）。
                    Row::make()
                        ->spacing(12.0)
                        ->children([
                            Spinner::make(),
                            Spinner::make()->diameter(32.0),
                            Spinner::make()->diameter(48.0),
                            Text::make('加载指示器（Spinner）')->caption(),
                        ]),
                    // ★ Expanded：占据 Column 剩余高度 ⇒ 验证 flex 布局把剩余空间分给了它
                    Expanded::make(
                        Padding::make(
                            Text::make('占据剩余空间（Expanded）→ 验证 flex 不是把子项都压到最小')
                                ->maxLines(3),
                        )->all(8.0),
                    ),
                ]),
        )->all(16.0);
    }
}
