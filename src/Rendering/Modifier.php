<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;

/**
 * 修饰字段的集合（**值对象**：不可变、按值比较、可共享）。
 *
 * ```php
 * Text::make('hi')->paddingAll(8.0)->background($c)->radius(4.0)
 * ```
 *
 * ## 为什么是一个对象而不是 Widget 上的二十几个属性
 *
 * `Element::syncBox()` 只需把**一个**引用递给盒（同 `setCursor()` 的形状），不必为
 * 每个修饰加一条传递路径。十二处抄同一句、漏抄不报错 —— 那是 `syncBox()` 注释里
 * 已经写明的问题。
 *
 * ## 为什么手写方法，而不是 `#[\Getter]` / `#[\With]`
 *
 * ★★ **那三个是 tpc 的编译期注解，`php.exe` 不认**（`typephp-docs/zh-cn/getter.md`：
 *   "Getter 方法在**编译期静态生成**……运行时不解析 Attribute"）。而 `smoke.php` /
 *   `lint.php` / `preview` / `selftest` **全部跑在 `php.exe` 上** ⇒ 用了它们，
 *   1000 条 smoke 断言会在第一次读修饰时全 Fatal，**而 AOT 构建照样绿**。
 *   这是最坏的一类失败：闸门跑不起来却"编译通过"。
 *   ⇒ 长期判据：`src/` 里只许用 **PHP 原生**注解（今天只有 `#[\Override]`）。
 *
 * ★ 而且单字段 `withXxx()` 一个都不够用：`background` 必须同时写 `hasBackground`，
 *   `border` 要同时写颜色与宽度，`padding` 要同时写四个边 ⇒ 按**用途**成形才划算。
 *
 * ## 为什么字段 `private` 而不是 `public`
 *
 * 对外只读访问用方法（`private` 在类外同样不可访问，但保留完整的优化能力）。
 * ★ 类内**一律直读 `$this->padLeft`，绝不走 `padLeft()`** —— 方法调用比属性读贵
 * （`docs/AOT-CONSTRAINTS.md` §2.1 实测 +22 ns/read）。布局数学全在类内 ⇒ 这条
 * 决定收益成不成立。
 *
 * ## 为什么不用 `readonly`
 *
 * `withXxx()` 要 `clone $this` 再写克隆的字段（`clone` 的结果**可变**，合法）。
 * `readonly` 属性写不了 ⇒ 会堵死整条"值对象换新"的路。与性能无关。
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
     *
     * ★ 与 `width` / `height` 同族：都是"约束 → 约束"的尺寸修饰，由
     *   `tighten()` 末尾交给 `BoxConstraints::applyAspect()`。**不是新渲染盒**
     *   （W5 判据"修饰是字段，不是节点"）。
     */
    private float $aspect = \NAN;

    /**
     * 内容居中（`Center` 的字段形态）。
     *
     * ★ 与 `alignSelf` 不是一件事：`alignSelf` 只在 `RenderFlex::placeChildren()`
     *   里被消费（**没有 Flex 父就是死字段**），而 `Center` 常常是页面根。
     *   居中是"把直接子盒平移" —— 与 padding 同一类操作，由基类 `layout()`
     *   的收尾步一起施加。
     */
    private bool $center = false;

    // ── 绘制维（D_PAINT）────────────────────────────────────────────
    // ★ **不用 `?Color`**：可空对象在编译期退化为 `php::Var` ⇒ 用"值 + bool"。
    //   `Color` 没有能当属性初始化器的常量（只有 `Color::black()` 静态方法）
    //   ⇒ 在 `__construct` 里占位。
    private Color $background;

    private bool $hasBackground = false;

    private Color $borderColor;

    private bool $hasBorder = false;

    private float $borderWidth = 0.0;

    /**
     * 底色渐变（paint source 的另一支；与 `$background` 互斥地解释 —— 有渐变时渐
     * 变赢）。
     *
     * ★★ **为什么是"占位对象 + bool"而不是 `?Gradient`**：与 `$background` 完全同
     *   一条账 —— 可空对象在编译期退化为 `php::Var`（见上面绘制维的段注）。
     *   照抄成 `?Gradient` 会在 AOT 上静默丢掉类型，`paintBehind()` 里读它变成动态。
     * ★ 坐标是**盒内比例**（`Gradient::vertical()` 这类工厂产出的形态），
     *   `paintBehind()` 拿到 `Size` 后调 `resolvedTo()` 换算。用户不需要知道盒多大。
     */
    private Gradient $gradient;

    private bool $hasGradient = false;

    private float $radius = 0.0;

    /** 阴影强度 = 黑色透明度（`0.0` = 不画） */
    private float $shadow = 0.0;

    // ── 合成维（D_COMPOSE）──────────────────────────────────────────
    // ★ 这 3 个是**初值**，不是"当前值"：`RenderBox::setMods()` 读一次去初始化
    //   盒上的 `composeOpacity/composeDx/composeDy`，此后动画走 `setCompose()`
    //   直接写盒，不再回到这里。rebuild 后"回到 Widget 声明的值"依赖的正是这条
    //   ⇒ 它们**不能删**（删了 `->opacity(0.5)` 只在第一次 layout 生效）。
    private float $opacity = 1.0;

    private float $translateX = 0.0;

    private float $translateY = 0.0;

    // ── 交互维（D_INTERACT）──────────────────────────────────────────
    //
    // ★ 与上面三个的区别：那三个是"画成什么样"，这个是"还接不接事件"。
    //
    // ★★ **必须住修饰符，不能让每个控件自己加一个 `disabled` 字段**：它要喂四处
    //   （命中 / 焦点 / 语义 / 绘制，见 `Widget::disabled()` 的表），而"漏一处"
    //   的失败形态是**看着灰了但点得动** —— 比不禁用更误导。修饰符是四处唯一的
    //   共同上游（`RenderBox::setMods()` / `FocusManager` / `SemanticsBuilder`
    //   都读得到它），所以这里一条声明就能让 20+ 个控件同时正确。
    private bool $disabled = false;

    /**
     * 禁用时是否由**框架统一变淡**（`RenderBox::DISABLED_ALPHA`）。
     *
     * ★ 默认 `true`（大多数控件没有"禁用态配色"，整体变淡是唯一可行的表达）。
     *   控件若自己会画禁用外观（`ToggleBase` / `Slider` / `Segment` 把标签换成
     *   `ColorToken::Muted`），必须置 `false` —— 否则"换灰"再叠一层 0.38 的 alpha，
     *   深灰字会淡到几乎看不见。
     * ★ 由 `Widget::paintsDisabledLook()` 声明、`Element::syncBox()` 落实
     *   （`withoutDim()`）—— 与 `paintsBoxChrome()` 同一条形状：**声明在控件、
     *   落实在 syncBox、消费在盒**。
     */
    private bool $dim = true;

    // ── 主轴弹性意图（`04` §2）──────────────────────────────────────
    private int $grow = 0;

    private int $shrink = 0;

    private float $basis = \NAN;

    private int $alignSelf = 0;      // RenderBox::ALIGN_* 常量

    // ── 层叠定位意图（`Stack` 的 `Positioned`，A4 / 2026-10-28）────────
    //
    // ★★ **这一族与上面四个是同一类东西**：都是"给**父容器**的布局意图"，只有
    //   `RenderFlex`（grow/shrink/basis/alignSelf）或 `RenderStack`（下面四个）才读。
    //   没有那个父 ⇒ 死字段、不报错。这条账 `alignSelf` 的注里已经认过，这里只是把它
    //   延伸到第二个父容器。
    //
    // ★★★ **这是"父意图"的最后一族**。再加第三族（Dock 槽名 / Table 列提示）就该
    //   引入独立的 `ParentData` 值对象，而不是第 28 个字段 —— 判据：
    //   本类字段数一旦超过 `ParentIntentFieldLimitTest` 的上限，就该动手了。
    //
    // ★ `NAN` = 该边未声明（与 `$width` / `$basis` 同一条"用 NAN 而不是 ?float"的账）。
    private float $insetLeft = \NAN;

    private float $insetTop = \NAN;

    private float $insetRight = \NAN;

    private float $insetBottom = \NAN;

    /** `withInset()` 的边序 —— 与 CSS 的 left/top/right/bottom 同序 */
    public const INSET_LEFT = 0;

    public const INSET_TOP = 1;

    public const INSET_RIGHT = 2;

    public const INSET_BOTTOM = 3;

    // ── 合成变换（A1c / 2026-10-29）────────────────────────────────
    //
    // ★ 这是**绘制期**的变换，与上面那族的"父意图"无关：不进布局、不改约束、
    //   不改子盒尺寸。布局怎么算还是怎么算，只是画的时候整个子树被仿射一下。
    //   ⇒ 视觉会溢出（放大 2 倍会盖到邻居），这是标准语义而不是缺陷。
    //
    // ★ `$transformCentered` 是"绕盒中心"的意图标记，不是另一份矩阵：盒尺寸
    //   布局期才知道，矩阵只能在 `RenderBox::effectiveTransform()` 里现算
    //   （`T(c) · M · T(-c)`）。放在这里的东西必须是**声明**，不能是算好的结果。
    private ?Matrix2D $transform = null;

    private bool $transformCentered = false;

    // ── 绘制裁剪（D_PAINT）──────────────────────────────────────────
    /**
     * 内容裁到**盒矩形**（含内边距）。
     *
     * ★ 与 `Overflow` 不是一回事：`Overflow` 是**布局期** C5 策略（放不下时
     *   报错还是夹住），`clip` 是**绘制期**"画的时候裁掉"。会超的容器两者都要
     *   —— 只声明 `Overflow::Clip` 是"不报错"，不裁就仍然画到盒外
     *   （2026-10-23 之前的真实状态：`RenderAppBar` 的注释写着"右侧导航项被裁掉"，
     *   而代码什么都没做）。
     * ★ 装饰（背景 / 边框 / 圆角 / **阴影**）不被裁：阴影本来就画到盒外
     *   （`paintBehind()` 里 `-grow` 起画），裁了等于把影子切掉。
     */
    private bool $clip = false;

    /**
     * 本盒是否声明为**重绘边界**（A 批②）。
     *
     * ★ 不是视觉属性，与 `clip` 同类 —— 放这里是因为 `setMods()` 已经是
     *   Widget → 盒的**唯一**落点，再开一条通道就多一处"记得同步"的地方。
     */
    private bool $repaintBoundary = false;

    public function __construct()
    {
        // 占位：`hasBackground` / `hasBorder` / `hasGradient` 为假时这些值无意义
        $this->background = Color::black();
        $this->borderColor = Color::black();
        $this->gradient = Gradient::linear(0.0, 0.0, 1.0, 0.0, [Color::black(), Color::black()]);
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
     * 尺寸修饰收紧约束：设了的那一轴变成**紧**的那一轴，且先夹进父给的区间。
     *
     * ★ "父更紧时听父的"不是宽容，是 C2 的唯一正确解 —— 约束是父给的，子无权
     *   突破（`tight(100)` 里塞 `width(200)` 不能让 `BoxConstraints` 抛
     *   "min 不得大于 max"，那是 Fatal）。
     * ★ 两轴都没设 ⇒ **原样返回入参**（不新建对象）：`layout()` 每次都会调它，
     *   给绝大多数没设尺寸的盒省一次分配。
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

            // ★ 属性读取先落局部标量：AOT 下在分支里直接读属性会被推成 Variant，
            //   与 `float` 形参不匹配 ⇒ C2440（`RenderFlex::tightMain` 同型坑）。
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

    /** 内容是否要在盒内居中 */
    public function hasCenter(): bool
    {
        return $this->center;
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
     *
     * ★ 必须返回 `?Color` 而不是 `Color`：未设时 `$background` 是占位的黑色
     *   （构造函数里初始化的），直接返回它等于把"没设"报成"黑色"。
     */
    public function fillColor(): ?Color
    {
        return $this->hasBackground ? $this->background : null;
    }

    /** 底色渐变；`null` = 没设。坐标是**盒内比例**，画之前要 `resolvedTo()` */
    public function gradient(): ?Gradient
    {
        return $this->hasGradient ? $this->gradient : null;
    }

    public function hasGradient(): bool
    {
        return $this->hasGradient;
    }

    /**
     * 视觉三字段（底 / 边 / 圆角）打包成 `BoxStyle`；三项全未设 ⇒ `null`。
     *
     * ## 为什么需要它（S1，2026-10-28）
     *
     * `BoxStyle` 曾是**第二份存储**：`BoxWidget` 子类（`Button` / `PopupPanel`）把
     * `->background()` 写进自己的 `private ?BoxStyle $box`，而其余控件写 `$mods`。
     * 于是同一个 API 名有两个落点，而"声明了修饰但修饰无处可去"那条守卫
     * （`Element::syncBox()` 读 `isDefault()`）**只看得到 `$mods`** —— 另一半静默。
     *
     * 现在存储只有 `$mods`；`BoxStyle` 降级为 **Layout 层的渲染参数**，需要它的控件
     * （`Button` / `PopupPanel` / `AppBar` 要交给 `Render*` 的构造器）从这里**派生**。
     *
     * ★ **派生不改变分层**：`paintBehind()` 画装饰层、`paintOwnChrome()` 画本体层，
     *   两者仍是两层不同的东西。变的只是"本体样式的数据从哪来"。
     * ★ **派生物与源幂等**：自绘 chrome 的控件会把这里派生出的值合并进 `BoxStyle`
     *   交给 `Render*` ⇒ 同一个矩形同一颜色可能被画两遍（`paintBehind` + `paintOwnChrome`），
     *   视觉与命令语义上都无害（值来自同一处，不可能不一致）。
     */
    public function boxStyle(): ?BoxStyle
    {
        $hasBorder = $this->hasBorder && $this->borderWidth > 0.0;
        if (!$this->hasBackground && !$this->hasGradient && !$hasBorder && $this->radius === 0.0) {
            return null;
        }

        return new BoxStyle(
            $this->hasBackground ? $this->background : null,
            $hasBorder ? $this->borderColor : null,
            $hasBorder ? $this->borderWidth : 0.0,
            $this->radius,
            $this->hasGradient ? $this->gradient : null,
        );
    }

    /**
     * 派生一份**去掉视觉三字段**（底 / 边 / 圆角）的副本，其余字段原样保留。
     *
     * ## 为什么需要它（S1 的收尾）
     *
     * 自绘 chrome 的控件（`Button` / `AppBar` / `PopupPanel`）把"用户设的样式"从
     * `$mods` 派生进 `BoxStyle` 交给 `Render*`，由 `paintOwnChrome()` 画。若把同一份
     * `$mods` 原样交给盒，`RenderBox::paint()` 的 ① 会**再画一遍**。
     *
     * ★★ **描边不幂等**：`Paint::stroke` 是带抗锯齿的部分覆盖，同色画两遍**不等于**
     *   画一遍 —— 实测 `golden/page` 红了两页（`ButtonsPage` 32 像素、`ListPage`
     *   322 像素，差异全落在**边框条带**上，max Δ=13/25）。底色倒是幂等的
     *   （不透明矩形全覆盖），所以只靠"看起来一样"发现不了这条。
     *
     * ★ **只剥这三项**：`shadow` 必须留着 —— `Button` 的浮起影子走
     *   `ShadowPainter::paintOutside()`，与 `hoverElevation` 是两套，剥掉就丢了
     *   用户显式声明的 `->shadow()`。`padding` / 尺寸 / 合成 / 弹性意图同理。
     */
    public function withoutBoxVisual(): self
    {
        if (!$this->hasBackground && !$this->hasBorder && $this->radius === 0.0) {
            return $this;
        }

        $copy = clone $this;
        $copy->hasBackground = false;
        $copy->hasBorder = false;
        $copy->borderWidth = 0.0;
        $copy->radius = 0.0;

        return $copy;
    }

    /** 无背景 / 渐变 / 边框 / 阴影 ⇒ false（省一次 `paintBehind()` 调用） */
    public function hasVisual(): bool
    {
        return $this->hasBackground || $this->hasGradient
            || ($this->hasBorder && $this->borderWidth > 0.0) || $this->shadow > 0.0;
    }

    /**
     * 装饰绘制 —— 由 `RenderBox::paint()` 在画内容**之前**调一次。
     *
     * **顺序不能反**（阴影在盒外 ⇒ 先画，否则会被底盖掉）：
     * ① 柔和阴影 → ② 底 → ③ 边框。
     *
     * ★ 为什么住这里而不上移到 `RenderBox`：它要读 7 个字段。住这里 ⇒ 同类内
     *   `private` 直读 **0 次方法调用**；住 `RenderBox` ⇒ 每帧每盒 7 次方法调用。
     *
     * ★ **只用 Core op**：`drawRect`（带圆角）三后端都支持。**不要**为阴影去用
     *   `drawPath` / 模糊 mask —— 那是 Optional 层，软光栅会报 `E-OP-UNSUPPORTED`。
     *
     * ★ 这段逐字搬自 `RenderCard::paint()`（该类在 W5 被删，只剩这十行需要新家）。
     */
    public function paintBehind(Canvas $canvas, Size $size): void
    {
        $rect = new Rect(0.0, 0.0, $size->width, $size->height);

        // ① 柔和阴影：画在盒外（父布局不会为它留白，块之间要留 ≥ 4px 间距）。
        //    形状（真模糊 / 三层外扩降级）收在 `ShadowPainter::paintOutside()`。
        if ($this->shadow > 0.0) {
            ShadowPainter::paintOutside($canvas, $rect, $this->radius, $this->shadow);
        }

        // ② 底 —— 渐变优先（它是"底色是这个渐变"的语义，不是叠加层）
        //    ★ 渐变坐标是盒内比例 ⇒ 必须先 `resolvedTo()` 换算成绝对像素；这一步
        //      放在这里而不是 `withGradient()` 里，因为只有画的时候才知道盒多大。
        if ($this->hasGradient) {
            $canvas->drawRect(
                $rect,
                Paint::fill($this->background)->withGradient(
                    $this->gradient->resolvedTo($size->width, $size->height),
                ),
                $this->radius,
            );
        } elseif ($this->hasBackground) {
            $canvas->drawRect($rect, Paint::fill($this->background), $this->radius);
        }

        // ③ 边框
        if ($this->hasBorder && $this->borderWidth > 0.0) {
            $canvas->drawRect(
                $rect,
                Paint::stroke($this->borderColor, $this->borderWidth),
                $this->radius,
            );
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
     *
     * ★ 用途只有一个：`Element::syncBox()` 判断"这个控件声明了修饰、但它自己不产盒"
     *   —— 那种情况下修饰**无处可去**，必须响亮失败而不是静默丢掉（见 `02` §11.7）。
     * ★ 不放进热路径：`layout()` / `paint()` 走的是 `hasPadding()` / `hasVisual()` /
     *   `hasCompose()` 三个窄判据，没有"全字段扫一遍"的开销。
     * ★★ **每一个字段都必须出现在这里**：漏一个 ⇒ 那个修饰在"不产盒"的控件上是
     *   **静默失效**（不报错、不写日志、只是没效果）。`repaintBoundary` 就漏过一整轮
     *   （2026-10-28 补上，S1）；`ModifierTest` 用反射枚举全部 `with*()` 守着这条。
     */
    public function isDefault(): bool
    {
        return !$this->hasPadding()
            && \is_nan($this->width)
            && \is_nan($this->height)
            && \is_nan($this->aspect)
            && !$this->hasBackground
            && !$this->hasGradient
            && !$this->hasBorder
            && $this->radius === 0.0
            && $this->shadow === 0.0
            && $this->opacity === 1.0
            && $this->translateX === 0.0
            && $this->translateY === 0.0
            && $this->grow === 0
            && $this->shrink === 0
            && \is_nan($this->basis)
            && $this->alignSelf === 0
            && !$this->hasInset()
            && !$this->center
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

    /** 变换是否以**盒中心**为原点（`rotate()` / `scale()` 用） */
    public function transformCentered(): bool
    {
        return $this->transformCentered;
    }

    /**
     * 声明绘制期仿射变换。
     *
     * ★ `$aroundCenter` 为真时矩阵作用于**盒中心**（`rotate()` / `scale()` 走这条）：
     *   真实矩阵要等布局完成才知道尺寸，所以这里只存"意图"，由
     *   `RenderBox::effectiveTransform()` 现算 `T(c) · M · T(-c)`。
     * ★ 传 `identity()` 等于不声明（`isDefault()` 仍然为真）—— 调用方不必自己判。
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

    // ── 写：值对象换新（每次 `clone` 一次，原对象不动）──────────────
    // ★ 全部返回 `self` 而不是 `static`：本类是 `final`，`static` 没有额外含义，
    //   而 tpc 对 `static` 返回类型的推断更保守。

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

    /** 底色（同时置 `hasBackground` —— 单字段 setter 表达不了"有没有"） */
    public function withFill(Color $color): self
    {
        $c = clone $this;
        $c->background = $color;
        $c->hasBackground = true;

        return $c;
    }

    /**
     * 把底色换成**渐变**（`Gradient::vertical()` 这类盒内比例的形态）。
     *
     * ★ 与 `withFill()` 的关系：**渐变赢**（`paintBehind()` 里先看 `hasGradient`）。
     *   两者都设是合法状态（用户 `->background($c)->gradient($g)`），语义是"底色是
     *   这个渐变"，纯色只在渐变被清掉之后才回到画面 —— 这样 `->gradient(null)`
     *   可以无损回退到实色。
     */
    public function withGradient(Gradient $gradient): self
    {
        $c = clone $this;
        $c->gradient = $gradient;
        $c->hasGradient = true;

        return $c;
    }

    /** 清掉渐变（回退到 `withFill()` 设的实色，若有） */
    public function withoutGradient(): self
    {
        if (!$this->hasGradient) {
            return $this;
        }

        $c = clone $this;
        $c->hasGradient = false;

        return $c;
    }

    public function withStroke(Color $color, float $width): self
    {
        $c = clone $this;
        $c->borderColor = $color;
        $c->hasBorder = true;
        $c->borderWidth = $width;

        return $c;
    }

    public function withRadius(float $radius): self
    {
        $c = clone $this;
        $c->radius = $radius;

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

    public function withCenter(bool $on): self
    {
        $c = clone $this;
        $c->center = $on;

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
     * 禁用时**不**统一变淡 —— 控件自己会画禁用外观。
     *
     * ★ 只由 `Element::syncBox()` 调（`paintsDisabledLook()` 声明处），应用不该直接调：
     *   它回答的是"框架要不要替这个控件变淡"，不是"这个控件禁不禁用"（那是 `withDisabled`）。
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

    public function withAlign(int $alignSelf): self
    {
        $c = clone $this;
        $c->alignSelf = $alignSelf;

        return $c;
    }

    /**
     * 声明某一边的层叠定位（`Positioned` 的字段形态）。
     *
     * ```php
     * $m->withInset(Modifier::INSET_LEFT, 8.0)->withInset(Modifier::INSET_TOP, 8.0);
     * ```
     *
     * ★ **一个方法 + 边序常量，而不是四个 `withInsetLeft()`**：四条边是同一个概念
     *   的四个分量（与 `withPadding(left,top,right,bottom)` 的打包同理），分成四个方法
     *   会让"设了哪几条边"这件事在 `ModifierTest` 的完整性表里占四行、而它们永远
     *   一起变。常量序与 CSS 一致（left/top/right/bottom）。
     * ★ 非法边序**响亮失败**：静默忽略会表现为"`Positioned::right(8)` 没生效"。
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
