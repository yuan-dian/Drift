<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Geometry\Size;
use Drift\Layout\RenderBackdropFilter;
use Drift\Layout\RenderBox;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\Color;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Paint;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;
use Drift\Rendering\SkiaCanvas;
use Drift\Widget\BackdropFilter;
use Drift\Widget\SizedBox;
use Drift\Widget\Widget;

/**
 * 毛玻璃（`BackdropFilter` / `Canvas::blurBackdrop`，从 smoke 迁出）。
 *
 * ## 这一组钉什么（不是"糊得好不好看"）
 *
 * ① 尺寸 = 子尺寸（不自己撑开、不额外占位）；
 * ② 模糊矩形 = **内容盒**（不是边框盒、不是整屏）；
 * ③ 模糊排在子内容**之前**（反了就变成"糊自己"）；
 * ④ 一次性操作，不进任何栈深度（`assertBalanced()` 必须过）；
 * ⑤ 单位换算：sigma 乘 dpr、矩形进设备像素（含当前偏移）。
 *
 * Raster 不实现模糊（空实现，见 `RasterCanvas::blurBackdrop()` 注释：软光栅做可分离
 * 卷积是每帧几十毫秒），所以像素级"糊得对不对"只能真机做 —— 这里验结构 + 验降级。
 *
 * ## 迁移时的有效性审计（2026-10-28 第 25 批）
 *
 * - ★ **修一处假绿**：原「回放到 Raster 不崩」的判据是
 *   `$replayOk && $glassRaster->width() === 200` —— `width()` 就是构造参数，
 *   **恒真**，等于只测了"不抛"。改为"真的不模糊"：先画一块 40×40 黑，再用
 *   sigma=20 对整张 200×200 模糊，断言 (20,20) 仍是纯黑、**(60,60) 仍是纯白**。
 *   真实现了模糊的话黑会渗到 60,60 ⇒ 红。这同时钉住"不崩"与"不模糊"两件事。
 * - ★ **加固一条脆弱判据**：原「顺序」用 `$blurAt < count() - 2` 这种魔法数
 *   （"后面还有 3 条命令"）—— 空子内容的 save/translate/restore 恰好凑够，
 *   换个叶控件就变味。改为给子**真墨**（`background()`）后断言
 *   `blurAt < 首次 OP_DRAW_RECT`，直接编码"模糊在子墨之前"。
 * - **有效**：其余每条都落在被测语义（盒类型 / 尺寸 / sigma 原样 / 内容盒矩形 /
 *   栈平衡 / 能力位 / dpr 换算 / sigma=0 早退 / 内边距反例）。
 * - **合并**：① 与 ② 共用同一夹具（挂载 + 布局），合进 `testMountsAsProxyAndSizesToChild`。
 */
final class BackdropFilterTest extends SmokeTestCase
{
    /**
     * 挂树 + 按 400×300 宽松约束布局。
     *
     * @return array{0: RenderBox|null, 1: \Drift\Widget\Element}
     */
    private static function mount(Widget $kid, float $sigma, ?EdgeInsets $pad = null): array
    {
        $widget = BackdropFilter::make($kid, $sigma);
        if ($pad !== null) {
            $widget = $widget->padding($pad);
        }
        $element = $widget->createElement();
        $element->mount(null, smokeContext());
        $box = $element->effectiveBox();
        $box?->layout(BoxConstraints::loose(new Size(400.0, 300.0)));

        return [$box, $element];
    }

    private static function kid(float $w = 120.0, float $h = 80.0): SizedBox
    {
        return SizedBox::make()->width($w)->height($h);
    }

    /** 录命令流：能同时验"录了什么"与"顺序对不对" */
    private static function record(RenderBox $box): DisplayList
    {
        $list = new DisplayList();
        $box->paint(new RecordingCanvas($list));

        return $list;
    }

    /** 首次出现某 op 的下标；没有则 -1（★ 不用 `array_search`：DisplayList 不是数组） */
    private static function findOp(DisplayList $list, int $op): int
    {
        for ($i = 0; $i < $list->count(); $i++) {
            if ($list->opAt($i) === $op) {
                return $i;
            }
        }

        return -1;
    }

    private static function rectText(mixed $rect): string
    {
        return $rect instanceof Rect
            ? sprintf('%.1f,%.1f,%.1f,%.1f', $rect->left, $rect->top, $rect->width, $rect->height)
            : 'rect=' . \var_export($rect, true);
    }

    public function testMountsAsProxyAndSizesToChild(): void
    {
        [$box] = self::mount(self::kid(), 16.0);

        // ① 有态 + 有盒 + 无子 ⇒ 走 `createRenderObjectFromState()` 叶子路径，盒类必须对
        check(
            'BackdropFilter · 挂载即建出 RenderBackdropFilter（ProxyElement 走单子路径）',
            $box instanceof RenderBackdropFilter,
            'box=' . ($box === null ? 'null' : \get_class($box)),
        );

        // ② 尺寸 = 子尺寸（不自己撑开、不额外占位）
        check(
            'BackdropFilter · 尺寸 = 子尺寸（不自己撑开、不额外占位）',
            $box?->size()?->width === 120.0 && $box?->size()?->height === 80.0,
            'size=' . \var_export($box?->size(), true),
        );
    }

    public function testBlurCommandCarriesSigmaAndContentBoxRect(): void
    {
        [$box] = self::mount(self::kid(), 16.0);
        $list = self::record($box);

        $blurAt = self::findOp($list, DisplayList::OP_BLUR_BACKDROP);
        $sigma = $blurAt >= 0 ? (float) $list->argAt($blurAt, 0) : -1.0;
        $rect = $blurAt >= 0 ? $list->argAt($blurAt, 1) : null;

        check(
            'BackdropFilter · 录下 OP_BLUR_BACKDROP 且 sigma 原样传下去',
            $blurAt >= 0 && $sigma === 16.0,
            'blurAt=' . $blurAt . ' sigma=' . $sigma,
        );

        // ★ 断言落在**被测语义**上：模糊矩形必须是内容盒 (0,0,120,80)。
        //   写 `$rect !== null` 之类是恒真断言，等于没测。
        check(
            'BackdropFilter · 模糊矩形 = 内容盒（0,0,120,80），不是边框盒也不是整屏',
            $rect instanceof Rect
                && $rect->left === 0.0 && $rect->top === 0.0
                && $rect->width === 120.0 && $rect->height === 80.0,
            self::rectText($rect),
        );
    }

    public function testBlurIsPaintedBeforeChildInk(): void
    {
        // ★ 子必须**有墨**，否则"模糊在子之前"这条证伪不了：空 SizedBox 只录
        //   save/translate/restore，没有任何 draw 命令，位置比较就成了空转。
        //   （原 smoke 用 `$blurAt < count() - 2` 的魔法数，靠那三条命令凑数。）
        [$box] = self::mount(
            SizedBox::make()->width(120.0)->height(80.0)->background(new Color(0xFF, 0x00, 0x00)),
            16.0,
        );
        $list = self::record($box);

        $blurAt = self::findOp($list, DisplayList::OP_BLUR_BACKDROP);
        $inkAt = self::findOp($list, DisplayList::OP_DRAW_RECT);

        check(
            'BackdropFilter · 顺序 = 压裁剪 → 模糊 → 画子（反了就变成"糊自己"）',
            $blurAt >= 1
                && $inkAt >= 0
                && $blurAt < $inkAt
                && $list->opAt($blurAt - 1) === DisplayList::OP_PUSH_CLIP,
            'blurAt=' . $blurAt . ' inkAt=' . $inkAt
                . ' prev=' . ($blurAt >= 1 ? $list->opAt($blurAt - 1) : -1)
                . ' count=' . $list->count(),
        );
    }

    public function testRecordingStackBalanced(): void
    {
        // ★ 一次性操作 ⇒ 栈必须仍然平衡（若哪天改成 push/pop 对，这条会立刻炸）
        [$box] = self::mount(self::kid(), 16.0);
        $list = self::record($box);

        $balanced = true;
        try {
            $list->assertBalanced();
        } catch (\Throwable) {
            $balanced = false;
        }

        check(
            'BackdropFilter · 录制栈平衡（blurBackdrop 不进 save/clip/layer 深度）',
            $balanced && $list->saveDepth() === 0 && $list->clipDepth() === 0,
            'balanced=' . \var_export($balanced, true)
                . ' save=' . $list->saveDepth() . ' clip=' . $list->clipDepth(),
        );
    }

    public function testRasterReplayDoesNotBlur(): void
    {
        // ★ 判据要能证伪"模糊被实现了"：整片同色的话，真模糊也看不出差别。
        //   所以先画一块 40×40 的黑，再对整张 200×200 做 sigma=20 的模糊 ——
        //   真模糊会把黑渗到 (60,60)；Raster 是空实现 ⇒ 边界纹丝不动。
        $list = new DisplayList();
        $rec = new RecordingCanvas($list);
        $rec->drawRect(new Rect(0.0, 0.0, 40.0, 40.0), Paint::fill(Color::black()));
        $rec->blurBackdrop(20.0, new Rect(0.0, 0.0, 200.0, 200.0));

        $raster = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
        $threw = false;
        try {
            $list->replay($raster);
        } catch (\Throwable) {
            $threw = true;
        }

        $core = $raster->pixelAt(20, 20);
        $bleed = $raster->pixelAt(60, 60);
        check(
            'BackdropFilter · 回放到 Raster 不崩、且真的不模糊（sigma 20 也不把黑渗到 60,60）',
            $threw === false
                && $core->r === 0 && $core->g === 0 && $core->b === 0
                && $bleed->r === 255 && $bleed->g === 255 && $bleed->b === 255,
            'threw=' . \var_export($threw, true)
                . sprintf(' core=%d,%d,%d', $core->r, $core->g, $core->b)
                . sprintf(' bleed=%d,%d,%d', $bleed->r, $bleed->g, $bleed->b),
        );
    }

    public function testRasterAdvertisesBackdropCapability(): void
    {
        $raster = new RasterCanvas(200, 200, Color::white(), Scale::of(1.0));
        check(
            'Canvas · Raster 也置 CAP_BACKDROP（近似 = 不模糊，与 CAP_LAYER 同档）',
            ($raster->capabilities() & CanvasCapabilities::CAP_BACKDROP) !== 0,
            'caps=' . $raster->capabilities(),
        );
    }

    public function testSkiaGetsDeviceSpaceSigmaAndRect(): void
    {
        // ★ 这一段钉的是**单位换算**：sigma 必须乘 dpr、矩形必须进设备像素（含当前偏移）。
        //   漏了 dpr ⇒ 200% 缩放下玻璃比设计稿"更硬"；漏了偏移 ⇒ 糊错位置。
        skiaBridgeReset();
        $canvas = new SkiaCanvas(400, 400, null, Scale::of(2.0));
        $canvas->translate(10.0, 20.0);
        $canvas->blurBackdrop(6.0, new Rect(0.0, 0.0, 50.0, 30.0));

        $call = null;
        foreach (skiaCalls() as $entry) {
            if ($entry[0] === 'blur_backdrop') {
                $call = $entry;
            }
        }

        check(
            'SkiaCanvas · blurBackdrop 按 dpr + 偏移换算成设备像素（sigma 6→12，矩形 20,40,100,60）',
            $call !== null
                && $call[1] === 12.0
                && $call[2] === 20.0 && $call[3] === 40.0
                && $call[4] === 100.0 && $call[5] === 60.0,
            $call === null ? 'no call' : \implode(',', \array_map('strval', $call)),
        );
    }

    public function testZeroSigmaSkipsBridgeCall(): void
    {
        // 反例：sigma=0 不该惊动桥（模糊半径 0 就是不模糊，连一次跨边界调用都不值）
        skiaBridgeReset();
        $canvas = new SkiaCanvas(400, 400, null, Scale::of(2.0));
        $canvas->blurBackdrop(0.0, new Rect(0.0, 0.0, 50.0, 30.0));

        $n = 0;
        foreach (skiaCalls() as $entry) {
            if ($entry[0] === 'blur_backdrop') {
                $n++;
            }
        }
        check(
            'SkiaCanvas · sigma=0 ⇒ 不发桥调用（早退在 PHP 侧）',
            $n === 0,
            'blurCalls=' . $n,
        );
    }

    public function testPaddingKeepsContentBoxRect(): void
    {
        // ★ 反例自检：没有内边距时"内容盒 == 边框盒"，上面那条断言**证伪不了**
        //   "误用 $this->size()" 的实现。加 5 的内边距把它们分开：
        //   边框盒 130×90、内容盒 120×80 —— 模糊矩形必须跟着**内容盒**走。
        [$box] = self::mount(self::kid(), 8.0, EdgeInsets::all(5.0));
        $list = self::record($box);
        $blurAt = self::findOp($list, DisplayList::OP_BLUR_BACKDROP);
        $rect = $blurAt >= 0 ? $list->argAt($blurAt, 1) : null;

        check(
            'BackdropFilter · 带内边距时模糊矩形仍 = 内容盒 120×80（不是边框盒 130×90）',
            $box?->size()?->width === 130.0 && $box?->size()?->height === 90.0
                && $rect instanceof Rect
                && $rect->width === 120.0 && $rect->height === 80.0,
            'box=' . \var_export($box?->size(), true) . ' rect=' . self::rectText($rect),
        );
    }

    public function testZeroSigmaIsLegal(): void
    {
        // 反例：sigma(0) 语义上就是"不模糊"，不该被当成错误
        $ok = true;
        try {
            BackdropFilter::make(self::kid(10.0, 10.0), 0.0);
        } catch (\Throwable) {
            $ok = false;
        }
        check(
            'BackdropFilter · sigma=0 合法（= 不模糊，不是错误）',
            $ok,
            'threw=' . \var_export(!$ok, true),
        );
    }
}
