<?php
declare(strict_types=1);

namespace Drift\Widget\Traits;

use Drift\Errors;
use Drift\Geometry\Alignment;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Matrix2D;
use Drift\Rendering\Color;
use Drift\Rendering\DashPattern;
use Drift\Rendering\Gradient;
use Drift\Rendering\Modifier;

/**
 * `Widget` 的修饰面：27 个 fluent setter，全部写同一个 `$this->mods`。
 *
 * 子类通常不该覆写这些方法（它们是"给任意控件贴一层"的统一入口）；
 * 字段 `$mods` 与读侧 `mods()` 留在 `Widget`（`Element::syncBox()` 只认那一个引用）。
 *
 * @internal 与 `Widget` 同生共死；应用只看 `Widget` 的方法列表
 */
trait WidgetModifiers
{
    /**
     * 四边等距内边距。
     *
     * ★ `padding` 是唯一"不该统一"的修饰：容器类刻意覆写它写自己的字段来拿
     *   padding 参与排布数学；塞回 `$mods` 会让布局再施加一次 ⇒ 双份内距且不报错。
     *   判据："盒怎么让位"→ `$mods`；"布局算法怎么用这个数"→ 自己字段。
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
     * 定尺寸（`SizedBox` 的字段形态）：给了的那一轴钉死，`null` = 该轴透传。
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
     * ★ AOT 下写 `16.0 / 9.0` 不要写 `16 / 9`：后者在 tpc 生成的 C++ 里是
     *   整数除法（= 1），解释器里是 1.7778 ⇒ 真机悄悄变成 1:1。
     * @api
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
     * @api
     */
    public function background(Color $color): static
    {
        $this->mutate();
        $this->mods = $this->mods->withFill($color);

        return $this;
    }

    /**
     * 底色换成一个渐变（盒内比例的形态，不需要知道盒多大）。
     * 与 `->background($c)` 同时声明时画渐变；`->gradient(null)` 清掉它，实色才算数。
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

    /**
     * 边框改虚线（`null` = 回到实线）。
     * 只改图案：颜色与宽度归 `border()`；没声明 `border()` 时它什么也不做。
     * @api
     */
    public function borderDash(?DashPattern $dash): static
    {
        $this->mutate();
        $this->mods = $this->mods->withBorderDash($dash);

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
     * 参数是浓度不是像素高度，模糊与下移由渲染层按浓度等比推出。
     * 阴影画在盒外 ⇒ 父布局不留白，块间留 ≥ 4px 否则邻块底色盖掉它。
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
     * 声明值：`RenderBox::setMods()` 读一次初始化合成参数，动画走 `RenderBox::setCompose()`
     * 直接写盒；rebuild 后回到这里声明的值。
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
     * @api
     */
    public function translate(float $dx, float $dy): static
    {
        $this->mutate();
        $this->mods = $this->mods->withTranslate($dx, $dy);

        return $this;
    }

    /**
     * 禁用：不接指针、不进 Tab 序、不进可聚焦表、语义 `enabled=false`、置灰。
     * 一个声明、四处消费：命中测试早退、焦点跳过、语义 `IsEnabled=false`、绘制乘 `DISABLED_ALPHA`。
     * 与 `skipFocus()` 分工：后者只移出 Tab 序仍点得动，本方法连"点得动"一起取消。
     * @api
     */
    public function disabled(bool $on = true): static
    {
        $this->mutate();
        $this->mods = $this->mods->withDisabled($on);

        return $this;
    }

    /**
     * 把内容裁到盒矩形（含内边距）。`clip(false)` 可显式关掉。
     * 与容器 `->overflow(Overflow::Clip)` 分工：前者管布局期"放不下报错还是夹住"，
     * `clip()` 管绘制期"画时裁掉"；会超的容器两者都要。装饰（背景 / 边框 / 圆角 / 阴影）不被裁。
     * @api
     */
    public function clip(bool $on = true): static
    {
        $this->mutate();
        $this->mods = $this->mods->withClip($on);

        return $this;
    }

    /**
     * 声明本盒为重绘边界：内容没变时只重放上次录下的绘制命令，跳过整棵子树的 `paint()`。
     * 只给粗粒度子树开（滚动内容区、LazyColumn 行、Table 行区）；给小盒开是负收益。
     * @api
     */
    public function repaintBoundary(bool $on = true): static
    {
        $this->mutate();
        $this->mods = $this->mods->withRepaintBoundary($on);

        return $this;
    }

    /**
     * 把内容在本盒内按锚点摆放（`Align` / `Stack` 的字段形态）。
     * 与 `alignSelf()` 不同：`alignSelf` 只在 `RenderFlex` 消费（无 Flex 父是死字段），本修饰任何盒都消费。
     * @api
     */
    public function alignContent(Alignment $alignment): static
    {
        $this->mutate();
        $this->mods = $this->mods->withContentAlign($alignment);

        return $this;
    }

    /**
     * 把内容在本盒内居中（`Alignment::center()` 的糖）。
     * @api
     */
    public function centerContent(bool $on = true): static
    {
        $this->mutate();
        // `false` 是撤掉声明（`null`），不是"钉到起点" —— 要钉起点用
        //   `alignContent(Alignment::topLeft())`。
        $this->mods = $this->mods->withContentAlign($on ? Alignment::center() : null);

        return $this;
    }

    /**
     * 主轴放大份额（`Expanded` 的字段形态）。
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
     * 空间不够按 `shrink × 起始尺寸` 加权压缩。下限是 0 无保底：被压到 0 不报错；
     * `width` 也压不动（`tighten()` 把 `width` 夹进父区间，紧约束一起夹成 0）。
     * @api
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
     * 声明本控件在层叠父容器（`Stack`）里的位置。
     * ★ 只有 `Stack` 读它：父不是 `Stack` ⇒ 四条边是死字段、不报错。
     * `null` = 该边不声明（不是 0）：声明一条 ⇒ 贴那边；两条对边 ⇒ 该轴拉伸；
     * 没声明的轴按 `Stack::alignment()`。定位子不撑大 `Stack`（全定位子的 `Stack` 尺寸 0，要自己套 `SizedBox`）。
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
     * @param int $align `RenderBox::ALIGN_INHERIT` / `ALIGN_START` / `ALIGN_CENTER`
     *                   / `ALIGN_END` / `ALIGN_STRETCH`
     */
    public function alignSelf(int $align): static
    {
        $this->mutate();
        $this->mods = $this->mods->withAlignSelf($align);

        return $this;
    }

    /**
     * 绘制期仿射变换：不参与布局，只改画出来的样子。
     * 不影响布局 ⇒ 放大后视觉溢出到邻居；命中测试跟着变换（`RenderBox::hitTest()` 反变换）：
     * 点在变换后可见位置才命中。`$aroundCenter` 为真 ⇒ 以盒中心为原点。
     * @api
     */
    public function transform(Matrix2D $matrix, bool $aroundCenter = false): static
    {
        $this->mutate();
        $this->mods = $this->mods->withTransform($matrix, $aroundCenter);

        return $this;
    }

    /**
     * 绕盒中心旋转 `$degrees` 度（顺时针为正，与 Skia / CSS 同向）。
     * @api
     */
    public function rotate(float $degrees): static
    {
        return $this->transform(Matrix2D::rotation($degrees), true);
    }

    /**
     * 绕盒中心等比缩放（1.0 = 不变；负值会镜像，不拦）。
     * @api
     */
    public function scale(float $factor): static
    {
        return $this->transform(Matrix2D::scaling($factor, $factor), true);
    }
}
