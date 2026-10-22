<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Modifier;

/**
 * 参与盒布局的渲染对象（§13.1）。
 *
 * ```
 * layout(BoxConstraints): Size   // 纯函数：约束 → 尺寸 + 子节点相对位置
 * paint(Canvas): void            // 父级已 translate 到本盒原点
 * ```
 *
 * ★ **`layout()` 是 `final`**：约束检查（C6）只该有一处。子类实现
 *   `performLayout()`，返回约束内的尺寸 —— 违反的话 `layout()` 立刻响亮失败，
 *   不会安静地画到盒外去。
 *
 * @api
 */
abstract class RenderBox extends RenderObject
{
    protected ?Size $size = null;
    protected float $offsetX = 0.0;
    protected float $offsetY = 0.0;

    // ── 浮层基准偏移（arch-v2 `01` §4.4，W3）──────────────────────────
    // ★ `hasBase=true` ⇒ 本盒是某棵**浮层树的根**：`absoluteOrigin()` 累加到它为止，
    //   加上自己的落点就停。没有它，嵌套浮层里的锚点只算到浮层根 (0,0)，
    //   子菜单会弹到左上角。
    // ★ `hasBase` 用独立 bool 而不是 "baseX !== 0"：浮层原点可能就是 (0,0)（模态）。
    protected float $baseX = 0.0;
    protected float $baseY = 0.0;
    protected bool $hasBase = false;

    // ── 修饰字段（arch-v2 `02` §11，W5）────────────────────────────
    // ★ **可空**，与 `?Size $size` 同形：smoke 里有十几个夹具直接
    //   `new SolidBox(...)` 造盒、不经 `Element::syncBox()` ⇒ 非空初始化会让它们
    //   全 Fatal。`?Obj` 的 `Var` 代价 = 每盒每帧一次读，与已判"不做"的
    //   `toObject`（0.5–1%）同阶。
    // ★ 这是**派生**字段（由 Widget 的声明递下来），不是盒自己的状态 ⇒ 与
    //   `composeOpacity` 那组"每帧都在变的动画量"是两件事。
    private ?Modifier $mods = null;

    /**
     * 收下 Widget 的修饰声明（`Element::syncBox()` 唯一调用点）。
     *
     * ★★ **M2-1 阶段只存引用 + 初始化合成参数，绝不碰 `setFlexIntent()`**：
     *   `Expanded` / `Flexible` 此刻仍然产 `RenderFlexible`，它的份额是在
     *   **构造器里** `setFlexIntent()` 写进去的；这里若按 `mods` 的默认值
     *   （`grow = 0`）再写一次，会把份额清零 ⇒ `Expanded` 全部失效、且不报错。
     *   接上 `setFlexIntent()` 的时机是 M2-5（`RenderFlexible` 删掉之后）。
     *
     * ★ `padding` / `tighten` / `paintBehind` 的消费在 M2-2 / M2-3，同样不在本步。
     */
    public function setMods(Modifier $mods): void
    {
        $this->mods = $mods;

        // 合成维是**初值**：读一次去初始化盒上的动画量，此后动画走
        // `setCompose()` 直接写盒（rebuild 后回到这里声明的值 —— `05` §10 #9）。
        $this->composeOpacity = $mods->composeOpacity();
        $this->composeDx = $mods->composeDx();
        $this->composeDy = $mods->composeDy();
    }

    /** `null` = 这个盒没有修饰（直接 `new` 出来的测试盒 / 未挂载） */
    public function mods(): ?Modifier
    {
        return $this->mods;
    }

    // ── 主轴弹性意图（§13.1 C4 扩展；`04` §2）──────────────────────
    // ★ 住 `RenderBox` 而不是"一个代理盒"：`RenderFlex` 本来就读 `$kid->flex()`，
    //   四个意图放同一层 ⇒ `02` §7 把 `Expanded`/`Flexible` 改成 shim 之后，
    //   代理盒（`RenderFlexible`）就没有存在理由了。
    // ★ **NAN = 未设**：`basis` 的"未设"（用自然尺寸）与"设为 0"必须是两件事，
    //   同 `02` §3.2 的 `width` 判据。
    private int $grow = 0;        // 0 = 不参与放大
    private int $shrink = 0;      // 0 = 不参与压缩
    private float $basis = \NAN;  // NAN = 用自然尺寸
    private int $alignSelf = self::ALIGN_INHERIT;

    public function size(): ?Size
    {
        return $this->size;
    }

    public function hasSize(): bool
    {
        return $this->size !== null;
    }

    /** 父定位子（C1）：子不自定位，所以这两个只有父会写 */
    public function offsetX(): float
    {
        return $this->offsetX;
    }

    public function offsetY(): float
    {
        return $this->offsetY;
    }

    protected function setOffset(float $x, float $y): void
    {
        $this->offsetX = $x;
        $this->offsetY = $y;
    }

    /** 标记本盒是浮层树的根，并给它客户区落点（`Application::placeEntry()` 每帧写） */
    public function setBase(float $x, float $y): void
    {
        $this->baseX = $x;
        $this->baseY = $y;
        $this->hasBase = true;
    }

    public function hasBase(): bool
    {
        return $this->hasBase;
    }

    public function baseX(): float
    {
        return $this->baseX;
    }

    public function baseY(): float
    {
        return $this->baseY;
    }

    /**
     * 盒在客户区的**绝对原点**（沿父链累加相对偏移）—— 逻辑像素。
     *
     * ★ 单一出处：命中测试 / IME 候选框 / 菜单锚点 / 语义几何全部走这里
     *   （`Application::absoluteOrigin()` 已改为委派到本方法）。自己沿 Element 链
     *   累加 `offset` 会**重复累加**（Element 不持有 offset，盒才持）。
     * ★ **遇到浮层根（`hasBase`）就加上它的落点并停止**：嵌套浮层里的锚点若一路
     *   累加到主树根，浮层根那一层会被算成 (0,0)（`01` §4.4 的真坑）。
     * ★ 未参与本轮布局（`size() === null`）的盒照常累加 —— 调用方自己判
     *   `hasSize()`；这里不做"部分布局"的特殊处理。
     *
     * @return array{0: float, 1: float} `[x, y]`
     */
    public static function absoluteOrigin(RenderBox $box): array
    {
        $x = 0.0;
        $y = 0.0;

        for ($node = $box; $node !== null; $node = $node->parent()) {
            $x += $node->offsetX();
            $y += $node->offsetY();

            if ($node->hasBase()) {
                $x += $node->baseX();
                $y += $node->baseY();
                break;
            }
        }

        return [$x, $y];
    }

    /**
     * 两阶段之一（§13.1）：约束 → 尺寸，**同时定位子节点**。
     *
     * 纯函数（§14.1）：同样输入 ⇒ 同样输出。所以它**不参与**脏判定 —— 由调用方
     * 决定何时重排（§13.3：Render 层全树重排）。
     */
    final public function layout(BoxConstraints $constraints): Size
    {
        $measured = $this->performLayout($constraints);

        // C6：**只有超出上界才算违反**——那意味着"我要画到盒外面去"。
        // 下界提升不是违反：`tight()` 的语义就是父把尺寸定死了，`constrain()`
        // 提下界是它的本职工作（Drift 的 Column 在紧约束下报"最宽子"而不是
        // 硬宽，是正确的——父只是给了上限）。
        if (($measured->width > $constraints->maxWidth || $measured->height > $constraints->maxHeight)
            && !LayoutTolerance::$previewing) {   // 预览期（拖动中）：瞬时违规降级为裁剪
            Errors::report(sprintf(
                '%s 画出的尺寸超出父容器给的可用空间：实际 %.1f × %.1f，最多只能用 %.1f × %.1f。'
                    . "\n" . '修法：让这个控件的尺寸跟随约束（不要写死比可用空间更大的尺寸），'
                    . '或检查父容器为什么只给了这么小。',
                static::class,
                $measured->width,
                $measured->height,
                $constraints->maxWidth,
                $constraints->maxHeight
            ));
        }

        $this->size = $constraints->constrain($measured);

        return $this->size;
    }

    /**
     * 子类只实现这一层。**必须返回约束内的尺寸**，否则 C6 立刻报。
     *
     * 若是容器：在这里给子节点 `layout()` 并 `setOffset()`（C1 单向数据流）。
     */
    abstract protected function performLayout(BoxConstraints $constraints): Size;

    /**
     * C3 内禀测量：本盒在**给定另一轴**时的自然尺寸。
     *
     * `$height = INF` 表示那一轴不限。为什么必须带另一轴：比例盒（图、
     * 视频）要靠本轴约束定另一轴尺寸，单轴内禀值给不出答案。
     */
    abstract public function intrinsicWidth(float $height): float;

    abstract public function intrinsicHeight(float $width): float;

    // ── `alignSelf` 的取值（`04` §2.4）────────────────────────────
    // ★ **int 哨兵而不是 enum**：`alignSelf` 要表达"未设"（INHERIT），而
    //   `FlexAlign` 没有这个 case；加一个会让 `placeChildren()` 的 match
    //   多一条"不该走到"的分支。int 0 是唯一不可能与真值混淆的写法。
    public const ALIGN_INHERIT = 0;   // 跟父容器的 crossAlign
    public const ALIGN_START   = 1;
    public const ALIGN_CENTER  = 2;
    public const ALIGN_END     = 3;
    public const ALIGN_STRETCH = 4;

    /**
     * 一次性写四个弹性意图（`Modifier` / `RenderFlexible` 的落点）。
     *
     * ★ 为什么是一个 setter 而不是四个：调用点（`Element::syncBox()`）只有一处，
     *   四个 setter = 四次方法调用 + 四次"漏调一个"的机会。
     */
    public function setFlexIntent(int $grow, int $shrink, float $basis, int $alignSelf): void
    {
        $this->grow = $grow;
        $this->shrink = $shrink;
        $this->basis = $basis;
        $this->alignSelf = $alignSelf;
    }

    /** 主轴放大份额（0 = 不参与） */
    public function grow(): int
    {
        return $this->grow;
    }

    /** 主轴压缩份额（0 = 不参与；`04` §3 的 shrink 分支读它） */
    public function shrink(): int
    {
        return $this->shrink;
    }

    /** 分配前的起始主轴尺寸（NAN = 用自然尺寸） */
    public function basis(): float
    {
        return $this->basis;
    }

    /** 交叉轴自对齐（ALIGN_INHERIT = 跟父容器） */
    public function alignSelf(): int
    {
        return $this->alignSelf;
    }

    /**
     * 主轴弹性份额（`RenderFlex` 读它；§13.1 C4）。
     *
     * ★ **`flex()` 是 `grow()` 的别名**（`04` §2.1）：语义没变，只是
     *   "住进字段"之后 `grow` 是正式名字。保留一个提交周期，
     *   等 `apps/showcase` 全量改名之后删。
     *
     * @deprecated 用 `grow()`
     */
    public function flex(): int
    {
        return $this->grow;
    }

    /**
     * C5 溢出策略。**默认 `Clip` 只给不会超的容器**（尺寸由内容或子决定的容器）；
     * 会超的容器（`Row` / `AppBar` / `Dock` 槽 / 自绘容器）**必须覆写**。
     *
     * @api
     */
    public function overflow(): Overflow
    {
        return Overflow::Clip;
    }

    abstract public function paint(Canvas $canvas): void;

    /**
     * 动画期的合成参数（arch-v2 `05` §5.4，W4-L3-4）—— **就地改，不重建盒、不重排**。
     *
     * ★ 为什么允许可变字段破坏「Render 层也是值对象」的纪律：值对象管的是
     *   **描述**（Widget 层，不可变），这里管的是**每帧都在变的动画量**。
     *   两者失效频率差三个数量级 —— 让动画量走值对象等于每帧造一个新盒，
     *   而造盒正是 `D_LAYOUT` 那条最贵的路（§5.2）。
     * ★ **rebuild 会把这些值丢掉**（新盒的字段是默认值）—— 这是**正确行为**：
     *   结构变了，动画的中间态就不该继续顶着（`05` §10 边界 #9）。
     *   所以它只在"纯动画帧"里有效，与 `Frame::run` 跳过 layout 的判据是同一个前提。
     */
    private float $composeOpacity = 1.0;

    private float $composeDx = 0.0;

    private float $composeDy = 0.0;

    /**
     * 就地改合成参数（不重建、不重排、不置任何脏位）。
     *
     * ```php
     * // 应用代码（淡入）：State::tickFrame 里直接写盒
     * public function tickFrame(float $timestampMs): bool
     * {
     *     $busy = $this->fade->tick($timestampMs);
     *     if (!$busy) { return false; }
     *     $this->element()?->renderObject()?->setCompose($this->fade->value(), 0.0, 0.0);
     *     return true;
     * }
     * ```
     *
     * ★ **不置布局脏**是整条路的重点：置了就等于每帧全树重排，这条路白修。
     * ★ `opacity >= 1` 且 `dx == dy == 0` ⇒ `paintComposed()` 走**零开销快路**
     *   （不 save / 不开层）⇒ 不用它就没有代价。
     *
     * @api
     */
    public function setCompose(float $opacity, float $dx = 0.0, float $dy = 0.0): void
    {
        $this->composeOpacity = $opacity;
        $this->composeDx = $dx;
        $this->composeDy = $dy;
    }

    public function composeOpacity(): float
    {
        return $this->composeOpacity;
    }

    /** @return array{0: float, 1: float} `[dx, dy]` */
    public function composeOffset(): array
    {
        return [$this->composeDx, $this->composeDy];
    }

    /**
     * 画自己（**含合成参数**）—— 父容器与 `Frame` 一律走这个入口，不走 `paint()`。
     *
     * ★ 平移用坐标加法（不开层），`opacity < 1` 才开层（`05` §5.5）：
     *   开层是"分配一整块像素"，为一次加法付这个代价不划算；而不透明度是
     *   **逐像素乘法**，必须在子树画完之后才能乘（否则子与子重叠处被乘两次）。
     * ★ 默认参数下（`1.0 / 0 / 0`）**一条命令都不多**：没有 save、没有层。
     *
     * @api
     */
    final public function paintComposed(Canvas $canvas): void
    {
        $opacity = $this->composeOpacity;
        $dx = $this->composeDx;
        $dy = $this->composeDy;
        $translated = $dx !== 0.0 || $dy !== 0.0;
        $layered = $opacity < 1.0;

        if (!$translated && !$layered) {
            $this->paint($canvas);   // 零开销快路：绝大多数盒一辈子走这里

            return;
        }

        $canvas->save();

        if ($translated) {
            $canvas->translate($dx, $dy);
        }

        if ($layered) {
            $canvas->pushLayer(max(0.0, $opacity));
        }

        $this->paint($canvas);

        if ($layered) {
            $canvas->popLayer();
        }

        $canvas->restore();
    }

    /**
     * 命中测试（§9.1）：**从本盒出发**深度优先，返回最深的命中盒。
     *
     * `$x` / `$y` 是本盒的**局部**坐标（左上角为原点，逻辑单位，§6.1）。
     *
     * ★ 三条规则（§9.1）：
     *   ① 越界 ⇒ `null`；
     *   ② 子节点**逆序**问（后画的在上面，与 `paint()` 顺序相反）；
     *   ③ 子节点都不中 ⇒ **返回自己** —— 容器吞掉事件（"禁用 ≠ 穿透"的基础）。
     * ★ **`Offstage` 无需特判**：它跳过 layout ⇒ `size` 为 null ⇒ 这里直接 `null`。
     * ★ **一次命中、三方消费**（事件路由 / 语义 / 光标形状）⇒ 调用方只走一次树。
     *
     * @api
     */
    public function hitTest(float $x, float $y): ?RenderBox
    {
        $size = $this->size;

        if ($size === null) {
            return null;
        }

        if ($x < 0.0 || $y < 0.0 || $x >= $size->width || $y >= $size->height) {
            return null;
        }

        /** @var list<RenderObject> $children */
        $children = [];
        $this->visitChildren(function (RenderObject $child) use (&$children): void {
            $children[] = $child;
        });

        foreach (array_reverse($children) as $child) {
            if (!$child instanceof RenderBox) {
                continue;
            }

            $hit = $child->hitTest($x - $child->offsetX(), $y - $child->offsetY());

            if ($hit !== null) {
                return $hit;
            }
        }

        return $this;
    }
}
