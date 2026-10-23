<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Geometry\EdgeInsets;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\Color;
use Drift\Rendering\ColorToken;

/**
 * 卡片：**一个内容块**（§15.7 的"需要盒样式的控件"那一类，带内边距）。
 *
 * ```php
 * Card::make(Column::make()->spacing(8.0)->children([
 *     Text::make('Row：主轴排布')->heading(),
 *     Row::make()->spacing(8.0)->children([...]),
 * ]));
 * ```
 *
 * ## 它补的是哪个洞
 *
 * `BoxWidget`（`Button` / `PopupPanel` 的共同基类）只有 **background / border /
 * radius**，**没有 padding**；而 `Column` / `Row` 是结构节点，**刻意不带盒样式**
 * （§15.7：布局节点不该有绘制样式）。`Card` 就是补这一个洞：**盒样式 + 内边距 +
 * 柔和阴影**。
 *
 * ## W5 之后它不再是"一个盒"，而是**样式预设**（arch-v2 `02` §7.1）
 *
 * `RenderCard` 已删。`build()` 把「底 / 边 / 圆角 / 阴影 / 内边距」写成**子控件的
 * `mods`**，由 `RenderBox::layout()` / `paint()` 施加。三个后果值得知道：
 *
 * - **盒尺寸仍是"内容 + 内边距"**（`mods.padding` 的 ②⑤ 步），内禀尺寸也含它；
 * - **内容原点仍是 `(padLeft, padTop)`**，只是换算从"父盒平移子盒"变成
 *   `contentOriginX/Y()`（`02` §4.1.1）—— 所以 IME / 命中测试不用改；
 * - **裁剪矩形变大了一个内边距**：以前 `RenderCard` 是外层盒、子盒是内容区；
 *   现在内边距属于子盒自己 ⇒ 子盒的 `Overflow` 裁剪按含内边距的矩形算。
 *   这与 `Padding` 在 M2-3 的收敛是同一条语义，不是新偏差。
 *
 * ★ **`build()` 必须 `clone $kid`**：`Card` 的语义是"包一层"，不是"给这个控件
 *   加样式"。`Widget` 可共享（`children([$a, $a])` 合法）⇒ 就地 `mutate` 会把卡片
 *   样式漏给另一处引用。`Widget::__clone()` 会清封存位，否则克隆出来的写不了。
 *
 * ★ **为什么是 `StatelessWidget` 而不是 `child()` 直接转发**：默认样式要取主题
 *   token（`ColorToken::Pane` / `Border`），而主题只能从 `BuildContext::theme()` 拿。
 *   `build(BuildContext)` 是本仓唯一"有 context 又只做组合"的位置。
 *
 * ★ **诚实的边界**：`build()` 是"给子控件加样式"，不是真的再包一层 ⇒ 若子控件
 *   自己是 `BoxWidget`（`Button` / `PopupPanel`），它的 `background()` 会写自己的
 *   `BoxStyle`，卡片的底/边就会**覆盖**它而不是垫在它下面。今天所有调用点传的都是
 *   结构节点 / `Text` / `ScrollView`，走 `mods` 那条路；要真"垫一层"得等 `Stack`。
 *   ★ **结构节点会被套一层代理盒**（`build()` 里的 `instanceof RenderBoxWidget`
 *   分支）：`ScrollView` / `TextField` 这类"盒住在自己 `*Box` 里"的控件，直接收
 *   修饰会撞 §11.7 守卫（M2-5 之后 `Card(ScrollView)` 就这么炸过）—— 套
 *   `SizedBox`（`RenderProxy`）之后样式落到代理盒上，外观与 `RenderCard` 同类。
 *
 * @api
 */
final class Card extends StatelessWidget
{
    private ?Widget $kid = null;

    private EdgeInsets $padding;

    /**
     * 阴影强度（`0.0` = 不画）。
     *
     * ★ 默认 **0**：LayUI 的卡片靠 1px 边框就立住了，阴影只是"贴地"的一点点。
     *   要"浮起来"的观感再显式 `->shadow(0.08)` —— 值域就是黑色透明度
     *   （0.05 ≈ `rgba(0,0,0,.05)`）。
     */
    private float $shadow = 0.0;

    /** @internal null = 没显式设过 ⇒ 用主题派生默认（§15.7 纪律 1：不用样式就不付分配） */
    private ?BoxStyle $box = null;

    public function __construct(?Widget $kid = null)
    {
        parent::__construct();
        $this->padding = EdgeInsets::all(16.0);

        if ($kid !== null) {
            $this->kid = $kid;
        }
    }

    public static function make(?Widget $kid = null): self
    {
        return new self($kid);
    }

    public function child(Widget $kid): static
    {
        $this->mutate();
        $this->kid = $kid;

        return $this;
    }

    public function kid(): ?Widget
    {
        return $this->kid;
    }

    public function padding(EdgeInsets $value): static
    {
        $this->mutate();
        $this->padding = $value;

        return $this;
    }

    public function paddingAll(float $value): static
    {
        return $this->padding(EdgeInsets::all($value));
    }

    public function shadow(float $value): static
    {
        $this->mutate();
        $this->shadow = $value;

        return $this;
    }

    // ── 盒样式三项（原来继承 `BoxWidget`；W5 之后自持一份，语义不变）────────
    // ★ 调了任何一个 ⇒ `$this->box` 非空 ⇒ `build()` 里**整套默认让位**
    //   （§15.7 纪律 3 的既有语义，与 `BoxWidget` 那层逐字一致）。

    public function background(Color $color): static
    {
        $this->mutate();
        $this->box = ($this->box ?? BoxStyle::none())->withBackground($color);

        return $this;
    }

    public function border(Color $color, float $width = 1.0): static
    {
        $this->mutate();
        $this->box = ($this->box ?? BoxStyle::none())->withBorder($color, $width);

        return $this;
    }

    public function radius(float $radius): static
    {
        $this->mutate();
        $this->box = ($this->box ?? BoxStyle::none())->withRadius($radius);

        return $this;
    }

    /** @internal 调试 / 夹具读它（渲染层不再需要） */
    public function box(): ?BoxStyle
    {
        return $this->box;
    }

    public function build(BuildContext $ctx): Widget
    {
        $theme = $ctx->theme();

        $style = $this->box ?? BoxStyle::none()
            ->withBackground($theme->color(ColorToken::Pane))
            ->withBorder($theme->color(ColorToken::Border), 1.0)
            ->withRadius(2.0);

        // ★ 样式总要落到**一个盒**上，分两种情况：
        // · 孩子自己产盒（`RenderBoxWidget`：`Column` / `Row` / `Text` / `Button`…）
        //   ⇒ 直接写给它，**不套壳**（这条路上的行为逐字不变）。
        // · 孩子**不产盒**（`ScrollView` / `TextField` / 页面这类结构节点 —— 它们的盒
        //   住在自己的 `*Box` 里）⇒ **必须先套一个代理盒**再写。否则修饰无处可去，
        //   §11.7 的守卫当场 FATAL：2026-10-23 实证，M2-5 把 Card 从"自己有盒"
        //   改成"样式注入孩子"之后，`Card::make(ScrollView::make(...))` 让示例台
        //   7 个页面一点就炸（`ScrollView 声明了修饰…但它自己不产渲染盒`）。
        // ★ 代理盒用 `SizedBox`（纯转发盒 `RenderProxy`）：`RenderBox::paint()` 通用
        //   画 mods 的 chrome（`paintBehind`：阴影 → 底 → 边），尺寸/内容原点走
        //   `layout()` ②⑤ 步的同一套 padding 规则 ⇒ 外观与几何都和被删掉的
        //   `RenderCard` 同类，且与"直接写给盒孩子"口径一致（都不 loosen）。
        $styled = $this->kid === null
            ? SizedBox::make()
            : ($this->kid instanceof RenderBoxWidget ? clone $this->kid : SizedBox::make($this->kid));

        $styled = $styled->padding($this->padding);

        if ($this->shadow > 0.0) {
            $styled = $styled->shadow($this->shadow);
        }
        if ($style->background !== null) {
            $styled = $styled->background($style->background);
        }
        if ($style->borderColor !== null && $style->borderWidth > 0.0) {
            $styled = $styled->border($style->borderColor, $style->borderWidth);
        }
        if ($style->radius > 0.0) {
            $styled = $styled->radius($style->radius);
        }

        return $styled;
    }
}
