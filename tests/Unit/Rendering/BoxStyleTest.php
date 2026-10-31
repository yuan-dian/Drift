<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Size;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Gradient;
use Drift\Rendering\Modifier;
use Drift\Rendering\RecordingCanvas;

/**
 * `BoxStyle`：**"没设过"与"画不出"是两件事**，且"底色怎么画"只有一个答案。
 *
 * ## 为什么这两条要单独钉（S1 收尾，2026-10-31）
 *
 * `Modifier` 的视觉四维（底 / 渐变 / 边 / 圆角）收成**一个** `BoxStyle` 字段之后，
 * `BoxStyle` 从"派生出来的第二份表达"变成了**唯一存储**。它的两个判据随之升级为
 * 结构性的，写错的代价也变了：
 *
 * | 判据 | 拿错会怎样 |
 * |---|---|
 * | `isBlank()` vs `isNone()` | `->radius(4)`（无底无边）被判成"没声明" ⇒ 静默丢掉 |
 * | `backgroundPaint()` 是唯一实现 | 第二套底色实现漏改 ⇒ 该控件静默不生效 |
 *
 * ## 为什么 `backgroundPaint()` 的判据落在 **base color**
 *
 * 渐变分支不拿 base color 调色，只拿它的 **alpha** 决定"能不能走不透明快路"。所以
 * 这个字段错了**画面完全看不出来**（像素一模一样），只在性能与"半透明渐变被当成
 * 不透明"上出事 —— 属于最该有判据、也最难被真机发现的那一类。
 * 旧 `Modifier::paintBehind()` 手写的那一遍用的是**占位黑**，`backgroundPaint()`
 * 用**第一个色标**：两条路在"只有渐变、且首色半透明"时给出不同的 paint。
 */
final class BoxStyleTest extends SmokeTestCase
{
    /**
     * `isNone()` = 画面上什么也没有；`isBlank()` = 一个样式字段都没设过。
     *
     * ★ 表里前两行是**唯一的差别来源**，其余行两者同真同假 —— 只有"设了但不画"
     *   才分叉。没有那两行，这条判据等于没测（两个方法写成同一个也全绿）。
     *
     * @return array<string, array{0: BoxStyle, 1: bool, 2: bool}> [样式, isNone, isBlank]
     */
    private static function blankCases(): array
    {
        return [
            // 什么也没设 —— 两者都真（唯一的重合区）
            'none()' => [BoxStyle::none(), true, true],
            // ★ 只设圆角：画面一样（无底无边），但声明确实发生过
            'radius only' => [new BoxStyle(radius: 4.0), true, false],
            // ★ 只设边框色、宽度 0：同上（`hasBorder()` 要求宽度 > 0）
            'border color, width 0' => [new BoxStyle(borderColor: Color::fromRgb(1, 2, 3), borderWidth: 0.0), true, false],
            // 以下"确实画得出东西" —— 两者都假
            'fill' => [new BoxStyle(background: Color::fromRgb(1, 2, 3)), false, false],
            'gradient' => [
                new BoxStyle(backgroundGradient: Gradient::vertical([Color::white(), Color::black()])),
                false,
                false,
            ],
            'border' => [new BoxStyle(borderColor: Color::fromRgb(1, 2, 3), borderWidth: 1.0), false, false],
        ];
    }

    public function testIsBlankVersusIsNone(): void
    {
        $wrong = [];
        foreach (self::blankCases() as $name => [$style, $none, $blank]) {
            if ($style->isNone() !== $none) {
                $wrong[] = $name . '：isNone 期望 ' . self::tf($none) . ' 实际 ' . self::tf($style->isNone());
            }
            if ($style->isBlank() !== $blank) {
                $wrong[] = $name . '：isBlank 期望 ' . self::tf($blank) . ' 实际 ' . self::tf($style->isBlank());
            }
        }

        check('BoxStyle · isNone() 与 isBlank() 各自的结果（只在"设了但不画"处分叉）', $wrong === [], implode('；', $wrong));
    }

    /**
     * ★ **两者必须真的分叉** —— 否则上面那张表可以靠"两个方法同义"通过。
     *
     * 这条是上一条的**自证**：如果 `isBlank()` 被写成 `return $this->isNone()`，
     * 上面那张表仍有 4/6 行对得上，只有这两行会红；而这里把"存在分叉"本身
     * 钉成断言，失败消息直接说出后果。
     */
    public function testRadiusOnlyIsDroppedIfJudgedByIsNone(): void
    {
        $style = new BoxStyle(radius: 4.0);

        check(
            'BoxStyle · 只设圆角 ⇒ isNone() 真而 isBlank() 假（拿 isNone 当判据会静默丢掉 ->radius(4)）',
            $style->isNone() === true && $style->isBlank() === false,
            'isNone=' . self::tf($style->isNone()) . ' isBlank=' . self::tf($style->isBlank()),
        );
    }

    /**
     * `backgroundPaint()` 的四种组合 —— **每一格都断言 base color**。
     *
     * ★ 渐变分支的 base color 是"看不见的判据"（见类注释）：只有渐变时取**第一个
     *   色标**，而不是占位黑。半透明首色的 alpha 要能原样传下去。
     */
    public function testBackgroundPaintBaseColor(): void
    {
        $solid = Color::fromRgba(10, 20, 30, 128);
        $gradient = Gradient::vertical([Color::fromRgba(200, 100, 50, 64), Color::white()]);

        check(
            'BoxStyle · 无底 ⇒ backgroundPaint() 为 null（调用方整个跳过）',
            BoxStyle::none()->backgroundPaint(10.0, 10.0) === null,
        );

        $paint = (new BoxStyle(background: $solid))->backgroundPaint(10.0, 10.0);
        check(
            'BoxStyle · 只有实色 ⇒ fill 且无渐变，颜色就是它',
            $paint !== null
            && $paint->gradient === null
            && $paint->color->equals($solid),
        );

        $paint = (new BoxStyle(backgroundGradient: $gradient))->backgroundPaint(10.0, 10.0);
        check(
            'BoxStyle · 只有渐变 ⇒ base color = 第一个色标（不是占位黑；alpha 参与"能否走不透明快路"）',
            $paint !== null
            && $paint->gradient !== null
            && $paint->color->equals($gradient->colors[0]),
            $paint === null ? 'paint 为 null' : 'color=' . self::rgba($paint->color) . ' 期望=' . self::rgba($gradient->colors[0]),
        );

        $paint = (new BoxStyle(background: $solid, backgroundGradient: $gradient))->backgroundPaint(10.0, 10.0);
        check(
            'BoxStyle · 实色 + 渐变 ⇒ 渐变赢（底仍取实色的 alpha，两者并存合法）',
            $paint !== null
            && $paint->gradient !== null
            && $paint->color->equals($solid),
        );
    }

    /**
     * ★ **`Modifier::paintBehind()` 不许有第二套底色实现**。
     *
     * 判据落在"录下来的 paint 的 base color"上：旧实现自己手写"渐变优先 → fill"，
     * 且 base 用占位黑 ⇒ 这条会红。它不崩、不报错、**像素也看不出来**（渐变分支
     * 不用 base 调色），只有这条断言能发现"又分叉了"。
     */
    public function testPaintBehindDelegatesToBackgroundPaint(): void
    {
        $gradient = Gradient::vertical([Color::fromRgba(200, 100, 50, 64), Color::white()]);
        $mods = (new Modifier())->withGradient($gradient);

        $list = new DisplayList();
        $mods->paintBehind(new RecordingCanvas($list), new Size(40.0, 20.0));

        $paint = $list->count() === 1 ? $list->argAt(0, 1) : null;

        check(
            'BoxStyle · paintBehind 只发一条 drawRect（无底无边时不多画）',
            $list->count() === 1 && $list->opAt(0) === DisplayList::OP_DRAW_RECT,
            'count=' . $list->count() . ' op=' . ($list->count() > 0 ? (string) $list->opAt(0) : '-'),
        );
        check(
            'BoxStyle · paintBehind 的 base color 与 backgroundPaint() 一致（唯一实现，不是第二套）',
            $paint instanceof \Drift\Rendering\Paint
            && $paint->gradient !== null
            && $paint->color->equals($gradient->colors[0]),
            $paint instanceof \Drift\Rendering\Paint
                ? 'color=' . self::rgba($paint->color) . ' 期望=' . self::rgba($gradient->colors[0])
                : '没有录到 paint',
        );
    }

    /** `withoutBackgroundGradient()`：清掉渐变后实色底**重新生效**（不是变成没底） */
    public function testWithoutBackgroundGradientRestoresSolid(): void
    {
        $solid = Color::fromRgb(1, 2, 3);
        $style = (new BoxStyle(background: $solid, backgroundGradient: Gradient::vertical([Color::white(), Color::black()])))
            ->withoutBackgroundGradient();

        $paint = $style->backgroundPaint(10.0, 10.0);

        check(
            'BoxStyle · 清渐变后实色底重新生效',
            $style->backgroundGradient === null
            && $paint !== null
            && $paint->gradient === null
            && $paint->color->equals($solid),
        );
        check(
            'BoxStyle · 没有渐变时 withoutBackgroundGradient() 返回自身（零成本）',
            BoxStyle::none()->withoutBackgroundGradient()->isBlank(),
        );
    }

    private static function rgba(Color $c): string
    {
        return sprintf('rgba(%d,%d,%d,%d)', $c->r, $c->g, $c->b, $c->a);
    }

    private static function tf(bool $v): string
    {
        return $v ? '真' : '假';
    }
}
