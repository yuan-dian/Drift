<?php
declare(strict_types=1);

namespace Drift\Geometry;

use Drift\Errors;

/**
 * 2×3 仿射矩阵（逻辑单位，§6.1）。
 *
 * ```
 * | a  c  tx |      x' = a·x + c·y + tx
 * | b  d  ty |      y' = b·x + d·y + ty
 * ```
 *
 * ★ **为什么是 2×3 而不是 3×3 / 4×4**：GUI 只需要平面仿射（平移 / 缩放 / 旋转 / 斜切），
 *   透视投影在桌面控件里没有消费者。少一维就少一半乘法，也不用处理 w 归一化 ——
 *   而 `Paint` / `Rect` 一样是"够用即止"的形状。
 *
 * ★ **不可变**：所有变换都返回新对象。`Transform` 会进 `Modifier`（进而进盒的合成槽），
 *   可变对象在那里会被下游悄悄改掉 —— 与 `Rect` / `Insets` 同一条纪律。
 *
 * ★★ **`then()` 的次序**：`$a->then($b)` 表示**先 `$a` 再 `$b`**
 *   （即矩阵乘 $M_a \cdot M_b$）。这与 `Canvas::transform()` 的"左乘当前 CTM"
 *   一致：代码里从上往下读就是变换的施加顺序，不需要在脑子里反着推。
 *
 * ★★ **AOT 纪律**：本类的字段全部声明为 `float`，构造函数有默认值；不做
 *   "`?:` 两支类型不同"、不用 `?->`、不用常量除法（`\M_PI / 180.0` 写成字面量常量）。
 *   这些是 `MEMORY.md` 里已经踩过的坑，值对象最容易被这些绊到。
 *
 * @api
 */
final readonly class Matrix2D
{
    /** 一度对应的弧度（常量除法在 AOT 下会生成 `int/int` ⇒ 一律写字面量） */
    public const DEG_TO_RAD = 0.017453292519943295;

    /** 判"是否轴对齐"的宽容度：缩放算出来的 1.0000000000000002 不该被当成旋转 */
    public const EPSILON = 1.0E-6;

    public function __construct(
        public float $a = 1.0,
        public float $b = 0.0,
        public float $c = 0.0,
        public float $d = 1.0,
        public float $tx = 0.0,
        public float $ty = 0.0,
    ) {
    }

    public static function identity(): self
    {
        return new self();
    }

    public static function translation(float $dx, float $dy): self
    {
        return new self(1.0, 0.0, 0.0, 1.0, $dx, $dy);
    }

    public static function scaling(float $sx, float $sy): self
    {
        return new self($sx, 0.0, 0.0, $sy, 0.0, 0.0);
    }

    /** 以 `($cx, $cy)` 为中心缩放（`Transform::scaleAround` 的直接落点） */
    public static function scalingAround(float $sx, float $sy, float $cx, float $cy): self
    {
        return new self($sx, 0.0, 0.0, $sy, $cx - $sx * $cx, $cy - $sy * $cy);
    }

    /** 顺时针为正（屏幕坐标 y 向下，与 Skia / CSS 同向） */
    public static function rotation(float $degrees): self
    {
        $rad = $degrees * self::DEG_TO_RAD;
        $cos = \cos($rad);
        $sin = \sin($rad);

        return new self($cos, $sin, -$sin, $cos, 0.0, 0.0);
    }

    public static function rotationAround(float $degrees, float $cx, float $cy): self
    {
        $rad = $degrees * self::DEG_TO_RAD;
        $cos = \cos($rad);
        $sin = \sin($rad);

        // 平移到原点 → 旋转 → 平移回去，直接乘开（少两次对象分配）
        return new self(
            $cos,
            $sin,
            -$sin,
            $cos,
            $cx - $cos * $cx + $sin * $cy,
            $cy - $sin * $cx - $cos * $cy,
        );
    }

    /**
     * 先 `$this`、再 `$next`（链式阅读顺序 = 变换的**施加顺序**）。
     *
     * ★★ 名字是 `then()` 而不是 `multiply()`：数学上"先 this 后 next"= `M_next · M_this`，
     *   左乘右乘的直觉恰好相反。用 `multiply()` 会让 `rotation(30)->multiply(scaling(2))`
     *   变成"先缩放再旋转" —— 而按名字读是反的，这种错**画出来是对的、只是位置差**
     *   （真机上表现为"图标歪了但也能看"）。名字承担语义，实现跟着名字。
     */
    public function then(self $next): self
    {
        return new self(
            $next->a * $this->a + $next->c * $this->b,
            $next->b * $this->a + $next->d * $this->b,
            $next->a * $this->c + $next->c * $this->d,
            $next->b * $this->c + $next->d * $this->d,
            $next->a * $this->tx + $next->c * $this->ty + $next->tx,
            $next->b * $this->tx + $next->d * $this->ty + $next->ty,
        );
    }

    /**
     * 在当前变换的**内层**再平移一步（`CTM · T`，与 `Canvas::translate()` 同语义）。
     *
     * ★ 与 `then(Matrix2D::translation(…))` **不是**一回事：那个是 `T · CTM`（外层平移，
     *   结果 = `CTM(p) + d`）。本方法是"平移先作用于几何、再被已有变换转走"
     *   （结果 = `CTM(p + d)`），也就是画布里 `translate()` 的语义。
     *
     * ★ 平移分量**必须过一遍线性部分**（`tx' = a·dx + c·dy + tx`）：只写 `tx + dx`
     *   得到的是外层平移，两者只在 CTM 无旋转无缩放时相同。
     *   ★ 2026-10-29 实测踩到：`rotate(90)` 之后再 `translate(10,0)`，只加 `tx`
     *     得到 `R(p) + (10,0)`，正确是 `R(p + (10,0))`，落点差一个旋转。
     */
    public function translated(float $dx, float $dy): self
    {
        return new self(
            $this->a,
            $this->b,
            $this->c,
            $this->d,
            $this->a * $dx + $this->c * $dy + $this->tx,
            $this->b * $dx + $this->d * $dy + $this->ty,
        );
    }

    public function applyX(float $x, float $y): float
    {
        return $this->a * $x + $this->c * $y + $this->tx;
    }

    public function applyY(float $x, float $y): float
    {
        return $this->b * $x + $this->d * $y + $this->ty;
    }

    public function applyPoint(Point $p): Point
    {
        return new Point($this->applyX($p->x, $p->y), $this->applyY($p->x, $p->y));
    }

    /**
     * 变换矩形 ⇒ **轴对齐外接矩形**（AABB）。
     *
     * ★ 返回的不是"变换后的矩形"（旋转后那不是矩形）—— 用途只有两个：语义/无障碍
     *   报告范围、以及"这个盒大概占了哪块地方"的估算。**不要**拿它做命中测试，
     *   命中走 `inverted()->applyPoint()`。
     */
    public function applyRect(Rect $r): Rect
    {
        $x0 = $r->left;
        $y0 = $r->top;
        $x1 = $r->right();
        $y1 = $r->bottom();

        $ax0 = $this->applyX($x0, $y0);
        $ay0 = $this->applyY($x0, $y0);
        $ax1 = $ax0;
        $ay1 = $ay0;

        $px = $this->applyX($x1, $y0);
        $py = $this->applyY($x1, $y0);
        $ax0 = $px < $ax0 ? $px : $ax0;
        $ax1 = $px > $ax1 ? $px : $ax1;
        $ay0 = $py < $ay0 ? $py : $ay0;
        $ay1 = $py > $ay1 ? $py : $ay1;

        $px = $this->applyX($x0, $y1);
        $py = $this->applyY($x0, $y1);
        $ax0 = $px < $ax0 ? $px : $ax0;
        $ax1 = $px > $ax1 ? $px : $ax1;
        $ay0 = $py < $ay0 ? $py : $ay0;
        $ay1 = $py > $ay1 ? $py : $ay1;

        $px = $this->applyX($x1, $y1);
        $py = $this->applyY($x1, $y1);
        $ax0 = $px < $ax0 ? $px : $ax0;
        $ax1 = $px > $ax1 ? $px : $ax1;
        $ay0 = $py < $ay0 ? $py : $ay0;
        $ay1 = $py > $ay1 ? $py : $ay1;

        return Rect::fromLtrb($ax0, $ay0, $ax1, $ay1);
    }

    /**
     * 逆矩阵；**退化（行列式为 0）返回 `null`**。
     *
     * ★ 返回 `null` 而不是抛 / 返回恒等：调用方是命中测试，语义是"这个盒在视觉上
     *   压成了一条线/一个点 ⇒ 点不中它"。悄悄返回恒等会让"点不中的盒"变成
     *   "点得中但位置错"，那是更难查的错。
     */
    public function inverted(): ?self
    {
        $det = $this->a * $this->d - $this->b * $this->c;
        if ($det > -self::EPSILON && $det < self::EPSILON) {
            return null;
        }

        $inv = 1.0 / $det;

        return new self(
            $this->d * $inv,
            -$this->b * $inv,
            -$this->c * $inv,
            $this->a * $inv,
            ($this->c * $this->ty - $this->d * $this->tx) * $inv,
            ($this->b * $this->tx - $this->a * $this->ty) * $inv,
        );
    }

    /**
     * 只含平移（无缩放/旋转/斜切）。
     *
     * ★ 这条判据是**兼容性的闸门**：`Canvas` 的平移走的是"PHP 侧累加 offset"
     *   老路（零成本、golden 一个字节都不动），只有非纯平移才切到矩阵路径。
     *   有了它，`RenderTransform` 之外的所有盒在 `paintComposed()` 里的开销
     *   仍然是一次 `null` 判断。
     */
    public function isTranslationOnly(): bool
    {
        return \abs($this->a - 1.0) < self::EPSILON
            && \abs($this->b) < self::EPSILON
            && \abs($this->c) < self::EPSILON
            && \abs($this->d - 1.0) < self::EPSILON;
    }

    public function isIdentity(): bool
    {
        return $this->isTranslationOnly()
            && \abs($this->tx) < self::EPSILON
            && \abs($this->ty) < self::EPSILON;
    }

    /**
     * 轴对齐（无旋转/斜切，可有缩放）。轴对齐的矩形画出来仍是矩形 ⇒
     * 光栅后端可以走"改尺寸"而不是"多边形填充"。
     */
    public function isAxisAligned(): bool
    {
        return \abs($this->b) < self::EPSILON && \abs($this->c) < self::EPSILON;
    }

    /** 水平与垂直缩放（`isAxisAligned()` 为真时才有意义） */
    public function scaleX(): float
    {
        return $this->a;
    }

    public function scaleY(): float
    {
        return $this->d;
    }

    /**
     * 拆成"纯缩放 + 平移"（仅在轴对齐时精确）。
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    public function decomposeAxisAligned(): array
    {
        return [$this->a, $this->d, $this->tx, $this->ty];
    }

    /**
     * 结构相等（**按字段精确比**，不做 epsilon 宽容）。
     *
     * ★ 精确比是有意的：调用方（`RenderBox::applyTransform()`）用它判断
     *   "这次 rebuild 的变换和上一条命令流里录的一样吗"。宽容比会把
     *   "差了 1e-7" 的两次声明当成同一个 ⇒ 重放旧命令 ⇒ 动画最后一步差一丝。
     */
    public function equals(self $other): bool
    {
        return $this->a === $other->a
            && $this->b === $other->b
            && $this->c === $other->c
            && $this->d === $other->d
            && $this->tx === $other->tx
            && $this->ty === $other->ty;
    }

    /**
     * 给调试/日志用（`Matrix2D` 没有 `__toString` 是有意的：算术类不做格式化）。
     */
    public function describe(): string
    {
        return \sprintf(
            'matrix(%.4f, %.4f, %.4f, %.4f, %.4f, %.4f)',
            $this->a,
            $this->b,
            $this->c,
            $this->d,
            $this->tx,
            $this->ty,
        );
    }

    /**
     * 自检：`inverted()` 与本矩阵复合应当是恒等。
     *
     * ★ 只给测试与断言用 —— 生产路径不做这个检查（每次命中去乘一遍太贵）。
     */
    public function isInvertible(): bool
    {
        $inv = $this->inverted();
        if ($inv === null) {
            return false;
        }
        $back = $this->then($inv);

        return $back->isIdentity();
    }

    /** 供 `Errors::report` 的修法提示复用（避免两处文案漂移） */
    public static function singularHint(): string
    {
        return '矩阵退化（行列式为 0）⇒ 该盒在视觉上压成一条线/一个点，命中测试不参与；'
            . '若这是意外，检查旋转/缩放是否乘了 0';
    }
}
