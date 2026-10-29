<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Layout;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Alignment;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\LayoutTolerance;
use Drift\Layout\RenderBox;
use Drift\Widget\Align;
use Drift\Widget\Center;
use Drift\Widget\SizedBox;
use Drift\Widget\Widget;

/**
 * A4c（2026-10-29）：`Align` / `->alignContent()` 的布局判据。
 *
 * ## 四条裁决，每条一个判据
 *
 * | # | 裁决 | 判据 |
 * |---|---|---|
 * | ① | 九宫格九个落点都算对 | `testNineAnchorsPlaceChildInsideBox` |
 * | ② | 对齐**不动**自身尺寸与子尺寸 | `testAlignmentDoesNotResizeAnything` |
 * | ③ | 内容超盒时**不产生负偏移**（贴起点），与 `RenderStack` 同口径 | `testOverflowingContentStaysAtOrigin` |
 * | ④ | `Center` / `Align(center)` / `centerContent()` **三者同解** | `testCenterIsJustAnAnchor` |
 *
 * ★ ① 必须**九个全测**：只测 `center` 会让"实现里硬编码 free/2"也全绿，
 *   而那正是 `bool $center` 时代的样子 —— 本件的全部价值就是把它推广到九个点。
 *
 * ★ ③ 是"要不要夹"的唯一判据。夹在**调用点**而不在 `Alignment` 里：
 *   值对象只做数学，`RenderBox` 与 `RenderStack` 各自选择"内容超盒就贴起点"。
 *   用 `LayoutTolerance::$previewing` 让 C6 降级为裁剪 —— 否则这条判据会被
 *   "内容超出约束"的响亮失败先打断（那是正确行为，不是本件要测的东西）。
 *
 * ★ 为什么放 Feature 而不是 Unit：判据是"`Modifier` 字段 → 基类 `layout()` ③⑦
 *   → 子盒 offset"整条链的结果，单独测 `Alignment` 或单独测 `Modifier` 都
 *   测不出"值进去了但没被消费"。
 */
final class AlignTest extends SmokeTestCase
{
    /**
     * 搭一个 `Align` 盒并排一次，返回 [盒尺寸, 子 offsetX, 子 offsetY]。
     *
     * @return array{0: Size, 1: float, 2: float}
     */
    private static function offsets(
        Widget $root,
        Size $outer = new Size(400.0, 400.0),
    ): array {
        $element = $root->createElement();
        $element->mount(null, smokeContext());
        $box = $element->findBoxOf(RenderBox::class);
        if ($box === null) {
            throw new \RuntimeException('控件没有挂出 RenderBox');
        }
        $box->layout(BoxConstraints::loose($outer));

        $kid = null;
        $box->visitChildren(static function (RenderBox $child) use (&$kid): void {
            $kid = $child;
        });
        if ($kid === null) {
            throw new \RuntimeException('盒没有子节点');
        }

        return [$box->size() ?? new Size(), $kid->offsetX(), $kid->offsetY()];
    }

    /** 100×100 的盒、20×10 的子 ⇒ 自由空间 80×90 */
    private static function anchored(Alignment $a): array
    {
        return self::offsets(
            Align::make(SizedBox::make()->size(20.0, 10.0), $a)->boxSize(100.0, 100.0)
        );
    }

    public function testNineAnchorsPlaceChildInsideBox(): void
    {
        // free = 80 × 90 ⇒ -1 ⇒ 0、0 ⇒ 40/45、+1 ⇒ 80/90
        $want = [
            'topLeft' => [0.0, 0.0],
            'topCenter' => [40.0, 0.0],
            'topRight' => [80.0, 0.0],
            'centerLeft' => [0.0, 45.0],
            'center' => [40.0, 45.0],
            'centerRight' => [80.0, 45.0],
            'bottomLeft' => [0.0, 90.0],
            'bottomCenter' => [40.0, 90.0],
            'bottomRight' => [80.0, 90.0],
        ];

        $all = [
            'topLeft' => Alignment::topLeft(),
            'topCenter' => Alignment::topCenter(),
            'topRight' => Alignment::topRight(),
            'centerLeft' => Alignment::centerLeft(),
            'center' => Alignment::center(),
            'centerRight' => Alignment::centerRight(),
            'bottomLeft' => Alignment::bottomLeft(),
            'bottomCenter' => Alignment::bottomCenter(),
            'bottomRight' => Alignment::bottomRight(),
        ];

        foreach ($want as $name => [$x, $y]) {
            [, $dx, $dy] = self::anchored($all[$name]);
            check(
                "Align · {$name} ⇒ 子落在 ({$x}, {$y})",
                $dx === $x && $dy === $y,
                sprintf('实际 (%.1f, %.1f)', $dx, $dy),
            );
        }

        $this->flushChecks();
    }

    public function testAlignmentDoesNotResizeAnything(): void
    {
        // 盒仍是 100×100（对齐只挪子，不改盒）；子仍是 20×10
        [$size, $dx, $dy] = self::anchored(Alignment::bottomRight());
        check(
            'Align · 对齐不改盒尺寸（仍是 100×100）',
            $size->width === 100.0 && $size->height === 100.0,
            sprintf('实际 %.1f×%.1f', $size->width, $size->height),
        );

        // 松散约束下不"撑满"：不写 boxSize 时盒退化成内容尺寸 ⇒ 没有可对齐的空间
        [$loose, $ldx, $ldy] = self::offsets(
            Align::make(SizedBox::make()->size(20.0, 10.0), Alignment::bottomRight())
        );
        check(
            'Align · 松散约束下盒 = 内容尺寸（不学 Flutter 撑满有界轴）',
            $loose->width === 20.0 && $loose->height === 10.0,
            sprintf('实际 %.1f×%.1f', $loose->width, $loose->height),
        );
        check(
            'Align · 没有可对齐空间 ⇒ 子停在原点（不是负偏移）',
            $ldx === 0.0 && $ldy === 0.0,
            sprintf('实际 (%.1f, %.1f)', $ldx, $ldy),
        );

        $this->flushChecks();
    }

    public function testOverflowingContentStaysAtOrigin(): void
    {
        LayoutTolerance::$previewing = true;
        try {
            // 20×20 的盒里塞 50×50 的子：free = -30 ⇒ 夹到 0 ⇒ 贴起点
            [, $dx, $dy] = self::offsets(
                Align::make(SizedBox::make()->size(50.0, 50.0), Alignment::bottomRight())
                    ->boxSize(20.0, 20.0)
            );
        } finally {
            LayoutTolerance::$previewing = false;
        }

        check(
            'Align · 内容比盒大时不产生负偏移（贴起点，与 RenderStack 同口径）',
            $dx === 0.0 && $dy === 0.0,
            sprintf('实际 (%.1f, %.1f)', $dx, $dy),
        );

        $this->flushChecks();
    }

    public function testAlignmentUsesContentAreaMinusPadding(): void
    {
        // padding 20 ⇒ 内容区 60×60；子 20×10 ⇒ free = 40×50 ⇒ 右下 (40, 50)
        [, $dx, $dy] = self::offsets(
            Align::make(SizedBox::make()->size(20.0, 10.0), Alignment::bottomRight())
                ->boxSize(100.0, 100.0)
                ->paddingAll(20.0)
        );

        check(
            'Align · 落点按「内容区 − 内容尺寸」算（含 padding 时不多不少）',
            $dx === 40.0 && $dy === 50.0,
            sprintf('实际 (%.1f, %.1f)', $dx, $dy),
        );

        $this->flushChecks();
    }

    /**
     * `Center` / `Align(center)` / `centerContent()` **三者同解**。
     *
     * ★ 这条是"收敛成一个机制"的证据：三个入口写的是同一个 `mods.align` 字段，
     *   任何一个偷偷变成独立实现（比如又造一个 `RenderCenter`）都会在这里分叉。
     */
    public function testCenterIsJustAnAnchor(): void
    {
        $kid = static fn(): Widget => SizedBox::make()->size(20.0, 10.0);

        [, $cx, $cy] = self::offsets(Center::make($kid())->boxSize(100.0, 100.0));
        [, $ax, $ay] = self::offsets(
            Align::make($kid(), Alignment::center())->boxSize(100.0, 100.0)
        );
        [, $mx, $my] = self::offsets(
            SizedBox::make($kid())->size(100.0, 100.0)->centerContent()
        );

        check(
            'Center · 与 Align(center) 同解',
            $cx === $ax && $cy === $ay,
            sprintf('Center(%.1f,%.1f) vs Align(%.1f,%.1f)', $cx, $cy, $ax, $ay),
        );
        check(
            'Center · 与 centerContent() 同解',
            $cx === $mx && $cy === $my,
            sprintf('Center(%.1f,%.1f) vs centerContent(%.1f,%.1f)', $cx, $cy, $mx, $my),
        );
        check(
            'Center · 落点确实是正中（100−20)/2, (100−10)/2',
            $cx === 40.0 && $cy === 45.0,
            sprintf('实际 (%.1f, %.1f)', $cx, $cy),
        );

        $this->flushChecks();
    }

    /**
     * `centerContent(false)` 是**撤掉声明**，不是"钉到起点"。
     *
     * ★ 判据：撤掉之后 `mods.alignment()` 必须是 `null`。若写成
     *   `Alignment::topLeft()`，布局结果一样（都是偏移 0），但语义从"没有对齐意图"
     *   变成了"钉在左上"—— 后者会让 `isDefault()` 为假，多留一份修饰、多一次
     *   重绘边界判定，且以后加"未声明时按别处回退"的规则时会走错分支。
     */
    public function testCenterContentFalseRemovesTheDeclaration(): void
    {
        $on = SizedBox::make()->size(100.0, 100.0)->centerContent();
        $off = SizedBox::make()->size(100.0, 100.0)->centerContent(false);
        $toggled = SizedBox::make()->size(100.0, 100.0)->centerContent()->centerContent(false);

        check(
            'centerContent(true) ⇒ mods 里有 center 锚点',
            $on->mods()->alignment() !== null
                && $on->mods()->alignment()->equals(Alignment::center()),
        );
        check(
            'centerContent(false) ⇒ mods 里没有对齐声明（null，不是 topLeft）',
            $off->mods()->alignment() === null,
        );
        check(
            'centerContent(true → false) ⇒ 声明被撤掉（可撤回，不是单向开关）',
            $toggled->mods()->alignment() === null,
        );
        check(
            '两种写法（直接 false / 先 true 再 false）结果一致',
            $off->mods()->alignment() === $toggled->mods()->alignment(),
        );

        $this->flushChecks();
    }
}
