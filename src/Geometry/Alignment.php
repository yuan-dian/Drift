<?php
declare(strict_types=1);

namespace Drift\Geometry;

/**
 * 对齐锚点（九宫格的**连续**形态）。
 *
 * ```
 * (-1,-1) topLeft     (0,-1) topCenter     (1,-1) topRight
 * (-1, 0) centerLeft  (0, 0) center        (1, 0) centerRight
 * (-1, 1) bottomLeft  (0, 1) bottomCenter  (1, 1) bottomRight
 * ```
 *
 * ## 语义
 *
 * 每个分量是 **`-1..1` 的分数**：`-1` = 起点（左 / 上），`0` = 居中，`+1` = 终点
 * （右 / 下）。落点由 {@see resolveX()} 求：
 *
 * ```
 * offset = free * (value + 1) / 2
 * ```
 *
 * `-1 ⇒ 0`、`0 ⇒ free/2`、`+1 ⇒ free` —— 与九宫格的直觉一致，且**中间任意值都有意义**
 * （`0.5` = 起点与中心之间）。这是它与"九宫格枚举"的唯一区别，也是它存在的理由。
 *
 * ★ **不夹取**。`|x| > 1` 是合法输入，含义是"内容伸出盒外"（滑入/滑出动效需要）。
 *   要不要夹由**消费方**决定 —— `RenderBox` 与 `RenderStack` 都选择"内容比盒大时
 *   不产生负偏移"（贴起点），因为那说明布局本身有问题（C6 该报的），不该用对齐
 *   去掩盖。**夹在调用点、不夹在这里**：值对象只做数学。
 *
 * ★ 不提供 `Alignment::fromStackAlign()` 之类的转换：`StackAlign` 这个九宫格枚举
 *   已经退役，本类就是它唯一的替代（同一概念不留两套词汇）。
 *
 * @api
 */
final readonly class Alignment
{
    /** 分数比较容差（`-1.0000000001` 与 `-1` 视为同一个锚点） */
    public const float EPSILON = 1.0E-9;

    public function __construct(public float $x = 0.0, public float $y = 0.0)
    {
    }

    // ── 九宫格 ─────────────────────────────────────────────────────

    public static function topLeft(): self
    {
        return new self(-1.0, -1.0);
    }

    public static function topCenter(): self
    {
        return new self(0.0, -1.0);
    }

    public static function topRight(): self
    {
        return new self(1.0, -1.0);
    }

    public static function centerLeft(): self
    {
        return new self(-1.0, 0.0);
    }

    public static function center(): self
    {
        return new self(0.0, 0.0);
    }

    public static function centerRight(): self
    {
        return new self(1.0, 0.0);
    }

    public static function bottomLeft(): self
    {
        return new self(-1.0, 1.0);
    }

    public static function bottomCenter(): self
    {
        return new self(0.0, 1.0);
    }

    public static function bottomRight(): self
    {
        return new self(1.0, 1.0);
    }

    // ── 查询 ───────────────────────────────────────────────────────

    public function isCentered(): bool
    {
        return \abs($this->x) < self::EPSILON && \abs($this->y) < self::EPSILON;
    }

    /** 两轴都在 `-1..1` 内 ⇒ 内容整个待在盒里（给定 `free ≥ 0` 的前提下） */
    public function isInBox(): bool
    {
        return \abs($this->x) <= 1.0 + self::EPSILON
            && \abs($this->y) <= 1.0 + self::EPSILON;
    }

    // ── 求落点 ─────────────────────────────────────────────────────

    /**
     * 水平落点。
     *
     * @param float $free 盒的可用宽 − 内容宽（**调用方负责**把负数按自己的口径处理）
     */
    public function resolveX(float $free): float
    {
        return $free * ($this->x + 1.0) * 0.5;
    }

    /** 垂直落点。见 {@see resolveX()} */
    public function resolveY(float $free): float
    {
        return $free * ($this->y + 1.0) * 0.5;
    }

    // ★ 没有 `resolve(w,h): Point`：`RenderStack` 每帧每个子都要算落点，多造一个
    //   `Point` 是白花的分配。两个 float 返回值的调用点更短，也更好断言。

    // ── 值语义 ─────────────────────────────────────────────────────

    public function equals(self $other): bool
    {
        // ★ AOT 纪律：读**另一个对象**的属性拿到 `php::Var` ⇒ 先 `(float)` 落局部。
        return \abs($this->x - (float) $other->x) < self::EPSILON
            && \abs($this->y - (float) $other->y) < self::EPSILON;
    }

    /**
     * 九宫格认得出来就给名字，否则给坐标（报错信息 / 示例台用）。
     *
     * ★ 写成一串早退 `if` 而不是 `match (true)`：AOT 对"分支条件是方法调用"的
     *   `match`/`switch` 有前科（见 `MEMORY.md` 的 AOT 五坑），这里没有性能诉求。
     */
    public function describe(): string
    {
        if ($this->equals(self::topLeft())) { return 'topLeft'; }
        if ($this->equals(self::topCenter())) { return 'topCenter'; }
        if ($this->equals(self::topRight())) { return 'topRight'; }
        if ($this->equals(self::centerLeft())) { return 'centerLeft'; }
        if ($this->equals(self::center())) { return 'center'; }
        if ($this->equals(self::centerRight())) { return 'centerRight'; }
        if ($this->equals(self::bottomLeft())) { return 'bottomLeft'; }
        if ($this->equals(self::bottomCenter())) { return 'bottomCenter'; }
        if ($this->equals(self::bottomRight())) { return 'bottomRight'; }

        return \sprintf('Alignment(%.2f, %.2f)', $this->x, $this->y);
    }
}
