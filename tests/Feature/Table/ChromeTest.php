<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Table;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Axis;
use Drift\Layout\RenderFlex;
use Drift\Layout\ScrollBarHost;
use Drift\Rendering\ColorToken;
use Drift\Theme\ThemeData;
use Drift\Widget\Element;

/**
 * 表格的**外观层**：斑马行、滚动条可见性、以及"画出来的位置"。
 *
 * ## 为什么必须有一组像素断言
 *
 * 结构断言（`offsetY === 24`、`maxScroll === 399792`）能过，而 paint 期多一次
 * `translate` 照样错位 —— 这是 `RenderScrollView` 那次的教训。所以这里有一组
 * 判"像素落在哪"的断言，专门抓平移 / 裁剪的错。
 *
 * ★ 清屏色用**洋红**（`Fixtures::rasterOf` 里写死）而不是白：表格底
 *   `ColorToken::Surface` 三套主题都是纯白，白底画布会让"这里画了 Surface"与
 *   "这里什么都没画"无法区分 —— 一条恒真的断言等于没有。
 * ★ 取 x=305：竖滚动条占 `[320−3−6, 320−3] = [311, 317]`，用 315 会落在条上
 *   （踩过一次：表头底被验成 `Muted` 的 110,119,129）。最后一列 fixed + Center
 *   对齐，右侧留白 ⇒ 露出的一定是背景色。
 *
 * ## 迁移时的有效性审计（2026-10-28 第 26 批）
 *
 * - **有效**：九条都落在"画出来是什么"上，不是字段回读。
 * - ★ **顺序陷阱（原样保留）**：斑马行必须用**未滚动**的 2 行小表。拿滚过 48 的
 *   大表会让"窗内第 0 个 Element"变成第 1 行，断言整个反过来 —— 这条曾被自己抓到过。
 * - ★ **取盒时机（原样保留）**：滚动后的像素断言必须用**滚动后重新取**的盒。
 *   `syncBox()` 每次 build 都造新盒，滚动前的旧对象画出来是未滚画面
 *   （y=40 落在第 0 行，验成 Surface 白）—— 这也是踩过的坑。
 */
final class ChromeTest extends SmokeTestCase
{
    /** `RenderTable` 的行区（= `Column` 的盒），取不到返回 null */
    private static function bodyOf(Element $root): ?RenderFlex
    {
        $el = findElement(
            $root,
            static fn(Element $e): bool => $e->widget() instanceof \Drift\Widget\Column,
        );
        $box = $el?->renderObject();

        return $box instanceof RenderFlex ? $box : null;
    }

    public function testZebraOnlyOnOddRows(): void
    {
        // ★ 必须用未滚动的小表（见类注释的"顺序陷阱"）
        $root = Fixtures::mount(Fixtures::build(2));
        $body = self::bodyOf($root);
        $line0 = $body?->children()[0] ?? null;
        $line1 = $body?->children()[1] ?? null;

        check(
            'Table · 斑马行只给奇数行（第 0 行无背景、第 1 行有）',
            $line0 !== null && $line1 !== null
                && $line0->mods()?->hasVisual() === false
                && $line1->mods()?->hasVisual() === true,
            \sprintf(
                '%s / %s',
                $line0?->mods()?->hasVisual() === true ? '有' : '无',
                $line1?->mods()?->hasVisual() === true ? '有' : '无',
            ),
        );
    }

    public function testNoScrollBarWhenContentFits(): void
    {
        // 2 行 ⇒ 内容 80 < 行区 208 ⇒ 不溢出 ⇒ 没有条
        $box = Fixtures::boxOf(Fixtures::mount(Fixtures::build(2)));

        check(
            'Table · 内容不溢出 ⇒ maxScroll = 0 且不画滚动条',
            $box?->maxScroll(Axis::Vertical) === 0.0 && $box->thumbRect(Axis::Vertical) === null,
            'maxScroll=' . (string) ($box?->maxScroll(Axis::Vertical) ?? -1.0)
                . ' thumb=' . ($box?->thumbRect(Axis::Vertical) === null ? 'null' : '有'),
        );
    }

    public function testScrollBarAppearsAndHostInterface(): void
    {
        $box = Fixtures::boxOf(Fixtures::mount(Fixtures::build(10000)));

        check(
            'Table · 内容溢出 ⇒ 有滚动条（几何与 ScrollView 同一份 `ScrollBarGeometry`）',
            $box !== null && $box->thumbRect(Axis::Vertical) !== null,
            $box?->thumbRect(Axis::Vertical) === null ? 'null' : '有',
        );

        // 接口断言：滚动条能拖，是因为 `RenderTable` 实现了同一个 `ScrollBarHost`
        // （不是各写一份命中逻辑）。
        check(
            'Table · RenderTable 实现 ScrollBarHost（滚动条可拖 —— 与 ScrollView 同一接口）',
            $box instanceof ScrollBarHost,
            $box instanceof ScrollBarHost ? 'ok' : '未实现',
        );
    }

    public function testPixelsOfUnscrolledSmallTable(): void
    {
        $theme = ThemeData::light();
        $chromeInk = $theme->color(ColorToken::Chrome);
        $surfaceInk = $theme->color(ColorToken::Surface);
        $paneInk = $theme->color(ColorToken::Pane);

        $raster = Fixtures::rasterOf(Fixtures::boxOf(Fixtures::mount(Fixtures::build(2))), 320, 240);

        check(
            'Table · 像素：表头区是表头底色（y=10）',
            $raster->pixelAt(305, 10)->equals($chromeInk),
            Fixtures::pxOf($raster, 305, 10),
        );
        check(
            'Table · 像素：第 0 行（偶数行）是表格底色、第 1 行（奇数行）是斑马色',
            $raster->pixelAt(305, 50)->equals($surfaceInk)
                && $raster->pixelAt(305, 90)->equals($paneInk),
            Fixtures::pxOf($raster, 305, 50) . ' / ' . Fixtures::pxOf($raster, 305, 90),
        );
        // 内容不足一屏时，行区下方必须仍是**表格底**（不是窗口底色透出来）
        check(
            'Table · 像素：内容不足一屏时，行区下方的空白仍是表格底色（不是窗口底色透出来）',
            $raster->pixelAt(305, 200)->equals($surfaceInk),
            Fixtures::pxOf($raster, 305, 200),
        );
    }

    public function testPixelsAfterScroll(): void
    {
        $theme = ThemeData::light();
        $chromeInk = $theme->color(ColorToken::Chrome);
        $paneInk = $theme->color(ColorToken::Pane);

        $root = Fixtures::mount(Fixtures::build(10000));
        Fixtures::stateOf($root)?->scrollBy(48.0);
        Fixtures::pump($root);

        // ★ 必须重新取盒（见类注释的"取盒时机"）
        $raster = Fixtures::rasterOf(Fixtures::boxOf($root), 320, 240);

        // 滚动 48 ⇒ 第 1 行（内容 y=40）落在盒内 32 − 48 + 40 = 24..64，取 40 是它的斑马色
        check(
            'Table · 像素：滚动 48 后第 1 行落在 y=40（行区上移 8，斑马色）',
            $raster->pixelAt(305, 40)->equals($paneInk),
            Fixtures::pxOf($raster, 305, 40),
        );

        // ★★ 裁剪：滚动后**表头区必须还是表头底色** —— 行区上半会被裁掉。
        //   不裁的话行会画到表头上（表头整个被行盖住），而属性断言全都能过。
        //   ★ 必须滚到**带底色的行**与表头重叠的位置，否则这条恒绿（踩过一次）：
        //     滚 48 时与表头重叠的只有第 0 行，而第 0 行没有底色（斑马只给奇数行）
        //     ⇒ 不裁剪也照样看到表头。滚 88 ⇒ 第 1 行（斑马色）盖住 y=−16..24，
        //     含 y=10；此时不裁剪就会验成 Pane。
        $deep = Fixtures::mount(Fixtures::build(10000));
        Fixtures::stateOf($deep)?->scrollBy(88.0);
        Fixtures::pump($deep);
        $deepRaster = Fixtures::rasterOf(Fixtures::boxOf($deep), 320, 240);

        check(
            'Table · 像素：滚动后表头没被行区盖住（y=10 仍是表头底色，裁剪生效）',
            $deepRaster->pixelAt(305, 10)->equals($chromeInk),
            Fixtures::pxOf($deepRaster, 305, 10),
        );
    }
}
