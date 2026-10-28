<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Errors;
use Drift\Geometry\EdgeInsets;
use Drift\Rendering\Color;
use Drift\Rendering\Gradient;
use Drift\Rendering\Modifier;

/**
 * `Widget` 的**修饰面**：22 个 fluent setter，全部写同一个 `$this->mods`。
 *
 * | 组 | 方法 |
 * |---|---|
 * | 内边距 / 尺寸 | `padding` `paddingAll` `paddingOnly` `boxSize` `width` `height` `aspect` |
 * | 绘制 | `background` `border` `radius` `shadow` |
 * | 合成 / 裁剪 | `opacity` `translate` `clip` `repaintBoundary` `centerContent` |
 * | 主轴意图 | `grow` `shrink` `basis` `alignSelf` |
 * | 层叠意图 | `positioned`（`Stack` 定位） |
 *
 * ★ **这些方法子类通常不该覆写**（它们是"给任意控件贴一层"的统一入口）。
 *   例外是自带样式体系的 `BoxWidget` 子类：它们覆写同名方法写自己的 `BoxStyle`
 *   —— 那条边界（`Modifier` vs `BoxStyle`）是已知的双轨制，见
 *   `docs/REVIEW-2026-10-28.md` §1.2 L2。
 * ★ 字段 `$mods` 与读侧 `mods()` 留在 `Widget`：`Element::syncBox()` 只认那一个引用。
 *
 * @internal 与 `Widget` 同生共死；应用只看 `Widget` 的方法列表
 */
trait WidgetModifiers
{
    /**
     * 四边等距内边距。
     *
     * ```php
     * Text::make('hi')->paddingAll(8.0)          // 简写
     * $x->padding(EdgeInsets::symmetric(12.0, 8.0));
     * ```
     *
     * ★ 收 `EdgeInsets` 而不是 `float`：`padding(EdgeInsets)` 在 `Flex` /
     *   `LazyColumn` / `PopupPanel` / `Card` / `Padding` 上**已经存在**且被
     *   `apps/` 里十几处调用。基类收 `float` 会让那些子类变成"不兼容覆写"
     *   ⇒ 整个 `src/` 装载不起来。等距用法由 `paddingAll()` 覆盖。
     *
     * ★★ **`padding` 是唯一一个"不该统一"的修饰**（S1 的边界，2026-10-28）。
     *   这一份写 `$mods`，由 `RenderBox::layout()` 的 ②⑤ 统一施加。
     *   但容器类（`Flex` / `LazyList` / `LazyGrid` / `PopupPanel`）**刻意覆写它**
     *   写自己的字段 —— 因为它们要拿 padding **参与排布数学**（`RenderFlex` 在
     *   `deflate()` 之后直接用 `padding->left` 算 pen，`LazyGrid` 把它转给内部
     *   `Column`）。塞回 `$mods` 会让 ②⑤ 也做一次 ⇒ **双份内距**，且不报错。
     *   ⇒ 判据：**"盒怎么让位"→ `$mods`；"布局算法怎么用这个数"→ 自己字段**。
     *   背景/边框/圆角没有这个二义性（渲染层只画一次），所以它们统一到了 `$mods`。
     *
     * @api
     */
    public function padding(EdgeInsets $insets): static
    {
        $this->mutate();
        $this->mods = $this->mods->withPadding(
            $insets->left,
            $insets->top,
            $insets->right,
            $insets->bottom,
        );

        return $this;
    }

    /** 四边等距（`padding()` 的简写） */
    public function paddingAll(float $all): static
    {
        $this->mutate();
        $this->mods = $this->mods->withPadding($all, $all, $all, $all);

        return $this;
    }

    /** 逐边设置；`null` = 不动这一边（便于分次补设） */
    public function paddingOnly(
        ?float $left = null,
        ?float $top = null,
        ?float $right = null,
        ?float $bottom = null
    ): static {
        $this->mutate();

        $l = $this->mods->padLeft();
        $t = $this->mods->padTop();
        $r = $this->mods->padRight();
        $b = $this->mods->padBottom();

        if ($left !== null) {
            $l = $left;
        }
        if ($top !== null) {
            $t = $top;
        }
        if ($right !== null) {
            $r = $right;
        }
        if ($bottom !== null) {
            $b = $bottom;
        }

        $this->mods = $this->mods->withPadding($l, $t, $r, $b);

        return $this;
    }

    /**
     * 定尺寸（`SizedBox` 的字段形态）：给了的那一轴**钉死**，`null` = 该轴透传。
     *
     * ★ 叫 `boxSize` 而不是 `size`：`Text::size()` 是字号、`SizedBox::size()` 是
     *   两个必选参数 —— 两个都不兼容，基类叫 `size()` 会让它们变成非法覆写。
     *
     * @api
     */
    public function boxSize(?float $width = null, ?float $height = null): static
    {
        $this->mutate();

        $w = \NAN;
        if ($width !== null) {
            $w = $width;
        }
        $h = \NAN;
        if ($height !== null) {
            $h = $height;
        }

        $this->mods = $this->mods->withSize($w, $h);

        return $this;
    }

    /** 只定宽（高随内容） */
    public function width(float $width): static
    {
        $this->mutate();
        $this->mods = $this->mods->withWidth($width);

        return $this;
    }

    /** 只定高（宽随内容） */
    public function height(float $height): static
    {
        $this->mutate();
        $this->mods = $this->mods->withHeight($height);

        return $this;
    }

    /**
     * 宽高比（宽 ÷ 高）—— `AspectRatio` 的字段形态，任意控件可用。
     *
     * ```php
     * Image::make('cover.png')->aspect(16 / 9);   // 宽随父容器，高按比例
     * ```
     *
     * ★ **显式尺寸优先**：`->width(320)->aspect(16 / 9)` ⇒ 高 = 320 ÷ (16/9)。
     *   两轴都显式给了 ⇒ 比例没有自由度（`tighten()` 只把两轴变紧，不再解比例）。
     * ★ 至少一轴有界才生效；两轴都无界时比例给不出绝对尺寸（保持原约束）。
     * ★★ **AOT 下写 `16.0 / 9.0`，不要写 `16 / 9`**：后者在 tpc 生成的 C++ 里是
     *   **整数除法**（= 1），而解释器里是 1.7778 ⇒ 真机悄悄变成 1:1（2026-10-24 实测）。
     */
    public function aspect(float $ratio): static
    {
        if ($ratio <= 0.0 || \is_nan($ratio) || \is_infinite($ratio)) {
            Errors::report(\sprintf(
                '宽高比必须是有限正数，实际传入 %s。' . "\n" . '修法：写成 `->aspect(16 / 9)` 这样的正数；'
                    . '不需要比例就别调这个方法。',
                (string) $ratio
            ));
        }

        $this->mutate();
        $this->mods = $this->mods->withAspect($ratio);

        return $this;
    }

    /**
     * 底色。
     *
     * ★ `BoxWidget` 的子类（`Button` / `PopupPanel` / …）覆写了本方法、写进
     *   `BoxStyle` —— 那是控件自带的整套样式，与 `mods` 的"贴一层装饰"是两件事，
     *   刻意不合并。
     *
     * @api
     */
    public function background(Color $color): static
    {
        $this->mutate();
        $this->mods = $this->mods->withFill($color);

        return $this;
    }

    /**
     * 底色换成一个**渐变**（盒内比例的形态，不需要知道盒多大）。
     *
     * ```php
     * Card::make($body)->gradient(Gradient::vertical([Color::white(), $shade]))
     * Button::primary('保存')->gradient(Gradient::horizontal([$a, $b]))
     * ```
     *
     * ★ **渐变赢**：与 `->background($c)` 同时声明时画的是渐变；`->gradient(null)`
     *   清掉它，实色才算数（`withGradient(null)` 在 `Modifier` 侧叫 `withoutGradient`）。
     * ★ 坐标是 `0..1` 的比例 —— `Gradient::linear(20,20,180,20,...)` 那种**绝对坐标**
     *   的渐变照样能传（`resolvedTo()` 会原样放行），但那样就要求你知道盒尺寸，
     *   一般只有在自定义 `CustomPainter` 里才这么写。
     *
     * @api
     */
    public function gradient(?Gradient $gradient): static
    {
        $this->mutate();
        $this->mods = $gradient === null
            ? $this->mods->withoutGradient()
            : $this->mods->withGradient($gradient);

        return $this;
    }

    public function border(Color $color, float $width = 1.0): static
    {
        $this->mutate();
        $this->mods = $this->mods->withStroke($color, $width);

        return $this;
    }

    public function radius(float $radius): static
    {
        $this->mutate();
        $this->mods = $this->mods->withRadius($radius);

        return $this;
    }

    /**
     * 柔和投影（`$alpha` = 黑色浓度，`0.0` = 不画；档位见 `Elevation`）。
     *
     * ★ 参数是**浓度**不是像素高度：模糊与下移由渲染层按浓度等比推出
     *   （`Modifier::paintBehind()`）—— 一个数控制"浮起多少"。
     * ★ 阴影画在**盒外** ⇒ 父布局不会为它留白，块之间要留 ≥ 4px 间距，
     *   否则邻块底色会盖掉它。
     *
     * @api
     */
    public function shadow(float $alpha): static
    {
        $this->mutate();
        $this->mods = $this->mods->withShadow($alpha);

        return $this;
    }

    /**
     * 不透明度（`1.0` = 不透明）。
     *
     * ★ 这是**声明值**：`RenderBox::setMods()` 读一次去初始化盒上的合成参数，
     *   此后动画走 `RenderBox::setCompose()` 直接写盒、不重建不重排。
     *   rebuild 之后回到这里声明的值（结构变了，动画中间态就不该继续顶着）。
     *
     * @api
     */
    public function opacity(float $value): static
    {
        $this->mutate();

        if ($value < 0.0 || $value > 1.0) {
            Errors::report(\sprintf('opacity 应在 0.0..1.0，收到 %.3f', $value));
        }

        $this->mods = $this->mods->withOpacity($value);

        return $this;
    }

    /**
     * 绘制期平移（不开层、不重排）—— 合成维，与 `opacity()` 同一组。
     *
     * @api
     */
    public function translate(float $dx, float $dy): static
    {
        $this->mutate();
        $this->mods = $this->mods->withTranslate($dx, $dy);

        return $this;
    }

    /**
     * 把**内容**裁到盒矩形（含内边距）。`clip(false)` 可显式关掉。
     *
     * ★ 与容器声明 `->overflow(Overflow::Clip)` 的分工：`Overflow` 管**布局期**
     *   "放不下时报错还是夹住"，`clip()` 管**绘制期**"画的时候裁掉"。会超的容器
     *   两者都要 —— 只声明 `Overflow::Clip` 只是"不报错"，内容仍然画到盒外。
     * ★ 装饰（背景 / 边框 / 圆角 / 阴影）**不**被裁：阴影本来就在盒外。
     *
     * @api
     */
    public function clip(bool $on = true): static
    {
        $this->mutate();
        $this->mods = $this->mods->withClip($on);

        return $this;
    }

    /**
     * 声明本盒为**重绘边界**：内容没变时只重放上次录下的绘制命令，跳过整棵子树的 `paint()`。
     *
     * ★ 只给**粗粒度**子树开：滚动容器内容区、`LazyColumn` 的行、`Table` 的行区。
     *   给小盒（每个文本一层）开是负收益 —— 每帧录一条命令比直接画还慢。
     * ★ 开了不等于一定更快：内容常变的盒会每次录 + 每次放，比不缓存多一份命令数组。
     *
     * @api
     */
    public function repaintBoundary(bool $on = true): static
    {
        $this->mutate();
        $this->mods = $this->mods->withRepaintBoundary($on);

        return $this;
    }

    /**
     * 把**内容**在本盒内居中（`Center` 的字段形态）。
     *
     * ★ 与 `alignSelf()` 不是一件事：`alignSelf` 只在 `RenderFlex` 里被消费
     *   （没有 Flex 父就是死字段），而 `Center` 常常是页面根。
     * ★ 叫 `centerContent` 而不是 `center`：`Dock::center(Widget)` 是五区槽位。
     *
     * @api
     */
    public function centerContent(bool $on = true): static
    {
        $this->mutate();
        $this->mods = $this->mods->withCenter($on);

        return $this;
    }

    /**
     * 主轴放大份额（`Expanded` 的字段形态）。
     *
     * ★ `RenderFlex` 读的是盒上的 `grow()`，`Element::syncBox()` 把这里声明的值
     *   递过去 —— 所以 `Expanded::make($x)->child()` 只是 `$x->grow(1)`。
     *
     * @api
     */
    public function grow(int $weight): static
    {
        $this->mutate();

        if ($weight < 0) {
            Errors::report(\sprintf('grow 份额不能为负，收到 %d', $weight));
        }

        $this->mods = $this->mods->withGrow($weight);

        return $this;
    }

    /**
     * 主轴压缩份额（`Flexible` 的字段形态）。
     *
     * 空间不够时按 `shrink × 起始尺寸` 加权压缩（`04` §3.2，≤3 轮冻结迭代）。
     *
     * ★ **下限是 0，没有保底**（`04` §3.4）：被压到 0 的控件内容不可见，但
     *   **不报错**。今天也没有"设 `width` 就压不动"这回事 —— `Modifier::tighten()`
     *   是把 `width` 夹进父给的区间，紧约束 `(0, 0)` 会把 `width` 一起夹成 0。
     *   要真正的下限得等 `min-content`，而它需要文本的最小宽度（今天没有）。
     */
    public function shrink(int $weight): static
    {
        $this->mutate();

        if ($weight < 0) {
            Errors::report(\sprintf('shrink 份额不能为负，收到 %d', $weight));
        }

        $this->mods = $this->mods->withShrink($weight);

        return $this;
    }

    /** 分配前的起始主轴尺寸（不设 = 用自然尺寸） */
    public function basis(float $basis): static
    {
        $this->mutate();
        $this->mods = $this->mods->withBasis($basis);

        return $this;
    }

    /**
     * 声明本控件在**层叠父容器**（`Stack`）里的位置（A4，2026-10-28）。
     *
     * ```php
     * Stack::make()->children([
     *     $photo,
     *     Text::make('NEW')->positioned(right: 8.0, top: 8.0),   // 右上角标
     * ]);
     * ```
     *
     * ★ `null` = 该边不声明（不是 0）：
     *   · 声明**一条** ⇒ 贴那边（`right: 8` = 距右 8，尺寸仍由内容定）；
     *   · 声明**两条对边** ⇒ 那一轴被**拉伸**（`left: 0, right: 0` = 铺满整层）；
     *   · 没声明的轴按 `Stack::alignment()`。
     *
     * ★ **只有 `Stack` 读它**（与 `alignSelf()` 只有 `Row`/`Column` 读同款）。
     *   父不是 `Stack` ⇒ 这四条边是死字段、**不报错** —— 这条账记在
     *   `RenderBox` 的 `$insetLeft` 上，由 `ParentIntentTest` 盯着别再扩族。
     *
     * ★ 定位子**不撑大 `Stack`**（`RenderStack::layoutPositioned()` 的注）：全是
     *   定位子的 `Stack` 尺寸是 0 ⇒ 那种写法要自己套 `SizedBox`。
     *
     * @api
     */
    public function positioned(
        ?float $left = null,
        ?float $top = null,
        ?float $right = null,
        ?float $bottom = null,
    ): static {
        $this->mutate();

        $mods = $this->mods;
        if ($left !== null) {
            $mods = $mods->withInset(Modifier::INSET_LEFT, $left);
        }
        if ($top !== null) {
            $mods = $mods->withInset(Modifier::INSET_TOP, $top);
        }
        if ($right !== null) {
            $mods = $mods->withInset(Modifier::INSET_RIGHT, $right);
        }
        if ($bottom !== null) {
            $mods = $mods->withInset(Modifier::INSET_BOTTOM, $bottom);
        }
        $this->mods = $mods;

        return $this;
    }

    /**
     * 交叉轴自对齐（覆盖父容器的 `crossAlign`）。
     *
     * @param int $align `RenderBox::ALIGN_INHERIT` / `ALIGN_START` / `ALIGN_CENTER`
     *                   / `ALIGN_END` / `ALIGN_STRETCH`
     */
    public function alignSelf(int $align): static
    {
        $this->mutate();
        $this->mods = $this->mods->withAlign($align);

        return $this;
    }
}
