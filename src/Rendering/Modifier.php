<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
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

    // ── 主轴弹性意图（`04` §2）──────────────────────────────────────
    private int $grow = 0;

    private int $shrink = 0;

    private float $basis = \NAN;

    private int $alignSelf = 0;      // RenderBox::ALIGN_* 常量

    public function __construct()
    {
        // 占位：`hasBackground` / `hasBorder` 为假时这两个值无意义
        $this->background = Color::black();
        $this->borderColor = Color::black();
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
        if (!$hasW && !$hasH) {
            return $c;
        }

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

        return new BoxConstraints($minW, $maxW, $minH, $maxH);
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

    // ── 读：绘制（`RenderBox::paint()` 每帧读）─────────────────────

    /** 无背景 / 边框 / 阴影 ⇒ false（省一次 `paintBehind()` 调用） */
    public function hasVisual(): bool
    {
        return $this->hasBackground || ($this->hasBorder && $this->borderWidth > 0.0) || $this->shadow > 0.0;
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

        // ① 柔和阴影：三层递减外扩
        //    单层实心矩形看起来像描边（"生硬"），三层叠出过渡带才像影子。
        //    ★ 阴影画在盒外 ⇒ 父布局不会为它留白，块之间要留 ≥ 4px 间距，
        //      否则邻块底色盖掉它。
        if ($this->shadow > 0.0) {
            $base = (int) \round(255.0 * \min(1.0, $this->shadow));
            $steps = [3 => 0.35, 2 => 0.60, 1 => 1.00];

            foreach ($steps as $grow => $weight) {
                $alpha = \max(1, (int) \round($base * $weight));
                $canvas->drawRect(
                    new Rect(-$grow, -$grow, $size->width + 2 * $grow, $size->height + 2 * $grow),
                    Paint::fill(new Color(0, 0, 0, $alpha)),
                    $this->radius + $grow,
                );
            }
        }

        // ② 底
        if ($this->hasBackground) {
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
     */
    public function isDefault(): bool
    {
        return !$this->hasPadding()
            && \is_nan($this->width)
            && \is_nan($this->height)
            && !$this->hasBackground
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
            && !$this->center;
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

    /** 底色（同时置 `hasBackground` —— 单字段 setter 表达不了"有没有"） */
    public function withFill(Color $color): self
    {
        $c = clone $this;
        $c->background = $color;
        $c->hasBackground = true;

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

    public function withShadow(float $elevation): self
    {
        $c = clone $this;
        $c->shadow = $elevation;

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
}
