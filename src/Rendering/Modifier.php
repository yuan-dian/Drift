<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;
use Drift\Geometry\Alignment;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;

/**
 * 修饰字段的集合（值对象：不可变、按值比较、可共享）。
 * 它是样式聚合核心：十二处修饰经这里一次性交给盒。
 * 类内直读私有字段，不叫访问器方法（属性读比方法调用廉价）。
 *
 * @api
 */
final class Modifier
{
    // ── 布局维（D_LAYOUT）───────────────────────────────────────────
    private float $padLeft = 0.0;

    private float $padTop = 0.0;

    private float $padRight = 0.0;

    private float $padBottom = 0.0;

    /** NAN = 未设（用内容的自然尺寸）；`0.0` 是"宽 0"这个**合法值** */
    private float $width = \NAN;

    private float $height = \NAN;

    /**
     * 宽高比（宽 ÷ 高）；`NAN` = 未设。
     * 与 `width`/`height` 同族：由 `tighten()` 交给 `BoxConstraints::applyAspect()`，
     * 不是新渲染盒（修饰是字段，不是节点）。
     */
    private float $aspect = \NAN;

    /**
     * 内容在盒内的对齐锚点（Align 的字段形态）。
     * 与 `alignSelf` 不同：`alignSelf` 只在 Flex 父里被消费，无 Flex 父即是死字段；
     * 而 `Align` 常作页面根。对齐是"把直接子盒平移"，由基类 layout() 收尾步施加。
     * `null` = 未声明；九宫格是连续的，`center` 只是 Alignment::center() 一个点。
     */
    private ?Alignment $align = null;

    // ── 绘制维（D_PAINT）────────────────────────────────────────────
    /**
     * 视觉四维（底 / 渐变 / 边 / 圆角）唯一的存储。
     * 收成一个字段：派生表达会漏剥渐变，使自绘控件把渐变画两遍（半透明偏暗），
     * 且不报错。剥 = BoxStyle::none()，没有字段列表可漏。
     * 用非空 BoxStyle（可空性在它内部），避免可空对象属性退化为 php::Var。
     */
    private BoxStyle $style;

    /** 阴影强度 = 黑色透明度（`0.0` = 不画） */
    private float $shadow = 0.0;

    // ── 合成维（D_COMPOSE）──────────────────────────────────────────
    // 这 3 个是初值，不是"当前值"：setMods() 读一次去初始化盒上的
    // composeOpacity/composeDx/composeDy，此后动画走 setCompose() 直接写盒，
    // 不再回到这里。rebuild 后"回到声明的值"依赖此，不能删。
    private float $opacity = 1.0;

    private float $translateX = 0.0;

    private float $translateY = 0.0;

    // ── 交互维（D_INTERACT）──────────────────────────────────────────
    //
    // ★ 必须住修饰符，不能让每个控件自己加 disabled 字段：它要喂命中 / 焦点 /
    //   语义 / 绘制四处，漏一处即"看着灰了但点得动"。修饰符是四处共同上游。
    //
    private bool $disabled = false;

    /**
     * 禁用时是否由框架统一变淡（RenderBox::DISABLED_ALPHA）。
     * 默认 true（多数控件无禁用态配色，整体变淡是唯一表达）。会自绘禁用外观的
     * 控件（ToggleBase/Slider/Segment 换 Muted 标签）须置 false，否则再叠 0.38
     * 的 alpha，深灰字会淡到几乎看不见。由 Widget::paintsDisabledLook() 声明、
     * Element::syncBox() 落实（withoutDim()）。
     */
    private bool $dim = true;

    // ── 主轴弹性意图 ──────────────────────────────────────────────
    private int $grow = 0;

    private int $shrink = 0;

    private float $basis = \NAN;

    private int $alignSelf = 0;      // RenderBox::ALIGN_* 常量

    // ── 层叠定位意图（`Stack` 的 `Positioned`）────────────────────────
    //
    // 与上面四个是同一类东西：都是给父容器的布局意图，只有 RenderFlex 或
    // RenderStack 才读，没有那个父即是死字段、不报错。
    // NAN = 该边未声明（与 $width/$basis 同一条"用 NAN 而非 ?float"的账）。
    //
    private float $insetLeft = \NAN;

    private float $insetTop = \NAN;

    private float $insetRight = \NAN;

    private float $insetBottom = \NAN;

    /** `withInset()` 的边序 —— 与 CSS 的 left/top/right/bottom 同序 */
    public const INSET_LEFT = 0;

    public const INSET_TOP = 1;

    public const INSET_RIGHT = 2;

    public const INSET_BOTTOM = 3;

    // ── 合成变换 ─────────────────────────────────────────────
    //
    // 这是绘制期的变换，与上面那族的"父意图"无关：不进布局、不改约束、不改子盒尺寸，
    // 只是画时整个子树被仿射一下；视觉会溢出（放大 2 倍盖到邻居）是标准语义非缺陷。
    // $transformCentered 是"绕盒中心"的意图标记，不是另一份矩阵：盒尺寸布局期才知，
    // 矩阵只能在 RenderBox::effectiveTransform() 里现算（T(c)·M·T(-c)）。
    //
    private ?Matrix2D $transform = null;

    private bool $transformCentered = false;

    // ── 绘制裁剪（D_PAINT）──────────────────────────────────────────
    /**
     * 内容裁到盒矩形（含内边距）。
     * 与 Overflow 不同：Overflow 是布局期 C5 策略（放不下时报错还是夹住），
     * clip 是绘制期"画时裁掉"。会超的容器两者都要：只声明 Overflow::Clip 是不报错，
     * 不裁仍画到盒外。装饰（背景/边框/圆角/阴影）不被裁：阴影本就画到盒外。
     */
    private bool $clip = false;

    /**
     * 本盒是否声明为重绘边界。
     * 不是视觉属性，与 clip 同类；放这里因 setMods() 已是 Widget→盒的唯一落点，
     * 再开通道就多一处"记得同步"的地方。
     */
    private bool $repaintBoundary = false;

    public function __construct()
    {
        // 视觉四维初值 = "什么都没设"（BoxStyle::none() 是全空值对象）
        $this->style = BoxStyle::none();
    }

    // ── 读：布局（`RenderBox::layout()` 每帧读）─────────────────────

    /** 四边全 0 ⇒ false（省一次 save/translate + 一次子盒遍历） */
    public function hasPadding(): bool
    {
        return $this->padLeft !== 0.0
            || $this->padTop !== 0.0
            || $this->padRight !== 0.0
            || $this->padBottom !== 0.0;
    }

    public function padLeft(): float
    {
        return $this->padLeft;
    }

    public function padTop(): float
    {
        return $this->padTop;
    }

    public function padRight(): float
    {
        return $this->padRight;
    }

    public function padBottom(): float
    {
        return $this->padBottom;
    }

    /** 四边打包（给 `BoxConstraints::deflate()` / `Size::inflate()` 用） */
    public function insets(): EdgeInsets
    {
        return new EdgeInsets($this->padLeft, $this->padTop, $this->padRight, $this->padBottom);
    }

    /**
     * 尺寸修饰收紧约束：设了的那一轴变成紧的那一轴，且先夹进父给的区间。
     * 父更紧时听父的：约束是父给的，子无权突破（tight(100) 里塞 width(200)
     * 不能让 BoxConstraints 抛 "min>max"），那是 Fatal。
     * 两轴都没设 ⇒ 原样返回入参，给绝大多数没设尺寸的盒省一次分配。
     */
    public function tighten(BoxConstraints $c): BoxConstraints
    {
        $hasW = !\is_nan($this->width);
        $hasH = !\is_nan($this->height);
        $hasAspect = !\is_nan($this->aspect);
        if (!$hasW && !$hasH && !$hasAspect) {
            return $c;
        }

        if ($hasW || $hasH) {
            $minW = $c->minWidth;
            $maxW = $c->maxWidth;
            $minH = $c->minHeight;
            $maxH = $c->maxHeight;

            // 属性读取先落局部标量：AOT 下分支里直接读属性会被推成 Variant，
            // 与 float 形参不匹配 ⇒ C2440。
            if ($hasW) {
                $w = $this->width;
                if ($w > $maxW) {
                    $w = $maxW;
                }
                if ($w < $minW) {
                    $w = $minW;
                }
                $minW = $w;
                $maxW = $w;
            }

            if ($hasH) {
                $h = $this->height;
                if ($h > $maxH) {
                    $h = $maxH;
                }
                if ($h < $minH) {
                    $h = $minH;
                }
                $minH = $h;
                $maxH = $h;
            }

            $c = new BoxConstraints($minW, $maxW, $minH, $maxH);
        }

        // 比例在显式尺寸之后施加：已紧的那一轴优先，另一轴由比例推出
        if ($hasAspect) {
            $ratio = $this->aspect;
            $c = $c->applyAspect($ratio);
        }

        return $c;
    }

    /** 该轴是否显式定了尺寸（`NAN` = 未设） */
    public function hasBoxWidth(): bool
    {
        return !\is_nan($this->width);
    }

    public function hasBoxHeight(): bool
    {
        return !\is_nan($this->height);
    }

    /** 显式宽度（`NAN` = 未设；调用前先 `hasBoxWidth()`） */
    public function boxWidth(): float
    {
        return $this->width;
    }

    public function boxHeight(): float
    {
        return $this->height;
    }

    /** 是否设了宽高比（`NAN` = 未设） */
    public function hasAspect(): bool
    {
        return !\is_nan($this->aspect);
    }

    /** 宽高比（`NAN` = 未设；调用前先 `hasAspect()`） */
    public function aspect(): float
    {
        return $this->aspect;
    }

    /** 父约束 → 内容盒约束（内边距内缩） */
    public function deflate(BoxConstraints $c): BoxConstraints
    {
        return $c->deflate($this->insets());
    }

    /** 内容尺寸 → 盒尺寸（内边距加回） */
    public function inflate(Size $size): Size
    {
        return $size->inflate($this->insets());
    }

    /** 内容是否裁到盒矩形（`Widget::clip()` / `Overflow::Clip` 的落点） */
    public function hasClip(): bool
    {
        return $this->clip;
    }

    /** 是否声明为重绘边界（未变则重放上次命令，跳过整棵子树的 `paint()`） */
    public function repaintBoundary(): bool
    {
        return $this->repaintBoundary;
    }

    /** 内容对齐锚点（`null` = 未声明 ⇒ `layout()` 不动子盒偏移） */
    public function alignment(): ?Alignment
    {
        return $this->align;
    }

    // ── 读：主轴意图（`RenderBox::setMods()` 每次造盒读一次）────────

    public function flexGrow(): int
    {
        return $this->grow;
    }

    public function flexShrink(): int
    {
        return $this->shrink;
    }

    public function flexBasis(): float
    {
        return $this->basis;
    }

    public function flexAlign(): int
    {
        return $this->alignSelf;
    }

    // ── 读：层叠定位意图（`RenderStack::performLayout()` 每次布局读）────

    /** 有没有声明任何一边的定位（`Positioned` 用它判"这个子该被定位"） */
    public function hasInset(): bool
    {
        return !\is_nan($this->insetLeft)
            || !\is_nan($this->insetTop)
            || !\is_nan($this->insetRight)
            || !\is_nan($this->insetBottom);
    }

    /** `NAN` = 该边未声明 */
    public function insetLeft(): float
    {
        return $this->insetLeft;
    }

    public function insetTop(): float
    {
        return $this->insetTop;
    }

    public function insetRight(): float
    {
        return $this->insetRight;
    }

    public function insetBottom(): float
    {
        return $this->insetBottom;
    }

    // ── 读：绘制（`RenderBox::paint()` 每帧读）─────────────────────

    /**
     * 底色；`null` = 没设。
     * 必须返回 ?Color：BoxStyle 用 null 表达"没设"，返回占位色等于把"没设"报成黑色。
     */
    public function fillColor(): ?Color
    {
        return $this->style->background;
    }

    /** 底色渐变；`null` = 没设。坐标是盒内比例，画之前要 `resolvedTo()` */
    public function gradient(): ?Gradient
    {
        return $this->style->backgroundGradient;
    }

    public function hasGradient(): bool
    {
        return $this->style->backgroundGradient !== null;
    }

    /**
     * 视觉四维打包成 BoxStyle（就是那一个存储）；什么也不画 ⇒ null。
     * null 判据：isNone()（什么也不画）且无圆角。只设了圆角仍给样式——
     * Button 的"用户声明了圆角"要靠它，null 会被当成"没声明"。
     */
    public function boxStyle(): ?BoxStyle
    {
        if ($this->style->isNone() && $this->style->radius === 0.0) {
            return null;
        }

        return $this->style;
    }

    /**
     * 用户一个盒样式字段都没设过时，填入主题派生的默认样式；设过就原样返回。
     * 传进来的 $style 是非空值对象（BoxStyle::none() 也行，等于不填）。
     * 只填视觉四维，不碰 padding/尺寸/阴影——那些是控件自己的声明。
     *
     * @api
     */
    public function withBoxStyleIfBlank(BoxStyle $style): self
    {
        if (!$this->style->isBlank()) {
            return $this;
        }

        $copy = clone $this;
        $copy->style = $style;

        return $copy;
    }

    /**
     * 派生一份去掉视觉四维（底/渐变/边/圆角）的副本，其余字段原样保留。
     * 自绘 chrome 的控件把样式交给 Render* 由 paintOwnChrome() 画；同一份原样交盒，
     * paint() 会再画一遍。
     * ★ 渐变也必须剥：它同属"底"（hasBackground() 把实色与渐变更算一维）；
     *   旧实现逐字段剥漏了 gradient ⇒ 声明 gradient() 的 Button 画两遍（偏暗）且不报错。
     *   剥 = BoxStyle::none()，没有字段列表可漏。
     * 描边不幂等：Paint::stroke 带抗锯齿，同色画两遍不等于一遍，底色幂等会掩盖它。
     * shadow 必须留着：Button 的浮起影子走 ShadowPainter::paintOutside()，与 hoverElevation 是两套。
     */
    public function withoutBoxVisual(): self
    {
        if ($this->style->isBlank()) {
            return $this;
        }

        $copy = clone $this;
        $copy->style = BoxStyle::none();

        return $copy;
    }

    /** 无底 / 渐变 / 边框 / 阴影 ⇒ false（省一次 `paintBehind()` 调用） */
    public function hasVisual(): bool
    {
        return !$this->style->isNone() || $this->shadow > 0.0;
    }

    /**
     * 装饰绘制 —— 由 RenderBox::paint() 在画内容之前调一次。
     * 顺序不能反（阴影在盒外 ⇒ 先画，否则被底盖掉）：① 柔和阴影 → ② 底 → ③ 边框。
     * "底色怎么画"问 BoxStyle::backgroundPaint()（自绘 chrome 的唯一答案），此处不重写。
     * 只用 Core op：drawRect（带圆角）三后端都支持；勿为阴影用 drawPath/模糊 mask
     * （Optional 层，软光栅报 E-OP-UNSUPPORTED）。
     */
    public function paintBehind(Canvas $canvas, Size $size): void
    {
        $rect = new Rect(0.0, 0.0, $size->width, $size->height);
        $radius = $this->style->radius;

        // ① 柔和阴影：画在盒外（父布局不会为它留白，块之间要留 ≥ 4px 间距）。
        //    形状收在 ShadowPainter::paintOutside()。
        if ($this->shadow > 0.0) {
            ShadowPainter::paintOutside($canvas, $rect, $radius, $this->shadow);
        }

        // ② 底：渐变优先由 backgroundPaint() 决定；渐变坐标是盒内比例，
        //    内部 resolvedTo() 换算成绝对像素（画时才知道盒多大）。
        $paint = $this->style->backgroundPaint($size->width, $size->height);
        if ($paint !== null) {
            $canvas->drawRect($rect, $paint, $radius);
        }

        // ③ 边框
        $borderColor = $this->style->borderColor;
        if ($borderColor !== null && $this->style->borderWidth > 0.0) {
            $stroke = Paint::stroke($borderColor, $this->style->borderWidth);

            // 虚线只在这一处落到画笔上：BoxStyle::$borderDash 的唯一消费者；
            // Paint::withDash() 已把图案带给两后端，此处不必知后端是谁。
            $dash = $this->style->borderDash;
            if ($dash !== null) {
                $stroke = $stroke->withDash($dash);
            }

            $canvas->drawRect($rect, $stroke, $radius);
        }
    }

    // ── 读：合成（`RenderBox::setMods()` 每次造盒读一次）────────────

    /** `opacity == 1` 且平移为 0 ⇒ false（走 `paintComposed()` 的零开销快路） */
    public function hasCompose(): bool
    {
        return $this->opacity < 1.0 || $this->translateX !== 0.0 || $this->translateY !== 0.0;
    }

    /**
     * 所有字段都是默认值（没有任何修饰）。
     * ★ 每一个字段都必须出现在这里：漏一个 ⇒ 那个修饰在"不产盒"的控件上静默失效
     *   （不报错、不写日志、只是没效果）。ModifierTest 用反射枚举全部 with*() 守着。
     * 不放进热路径：layout()/paint() 走 hasPadding()/hasVisual()/hasCompose() 窄判据。
     * 用途：Element::syncBox() 判"控件声明了修饰但不产盒"——须响亮失败而非静默丢掉。
     */
    public function isDefault(): bool
    {
        return !$this->hasPadding()
            && \is_nan($this->width)
            && \is_nan($this->height)
            && \is_nan($this->aspect)
            && $this->style->isBlank()
            && $this->shadow === 0.0
            && $this->opacity === 1.0
            && $this->translateX === 0.0
            && $this->translateY === 0.0
            && $this->grow === 0
            && $this->shrink === 0
            && \is_nan($this->basis)
            && $this->alignSelf === 0
            && !$this->hasInset()
            && $this->align === null
            && !$this->clip
            && !$this->repaintBoundary
            && !$this->disabled
            && $this->dim
            && $this->transform === null
            && !$this->transformCentered;
    }

    // ── 读：合成变换（`Element::syncBox()` → `RenderBox::applyTransform()`）──

    public function transform(): ?Matrix2D
    {
        return $this->transform;
    }

    /** 变换是否以盒中心为原点（`rotate()` / `scale()` 用） */
    public function transformCentered(): bool
    {
        return $this->transformCentered;
    }

    /**
     * 声明绘制期仿射变换。
     * $aroundCenter 为真时矩阵作用于盒中心（rotate()/scale() 走这条）：真实矩阵
     * 等布局完成才知道尺寸，此处只存"意图"，由 RenderBox::effectiveTransform() 现算。
     * 传 identity() 等于不声明（isDefault() 仍为真）——调用方不必自己判。
     */
    public function withTransform(Matrix2D $matrix, bool $aroundCenter = false): self
    {
        $c = clone $this;

        if ($matrix->isIdentity()) {
            $c->transform = null;
            $c->transformCentered = false;

            return $c;
        }

        $c->transform = $matrix;
        $c->transformCentered = $aroundCenter;

        return $c;
    }

    public function composeOpacity(): float
    {
        return $this->opacity;
    }

    public function composeDx(): float
    {
        return $this->translateX;
    }

    public function composeDy(): float
    {
        return $this->translateY;
    }

    /** 禁用（状态位，不是动画量 —— 改了要 rebuild 而不是 `setCompose()`） */
    public function disabled(): bool
    {
        return $this->disabled;
    }

    /** 禁用时是否由框架统一变淡（`false` = 控件自己画禁用外观，见字段注） */
    public function dimsWhenDisabled(): bool
    {
        return $this->dim;
    }

    // ── 写：值对象换新（每次 clone 一次，原对象不动）──────────────
    // 全部返回 self 而不是 static：本类 final，static 无额外含义，tpc 对 static
    // 返回类型推断更保守。

    public function withPadding(float $left, float $top, float $right, float $bottom): self
    {
        $c = clone $this;
        $c->padLeft = $left;
        $c->padTop = $top;
        $c->padRight = $right;
        $c->padBottom = $bottom;

        return $c;
    }

    /** NAN = 该轴不设（用内容的自然尺寸） */
    public function withSize(float $width, float $height): self
    {
        $c = clone $this;
        $c->width = $width;
        $c->height = $height;

        return $c;
    }

    public function withWidth(float $width): self
    {
        $c = clone $this;
        $c->width = $width;

        return $c;
    }

    public function withHeight(float $height): self
    {
        $c = clone $this;
        $c->height = $height;

        return $c;
    }

    /** 宽高比（宽 ÷ 高）；调用方负责校验有限正数 */
    public function withAspect(float $ratio): self
    {
        $c = clone $this;
        $c->aspect = $ratio;

        return $c;
    }

    /** 底色（写进视觉四维那一个 BoxStyle —— 有没有底由它自己表达） */
    public function withFill(Color $color): self
    {
        $c = clone $this;
        $c->style = $c->style->withBackground($color);

        return $c;
    }

    /**
     * 把底色换成渐变（Gradient::vertical() 这类盒内比例的形态）。
     * 与 withFill() 的关系：渐变赢（backgroundPaint() 先看渐变）。两者都设是合法状态，
     * 语义是"底色是这个渐变"，纯色只在渐变被清掉后才回画面——gradient(null) 可无损回退。
     */
    public function withGradient(Gradient $gradient): self
    {
        $c = clone $this;
        $c->style = $c->style->withBackgroundGradient($gradient);

        return $c;
    }

    /** 清掉渐变（回退到 `withFill()` 设的实色，若有） */
    public function withoutGradient(): self
    {
        if ($this->style->backgroundGradient === null) {
            return $this;
        }

        $c = clone $this;
        $c->style = $c->style->withoutBackgroundGradient();

        return $c;
    }

    public function withStroke(Color $color, float $width): self
    {
        $c = clone $this;
        $c->style = $c->style->withBorder($color, $width);

        return $c;
    }

    /**
     * 边框改虚线（null = 回到实线）。
     * 不动颜色与宽度——两个方法各管一维，调用顺序无关。
     */
    public function withBorderDash(?DashPattern $dash): self
    {
        $c = clone $this;
        $c->style = $c->style->withBorderDash($dash);

        return $c;
    }

    public function withRadius(float $radius): self
    {
        $c = clone $this;
        $c->style = $c->style->withRadius($radius);

        return $c;
    }

    /**
     * @param float $alpha 阴影浓度（0..1），见 `Elevation` 的档位约定
     */
    public function withShadow(float $alpha): self
    {
        $c = clone $this;
        $c->shadow = $alpha;

        return $c;
    }

    /**
     * 声明内容对齐锚点。`null` = 撤掉声明。
     * identity 意义上"没有声明"只有 null 一种 ⇒ Alignment::topLeft() 是真声明
     * （把内容钉到起点），不能当默认值省掉。
     */
    public function withContentAlign(?Alignment $align): self
    {
        $c = clone $this;
        $c->align = $align;

        return $c;
    }

    public function withCompose(float $opacity, float $dx, float $dy): self
    {
        $c = clone $this;
        $c->opacity = $opacity;
        $c->translateX = $dx;
        $c->translateY = $dy;

        return $c;
    }

    public function withOpacity(float $opacity): self
    {
        $c = clone $this;
        $c->opacity = $opacity;

        return $c;
    }

    public function withTranslate(float $dx, float $dy): self
    {
        $c = clone $this;
        $c->translateX = $dx;
        $c->translateY = $dy;

        return $c;
    }

    public function withDisabled(bool $on): self
    {
        $c = clone $this;
        $c->disabled = $on;

        return $c;
    }

    /**
     * 禁用时不统一变淡——控件自己会画禁用外观。
     * 只由 Element::syncBox() 调（paintsDisabledLook() 声明处），应用不该直接调：
     * 它答"框架要不要替这控件变淡"，不是"这控件禁不禁用"（那是 withDisabled）。
     */
    public function withoutDim(): self
    {
        $c = clone $this;
        $c->dim = false;

        return $c;
    }

    public function withClip(bool $clip): self
    {
        $c = clone $this;
        $c->clip = $clip;

        return $c;
    }

    public function withRepaintBoundary(bool $on): self
    {
        $c = clone $this;
        $c->repaintBoundary = $on;

        return $c;
    }

    public function withGrow(int $grow): self
    {
        $c = clone $this;
        $c->grow = $grow;

        return $c;
    }

    public function withShrink(int $shrink): self
    {
        $c = clone $this;
        $c->shrink = $shrink;

        return $c;
    }

    public function withBasis(float $basis): self
    {
        $c = clone $this;
        $c->basis = $basis;

        return $c;
    }

    public function withAlignSelf(int $alignSelf): self
    {
        $c = clone $this;
        $c->alignSelf = $alignSelf;

        return $c;
    }

    /**
     * 声明某一边的层叠定位（Positioned 的字段形态）。
     * 一个方法 + 边序常量，而不是四个 withInsetLeft()：四条边是同一概念的四分量
     * （与 withPadding 打包同理），分四方法会让"设了哪几边"在完整性表里占四行。
     * 常量序与 CSS 一致（left/top/right/bottom）。
     * 非法边序响亮失败：静默忽略会表现为 Positioned::right(8) 没生效。
     */
    public function withInset(int $side, float $value): self
    {
        if ($side < self::INSET_LEFT || $side > self::INSET_BOTTOM) {
            Errors::report(sprintf('Modifier：层叠定位的边序必须是 0..3（left/top/right/bottom），收到 %d', $side));
        }

        $c = clone $this;
        if ($side === self::INSET_LEFT) {
            $c->insetLeft = $value;
        } elseif ($side === self::INSET_TOP) {
            $c->insetTop = $value;
        } elseif ($side === self::INSET_RIGHT) {
            $c->insetRight = $value;
        } else {
            $c->insetBottom = $value;
        }

        return $c;
    }
}
