<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Layout\RenderBox;
use Drift\Layout\RenderProxy;
use Drift\Rendering\BoxStyle;
use Drift\Rendering\ColorToken;

/**
 * 卡片：**一个内容块** —— 主题默认的底 / 边 / 圆角 + 16 内距。
 *
 * ```php
 * Card::make(Column::make()->spacing(8.0)->children([
 *     Text::make('Row：主轴排布')->heading(),
 *     Row::make()->spacing(8.0)->children([...]),
 * ]));
 * ```
 *
 * ## 它是什么：**一个预设，不是一套存储**（2026-10-31 收尾）
 *
 * 本类曾经是 `StatelessWidget`，`build()` 把「底 / 边 / 圆角 / 阴影 / 内边距」
 * **逐字段写进孩子控件的 `mods`**，为此自持 `?BoxStyle $box` + `float $shadow` +
 * `EdgeInsets $padding` 三个字段、7 个覆写方法、`build()` 里 5 个 `if` 分支，
 * 还要 `clone $kid`（免得样式漏给共享引用）。
 *
 * 那份重复的代价是**结构性的**：`Modifier` 以后新增一个视觉维（图片底 / 图案），
 * 卡片的 5 个分支**不会**自动流过去 —— 漏了不报错，只是那个维度在卡片上静默不生效。
 * 这正是 S1 在 `Modifier` 内部修掉的那类 bug（`withoutBoxVisual()` 漏剥渐变），
 * 只是换了个宿主。
 *
 * 现在它**一个样式字段都不存**：
 *
 * | 职责 | 落点 |
 * |---|---|
 * | 用户声明的底 / 边 / 圆角 / 阴影 / 内距 | **继承的 `$mods`**（与所有控件同一条路）|
 * | 用户没声明时的主题默认 | `defaultBoxStyle()`，由 `Element::syncBox()` 填入 |
 * | 盒 | `RenderProxy`（纯转发，与 `SizedBox` / `Padding` 同一个）|
 *
 * ⇒ 新增视觉维**零改动**自动生效，`Card` 从此不会漏任何维度。
 *
 * ## 它是**一层盒**，样式不再注入孩子
 *
 * 旧实现"把样式写进孩子"有两个绕不开的毛病，都已随这次改动消失：
 *
 * - **孩子自己的样式被覆盖**：`Card::make(Button::make('x'))->background($red)` 会把
 *   红色写进按钮的 `mods`，按钮的 `resolveBoxStyle()` 把它当"用户设的样式"用，
 *   于是**按钮底色变成卡片底色**。旧注释管这叫"诚实的边界"，其实是错的层——
 *   卡片的底应该**垫在**孩子下面。
 * - **两种孩子两条路**：孩子产盒（`Text`）就直接写它；孩子不产盒（`ScrollView`）
 *   就得先套一层 `SizedBox`。两条路各自有判据守着，而"哪条"取决于孩子的类型 ——
 *   这正是 `instanceof` 分支该消失的地方。
 *
 * 现在孩子**永远原样挂**在卡片的代理盒下面：卡片画自己的底，孩子画自己的。
 *
 * ★ **代价是多一层盒**（`SizedBox → Card(RenderProxy) → Column → …`）。这是"正确的
 *   层次"的价格，也是成熟 GUI 的一致选择（`Card` 是盒，样式从不注入子控件）。
 *   几何等价：代理盒的尺寸 = 孩子 + 内距，内容原点 = 内距（`RenderBox::layout()`
 *   的 ②⑤ 步）—— 与旧实现逐像素相同，`golden/page` 是这条的判据。
 * ★ **裁剪矩形变了（变正确了）**：旧实现内距属于孩子的盒 ⇒ 孩子的 `Overflow`
 *   裁剪按"含内距"的矩形算；现在内距属于卡片的盒 ⇒ 孩子按自己的内容区裁剪。
 * ★ 默认内距 16 在构造器里落到 `$mods`（不是字段）：`Card::make()` 的尺寸就是
 *   32×32，与 `Padding::make()->all(16)` 同一条路。
 *
 * @api
 */
final class Card extends NullableChildRenderObjectWidget
{
    public function __construct(?Widget $kid = null)
    {
        parent::__construct($kid);
        $this->paddingAll(16.0);
    }

    public static function make(?Widget $kid = null): self
    {
        return new self($kid);
    }

    public function kid(): ?Widget
    {
        return $this->kid;
    }

    /** 纯转发盒：尺寸与内容原点全由 `RenderBox::layout()` 的 ②⑤ 步按 `mods.padding` 施加 */
    public function createRenderObject(BuildContext $context): RenderBox
    {
        return new RenderProxy();
    }

    /**
     * 主题默认：`Surface` 底 + 1px `Border` + 2 圆角。
     *
     * ★ **只在用户一个盒样式字段都没设过时生效**（判据 `BoxStyle::isBlank()`，
     *   在 `Modifier::withBoxStyleIfBlank()` 里）。调了 `->background()` / `->border()` /
     *   `->radius()` 的任何一个 ⇒ 整套默认让位（§15.7 纪律 3 的既有语义，逐字不变）。
     * ★ 阴影**不在**默认里：LayUI 的卡片靠 1px 边框就立住了，要"浮起来"得显式
     *   `->shadow(0.08)`（值域就是黑色透明度）。
     */
    #[\Override]
    public function defaultBoxStyle(BuildContext $context): ?BoxStyle
    {
        $theme = $context->theme();

        return BoxStyle::none()
            ->withBackground($theme->color(ColorToken::Surface))
            ->withBorder($theme->color(ColorToken::Border), 1.0)
            ->withRadius(2.0);
    }
}
