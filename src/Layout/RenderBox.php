<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
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
     * 三件事，都是"把声明落到盒的字段上"：
     * ① 存引用（布局 / 绘制期读它）
     * ② 合成维**初值** —— 此后动画走 `setCompose()` 直接写盒，rebuild 后回到
     *    这里声明的值（`05` §10 #9）
     * ③ **弹性意图**（M2-4 起）—— `RenderFlexible` 已删，`mods.grow` 是份额的
     *    唯一来源。★ M2-1 时这一步必须缺席（那时份额还住在 `RenderFlexible` 的
     *    构造器里，按 mods 默认值再写一次会清零、`Expanded` 全失效且不报错）；
     *    `RenderFlexible` 删掉之后才轮到它。
     */
    public function setMods(Modifier $mods): void
    {
        $this->mods = $mods;

        $this->composeOpacity = $mods->composeOpacity();
        $this->composeDx = $mods->composeDx();
        $this->composeDy = $mods->composeDy();

        $this->setFlexIntent($mods->flexGrow(), $mods->flexShrink(), $mods->flexBasis(), $mods->flexAlign());
    }

    /** `null` = 这个盒没有修饰（直接 `new` 出来的测试盒 / 未挂载） */
    public function mods(): ?Modifier
    {
        return $this->mods;
    }

    /**
     * 内容盒原点在**本盒坐标系**里的偏移（arch-v2 `02` §4.1.1，W5）。
     *
     * ★ 子盒的 `offsetX/Y` 住**内容盒坐标**，所以"内容盒坐标 → 边框盒坐标"这一步
     *   必须有人补。补的地方只有两处，且都不是热路径：
     *   · 绘制 —— `paint()` 的 `translate(padLeft, padTop)`（画布自己带着走）
     *   · 几何 —— `hitTest()` 与 `absoluteOrigin()` 调本方法
     *   ⇒ `performLayout()` / `paintContent()` 因此**一行都不用改**：它们本来就
     *   以内容盒原点起算（19 个容器的 `setOffset` 已逐点核对）。
     *
     * ★ 返回 `float` 而不是 `?EdgeInsets`：AOT 下 `?Obj` 会被推成 `php::Var`，
     *   而这两个值每帧被 `hitTest` 读 ⇒ 退化代价直接落在命中测试上。
     */
    public function contentOriginX(): float
    {
        $m = $this->mods;

        if ($m === null || !$m->hasPadding()) {
            return 0.0;
        }

        return $m->padLeft();
    }

    /** 内容盒原点的 y（见 `contentOriginX()`） */
    public function contentOriginY(): float
    {
        $m = $this->mods;

        if ($m === null || !$m->hasPadding()) {
            return 0.0;
        }

        return $m->padTop();
    }

    // ── 主轴弹性意图（§13.1 C4 扩展；`04` §2）──────────────────────
    // ★ 住 `RenderBox` 而不是"一个代理盒"：`RenderFlex` 本来就读 `$kid->flex()`，
    //   四个意图放同一层 ⇒ `Expanded` / `Spacer` 的份额改由 `mods.grow` 声明之后，
    //   代理盒（`RenderFlexible`，M2-4 已删）就没有存在理由了。
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

            // ★ 子盒的 offset 是**内容盒**坐标 ⇒ 换成父的边框盒坐标要补父的内容原点。
            //   浮层根（`hasBase`）已经 `break`，不补 —— 它的落点就是它的边框盒原点。
            $up = $node->parent();
            if ($up instanceof RenderBox) {
                $x += $up->contentOriginX();
                $y += $up->contentOriginY();
            }
        }

        return [$x, $y];
    }

    /**
     * 两阶段之一（§13.1）：约束 → 尺寸，**同时定位子节点**。
     *
     * 纯函数（§14.1）：同样输入 ⇒ 同样输出。所以它**不参与**脏判定 —— 由调用方
     * 决定何时重排（§13.3：Render 层全树重排）。
     *
     * ## 本方法接管了四件事（arch-v2 `02` §4.1，W5）
     *
     * | 步 | 做什么 | 为什么在基类 |
     * |---|---|---|
     * | ① | `width`/`height` 修饰 ⇒ 收紧约束 | "设了就变紧轴"只有一份语义 |
     * | ② | padding 内缩 ⇒ 给子类的是**内容盒**约束 | 内缩只有一份实现 |
     * | ③ | `center` ⇒ 松掉下界 | 居中要子能缩 |
     * | ⑤ | padding 加回 ⇒ 盒尺寸 | 与 ② 成对 |
     * | ⑦ | `center` ⇒ 后置平移直接子盒 | 见下 |
     *
     * ★★ **子盒的 offset 住内容盒坐标**：`performLayout()` 收到的是内容盒约束，
     *   容器把子摆在"内容盒原点"坐标系里 —— 这与它们原本的写法**完全一致**
     *   （容器本来就从 `(0,0)` 起算）。⇒ **19 个 `performLayout` 一行都不用改，
     *   它们的 `paintContent()` 也不用改。**
     *
     * ★★ 于是 `(padLeft, padTop)` 这一步由**两个不同的地方**各自补一次，绝不重叠：
     *   · **绘制**：`paint()` 里 `save/translate(padLeft, padTop)/paintContent/restore`
     *   · **几何**：`hitTest()` 与 `absoluteOrigin()` 走 `contentOriginX/Y()`
     *   两边都补 = 重复平移（画得对、点得偏）；两边都不补 = padding 白设。
     *   2026-10-22 曾把 padding 也写进第 ⑦ 步（子盒改住边框盒坐标），与 `paint()`
     *   的 translate 撞成双重平移 —— 因为当时没有任何生产控件把 padding 写进
     *   `mods`，四档验证全绿也发现不了。
     *
     * ★ **`setOffset()` 是 `protected`**：基类调子类的 protected 方法在 PHP 与 tpc
     *   下都合法（同继承层级）。**不要**改成 `public` —— 那会破坏 C1「父定位子，
     *   子不自定位」的可见性约束。
     */
    final public function layout(BoxConstraints $constraints): Size
    {
        $m = $this->mods;

        // ① 尺寸修饰收紧约束（设了的那一轴变成紧的）
        if ($m !== null) {
            $constraints = $m->tighten($constraints);
        }

        // ② padding 内缩：给子类的约束是**内容盒**的
        $padded = $m !== null && $m->hasPadding();
        $inner = $constraints;
        if ($padded) {
            $inner = $m->deflate($constraints);
        }

        // ③ 居中 ⇒ 松掉下界。不松的话 `Stretch` 的 Flex 里子会被拉满、居中失效
        //    （`RenderCenter` 原本就是给子 `loosen()`，这里复刻）。
        $centered = $m !== null && $m->hasCenter();
        if ($centered) {
            $inner = $inner->loosen();
        }

        // ④ 子类量内容 —— **契约**：返回**内容**尺寸，不是盒尺寸
        $measured = $this->performLayout($inner);
        $content = $measured;   // ★ 内容尺寸：⑦ 的居中要它（⑤ 会把 `$measured` 换成盒尺寸）

        // ⑤ padding 加回，**并且先夹进父给的区间**再交给 ⑥
        // ★ 夹这一步不能省：内距比可用空间大时（`Padding::all(250)` 塞进 200 高的
        //   槽）口径是"按可用空间算、静默裁剪"—— W5 之前 `RenderPadding` 返回的就是
        //   `constrain(inflate(child))`。不夹 ⇒ 同一段代码从裁剪变成 C6 FATAL。
        //   内容自己超出约束（`SolidBox(500)` 塞进 100 宽）仍然由 ⑥ 响亮报 ——
        //   那才是 C6 要抓的东西。
        if ($padded) {
            $measured = $constraints->constrain($m->inflate($measured));
        }

        // ⑥ C6：**只有超出上界才算违反**——那意味着"我要画到盒外面去"。
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

        $boxSize = $constraints->constrain($measured);
        $this->size = $boxSize;

        // ★ 为什么**不**"有界轴撑满"（Flutter `Align` 的语义）：Drift 的
        //   `RenderFlex::childConstraints()` 给非弹性子**有界主轴**（`0..$mainMax`），
        //   而 Flutter 给的是 `0..INF` ⇒ 撑满会在 `Row` 里占满整行、把兄弟挤出去
        //   （2026-10-23 实测：`AccelApplication` 264.8 > 240 当场 C6 Fatal）。
        //   而"松约束下不居中"**不是 bug**：盒就是内容那么大，没有空间可居中
        //   （Flutter 在同样前提下也收缩）。要撑满请让父给 tight（`Dock` 的
        //   `center` 槽 / `Stack(Expand)` / 应用根都是这么做的）。

        // ⑦ 后置平移直接子盒：**只做 `center`**
        // ★ padding **不**进子盒偏移 —— 子盒坐标就住内容盒，换算交给 `paint()` 的
        //   `translate()` 与 `hitTest()`/`absoluteOrigin()` 的 `contentOriginX/Y()`
        //   （`02` §4.1.1）。进一次就与绘制重复平移，且不报错。
        //
        // ★★ **偏移按「盒的内容区 − 内容尺寸」算**。旧写法是
        //   `$inner->maxWidth - $measured->width`，两个都错：`$inner->maxWidth` 是
        //   **父给的可用宽**（松约束下比盒大 ⇒ 推出盒外），而 `$measured` 在 ⑤ 步
        //   已加回 padding（是**盒**尺寸）⇒ 有 padding 时偏移少算 `(padL+padR)/2`。
        //   现在用 `$boxSize - padding` 与 `$content`，与 `Align` 的口径一致。
        if ($centered) {
            $contentW = $boxSize->width;
            $contentH = $boxSize->height;
            if ($padded) {
                $contentW -= $m->padLeft() + $m->padRight();
                $contentH -= $m->padTop() + $m->padBottom();
            }

            $dx = \max(0.0, ($contentW - $content->width) / 2.0);
            $dy = \max(0.0, ($contentH - $content->height) / 2.0);

            $this->visitChildren(function (RenderObject $child) use ($dx, $dy): void {
                if ($child instanceof RenderBox) {
                    $child->setOffset($child->offsetX() + $dx, $child->offsetY() + $dy);
                }
            });
        }

        return $this->size;
    }

    /**
     * 子类只实现这一层。**必须返回内容尺寸**（不含 padding / 不被 `width` 修饰撑大），
     * 否则 C6 立刻报。
     *
     * 若是容器：在这里给子节点 `layout()` 并 `setOffset()`（C1 单向数据流）。
     * **坐标系是内容盒原点** —— 基类的 ⑦ 步会整体平移 padding，子类不要自己加。
     */
    abstract protected function performLayout(BoxConstraints $constraints): Size;

    /**
     * C3 内禀测量：本盒在**给定另一轴**时的自然尺寸（**盒**尺寸，含 padding）。
     *
     * `$height = INF` 表示那一轴不限。为什么必须带另一轴：比例盒（图、
     * 视频）要靠本轴约束定另一轴尺寸，单轴内禀值给不出答案。
     *
     * ★ **`final`**：与 `layout()` 同一个理由 —— "显式尺寸问它、否则问内容 + padding"
     *   这件事只该有一份实现。子类实现 `contentIntrinsicWidth()`。
     */
    final public function intrinsicWidth(float $height): float
    {
        $m = $this->mods;

        // ① 显式宽度 ⇒ 直接是它（不再问内容）
        if ($m !== null && $m->hasBoxWidth()) {
            return $m->boxWidth();
        }

        // ② 内容的内禀 + padding
        $innerHeight = $height;
        if ($m !== null && $m->hasPadding()) {
            $innerHeight = $height - $m->padTop() - $m->padBottom();
            if ($innerHeight < 0.0) {
                $innerHeight = 0.0;
            }
        }

        $width = $this->contentIntrinsicWidth($innerHeight);

        if ($m !== null && $m->hasPadding()) {
            $width += $m->padLeft() + $m->padRight();
        }

        return $width;
    }

    final public function intrinsicHeight(float $width): float
    {
        $m = $this->mods;

        if ($m !== null && $m->hasBoxHeight()) {
            return $m->boxHeight();
        }

        $innerWidth = $width;
        if ($m !== null && $m->hasPadding()) {
            $innerWidth = $width - $m->padLeft() - $m->padRight();
            if ($innerWidth < 0.0) {
                $innerWidth = 0.0;
            }
        }

        $height = $this->contentIntrinsicHeight($innerWidth);

        if ($m !== null && $m->hasPadding()) {
            $height += $m->padTop() + $m->padBottom();
        }

        return $height;
    }

    /** 内容的内禀宽（**不含** padding、**不看** `width` 修饰） */
    abstract protected function contentIntrinsicWidth(float $height): float;

    /** 内容的内禀高（**不含** padding、**不看** `height` 修饰） */
    abstract protected function contentIntrinsicHeight(float $width): float;

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
     * 一次性写四个弹性意图（`Modifier` 的落点；`Element::syncBox()` 唯一调用点）。
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

    /**
     * 绘制时是否把内容裁到盒矩形。
     *
     * ★ **默认 `false`，与 `overflow()` 的默认 `Clip` 故意不一致**：`overflow()`
     *   是"放不下时怎么办"的**契约查询**（默认值只是"不会超的容器"这个乐观假设），
     *   而裁剪要付出每帧 `save/pushClip/popClip/restore` 的代价 ⇒ 必须由真正会超的
     *   容器**显式覆写**。默认 `false` 还保证"新容器不覆写 ⇒ 行为与加此钩子之前
     *   完全一致"，不会因为忘记覆写而静默改变像素。
     *
     * ★ 覆写者：`RenderFlex` / `RenderDock`（`overflow === Clip` 时）、
     *   `RenderTextField`（恒真）。`RenderScrollView` **不**覆写 —— 它自己的
     *   `paintContent()` 已按视口矩形手写裁剪，再覆写就是双重压栈。
     *
     * @internal 量具 / `RenderBox::paint()` 读；应用侧用 `Widget::clip()`
     */
    public function clipsOverflow(): bool
    {
        return false;
    }

    /**
     * 画自己：**装饰 → 平移 → 内容**（arch-v2 `02` §4.2，W5）。
     *
     * ★ **`final`**：与 `layout()` 同一个理由 —— 装饰只该画一处。子类实现
     *   `paintContent()`；漏改名 = "抽象方法未实现" = 编译期 Fatal，不静默。
     *
     * ★ 为什么不让父容器调 `paintDecorated()`：那是 12+ 处抄同一句、漏抄不报错
     *   —— 正是 `Element::syncBox()` 注释里点明的那类问题。
     *
     * ★ **`translate()` 只改 PHP 侧偏移、从不调后端**（MEMORY 记载）⇒ `save()`
     *   必须真的保存/恢复它。三后端的 `saveDepth` 与偏移栈一致性由 smoke 的
     *   「W8 栈平衡」与 golden 守着。
     */
    final public function paint(Canvas $canvas): void
    {
        $m = $this->mods;
        $size = $this->size;

        // ① 背景 / 边框 / 圆角 / 阴影 —— 画在自己身上，在内容**之下**
        //    ★ 必须在裁剪**之外**：阴影按 `-grow` 起画、本来就在盒外（`02` §11.11）。
        if ($m !== null && $size !== null && $m->hasVisual()) {
            $m->paintBehind($canvas, $size);
        }

        // ①.5 自己的 chrome（自持 `BoxStyle` 的容器在这里画背景/边框，**不**被裁）
        $this->paintOwnChrome($canvas);

        // ② 裁剪：矩形是**盒矩形**（含内边距），压栈在 `translate(padLeft, padTop)`
        //    之前 ⇒ `pushClip` 按当前偏移解释（`RasterCanvas::toDevice`），压的正是
        //    盒边界。两条来源：控件显式 `clip()`，或容器声明 `Overflow::Clip`。
        $clip = false;
        if ($size !== null) {
            $wantsClip = ($m !== null && $m->hasClip()) || $this->clipsOverflow();
            if ($wantsClip) {
                $w = $size->width;
                $h = $size->height;
                $canvas->save();
                $canvas->pushClip(new Rect(0.0, 0.0, $w, $h));
                $clip = true;
            }
        }

        // ③ 内容：有 padding ⇒ 平移后画（内容盒坐标系）
        if ($m !== null && $m->hasPadding()) {
            $canvas->save();
            $canvas->translate($m->padLeft(), $m->padTop());
            $this->paintContent($canvas);
            $canvas->restore();
        } else {
            $this->paintContent($canvas);
        }

        // ④ 与 ② 配对。★ 走**统一出口**而不是每个分支各自 `popClip()`：早退分支
        //    漏 pop ⇒ `DisplayList::assertBalanced()` 会抓（`clipDepth` 归不了零），
        //    但真机上表现为"后面整棵子树全被裁"且不报错 —— 最坏的那一类。
        if ($clip) {
            $canvas->popClip();
            $canvas->restore();
        }
    }

    /**
     * 子类只实现这一层（原 `paint()`，W5 全局改名）。
     *
     * **坐标系是内容盒原点**：有 padding 时基类已经 `translate(padLeft, padTop)`；
     * 需要"盒尺寸"就自己加回 padding（见 `02` §4.1.1 的两坐标系表）。
     */
    abstract protected function paintContent(Canvas $canvas): void;

    /**
     * 画**自己的 chrome**（背景 / 边框 / 圆角）—— 在裁剪**之外**、`paintContent()` 之前。
     *
     * ★ 为什么需要这个钩子：`Modifier` 的视觉走 `paintBehind()`（本来就在裁剪之外），
     *   但自持 `BoxStyle` 的容器（`AppBar` / `TextField` / `Button` / `PopupPanel`）
     *   一直是在 `paintContent()` 里画 chrome 的。它们一旦 `clipsOverflow()`，描边跨在
     *   盒边界上的那一半就会被裁掉 —— 2026-10-23 真机实测：`users` 顶条底部那条
     *   1px 灰线（`#ebeef1`）整行消失，diff 983 个像素。
     *
     * ★ 默认空实现、只被真正画 chrome 的容器覆写 ⇒ 其余盒每帧多一次空虚调用。
     */
    protected function paintOwnChrome(Canvas $canvas): void
    {
    }

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

        // ★ 子盒 offset 住内容盒坐标 ⇒ 先把自己的内容原点减掉（`02` §4.1.1）。
        //   漏了 ⇒ 有 padding 的控件整体偏一个 padding 命中，而画面是对的 ——
        //   只有"点了没反应 / 点到隔壁"这种真机现象能暴露。
        $padX = $this->contentOriginX();
        $padY = $this->contentOriginY();

        foreach (array_reverse($children) as $child) {
            if (!$child instanceof RenderBox) {
                continue;
            }

            $hit = $child->hitTest($x - $padX - $child->offsetX(), $y - $padY - $child->offsetY());

            if ($hit !== null) {
                return $hit;
            }
        }

        return $this;
    }
}
