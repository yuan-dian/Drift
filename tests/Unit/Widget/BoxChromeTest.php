<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderButton;
use Drift\Rendering\Color;
use Drift\Widget\AppBar;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\PopupPanel;
use Drift\Widget\SizedBox;
use Drift\Widget\Text;

/**
 * S1（2026-10-28）：**视觉落点唯一化**之后的两条纪律。
 *
 * ## 纪律 1：产盒控件只有一个样式存储（`$mods`）
 *
 * 此前 `BoxWidget` 子类把 `->background()` 写进自己的 `private ?BoxStyle`，其余控件
 * 写 `$mods`。同一条 API 两个落点，而 `Element::syncBox()` 那条"声明了修饰但修饰
 * 无处可去"的守卫**只看得到 `$mods`** ⇒ 另一半静默失效。
 *
 * ## 纪律 2：自绘 chrome 的控件必须"剥 + 合并"
 *
 * `paintsBoxChrome()` 为 `true` ⇒ 交给盒的 `$mods` 剥掉视觉三字段（否则描边画两遍，
 * **不幂等**：`Paint::stroke` 带抗锯齿，golden 实测差 32 / 322 像素）。
 * 但剥掉之后**必须**把用户设的那份合并进 `BoxStyle` —— 只剥不合并 = 用户设置变死字段，
 * 比双画更糟。本文件的第二条判据就是钉这个配对。
 */
final class BoxChromeTest extends SmokeTestCase
{
    private static function red(): Color
    {
        return Color::fromRgb(255, 0, 0);
    }

    /**
     * 判据 1：声明自绘 chrome 的控件，用户设的底色**真的到了** `Render*` 手里。
     *
     * ★ 这是"剥 + 合并"配对的**正向**证据 —— 只有"剥"的断言（`mods` 被清空）会被
     *   "根本没实现合并"满足，那种情况下用户的 `->background()` 是死字段。
     */
    public function testOwnChromeWidgetsMergeUserStyleIntoRenderObject(): void
    {
        $ctx = smokeContext();

        $plain = Button::make('确定')->createRenderObject($ctx);
        $red = Button::make('确定')->background(self::red())->createRenderObject($ctx);

        check(
            '盒样式 · Button 默认底来自主题（没设 ⇒ 不被空值覆盖）',
            $plain instanceof RenderButton && $plain->boxStyle()->background !== null,
        );
        check(
            '盒样式 · Button 的 ->background() 真的进了 RenderButton',
            $red instanceof RenderButton && $red->boxStyle()->background?->equals(self::red()) === true,
            $red instanceof RenderButton ? 'background=' . ($red->boxStyle()->background?->toArgb() ?? 'null') : '不是 RenderButton',
        );
        check(
            '盒样式 · 设了之后与默认不同（不是"两边都是主题色"的假绿）',
            $plain instanceof RenderButton && $red instanceof RenderButton
                && $plain->boxStyle()->background?->toArgb() !== $red->boxStyle()->background?->toArgb(),
        );

        $this->flushChecks();
    }

    /**
     * 判据 2：交给**盒**的 `$mods` 已剥掉视觉三字段（否则描边画两遍）。
     *
     * ★ 用 `findBoxOf()` 读挂载后盒上的 `mods()` —— 那正是 `RenderBox::paint()` 每一帧
     *   真正读的那一份，不是 Widget 上的声明。
     * ★ 同时断言 `shadow` **保留**：`Button` 的浮起影子走 `ShadowPainter`，而用户显式
     *   `->shadow()` 走 `paintBehind()` —— 剥视觉时把它一起剥掉会静默吞掉用户声明。
     */
    public function testOwnChromeWidgetsStripBoxVisualFromBoxMods(): void
    {
        $ctx = smokeContext();

        $root = SizedBox::make(Button::make('确定')->background(self::red())->border(self::red(), 2.0)->radius(6.0))
            ->createElement();
        $root->mount(null, $ctx);
        $box = $root->findBoxOf(RenderButton::class);

        check('盒样式 · 挂出了 RenderButton', $box !== null);
        check(
            '盒样式 · 交给盒的 mods 已剥掉底 / 边 / 圆角（描边不幂等，画两遍就变样）',
            $box !== null
                && !$box->mods()->hasVisual()
                && $box->mods()->boxStyle() === null,
            $box === null ? 'box=null' : 'hasVisual=' . ($box->mods()->hasVisual() ? 'true' : 'false'),
        );

        // shadow 不在剥离范围内
        $root2 = SizedBox::make(Button::make('确定')->shadow(0.2))->createElement();
        $root2->mount(null, $ctx);
        $box2 = $root2->findBoxOf(RenderButton::class);
        check(
            '盒样式 · 剥视觉时保留 shadow（用户显式声明的影子不被吞）',
            $box2 !== null && $box2->mods()->hasVisual(),
            $box2 === null ? 'box=null' : 'hasVisual=false',
        );

        $this->flushChecks();
    }

    /**
     * 判据 3：`paintsBoxChrome()` 的取值表。
     *
     * ★ 这张表是"谁自绘 chrome"的**唯一**声明处。加新控件时若忘了声明，默认 `false`
     *   ⇒ 用户的 `->background()` 走 `paintBehind()`；若那个控件其实也自绘，就会双画。
     *   反过来误声明 `true` 而不合并，用户的设置变死字段。两个方向都有判据守着
     *   （本条 + 判据 1）。
     */
    public function testWhoPaintsOwnChrome(): void
    {
        $expected = [
            Button::class => true,
            AppBar::class => true,
            PopupPanel::class => true,
            Column::class => false,
            SizedBox::class => false,
            Text::class => false,
        ];

        $wrong = [];
        foreach ($expected as $cls => $want) {
            $widget = match ($cls) {
                Button::class => Button::make('x'),
                AppBar::class => AppBar::make(),
                PopupPanel::class => PopupPanel::make(Text::make('x')),
                Column::class => Column::make(),
                SizedBox::class => SizedBox::make(),
                Text::class => Text::make('x'),
            };
            if ($widget->paintsBoxChrome() !== $want) {
                $wrong[] = $cls . '（期望 ' . ($want ? 'true' : 'false') . '）';
            }
        }

        check('盒样式 · 自绘 chrome 的控件清单（表驱动，加新控件要显式选一侧）', $wrong === [], \implode(', ', $wrong));

        $this->flushChecks();
    }
}
