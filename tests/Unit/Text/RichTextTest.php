<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Text;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Input\CursorShape;
use Drift\Layout\RenderRichText;
use Drift\Rendering\Color;
use Drift\Text\Paragraph;
use Drift\Text\TextSpan;
use Drift\Text\TextStyle;
use Drift\Widget\RichText;
use Drift\Widget\Span;

/**
 * `RichText`：一段里多个样式 —— 排版产物、缓存键纪律、链接命中。
 *
 * ## 三块判据，三种坏法
 *
 * | 块 | 坏法 | 可观测面 |
 * |---|---|---|
 * | 排版产物（run / cluster / 行高 / 背景）| 样式丢了、偏移错了、行高被小字压扁 | `Paragraph::lines[0]->runs` |
 * | 缓存键纪律（颜色 / 背景色**不进**键）| 改个颜色就全量重排 —— **功能全对、只是慢** | `Paragraph::keyForSpans()` |
 * | 链接命中（`tagAt` / `cursorAt`）| 链接点不动 / 整段都变成链接 | `RenderRichText` |
 *
 * ★ 缓存键那两条**不是重复的排版断言**：它们的失败形态是"性能静默退化"，
 *   排版断言全绿也发现不了（§8.1：键只收排版输入）。所以单独一个方法，
 *   且断言写成"两个不同颜色 ⇒ 同一个键"，而不是"键里有/没有颜色字符串"
 *   （后者会把实现细节钉死，改个 hash 算法就红）。
 *
 * ## 与 `Feature/Input/RichTextKeyboardTest` 的分工
 *
 * 那边验"链接键盘可达"（`onSpanTap` ⇒ 进 Tab 序 ⇒ Enter 触发），需要
 * `FocusManager` ⇒ Feature 层。本文件不碰应用层，只要一次 layout。
 */
final class RichTextTest extends SmokeTestCase
{
    private const EPS = 0.01;

    /**
     * 造一个 layout 过的 `RenderRichText`（Widget 不可变 ⇒ 盒必须现取）。
     *
     * @param list<Span> $spans
     */
    private static function laidOut(array $spans, Size $max): ?RenderRichText
    {
        $el = RichText::make($spans)->createElement();
        $el->mount(null, smokeContext());
        $box = $el->effectiveBox();
        $box?->layout(BoxConstraints::loose($max));

        return $box instanceof RenderRichText ? $box : null;
    }

    /** 第一行里第一个 `argb` 匹配的 run */
    private static function runWithArgb(?RenderRichText $box, int $argb): ?object
    {
        foreach ($box?->paragraph()?->lines[0]->runs ?? [] as $run) {
            if ($run->argb === $argb) {
                return $run;
            }
        }

        return null;
    }

    /**
     * 两段各自成 run（前段走默认色、后段走显式色），且后段的 `cluster`
     * 是**全文字节偏移**（不是段内 0）。
     *
     * ★ 两条必须一起：只验"两段颜色不同"会奖励"每段都从 0 开始编号 cluster"的实现
     *   —— 那种实现在纯色段上看不出问题，但链接命中 / 光标定位会整体错位。
     */
    public function testEachSpanBecomesItsOwnRun(): void
    {
        $box = self::laidOut([
            Span::make('保存失败：')->bold(),
            Span::make('磁盘空间不足')->color(Color::fromHex('#d93025')),
        ], new Size(500.0, 120.0));

        $runs = $box?->paragraph()?->lines[0]->runs ?? [];
        $accent = Color::fromHex('#d93025')->toArgb();
        $tail = self::runWithArgb($box, $accent);
        $firstArgb = $runs === [] ? null : $runs[0]->argb;

        check(
            'RichText · 两段各自成 run（前段默认色、后段显式色）',
            \count($runs) >= 2 && $firstArgb === -1 && $tail !== null,
            \sprintf('runs=%d 首=%s', \count($runs), \var_export($firstArgb, true))
        );
        check(
            'RichText · 后段 cluster 是全文字节偏移（不是 0；段内回退 run 也一起抬）',
            $tail !== null && $tail->glyphs !== []
                && $tail->glyphs[0]->cluster === \strlen('保存失败：'),
            \sprintf(
                '后段首 run 的 cluster=%s 期望=%d',
                \var_export($tail?->glyphs[0]->cluster ?? null, true),
                \strlen('保存失败：')
            )
        );
    }

    /**
     * 行高按**最大** span 字号（28pt ⇒ 高 ≥ 28），不被小字压扁。
     *
     * ★ 这是"一段里字号不同"唯一的几何判据：若实现用首个 span 的字号定行高，
     *   大字号那行会被裁掉上半截（且不报错）。
     */
    public function testLineHeightFollowsLargestSpan(): void
    {
        $box = self::laidOut([
            Span::make('大')->size(28.0),
            Span::make('小'),
        ], new Size(500.0, 200.0));

        check(
            'RichText · 行高按最大 span 字号（28pt ⇒ 高 ≥ 28，不被 14pt 压扁）',
            $box?->size() !== null && $box->size()->height >= 28.0,
            \sprintf('高 %.1f', $box?->size()?->height ?? -1.0)
        );
    }

    /**
     * 缓存键**只收排版输入**：颜色与背景色都不进键。
     *
     * ★ 两条合一个方法，因为它们是同一条纪律的两个面（前景色 / 背景色）——
     *   只钉前景色的话，把 `background` 塞进 `cacheKey()` 的改法照样绿。
     * ★ 判据形态是"**两个不同颜色 ⇒ 同一个键**"，不是"键里没有颜色字符串"：
     *   后者把 hash 实现细节钉死，换算法就红（假红）。
     */
    public function testCacheKeyIgnoresPaintOnlyInputs(): void
    {
        $base = TextStyle::of(14.0);
        $scale = new Scale(1.0);
        $maxW = 100.0;

        $redKey = Paragraph::keyForSpans([new TextSpan('x', $base, 0xFFFF0000)], $base, $maxW, $scale);
        $blueKey = Paragraph::keyForSpans([new TextSpan('x', $base, 0xFF0000FF)], $base, $maxW, $scale);
        check(
            'RichText · 缓存键不含颜色（只换色不重排 —— §8.1 键只收排版输入）',
            $redKey === $blueKey,
            \sprintf('A=%s B=%s', \substr($redKey, 0, 8), \substr($blueKey, 0, 8))
        );

        $noBgKey = Paragraph::keyForSpans(
            [new TextSpan('x', $base, -1, '', false, -1)],
            $base,
            $maxW,
            $scale
        );
        $yellowBgKey = Paragraph::keyForSpans(
            [new TextSpan('x', $base, -1, '', false, 0xFFFFFF00)],
            $base,
            $maxW,
            $scale
        );
        check(
            'RichText · 缓存键不含背景色（改背景不重排，与颜色同一条纪律）',
            $noBgKey === $yellowBgKey,
            \sprintf('A=%s B=%s', \substr($noBgKey, 0, 8), \substr($yellowBgKey, 0, 8))
        );
    }

    /** 造一个带链接的富文本（三个 span，链接在中间） */
    private static function linkBox(): array
    {
        $box = self::laidOut([
            Span::make('加载失败，'),
            Span::make('重试')->tag('retry')->underline()->color(Color::fromHex('#009688')),
            Span::make('。'),
        ], new Size(500.0, 60.0));

        $run = null;
        $startX = 0.0;
        foreach ($box?->paragraph()?->lines[0]->runs ?? [] as $candidate) {
            if ($candidate->tag === 'retry') {
                $run = $candidate;

                break;
            }
            $startX += $candidate->width;
        }

        return [$box, $run, $startX];
    }

    /**
     * `tag` 与下划线落在**同一个** run 上。
     *
     * ★ 失败形态：下划线画在了前一/后一个 run 上 ⇒ 看起来"有下划线"，
     *   但点击命中的范围与视觉范围错开一格（静默）。
     */
    public function testTagAndUnderlineShareTheRun(): void
    {
        [, $run] = self::linkBox();

        check(
            'RichText · tag 与下划线落在同一个 run 上',
            $run !== null && $run->underline === true,
            \sprintf('tag=%s underline=%s', $run?->tag ?? '(null)', $run !== null && $run->underline ? 'y' : 'n')
        );
    }

    /**
     * `tagAt()` 按**字节偏移**映射到 tag：链接中点命中、普通文本返回空串。
     *
     * ★ 两条一起：只验"链接中点命中"会奖励"整段都返回第一个 tag"的实现；
     *   只验"普通文本返回空串"会奖励"永远返回空串"的实现。
     * ★ 普通文本的探测点用 x=1（第一段 '加载失败，' 里），不是"链接左边一点点"
     *   —— 后者可能落在链接 run 的边界上，判据不稳。
     */
    public function testTagAtMapsByteOffsetsToTags(): void
    {
        [$box, $run, $startX] = self::linkBox();
        $para = $box?->paragraph();

        check(
            'RichText · tagAt 命中链接中点（字节偏移映射，不另算 pen）',
            $run !== null && $para !== null
                && $para->tagAt($startX + $run->width / 2.0, 5.0) === 'retry',
            \sprintf(
                'tagAt=%s',
                \var_export($para?->tagAt($startX + ($run?->width ?? 0.0) / 2.0, 5.0), true)
            )
        );
        check(
            'RichText · tagAt 在普通文本上返回空串（不是"整段都是链接"）',
            $para !== null && $para->tagAt(1.0, 5.0) === '',
            \sprintf('tagAt=%s', \var_export($para?->tagAt(1.0, 5.0), true))
        );
    }

    /**
     * 光标形状跟着 `tagAt` 走：链接上 `PointingHand`、普通文字 `null`。
     *
     * ★ 这条钉的是"`cursorAt()` 真的复用了 tag 判定"，不是另写一套几何 ——
     *   两套算法迟早漂移（一处改了另一处忘），现象是"手型出现了但点不动"。
     */
    public function testCursorFollowsTagAt(): void
    {
        [$box, $run, $startX] = self::linkBox();
        $onLink = $box === null || $run === null ? null : $box->cursorAt($startX + $run->width / 2.0, 5.0);
        $onPlain = $box?->cursorAt(1.0, 5.0);

        check(
            'RichText · cursorAt：链接上 PointingHand、普通文字 null（按位置变，不是整段）',
            $onLink === CursorShape::PointingHand && $onPlain === null,
            \sprintf('link=%s plain=%s', $onLink?->name ?? 'null', $onPlain?->name ?? 'null')
        );
    }

    /**
     * `background` 落在 run 上，且与 `argb` 分开（互不覆盖）。
     *
     * ★ 断言"`argb` 仍是 -1"这一半不能省：若实现把背景色写进 `argb`，
     *   文字本身会被染成高亮色 —— 视觉上"整块变色"，而 `background` 那条
     *   单独看还是绿的。
     */
    public function testBackgroundIsCarriedOnTheRun(): void
    {
        $box = self::laidOut([
            Span::make('正常 '),
            Span::make('高亮')->background(Color::fromHex('#fff3cd')),
        ], new Size(400.0, 60.0));

        $bg = null;
        foreach ($box?->paragraph()?->lines[0]->runs ?? [] as $candidate) {
            if ($candidate->background >= 0) {
                $bg = $candidate;

                break;
            }
        }

        check(
            'RichText · background 落在 run 上（ARGB 透传，与 argb 分开）',
            $bg !== null
                && $bg->background === Color::fromHex('#fff3cd')->toArgb()
                && $bg->argb === -1,
            \sprintf('bg=%s fg=%s', \var_export($bg?->background, true), \var_export($bg?->argb, true))
        );
    }
}
