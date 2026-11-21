<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Layout;

use Drift\Core\Pipeline;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\Alignment;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;
use Drift\Layout\Overflow;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\Flex;
use Drift\Widget\Padding;
use Drift\Widget\SizedBox;
use Drift\Widget\Stack;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 盒复用（`RenderBoxWidget::updateRenderObject()`）与"新盒 ⇒ 祖先必脏"。
 *
 * ## 为什么这两条要一起钉
 *
 * 复用盒才会让祖先**命中布局缓存**；一旦命中，就要求"新盒出现时祖先必须被标脏"
 * 是**无条件成立**的。少了后者，复用盒 + 缓存就是一对静默错：新盒 `size` 恒 null、
 * 从不 `performLayout()`，它的子层留着上一轮的偏移 —— 画面错位、不报错、像素金标
 * 也未必覆盖到（实测踩的就是表头列线错位 60px）。
 *
 * ★ 判据一律看**对象身份与几何值**，不看画面：像素相同也可能是"每次都造新盒"，
 *   那等于把复用的收益全丢掉，却验不出任何东西。
 */
final class BoxReuseTest extends SmokeTestCase
{
    private static function constraints(): BoxConstraints
    {
        return BoxConstraints::tight(new Size(200.0, 120.0));
    }

    /** 挂载 + 首帧布局 */
    private static function mount(Widget $widget): Element
    {
        $element = $widget->createElement();
        $element->mount(null, smokeContext());
        $element->effectiveBox()?->layout(self::constraints());

        return $element;
    }

    private static function color(ColorToken $token): Color
    {
        return ThemeData::light()->color($token);
    }

    /**
     * 配置不变 ⇒ 盒**同一个实例**（这才是"复用"）。
     *
     * 反面判据也一起给：配置变了（`padding` 属于几何）⇒ 仍复用同一个盒，但必须
     * 标布局脏 —— 否则"改了内距尺寸不动"，静默。
     */
    public function testProxyBoxIsReusedAndGeometryChangeMarksItDirty(): void
    {
        $surface = self::color(ColorToken::Surface);
        $element = self::mount(
            SizedBox::make()->size(40.0, 20.0)->background($surface)
        );

        $first = $element->renderObject();
        check('盒复用 · 首帧造出渲染盒', $first instanceof RenderBox, $first === null ? 'null' : $first::class);

        // ① 同配置换新 Widget 实例（重建的同构产物）⇒ 盒不换
        $element->update(SizedBox::make()->size(40.0, 20.0)->background($surface));
        $second = $element->renderObject();
        check(
            '盒复用 · 配置不变 ⇒ 盒是同一个实例',
            $first !== null && $first === $second,
            $second === null ? 'null' : ($first === $second ? '同一个' : '换了新盒'),
        );

        // ② 只改几何（padding）⇒ 复用盒，但必须标脏
        $element->update(SizedBox::make()->size(40.0, 20.0)->background($surface)->paddingAll(6.0));
        $third = $element->renderObject();
        check(
            '盒复用 · 改 padding ⇒ 仍复用同一个盒',
            $first === $third,
            $third === null ? 'null' : ($first === $third ? '同一个' : '换了新盒'),
        );
        check(
            '盒复用 · 改 padding ⇒ 标布局脏（否则静默不重排）',
            $third !== null && $third->needsLayout(),
            $third === null ? 'null' : ($third->needsLayout() ? '已标脏' : '没标脏'),
        );
    }

    /**
     * 只改颜色 ⇒ 盒复用、**不**标布局脏，但必须请求重绘。
     *
     * `Frame::run()` 的 paint 门控是"本帧 layout 跑过 或 paintEpoch 变"。复用盒不会
     * bump layoutEpoch ⇒ 不在 `setMods()` 里推 paintEpoch，颜色就永远停在上一帧。
     */
    public function testColorOnlyChangeReusesBoxAndRequestsRepaint(): void
    {
        $element = self::mount(
            SizedBox::make()->size(40.0, 20.0)->background(self::color(ColorToken::Border))
        );
        $box = $element->renderObject();
        $epoch = Pipeline::current()->paintEpoch();

        $element->update(
            SizedBox::make()->size(40.0, 20.0)->background(self::color(ColorToken::Accent))
        );
        $after = $element->renderObject();

        check('盒复用 · 只改颜色 ⇒ 盒不换', $box !== null && $box === $after);
        check(
            '盒复用 · 只改颜色 ⇒ 不标布局脏（尺寸没变，重排是白做）',
            $after !== null && !$after->needsLayout(),
            $after === null ? 'null' : ($after->needsLayout() ? '误标了脏' : '未标脏'),
        );
        check(
            '盒复用 · 只改颜色 ⇒ 请求重绘（publishEpoch 必须前进）',
            Pipeline::current()->paintEpoch() > $epoch,
            'paintEpoch ' . $epoch . ' → ' . Pipeline::current()->paintEpoch(),
        );
    }

    /**
     * **直推路径**（`rebuild()` + `layout()`，不过 `settlePass()`）下新盒也必须让祖先变脏。
     *
     * 这条是从真实故障反推出来的：表头列线悬停后第二条起整列错位 60px，根因就是
     * "容器换了新盒，但根盒命中缓存、`layout()` 直接返回，新盒永远没被排过"。
     * 曾经把标脏放在 `settlePass()` 的**后序**里 —— 走 `Frame` 没问题，走
     * `rebuild() + layout()`（测试夹具与若干工具脚本就是这么推帧的）就整段跳过。
     *
     * 夹具刻意做三层：外层 `Padding` 配置不变 ⇒ 它的盒一直是干净的；中间那个
     * `Column` 每次更新都造新盒；最内层尺寸随 State 变。
     *
     * ★ **怎么让复用盒"确实换新"**：`RenderFlex` 现在会做配置判等（同 `Text`），
     *   光重建不再换盒。要造出新盒又**不引入任何几何变化**，就切 `overflow`
     *   （Report ↔ Clip）—— 配置不等 ⇒ 换盒；不溢出时裁剪不影响任何尺寸与偏移。
     */
    public function testFreshContainerBoxDirtiesAncestorsOnDirectRebuildPath(): void
    {
        // 根就是 State 自己（不经过宿主），结构：Padding(10) → Column → 两个定宽 SizedBox。
        // 重建时**没有任何几何变化**，但 `Column` 换新盒（`overflow` 变了）。
        // 这正是表头列线那次故障的形状：新盒出现了，可它的祖先全都干净。
        $element = self::mount(new BoxReuseProbe());

        $padding = $element->childAt(0);
        $column = $padding?->childAt(0);
        check('直推路径 · 中间层是 Padding → Column', $column !== null);

        $first = $column?->renderObject();
        check(
            '直推路径 · 首帧中间层已排过',
            $first !== null && $first->size() !== null,
            $first === null || $first->size() === null ? 'size=null' : '有尺寸',
        );

        // ★ **只** rebuild + layout（不 settle）—— 测试夹具与若干工具脚本就是这么推帧的，
        //   它们不经过 `settlePass()`。曾经把"新盒 ⇒ 祖先标脏"放在 settlePass 后序里，
        //   于是这条路径整套跳过：根盒命中缓存，新盒永远没被排过。
        $probe = $element->state();
        \assert($probe instanceof BoxReuseProbeState);
        $probe->clip = true;                 // 配置变 ⇒ 换新盒；几何与像素都不变
        $element->state()?->markDirty();
        $element->rebuild();
        $element->effectiveBox()?->layout(self::constraints());

        $second = $column?->renderObject();
        check(
            '直推路径 · 中间层换了新盒（这条路径确实造了新盒）',
            $second !== null && $second !== $first,
            $second === $first ? '还是旧盒' : '已换新盒',
        );
        check(
            '直推路径 · 新盒拿到尺寸（祖先没命中缓存把它跳过）',
            $second?->size() !== null,
            $second?->size() === null ? 'size 仍为 null ⇒ 祖先没被标脏' : '有尺寸',
        );

        $leaf = $column?->childAt(0)?->renderObject();
        $leafSize = $leaf?->size();
        check(
            '直推路径 · 子层几何照旧（10 宽）',
            $leafSize !== null && \abs($leafSize->width - 10.0) < 0.01,
            $leafSize === null ? 'null' : \sprintf('%.1f', $leafSize->width),
        );
        check(
            '直推路径 · 第二个子层偏移按上一层实测高度（10）',
            ($column?->childAt(1)?->renderObject()?->offsetY() ?? -1.0) === 10.0,
            \sprintf('%.1f', $column?->childAt(1)?->renderObject()?->offsetY() ?? -1.0),
        );
    }

    /**
     * 复用盒 + 子被**删除** ⇒ 盒不换，但必须标布局脏。
     *
     * 这是 `RenderFlex` 开始判配置等价之后新出现的缺口：删掉一个子不产生任何新盒，
     * 子层也不会 `markLayoutDirtyUp()` 上传（`unmount()` 只 `bumpStructure()`）——
     * 若没人兜，`needsLayout` 仍是 false、`layout()` 直接返回上一轮的 `size`，
     * 容器的自然高度停在旧值（子少了一个，高度没跟着减）且不报错。
     *
     * ★ 为什么必须用"删"而不是"加"：加子会 mount 一个新 Element，它的 `syncBox()`
     *   立刻 `markLayoutDirtyUp()` 把容器顺带标脏 —— 正好掩盖掉这条缺口（同上一轮
     *   "改宽度掩盖缓存缺陷"的夹具陷阱）。
     *
     * 判据：① 盒身份不变（确实复用了）；② 容器高度从 30 变 20（真的重排了）。
     */
    public function testReusedContainerMarksDirtyWhenChildrenRemoved(): void
    {
        // ★ 必须用 **loose** 约束：tight 会把 Column 钉在 120 高，删掉一个子高度也不变，
        //   "没重排"这条缺陷就不可见了。loose 下自然高才是 3×10=30（删后 20）。
        $loose = BoxConstraints::loose(new Size(200.0, 200.0));
        $element = (new BoxChildrenProbe())->createElement();
        $element->mount(null, smokeContext());
        $element->effectiveBox()?->layout($loose);

        $column = $element->childAt(0);
        $first = $column?->renderObject();
        $heightFirst = $first?->size()?->height;
        check(
            '子删除 · 首帧三个子（高 30）',
            $column !== null && $column->childCount() === 3 && $heightFirst === 30.0,
            $column === null ? 'null' : \sprintf('%d 子 / 高 %.1f', $column->childCount(), $heightFirst ?? -1.0),
        );

        $probe = $element->state();
        \assert($probe instanceof BoxChildrenProbeState);
        $probe->dropOne = true;
        $element->state()?->markDirty();
        $element->rebuild();
        $element->effectiveBox()?->layout($loose);

        $second = $column?->renderObject();
        $heightSecond = $second?->size()?->height;
        check(
            '子删除 · 配置没变 ⇒ 复用同一个盒',
            $first !== null && $first === $second,
            $second === null ? 'null' : ($first === $second ? '同一个' : '换了新盒'),
        );
        check(
            '子删除 · 容器高度跟着减（结构变化确实触发重排）',
            $heightSecond === 20.0,
            \sprintf('%.1f', $heightSecond ?? -1.0),
        );
    }
    /**
     * `Stack` 的 `null` 对齐是"默认 TopLeft"的**哨兵**，判等必须解析后比。
     *
     * 拿 `null` 与 `Alignment` 对象直接比永远不等 ⇒ 组合写法（`make()` 不调 alignment）
     * 与等价写法（显式 `topLeft()`）之间复用静默失效 —— 不报错，只是白造盒。
     * 反面判据一起钉：真换成 center 必须换盒。
     */
    public function testStackAlignmentSentinelComparesResolved(): void
    {
        // ★ 顺序有意义：先显式 `topLeft()`、再退回哨兵 `null`。反过来的话新 Widget
        //   带的是对象，`$align === null` 那种错写法根本不会被走到（变异验证会假绿）。
        $element = self::mount(Stack::make()->alignment(Alignment::topLeft())->children([
            SizedBox::make()->size(10.0, 10.0),
        ]));
        $first = $element->renderObject();

        // ① 显式 topLeft ↔ 哨兵 null（等价）⇒ 复用
        $element->update(Stack::make()->children([SizedBox::make()->size(10.0, 10.0)]));
        $second = $element->renderObject();
        check(
            'Stack · 显式 topLeft 与 null 哨兵等价 ⇒ 复用盒',
            $first !== null && $first === $second,
            $second === null ? 'null' : ($first === $second ? '同一个' : '换了新盒'),
        );

        // ② 真换对齐 ⇒ 换盒（几何变了）
        $element->update(Stack::make()->alignment(Alignment::center())->children([
            SizedBox::make()->size(10.0, 10.0),
        ]));
        check(
            'Stack · 换 alignment ⇒ 换盒',
            $element->renderObject() !== null && $element->renderObject() !== $second,
            $element->renderObject() === $second ? '还是旧盒' : '已换新盒',
        );
    }
    /**
     * 单子代理盒：子被去掉 ⇒ 复用盒必须清槽，否则旧子继续被布局与绘制（幽灵节点）。
     *
     * `SizedBox::make()` 允许无子，于是"有子 → 无子"是真实路径；换盒时代价是新盒的
     * `child` 天然为 null，复用时不会 —— 只有挂接方显式清才正确。
     */
    public function testReusedProxyBoxClearsRemovedChild(): void
    {
        $element = self::mount(
            SizedBox::make(Text::make('x'))->size(40.0, 20.0)
        );
        $box = $element->renderObject();
        check(
            '代理盒 · 首帧挂上了子盒',
            $box instanceof \Drift\Layout\RenderProxyBox && $box->child() !== null,
        );

        $element->update(SizedBox::make()->size(40.0, 20.0));
        $after = $element->renderObject();
        check(
            '代理盒 · 去掉子 ⇒ 仍复用同一个盒',
            $box !== null && $box === $after,
            $after === null ? 'null' : ($box === $after ? '同一个' : '换了新盒'),
        );
        check(
            '代理盒 · 去掉子 ⇒ 盒里不再挂旧子（否则幽灵继续画）',
            $after instanceof \Drift\Layout\RenderProxyBox && $after->child() === null,
            $after instanceof \Drift\Layout\RenderProxyBox && $after->child() !== null ? '仍挂着旧子' : '已清空',
        );
    }
    /**
     * 父类判等看不见子类字段 ⇒ 子类盒一律不复用。
     *
     * 夹具是"`Flex` 子类 + `RenderFlex` 子类"（真实形状：`AppBar` / `RenderAppBar`）。
     * 只比五元配置就复用的后果是：子类那份配置变了但盒还是旧的，静默。
     */
    public function testSubclassBoxIsNeverReusedByParentRule(): void
    {
        $element = (new ReuseProbeBar())->extra(1.0)->createElement();
        $element->mount(null, smokeContext());
        $first = $element->renderObject();
        check('子类盒 · 首帧造出 RenderFlex 子类', $first instanceof RenderFlex);

        $element->update((new ReuseProbeBar())->extra(2.0));
        check(
            '子类盒 · 只改子类字段 ⇒ 必须换盒（父类判等看不见它）',
            $element->renderObject() !== null && $element->renderObject() !== $first,
            $element->renderObject() === $first ? '复用了父类判等 ⇒ 子类配置丢失' : '已换新盒',
        );

        // 反面：连子类字段都没变时，也不复用（保守方向，不追求收益）。
        $second = $element->renderObject();
        $element->update((new ReuseProbeBar())->extra(2.0));
        check(
            '子类盒 · 配置全同也换盒（子类不参与父类判等，宁可多造）',
            $element->renderObject() !== $second,
            $element->renderObject() === $second ? '意外复用了子类盒' : '已换新盒',
        );
    }
}

/** 夹具：Flex 子类，带一个父类看不见的字段 */
final class ReuseProbeBar extends Flex
{
    private float $extra = 1.0;

    public function __construct()
    {
        parent::__construct();
    }

    protected function isVertical(): bool
    {
        return true;
    }

    public function extra(float $value): static
    {
        $this->mutate();
        $this->extra = $value;

        return $this;
    }

    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new ReuseProbeBarBox($this->extra);
    }
}

final class ReuseProbeBarBox extends RenderFlex
{
    public function __construct(public readonly float $extra)
    {
        parent::__construct(true);
    }
}
final class BoxReuseProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new BoxReuseProbeState();
    }
}

final class BoxReuseProbeState extends State
{
    /** 切换后 `RenderFlex` 配置不等 ⇒ 换新盒；不溢出时几何毫无变化 */
    public bool $clip = false;

    public function build(BuildContext $context): Widget
    {
        // 外层 Padding 的配置恒定 ⇒ 它的盒永远干净；内层 Column 每帧换新盒。
        // 这正是"干净的祖先缓存遮住新盒"的最小复现形态。
        return Padding::make(
            Column::make()
                ->overflow($this->clip ? Overflow::Clip : Overflow::Report)
                ->children([
                    SizedBox::make()->size(10.0, 10.0),
                    SizedBox::make()->size(20.0, 10.0),
                ])
        )->paddingAll(10.0);
    }
}

/** 夹具：Column 配置恒定，只有子数随 State 变（3 → 2） */
final class BoxChildrenProbe extends StatefulWidget
{
    public function createState(): State
    {
        return new BoxChildrenProbeState();
    }
}

final class BoxChildrenProbeState extends State
{
    public bool $dropOne = false;

    public function build(BuildContext $context): Widget
    {
        $kids = [
            SizedBox::make()->size(10.0, 10.0),
            SizedBox::make()->size(20.0, 10.0),
            SizedBox::make()->size(30.0, 10.0),
        ];
        if ($this->dropOne) {
            \array_pop($kids);   // 只删不加：unmount 不标脏，缺口全靠 adoptBoxes 兜
        }

        return Column::make()->children($kids);
    }
}
