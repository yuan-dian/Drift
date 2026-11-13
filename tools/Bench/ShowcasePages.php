<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

use App\Showcase\Pages\AlignPage;
use App\Showcase\Pages\AspectPage;
use App\Showcase\Pages\BigListPage;
use App\Showcase\Pages\ButtonsPage;
use App\Showcase\Pages\CalendarPage;
use App\Showcase\Pages\CustomPaintPage;
use App\Showcase\Pages\DisabledPage;
use App\Showcase\Pages\DropPage;
use App\Showcase\Pages\FormPage;
use App\Showcase\Pages\GlassPage;
use App\Showcase\Pages\GridPage;
use App\Showcase\Pages\IconPage;
use App\Showcase\Pages\ImagePage;
use App\Showcase\Pages\LayoutPage;
use App\Showcase\Pages\ListPage;
use App\Showcase\Pages\LogPage;
use App\Showcase\Pages\MotionPage;
use App\Showcase\Pages\PickerPage;
use App\Showcase\Pages\RichTextPage;
use App\Showcase\Pages\SegmentedPage;
use App\Showcase\Pages\SelectPage;
use App\Showcase\Pages\SliderPage;
use App\Showcase\Pages\TablePage;
use App\Showcase\Pages\TextPage;
use App\Showcase\Pages\TooltipPage;
use App\Showcase\Pages\TransformPage;
use App\Showcase\Pages\TreePage;
use Drift\Widget\Widget;

/**
 * `apps/showcase` 的页面清单 —— **显式**，给 AOT 用。
 *
 * ★ 为什么不扫磁盘（2026-11-04）：原来 `--pages` 靠 `glob` + `ReflectionClass` +
 *   `new $class()` 发现页面，解释器下完全够用。但 `probes/frame_tax_aot` 要的正是
 *   「真实 27 页」的 AOT 口径，而 **AOT 是静态编译**：反射构造任意类这条路不通。
 *   ⇒ 只能把「有哪些页、怎么 new」写成字面量。
 *
 * ★ 清单的代价与对价：手写清单会**漂移** —— 新加一页忘了填，它就从分母里静默消失，
 *   而且所有判据照绿（页数 27→26 仍 ≥20）。对价是 `frame-tax-probe.php --pages --check`
 *   的判据 ⑤：**磁盘上可用的页 == 本清单**，多一个少一个都红。
 *   ⇒ 分层不是妥协：「清单」必须 AOT 可编（纯静态），「比对」只能解释器做（要反射）。
 *
 * ★ 只收**构造器无必填参数**的页（27 / 34）。另外 7 页（`AsyncTablePage` /
 *   `ComboBoxPage` / `ConcurrentFetchPage` / `DialogPage` / `FeedbackPage` /
 *   `LoadMorePage` / `SnackbarPage`）要注入 `\Closure $fetchRows` 之类，
 *   与 `golden-raster --pages` 的规则逐条相同 —— 量的是"打开一页的成本"，
 *   不是"给它接一个假数据源"的成本。
 *
 * ★ 单一数据源：`names()` 由键派生、`make()` 由同一个表取 —— **两者不可能不同步**。
 *   这比「一个名字数组 + 一个 match 分支表」好：那种写法有两份数据，漏改一处就静默错。
 *
 * ★ 本文件还被 `scene-set-check.php` 在没有 autoload 的情况下直接 require，只为读
 *   `names()`。⇒ 表里的值必须保持延迟闭包，不能在加载期 `new` 页面类或求值框架类型。
 *
 * @internal 量具专用，不进公共 API
 */
final class ShowcasePages
{
    /**
     * 页名 => 构造闭包。
     *
     * ★ 用闭包而不是直接 `new`：直接 `new` 会在**调用 `all()` 时就造出 27 棵树**，
     *   既占内存又让页面构造的副作用提前发生，量的不再是"打开这一页"。
     *
     * @return array<string, \Closure(): Widget>
     */
    public static function all(): array
    {
        return [
            'AlignPage' => static fn(): Widget => new AlignPage(),
            'AspectPage' => static fn(): Widget => new AspectPage(),
            'BigListPage' => static fn(): Widget => new BigListPage(),
            'ButtonsPage' => static fn(): Widget => new ButtonsPage(),
            'CalendarPage' => static fn(): Widget => new CalendarPage(),
            'CustomPaintPage' => static fn(): Widget => new CustomPaintPage(),
            'DisabledPage' => static fn(): Widget => new DisabledPage(),
            'DropPage' => static fn(): Widget => new DropPage(),
            'FormPage' => static fn(): Widget => new FormPage(),
            'GlassPage' => static fn(): Widget => new GlassPage(),
            'GridPage' => static fn(): Widget => new GridPage(),
            'IconPage' => static fn(): Widget => new IconPage(),
            'ImagePage' => static fn(): Widget => new ImagePage(),
            'LayoutPage' => static fn(): Widget => new LayoutPage(),
            'ListPage' => static fn(): Widget => new ListPage(),
            'LogPage' => static fn(): Widget => new LogPage(),
            'MotionPage' => static fn(): Widget => new MotionPage(),
            'PickerPage' => static fn(): Widget => new PickerPage(),
            'RichTextPage' => static fn(): Widget => new RichTextPage(),
            'SegmentedPage' => static fn(): Widget => new SegmentedPage(),
            'SelectPage' => static fn(): Widget => new SelectPage(),
            'SliderPage' => static fn(): Widget => new SliderPage(),
            'TablePage' => static fn(): Widget => new TablePage(),
            'TextPage' => static fn(): Widget => new TextPage(),
            'TooltipPage' => static fn(): Widget => new TooltipPage(),
            'TransformPage' => static fn(): Widget => new TransformPage(),
            'TreePage' => static fn(): Widget => new TreePage(),
        ];
    }

    /** @return list<string> 按字典序（`all()` 已排好，`array_keys` 保值） */
    public static function names(): array
    {
        return \array_keys(self::all());
    }

    /**
     * 造一页。
     *
     * ★ 未知名字**抛异常**而不是返回 `null`：调用方要的是 `\Closure(): Widget`，
     *   返回 `?Widget` 会把空值推到布局里，症状是"这页怎么是空的"，离原因很远。
     */
    public static function make(string $name): Widget
    {
        $factory = self::all()[$name] ?? null;
        if ($factory === null) {
            throw new \InvalidArgumentException('未知页面：' . $name);
        }

        return $factory();
    }
}
