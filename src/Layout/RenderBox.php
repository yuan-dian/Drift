<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\LayerCache;
use Drift\Rendering\Modifier;
use Drift\Rendering\Paint;

/**
 * 参与盒布局的渲染对象：`layout()` 约束 → 尺寸 + 子节点相对位置，`paint()` 以本盒原点绘制。
 *
 * ★ **`layout()` 是 `final`**：约束检查（C6）只该有一处。子类实现 `performLayout()`，
 *   返回约束内的尺寸 —— 违反的话 `layout()` 立刻响亮失败，不会安静地画到盒外去。
 *
 * @api
 */
abstract class RenderBox extends RenderObject
{
    protected ?Size $size = null;
    protected float $offsetX = 0.0;
    protected float $offsetY = 0.0;

    // 上一次 layout 收到的**内容盒**约束（`performLayout()` 的入参）。纯数据快照：
    // 只被读（`constraints()` / 约束反馈），从不参与任何判定 ⇒ 布局仍是纯函数。
    private ?BoxConstraints $lastConstraints = null;

    // 浮层树的根：`absoluteOrigin()` 累加到它为止再加落点。没有它，嵌套浮层里的锚点
    // 只算到浮层根 (0,0)。★ 用独立 bool 而非 "baseX !== 0"：浮层原点可能就是 (0,0)。
    protected float $baseX = 0.0;
    protected float $baseY = 0.0;
    protected bool $hasBase = false;

    // 修饰声明（由 Widget 递下来），**可空**：测试夹具直接 new 盒、不经 `syncBox()`。
    // ★ 派生字段，与 `composeOpacity` 那组每帧在变的动画量是两件事。
    private ?Modifier $mods = null;

    /**
     * 收下 Widget 的修饰声明（`Element::syncBox()` 唯一调用点）。
     * 合成维**初值**在这里落定；此后动画走 `setCompose()` 直接写盒，rebuild 后回到声明值。
     */
    public function setMods(Modifier $mods): void
    {
        $this->mods = $mods;

        // ★ 禁用把置灰**折进**合成 alpha：`paintComposed()` 在 `opacity < 1` 时已开层，
        //   整棵子树一起变淡。`dim = false` 的控件自绘禁用外观，不再乘以免二次衰减。
        $this->disabled = $mods->disabled();
        $dim = $this->disabled && $mods->dimsWhenDisabled();
        $this->applyCompose(
            $dim ? $mods->composeOpacity() * self::DISABLED_ALPHA : $mods->composeOpacity(),
            $mods->composeDx(),
            $mods->composeDy(),
        );

        $this->setRepaintBoundary($mods->repaintBoundary());

        $this->setFlexIntent($mods->flexGrow(), $mods->flexShrink(), $mods->flexBasis(), $mods->flexAlign());
        $this->setStackIntent($mods->insetLeft(), $mods->insetTop(), $mods->insetRight(), $mods->insetBottom());
        $this->applyTransform($mods->transform(), $mods->transformCentered());
    }

    /** `null` = 这个盒没有修饰（直接 `new` 出来的测试盒 / 未挂载） */
    public function mods(): ?Modifier
    {
        return $this->mods;
    }

    /**
     * ★ 读的是 `setMods()` 折下来的字段，不是 `mods()->disabled()`：`hitTest()` 逐盒调用。
     * ★ `mods === null` ⇒ `false`："没声明" 与 "声明了可用" 行为相同。
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    /**
     * 内容盒原点在**本盒坐标系**里的偏移。
     *
     * ★ 子盒 offset 住**内容盒坐标** ⇒ 换算必须在绘制（`paint()` 的 `translate`）与几何
     *   （`hitTest()` / `absoluteOrigin()`）各补一次、绝不重叠：都补 = 重复平移（画得对、点得偏）。
     * ★ 返回 `float` 而非 `?EdgeInsets`：AOT 下 `?Obj` 推成 `php::Var`，代价落在每帧命中测试上。
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

    // 主轴弹性意图（`RenderFlex` 读）。**NAN = 未设**：`basis` 的"未设"与"设为 0"是两件事。
    private int $grow = 0;        // 0 = 不参与放大
    private int $shrink = 0;      // 0 = 不参与压缩
    private float $basis = \NAN;  // NAN = 用自然尺寸
    private int $alignSelf = self::ALIGN_INHERIT;

    // 层叠定位意图（`RenderStack` 读）。**NAN = 未声明**（不是 0）：`left(0)` 与"没说
    // 左边在哪"是两件事；没有对应的父容器就是死字段、不报错。
    private float $insetLeft = \NAN;

    private float $insetTop = \NAN;

    private float $insetRight = \NAN;

    private float $insetBottom = \NAN;

    // 合成变换：**绘制期**语义，不进布局、不改约束、不改子盒尺寸 ⇒ 视觉会溢出（放大 2 倍
    // 盖到邻居），这是标准语义。★ 绕中心的那份在 `effectiveTransform()` 里现算。
    private ?Matrix2D $composeTransform = null;

    private bool $composeTransformCentered = false;

    public function size(): ?Size
    {
        return $this->size;
    }

    /**
     * 上一次 layout 收到的**内容盒**约束（= `performLayout()` 的入参，已过 mods 收紧 /
     * padding 内缩 / align 松下界）；还没排过 ⇒ `null`。
     *
     * ★ **`null` 是"不知道"，不是"无界"**：拿它当 `unbounded()` 用，会把"没排过"和
     *   "父给了无界"混成一件事。
     * ★ 消费者按需判 `hasBoundedWidth()` / `hasBoundedHeight()`，别把 `INF` 当大数字比。
     *
     * @api
     */
    public function constraints(): ?BoxConstraints
    {
        return $this->lastConstraints;
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

    /**
     * 改位置 ⇒ **必须标重绘**。
     * ★ 收口在本字段的写入点：调用方有十几处，让每个调用方记得标脏 ⇒ 漏一处就是
     *   "位置变了但画面不动"（静默）。
     */
    protected function setOffset(float $x, float $y): void
    {
        if ($this->offsetX === $x && $this->offsetY === $y) {
            return;
        }

        $this->offsetX = $x;
        $this->offsetY = $y;

        $this->pipeline()->bumpPaintEpoch();
    }

    /**
     * 标记本盒是浮层树的根，并给它客户区落点（`Application::placeEntry()` 每帧写）。
     * ★ 落点是在 `Frame::run` 之外算出来的 ⇒ 不标重绘就是"面板该跟着锚点挪、却停在原地"。
     */
    public function setBase(float $x, float $y): void
    {
        if ($this->hasBase && $this->baseX === $x && $this->baseY === $y) {
            return;
        }

        $this->baseX = $x;
        $this->baseY = $y;
        $this->hasBase = true;

        $this->pipeline()->bumpPaintEpoch();
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
     * ★ 单一出处：命中测试 / IME 候选框 / 菜单锚点 / 语义几何全部走这里；自己沿 Element
     *   链累加 `offset` 会**重复累加**（Element 不持有 offset，盒才持）。
     * ★ **遇到浮层根（`hasBase`）就加上落点并停止**；`size() === null` 的盒照常累加。
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
            //   浮层根已 `break`，不补 —— 它的落点就是它的边框盒原点。
            $up = $node->parent();
            if ($up instanceof RenderBox) {
                $x += $up->contentOriginX();
                $y += $up->contentOriginY();
            }
        }

        return [$x, $y];
    }

    /**
     * 约束 → 尺寸，**同时定位子节点**；纯函数，不参与脏判定。
     *
     * 基类接管：`width`/`height` 修饰收紧约束 → padding 内缩（给子类**内容盒**约束）→
     * `align` 松掉下界 → padding 加回并夹进父区间 → `align` 后置平移子盒。
     *
     * ★★ `(padLeft, padTop)` 必须在绘制与几何两处**各补一次、绝不重叠**：都补 = 重复
     *   平移（画得对、点得偏），都不补 = padding 白设。
     * ★ `setOffset()` 保持 `protected`：改 `public` 会破坏「父定位子，子不自定位」。
     */
    final public function layout(BoxConstraints $constraints): Size
    {
        $this->pipeline()->bumpLayout();

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

        // ③ 对齐 ⇒ 松掉下界，否则 `Stretch` 的 Flex 里子会被拉满、对齐失效。
        // ★ 不用 `?->`：AOT 下 `?->` 会走跨实例查表、对本类字段也失效 ⇒ 拆成显式 null 判断。
        $align = null;
        if ($m !== null) {
            $align = $m->alignment();
        }

        if ($align !== null) {
            $inner = $inner->loosen();
        }

        // ④ 子类量内容 —— **契约**：返回**内容**尺寸，不是盒尺寸
        // ★ 快照在调用之前落：`performLayout()` 抛异常时"收到的约束"仍是真的。
        $this->lastConstraints = $inner;
        $measured = $this->performLayout($inner);
        $content = $measured;   // ★ 内容尺寸：⑦ 的居中要它（⑤ 会把 `$measured` 换成盒尺寸）

        // ⑤ padding 加回，**先夹进父给的区间**：内距比可用空间大时的口径是"静默裁剪"，
        //   不夹就从裁剪变成 C6 FATAL（内容自己超约束仍由 ⑥ 报）。
        if ($padded) {
            $measured = $constraints->constrain($m->inflate($measured));
        }

        // ⑥ C6：**只有超出上界才算违反**——那意味着"我要画到盒外面去"。
        // 下界提升不是违反：`constrain()` 提下界是它的本职工作。
        if ($measured->width > $constraints->maxWidth + LayoutTolerance::EPSILON
            || $measured->height > $constraints->maxHeight + LayoutTolerance::EPSILON) {
            if (LayoutTolerance::$windowDriven) {
                // ★ 窗口帧里只裁剪 + 留痕，不让用户看到 Fatal 框；纯布局仍中止。
                LayoutTolerance::clipped(static::class . ':C6', sprintf(
                    '%s 画出的尺寸超出了可用空间：实际 %.1f × %.1f，最多只能用 %.1f × %.1f —— 超出部分已裁掉。'
                        . "\n" . '这不是崩溃：把窗口调大即可恢复完整显示。'
                        . "\n" . '若这个控件不该有这么大，让它跟随约束（不要写死大于可用空间的尺寸）。',
                    static::class,
                    $measured->width,
                    $measured->height,
                    $constraints->maxWidth,
                    $constraints->maxHeight
                ));
            } else {
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
        }

        $boxSize = $constraints->constrain($measured);
        $this->size = $boxSize;

        // ★ 有界轴**不撑满**：`RenderFlex` 给非弹性子的是有界主轴 ⇒ 撑满会占满整行、
        //   把兄弟挤出去（C6 Fatal）。松约束下不居中不是 bug，要撑满请让父给 tight。

        // ⑦ 后置平移直接子盒（按对齐锚点）。★ padding **不**进子盒偏移 —— 进一次就与
        //   绘制重复平移，且不报错。自由空间先夹到 ≥ 0：超盒该由 ⑥ 报，不该由对齐掩盖。
        if ($align !== null) {
            $contentW = $boxSize->width;
            $contentH = $boxSize->height;
            if ($padded) {
                $contentW -= $m->padLeft() + $m->padRight();
                $contentH -= $m->padTop() + $m->padBottom();
            }

            $freeW = $contentW - $content->width;
            $freeH = $contentH - $content->height;
            if ($freeW < 0.0) {
                $freeW = 0.0;
            }
            if ($freeH < 0.0) {
                $freeH = 0.0;
            }

            $dx = $align->resolveX($freeW);
            $dy = $align->resolveY($freeH);

            $this->visitChildren(function (RenderObject $child) use ($dx, $dy): void {
                if ($child instanceof RenderBox) {
                    $child->setOffset($child->offsetX() + $dx, $child->offsetY() + $dy);
                }
            });
        }

        return $this->size;
    }

    /**
     * 子类只实现这一层。**必须返回内容尺寸**（不含 padding / 不被 `width` 撑大），否则 C6 报。
     * 容器在这里给子 `layout()` 并 `setOffset()`；**坐标系是内容盒原点**，不要自己加 padding。
     */
    abstract protected function performLayout(BoxConstraints $constraints): Size;

    /**
     * C3 内禀测量：本盒在**给定另一轴**时的自然尺寸（**盒**尺寸，含 padding）。
     *
     * `$height = INF` 表示该轴不限；比例盒要靠另一轴的约束定尺寸。★ `final`，与
     * `layout()` 同理：只该有一份实现。子类实现 `contentIntrinsicWidth()`。
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

    // `alignSelf` 的取值。★ **int 哨兵而不是 enum**：要表达"未设"（INHERIT），
    // 而 `FlexAlign` 没有这个 case；加一个会让 `placeChildren()` 的 match 多一条死分支。
    public const ALIGN_INHERIT = 0;   // 跟父容器的 crossAlign
    public const ALIGN_START   = 1;
    public const ALIGN_CENTER  = 2;
    public const ALIGN_END     = 3;
    public const ALIGN_STRETCH = 4;

    /**
     * 一次性写四个弹性意图（`Element::syncBox()` 唯一调用点）：四个 setter 就是四次
     * "漏调一个"的机会，而漏调不报错。
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

    /** 主轴压缩份额（0 = 不参与） */
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
     * 一次性写四条边的层叠定位（`Element::syncBox()` 唯一调用点）；漏调一条 = 某条边静默不生效。
     */
    public function setStackIntent(float $left, float $top, float $right, float $bottom): void
    {
        $this->insetLeft = $left;
        $this->insetTop = $top;
        $this->insetRight = $right;
        $this->insetBottom = $bottom;
    }

    /** 有没有声明任何一边的定位（`RenderStack` 用它分"定位子 / 非定位子"） */
    public function hasInset(): bool
    {
        return !\is_nan($this->insetLeft)
            || !\is_nan($this->insetTop)
            || !\is_nan($this->insetRight)
            || !\is_nan($this->insetBottom);
    }

    /** `NAN` = 该边未声明（未声明的轴按 `Alignment` 排） */
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

    /**
     * ★ `flex()` 是 `grow()` 的别名，保留一个提交周期供旧调用点改名。
     *
     * @deprecated 用 `grow()`
     */
    public function flex(): int
    {
        return $this->grow;
    }

    /**
     * C5 溢出策略。**默认 `Clip` 只给不会超的容器**；会超的容器（`Row` / `AppBar` /
     * `Dock` 槽 / 自绘容器）**必须覆写**。
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
     * ★ **默认 `false`，与 `overflow()` 的默认 `Clip` 故意不一致**：裁剪要付每帧
     *   `save/pushClip/popClip/restore` 的代价 ⇒ 必须由真正会超的容器显式覆写。
     * ★ `RenderScrollView` **不**覆写 —— 它的 `paintContent()` 已手写视口裁剪。
     *
     * @internal 量具 / `RenderBox::paint()` 读；应用侧用 `Widget::clip()`
     */
    public function clipsOverflow(): bool
    {
        return false;
    }

    /**
     * 画自己：**装饰 → 平移 → 内容**。`final`：装饰只该画一处，子类实现 `paintContent()`。
     * ★ **`translate()` 只改 PHP 侧偏移、从不调后端** ⇒ `save()` 必须真的保存/恢复它。
     */
    final public function paint(Canvas $canvas): void
    {
        $m = $this->mods;
        $size = $this->size;

        // ① 背景 / 边框 / 圆角 / 阴影 —— 画在自己身上，在内容**之下**
        //    ★ 必须在裁剪**之外**：阴影按 `-grow` 起画、本来就在盒外。
        if ($m !== null && $size !== null && $m->hasVisual()) {
            $m->paintBehind($canvas, $size);
        }

        // ①.5 自己的 chrome（自持 `BoxStyle` 的容器在这里画背景/边框，**不**被裁）
        $this->paintOwnChrome($canvas);

        // ①.6 两相位状态层：hover 在下、press 在上，盖住底色但不盖内容
        $this->paintInteraction($canvas, $size);

        // ② 裁剪：矩形是**盒矩形**（含内边距），压栈在 `translate(padLeft, padTop)`
        //    之前 ⇒ 压的正是盒边界。两条来源：控件显式 `clip()`，或容器声明 `Overflow::Clip`。
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

        // ④ 与 ② 配对。★ 走**统一出口**：早退分支漏 pop 会让后面整棵子树全被裁，
        //    且不报错（`DisplayList::assertBalanced()` 只抓得到栈不配平）。
        if ($clip) {
            $canvas->popClip();
            $canvas->restore();
        }
    }

    /**
     * 子类只实现这一层。**坐标系是内容盒原点**：有 padding 时基类已经
     * `translate(padLeft, padTop)`，需要"盒尺寸"就自己加回 padding。
     */
    abstract protected function paintContent(Canvas $canvas): void;

    /**
     * 把子盒平移到**它自己的位置**画出去 —— 父容器画子的唯一入口。
     * ★ 四行必须按序：写反或漏 `translate` 时**栈仍配平、整帧不报错**，错的是后面所有
     *   兄弟的位置；少一个 `save` 则平移**累积**，越往后越偏。
     */
    final protected function paintChildAt(Canvas $canvas, RenderBox $child): void
    {
        $canvas->save();
        $canvas->translate($child->offsetX(), $child->offsetY());
        $child->paintComposed($canvas);
        $canvas->restore();
    }

    /**
     * 画**自己的 chrome**（背景 / 边框 / 圆角）—— 在裁剪**之外**、`paintContent()` 之前。
     * ★ 自持 `BoxStyle` 的容器若不在这里画，一旦 `clipsOverflow()`，描边跨在盒边界上的
     *   那一半就会被裁掉。
     */
    protected function paintOwnChrome(Canvas $canvas): void
    {
    }

    /**
     * 两相位状态层，**默认形状 = 整盒矩形**。
     *
     * ★ 自绘控件覆写它换几何（开关只给本体一圈光晕、分段只给药丸、滑块只给拇指），
     *   输入框则换成**描边**（同一种状态的另一种形状，见 `paintStateLayer()`）。
     *   层序由 `final paint()` 钉死：`装饰 → 自己的 chrome → 状态层 → 内容`，
     *   覆写只能换形状、换不了层次 —— 需要层压在别的图元之上时，把那块图元挪进
     *   `paintOwnChrome()`。
     */
    protected function paintInteraction(Canvas $canvas, ?Size $size): void
    {
        if ($size === null) {
            return;
        }

        $this->paintStateLayer(
            $canvas,
            new Rect(0.0, 0.0, $size->width, $size->height),
            $this->interactionRadius(),
        );
    }

    /**
     * 状态层原语：未声明走零成本早退；静止时进度为 0，一条命令都不发。
     * `final` —— 层次（hover 在下、press 在上）只该有一份实现。
     *
     * @param float $strokeWidth `0` = 填充形状（按钮 / 开关 / 段 / 滑块）；
     *                           `> 0` = 沿形状描边（输入框：底与 `Hover` token 太近，
     *                           填充叠色在亮主题下几乎不可见，反馈该落在边框上）。
     */
    final protected function paintStateLayer(Canvas $canvas, Rect $shape, float $radius, float $strokeWidth = 0.0): void
    {
        $hover = $this->interactionHover;
        $down = $this->interactionPress;
        if ($hover === null && $down === null) {
            return;
        }

        if ($hover !== null) {
            $a = (int) \round($hover->a * $this->hoverProgress());
            if ($a > 0) {
                $canvas->drawRect($shape, $this->stateBrush($hover->withAlpha($a), $strokeWidth), $radius);
            }
        }

        if ($down !== null) {
            $a = (int) \round($down->a * $this->pressProgress());
            if ($a > 0) {
                $canvas->drawRect($shape, $this->stateBrush($down->withAlpha($a), $strokeWidth), $radius);
            }
        }
    }

    /** 状态层的笔：两相位共用一支，避免淡入逻辑被抄两遍。 */
    private function stateBrush(Color $color, float $strokeWidth): Paint
    {
        return $strokeWidth > 0.0 ? Paint::stroke($color, $strokeWidth) : Paint::fill($color);
    }

    /** 状态层圆角；自持 `BoxStyle` 的盒覆写。 */
    protected function interactionRadius(): float
    {
        $m = $this->mods;
        if ($m === null) {
            return 0.0;
        }

        $style = $m->boxStyle();
        if ($style === null) {
            return 0.0;
        }

        return $style->radius;
    }

    /**
     * 动画期的合成参数 —— **就地改，不重建盒、不重排**。
     * ★ **rebuild 会把这些值丢掉**（新盒是默认值），这是正确的：结构变了，动画中间态
     *   就不该继续顶着。
     */
    private float $composeOpacity = 1.0;

    private float $composeDx = 0.0;

    private float $composeDy = 0.0;

    // 交互维（`Widget::disabled()` 的下游）：只在 `setMods()` 时变，不需要"直接写盒"的旁路。
    // ★ 存成 bool 而不是每次读 `mods->disabled()`：`hitTest()` 逐盒调用，而 `mods` 是
    //   `?Obj`（AOT 下退化成 `Var`），代价直接落在命中测试上。
    private bool $disabled = false;

    /**
     * 禁用时整棵子树的不透明度。0.38 才落进 Windows 原生禁用控件的观感区间（0.5 仍像可点）。
     */
    public const DISABLED_ALPHA = 0.38;

    // 层缓存：缓存 `paint()` 的**命令序列**，画自身局部坐标 ⇒ 与位置无关 ⇒ 滚动命中。
    // ★ 失效判据**不含** compose 偏移 / 不透明度 / 滚动偏移：含了就等于"一动就失效"，
    //   比不缓存还慢。

    protected bool $repaintBoundary = false;

    protected ?LayerCache $layer = null;

    /**
     * 就地改合成参数（不重建、不重排、**不置布局脏**）。
     *
     * ★ 不置布局脏是整条路的重点：置了就等于每帧全树重排。
     * ★ `opacity >= 1` 且 `dx == dy == 0` ⇒ `paintComposed()` 走**零开销快路**。
     * ★★ 值真变了必须让**外层**重绘边界重录：`pushLayer(alpha)` 是录进 `DisplayList`
     *   的一条命令，而缓存判据是 `layoutEpoch + 尺寸` —— 两者都不变 ⇒ 命中缓存、重放
     *   旧 alpha ⇒ **画面一动不动**（值在滑、屏上是死的）。
     *
     * @api
     */
    public function setCompose(float $opacity, float $dx = 0.0, float $dy = 0.0): void
    {
        $this->applyCompose($opacity, $dx, $dy);
    }

    public function composeOpacity(): float
    {
        return $this->composeOpacity;
    }

    /**
     * 合成槽的**唯一落点**（`setMods()` 与 `setCompose()` 都走这里）。
     * ★ 值没变就**什么都不做**：否则动画收口后静止画面每帧也重录外层缓存（空闲烧 CPU）。
     */
    private function applyCompose(float $opacity, float $dx, float $dy): void
    {
        if ($this->composeOpacity === $opacity && $this->composeDx === $dx && $this->composeDy === $dy) {
            return;
        }

        $this->composeOpacity = $opacity;
        $this->composeDx = $dx;
        $this->composeDy = $dy;

        $this->invalidateEnclosingLayer();

        // ★★ 还要标"下一帧必须画"：`invalidateEnclosingLayer()` 只让边界重录命令流，
        //   不负责把新命令流**上屏**。漏了 ⇒ 现象同样是"值在滑、屏上是死的"。
        $this->pipeline()->bumpPaintEpoch();
    }

    /**
     * 让**最近的外层**重绘边界丢掉缓存（`setCompose()` 的正确性前提）。
     *
     * ★ 必须是外层：本盒若是边界，`pushLayer(alpha)` 在 `paintOrReplay()` **之外**现算，
     *   自己的缓存不含它。★ 没有外层边界 ⇒ 每帧都真画 ⇒ 无事可做。
     * ★★ **只能走公开方法**：跨实例读 `$p->repaintBoundary` / `$p->layer` 在 AOT 下
     *   读不到父类 private ⇒ 失效逻辑**静默变成空操作**。
     */
    private function invalidateEnclosingLayer(): void
    {
        for ($p = $this->parent(); $p !== null; $p = $p->parent()) {
            if ($p instanceof self && $p->isRepaintBoundary()) {
                $p->invalidateLayerCache();

                return;
            }
        }
    }

    /**
     * 丢本盒的变换声明（`setMods()` 的落点）。
     * ★ 只在**声明真变了**时失效外层缓存，判据是**结构相等**：矩阵每次是新实例，按引用
     *   比会让动画期间每帧重录整棵子树。
     */
    private function applyTransform(?Matrix2D $matrix, bool $centered): void
    {
        $same = false;

        $old = $this->composeTransform;
        if ($old === null && $matrix === null) {
            $same = true;
        } elseif ($old !== null && $matrix !== null) {
            $same = $old->equals($matrix) && $this->composeTransformCentered === $centered;
        }

        $this->composeTransform = $matrix;
        $this->composeTransformCentered = $centered;

        if (!$same) {
            $this->invalidateEnclosingLayer();
        }
    }

    /**
     * 当前生效的矩阵（绕中心的那份在这里现算）。
     *
     * ★ `T(c) · M · T(-c)`：以盒中心为原点做变换；尺寸为 `null`（布局前）⇒ 退化为原点变换。
     * ★ 命中测试必须用**同一份**结果（否则"画在 A、点得中 B"）。
     */
    public function effectiveTransform(): ?Matrix2D
    {
        $m = $this->composeTransform;
        if ($m === null) {
            return null;
        }

        if (!$this->composeTransformCentered) {
            return $m;
        }

        $size = $this->size;
        if ($size === null) {
            return $m;
        }

        $cx = $size->width / 2.0;
        $cy = $size->height / 2.0;

        // ★ 读法：`a->then(b)` = 先 a 再 b，即"移到原点 → 施加 M → 移回中心"。
        //   顺序写反的后果不是崩溃：中心会**跟着跑**，看起来像"对齐算错了"。
        return Matrix2D::translation(-$cx, -$cy)
            ->then($m)
            ->then(Matrix2D::translation($cx, $cy));
    }

    /** 有没有声明变换（`paintComposed()` / `hitTest()` 的快路判断） */
    public function hasComposeTransform(): bool
    {
        return $this->composeTransform !== null;
    }

    /**
     * 丢掉本盒的层缓存（下一次绘制必然重录）。
     *
     * @internal 只给 `invalidateEnclosingLayer()` 用；公开是因为调用方拿到的是**另一个
     *   实例**，而 `private` 在 AOT 下跨实例不可见。
     */
    public function invalidateLayerCache(): void
    {
        // ★ 不用 `?->`：AOT 下"可空链"读**本类字段**会退化成按运行时类名查表
        //   （`Undefined property`，实测刷满 stdout 且失效）⇒ 一律拆成 `if`。
        $layer = $this->layer;
        if ($layer !== null) {
            $layer->invalidate();
        }
    }

    /**
     * 让**最近的重绘边界**（含自己）丢掉缓存，再标"下一帧必须画"。
     *
     * ★★ 从 `$this` 开始，与 `invalidateEnclosingLayer()`（从父开始）**故意不同**：
     *   hover 叠色 / 滚动条 thumb 的 alpha 是**录进命令流**的 ⇒ 自己若是边界也脏。
     */
    #[\Override]
    protected function markPaintChanged(): void
    {
        for ($p = $this; $p !== null; $p = $p->parent()) {
            if ($p instanceof self && $p->isRepaintBoundary()) {
                $p->invalidateLayerCache();

                break;
            }
        }

        $this->pipeline()->bumpPaintEpoch();
    }

    /** @return array{0: float, 1: float} `[dx, dy]` */
    public function composeOffset(): array
    {
        return [$this->composeDx, $this->composeDy];
    }

    /**
     * 画自己（**含合成参数**）—— 父容器与 `Frame` 一律走这个入口，不走 `paint()`。
     *
     * ★ 平移用坐标加法，`opacity < 1` 才开层：不透明度是逐像素乘法，必须在子树画完
     *   之后才能乘。★ 默认参数下（`1.0 / 0 / 0`）**一条命令都不多**。
     *
     * @api
     */
    final public function paintComposed(Canvas $canvas): void
    {
        $opacity = $this->composeOpacity;
        $dx = $this->composeDx;
        $dy = $this->composeDy;
        $matrix = $this->effectiveTransform();

        if ($matrix === null && $dx === 0.0 && $dy === 0.0 && $opacity >= 1.0) {
            $this->paintOrReplay($canvas);   // 零开销快路：绝大多数盒一辈子走这里

            return;
        }

        $canvas->save();

        // ★ 顺序：先变换、再平移。`Canvas::transform()` 是 `CTM · M`（后调用的更内层）
        //   ⇒ 与 `hitTest()` 的反变换严格互逆。写反了"画在 A、点得中 B"。
        if ($matrix !== null) {
            $canvas->transform($matrix);
        }

        if ($dx !== 0.0 || $dy !== 0.0) {
            $canvas->translate($dx, $dy);
        }

        if ($opacity < 1.0) {
            $canvas->pushLayer(max(0.0, $opacity));
        }

        $this->paintOrReplay($canvas);

        if ($opacity < 1.0) {
            $canvas->popLayer();
        }

        $canvas->restore();
    }

    /**
     * 画这一个盒，或从层缓存重放（**缓存的唯一出口**）。
     * 未命中 ⇒ 录一遍 + 立刻重放出画 ⇒ 开边界**不会**让首帧变黑。
     */
    private function paintOrReplay(Canvas $canvas): void
    {
        if (!$this->repaintBoundary) {
            $this->paint($canvas);

            return;
        }

        $layer = $this->layer;
        if ($layer !== null) {
            $s = $this->size;
            $w = $s === null ? -1.0 : $s->width;
            $h = $s === null ? -1.0 : $s->height;

            $epoch = $this->pipeline()->layoutEpoch();
            $rec = $layer->beginRecord($canvas, $epoch, $w, $h);
            if ($rec === null) {   // 命中：整棵子树一次 paint() 都不跑
                $this->pipeline()->bumpReplay();

                return;
            }
            $this->pipeline()->bumpPaint();
            $this->paint($rec);
            $layer->endRecord($canvas, $epoch, $w, $h);

            return;
        }

        $this->paint($canvas);
    }

    /**
     * 声明本盒是重绘边界（`Modifier::repaintBoundary()` → `setMods()` → 这里）。
     *
     * ★★ `on` 没变就**什么都不做**：无条件丢缓存会让命中率恒为 0 —— 边界开了等于没开，
     *   且不报错。★ 只给**粗粒度**子树开（每个文本盒一层比直接画还慢）。
     */
    public function setRepaintBoundary(bool $on): void
    {
        if ($this->repaintBoundary === $on) {
            return;
        }
        $this->repaintBoundary = $on;
        $this->layer = $on ? new LayerCache() : null;
    }

    public function isRepaintBoundary(): bool
    {
        return $this->repaintBoundary;
    }

    /**
     * 命中测试：**从本盒出发**深度优先，返回最深的命中盒。
     * `$x` / `$y` 是本盒的**局部**坐标（左上角为原点，逻辑单位）。
     *
     * ★ 三条规则：越界 ⇒ `null`；子节点**逆序**问（后画的在上面）；都不中 ⇒ **返回自己**。
     * ★ **禁用返回自己，不是 `null`**：返回 `null` 会让父容器继续问下一个兄弟 ⇒ 事件落到
     *   被盖住的那层 = **穿透**；返回自己则 `InputRouter` 见 `isDisabled()` 直接吞掉。
     * ★ **`Offstage` 无需特判**：它跳过 layout ⇒ `size` 为 null ⇒ 这里直接 `null`。
     *
     * @api
     */
    public function hitTest(float $x, float $y): ?RenderBox
    {
        $size = $this->size;

        if ($size === null) {
            return null;
        }

        // ★★ **反变换必须在边界检查之前**：旋转后的可见角落已伸出 `[0,w]×[0,h]`，
        //   先判边界会把"点在可见角上"当成盒外 ⇒ 画得出来、点不动。
        $matrix = $this->effectiveTransform();
        if ($matrix !== null) {
            $inv = $matrix->inverted();
            if ($inv === null) {
                return null;   // 退化：压成线/点 ⇒ 点不中（不是"点得中但位置错"）
            }

            // ★ 两个分量都用**原值**算：先写 `$x` 再拿新 `$x` 算 `$y` 会把 x 的变换混进 y。
            $px = $inv->applyX($x, $y);
            $py = $inv->applyY($x, $y);
            $x = $px;
            $y = $py;
        }

        if ($x < 0.0 || $y < 0.0 || $x >= $size->width || $y >= $size->height) {
            return null;
        }

        // ★ 排在 size 检查之后：否则"盒子还没布局"与"被禁用"会混成同一个结果。
        if ($this->disabled) {
            return $this;
        }

        /** @var list<RenderObject> $children */
        $children = [];
        $this->visitChildren(function (RenderObject $child) use (&$children): void {
            $children[] = $child;
        });

        // ★ 子盒 offset 住内容盒坐标 ⇒ 先减掉自己的内容原点，漏了 ⇒ 有 padding 的控件
        //   整体偏一个 padding 命中，而画面是对的。
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
