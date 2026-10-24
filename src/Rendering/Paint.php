<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 画笔：一次绘制调用的参数集（不可变值对象）。
 *
 * ★ 只管"怎么画"，不管"画成什么样" —— 背景 / 边框 / 圆角是 `BoxStyle`（§15.7）
 *   的事，`Paint` 是渲染层的原语。两者的转换发生在 RenderObject（§13.2）。
 *
 * **paint source 是正交维度**（D2）：`color`（实色）/ `gradient`（渐变）/
 * 两个都没给（等价 `NONE`）选择"用什么填充"；`style` + `dash` 选择
 * "怎么涂出来"。所以渐变与虚线都是**画笔属性**，不是新的 draw 方法 ——
 * `drawRect` / `drawEllipse` / `drawPath` 原样消费它们。
 *
 * @api
 */
final class Paint
{
    /**
     * `fill()` 的 interning 表（`argb → 实例`）。
     *
     * ★ 安全前提：`Paint` 的字段全是 `readonly` ⇒ 共享同一实例无副作用。
     * ★ 收益：每帧几十个 `Paint::fill()`（`Modifier::paintBehind` 的阴影层、
     *   `RenderScrollView`、`RenderRichText::paintRunDecorations` 的每个背景/下划线 run）
     *   ⇒ 同色现在命中同一支。
     * ★ 表**不设上限**：颜色种类由主题 + 业务常量决定（量级几十），不是每帧新增，
     *   与 `ImageLoader::$cache` 同一判断。
     * ★ 为什么不是 `readonly class`：那个糖会把**静态**属性也标 readonly，
     *   而 readonly 属性不能带默认值 ⇒ 缓存表写不出来。这里退回"逐属性 readonly"，
     *   语义与糖完全等价（`final` 已封死继承）。
     */
    private static array $fillCache = [];

    public function __construct(
        public readonly Color $color,
        public readonly PaintStyle $style = PaintStyle::Fill,
        public readonly float $strokeWidth = 1.0,
        public readonly ?Gradient $gradient = null,
        public readonly ?DashPattern $dash = null,
    ) {
        if ($style === PaintStyle::Stroke && $strokeWidth <= 0.0) {
            Errors::report('Paint：描边宽度必须为正，收到 ' . (string) $strokeWidth);
        }
        if ($gradient !== null && $style === PaintStyle::Stroke) {
            Errors::report('Paint：渐变描边暂不支持（P3 Extended 层只做实色描边），请用实色画笔');
        }
    }

    public static function fill(Color $color): self
    {
        return self::$fillCache[$color->toArgb()] ??= new self($color, PaintStyle::Fill);
    }

    public static function stroke(Color $color, float $width = 1.0): self
    {
        return new self($color, PaintStyle::Stroke, $width);
    }

    public function withColor(Color $color): self
    {
        return new self($color, $this->style, $this->strokeWidth, $this->gradient, $this->dash);
    }

    public function withAlpha(int $alpha): self
    {
        return new self($this->color->withAlpha($alpha), $this->style, $this->strokeWidth, $this->gradient, $this->dash);
    }

    /** 换 paint source 为渐变（传 null 回到实色） */
    public function withGradient(?Gradient $gradient): self
    {
        return new self($this->color, $this->style, $this->strokeWidth, $gradient, $this->dash);
    }

    /** 换虚线图案（传 null 回到实线） */
    public function withDash(?DashPattern $dash): self
    {
        return new self($this->color, $this->style, $this->strokeWidth, $this->gradient, $dash);
    }

    public function hasGradient(): bool
    {
        return $this->gradient !== null;
    }

    public function hasDash(): bool
    {
        return $this->dash !== null;
    }

    public function isStroke(): bool
    {
        return $this->style === PaintStyle::Stroke;
    }

    public function equals(Paint $other): bool
    {
        // ★ 渐变/虚线用 `==` 不用 `===`：它们是值对象（同构不同实例仍"同一支
        //   笔"），而 equals 的消费者（diff/复用）要的是值语义。
        return $this->color->equals($other->color)
            && $this->style === $other->style
            && $this->strokeWidth === $other->strokeWidth
            && $this->gradient == $other->gradient
            && $this->dash == $other->dash;
    }
}
