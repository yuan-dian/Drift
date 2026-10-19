<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\EdgeInsets;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 弹性盒（§13.1 的 `Column` / `Row` 渲染侧）。
 *
 * 三阶段：
 * 1. **问自然尺寸** —— 非弹性子拿 `0..主轴可用`，弹性子拿**无界主轴**（C4）；
 * 2. **分剩余空间** —— 主轴有界且有弹性子时，弹性子**整组拿走全部剩余**
 *    （可用主轴 − 非弹性子尺寸和 − 间距）按 flex 份额切分重排；弹性子第一遍
 *    量的自然尺寸**不是**要减掉的份额（2026-10-17 修：旧口径把它也减了 ⇒
 *    内容比视口长时拿 `tight(0)` 整块不可见、内容比剩余短时白留死空间）；
 * 3. **定位** —— 主轴顺序排、交叉轴按 `FlexAlign`（C1：父定位子）。
 *
 * ★ **C4 的"无界高"不是实现细节而是契约**：量自然尺寸那遍给弹性子无界主轴，
 *   否则弹性子按"现在剩多少"量，flex 分配就建立在错误分母上（图形比例会随
 *   兄弟数量漂移）。`0` 是合法主轴尺寸，所以不能用"0 表示无界"—— 那是 GUI 仓
 *   三次踩坑的那一条（C2）。
 *
 * ★ **不是 `final`**：`RenderAppBar`（§12.1 的 chrome 顶条）继承它 —— 顶条
 *   就是"带底色的弹性行"，只覆写 `paint()`，不为一个新名字复制一套 flex。
 *
 * @api
 */
class RenderFlex extends RenderBox
{
    /**
     * ★ **resize 预览期**（`Application::repaintForResize` 的 try/finally 置位）：
     *   用户拖窗口边缘时，中间尺寸是**瞬时态**——内容暂时放不下是**合法状态**，
     *   不是编程错误。此时 `Overflow::Report` 降级为裁剪（不弹 Fatal、不中断拖动）；
     *   松手后的正式帧照旧响亮失败。
     *
     * ★ 为什么开关放在 Layout 层而不是由 Application 传下来：布局不依赖 App（§4.3），
     *   而 `handleOverflow` 在最内层、拿不到调用方上下文 ⇒ 静态开关是唯一不外泄
     *   依赖的形态（同 `Errors` 的进程级语义）。
     *
     * @internal 只由 `Application::repaintForResize()` 在 try/finally 里翻转
     */
    public static bool $previewTolerant = false;

    /** @var list<RenderBox> */
    private array $kids = [];

    /**
     * @param bool $vertical true = `Column`（主轴竖）；false = `Row`（主轴横）
     * @param float $spacing 主轴相邻子的间距（不含 padding）
     * @param FlexAlign $crossAlign 交叉轴对齐
     * @param Overflow $overflow **必填，无默认值** —— C5 原话："会超的容器必须
     *        显式声明"。写成构造参数而不是字段默认，是为了让漏声明编译不过（G1）。
     */
    public function __construct(
        // ★ **AOT：会被继承的类（这里是 `RenderAppBar`），字段不能是 private** ——
        //   tpc 按运行时类型查属性表，父类 `private` 不进子类表 ⇒ 子类实例上访问会
        //   动态创建属性（`Deprecated: Creation of dynamic property RenderAppBar::$vertical`，
        //   且值为 NULL ⇒ 布局拿到错值）。与 `RenderBox::$size` 同一条纪律。
        //   2026-10-16 真机跑 `users.exe` 时当场暴露。
        protected readonly bool $vertical,
        protected readonly float $spacing = 0.0,
        protected readonly EdgeInsets $padding = new EdgeInsets(),
        protected readonly FlexAlign $crossAlign = FlexAlign::Start,
        protected readonly Overflow $overflow = Overflow::Report,
    ) {
    }

    public function addChild(RenderBox $child): void
    {
        $this->kids[] = $child;
        $this->adoptChild($child);
    }

    /**
     * C5 契约查询：返回本盒**实际生效**的溢出策略。
     *
     * ★ 必须覆写：`RenderBox::overflow()` 的默认值是 Clip，而本盒真正用的策略
     *   是构造参数 `$this->overflow`（默认 Report）—— 不覆写 ⇒ "查询说 Clip、
     *   行为是 Report"，W4（会超的容器声明溢出策略）的判定面整体说谎。
     *   2026-10-16 由 M2 conformance 夹具抓到（`tools/M2Conformance.php` W4）。
     */
    public function overflow(): Overflow
    {
        return $this->overflow;
    }

    /** @internal 重建前清空 —— 否则同一次 rebuild 会把子盒追加两遍 */
    public function clearChildren(): void
    {
        $this->kids = [];
    }

    /** @return list<RenderBox> */
    public function children(): array
    {
        return $this->kids;
    }

    public function visitChildren(callable $visitor): void
    {
        foreach ($this->kids as $kid) {
            $visitor($kid);
        }
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $inner = $constraints->deflate($this->padding);

        $vertical = $this->vertical;
        $maxMain = $vertical ? $inner->maxHeight : $inner->maxWidth;
        $maxCross = $vertical ? $inner->maxWidth : $inner->maxHeight;
        $mainBounded = $vertical ? $inner->hasBoundedHeight() : $inner->hasBoundedWidth();

        $hasFlex = false;
        foreach ($this->kids as $kid) {
            if ($kid->flex() > 0) {
                $hasFlex = true;
                break;
            }
        }

        // ── 第一遍：自然尺寸 ────────────────────────────────────────
        /** @var list<float> $mains 每个子的主轴尺寸 */
        $mains = [];
        $totalFlex = 0;

        foreach ($this->kids as $index => $kid) {
            $flex = $kid->flex();
            $totalFlex += $flex;

            // C4：有弹性子 ⇒ 弹性子拿无界主轴（分母是自然尺寸，不是剩余空间）。
            // 主轴本身就无界时同样给无界 ⇒ 无空间可分，flex 退化成自然尺寸。
            $childMainMax = ($hasFlex && $flex > 0) || !$mainBounded ? INF : max(0.0, $maxMain);
            $kid->layout($this->childConstraints($kid, $childMainMax, $maxCross));
            $mains[$index] = $vertical ? $kid->size()->height : $kid->size()->width;
        }

        $gap = $this->spacing * max(0, count($this->kids) - 1);

        // ── 第二遍：flex 有界时分剩余空间 ────────────────────────────
        // ★ **分母只含非弹性子的自然尺寸**（"分剩余空间"的标准语义）：弹性子整组
        //   拿走全部剩余，第一遍量的自然尺寸不是要减掉的份额。旧口径
        //   `array_sum($mains)` 把弹性子自然尺寸也减了 ⇒ 双重扣减：内容比视口长时
        //   `Expanded` 拿 `tight(0)`（整块不可见，2026-10-17 users 页实证）、内容比
        //   剩余短时容器白留没人要的死空间。非弹性子溢出时 `$free < 0` ⇒ 弹性子拿 0、
        //   C5 溢出处理照旧 ⇒ 既有溢出行为不变。
        if ($hasFlex && $mainBounded && $totalFlex > 0) {
            $fixedMain = 0.0;
            foreach ($this->kids as $index => $kid) {
                if ($kid->flex() === 0) {
                    $fixedMain += $mains[$index];
                }
            }
            $free = $maxMain - $fixedMain - $gap;
            // 溢出 ⇒ 无空间可分。此时按 C5 的策略处理（下面统一判），flex 项先拿 0。
            $space = max(0.0, $free);

            foreach ($this->kids as $index => $kid) {
                $flex = $kid->flex();
                if ($flex === 0) {
                    continue;
                }
                $share = $space * $flex / $totalFlex;
                $tight = $this->tightMain($inner, $share);
                $kid->layout($tight);
                $mains[$index] = $vertical ? $kid->size()->height : $kid->size()->width;
            }
        }

        $mainSize = array_sum($mains) + $gap;

        // ── C5：溢出策略是布局期行为，不是绘制期补丁 ────────────────
        // Report / 未实现的策略都在 handleOverflow 里中止了；能走到这里的只有
        // Clip ⇒ 盒子自己夹到可用主轴，子项仍按自然位置排（超出部分交给裁剪）。
        $reported = $mainSize;
        if ($mainBounded && $mainSize > $maxMain) {
            $this->handleOverflow($mainSize, $maxMain);
            $reported = $maxMain;
        }

        $crossSize = 0.0;
        foreach ($this->kids as $kid) {
            $cross = $vertical ? $kid->size()->width : $kid->size()->height;
            $crossSize = max($crossSize, $cross);
        }

        // ★ 交叉轴定位按**盒自身的交叉尺寸**居中，不是按约束上限：
        //   上限宽松时（`AppBar` 从纵向 `Column` 拿到 0..320 的高）拿上限居中会把子项
        //   推到盒**外面** —— 标题落到 y=143 而盒高仅 44.8（2026-10-16 由预览抓出）。
        //   盒尺寸要**按 `RenderBox::layout` 同口径夹一次**：父把交叉轴定死（`tight`）
        //   时下界会顶上来，此时必须按夹后的尺寸居中（否则 `Center` 退回 0）。
        $size = new Size(
            $vertical
                ? min($crossSize + $this->padding->horizontal(), $constraints->maxWidth)
                : $reported + $this->padding->horizontal(),
            $vertical
                ? $reported + $this->padding->vertical()
                : min($crossSize + $this->padding->vertical(), $constraints->maxHeight),
        );
        $clamped = $constraints->constrain($size);
        $crossInner = $vertical
            ? $clamped->width - $this->padding->horizontal()
            : $clamped->height - $this->padding->vertical();
        $this->placeChildren($mains, max(0.0, $crossInner));

        return $size;
    }

    public function intrinsicWidth(float $height): float
    {
        return $this->vertical ? $this->intrinsicCross($height) : $this->intrinsicMain($height);
    }

    public function intrinsicHeight(float $width): float
    {
        return $this->vertical ? $this->intrinsicMain($width) : $this->intrinsicCross($width);
    }

    public function paint(Canvas $canvas): void
    {
        $canvas->save();

        foreach ($this->kids as $kid) {
            $canvas->save();
            $canvas->translate($kid->offsetX(), $kid->offsetY());
            $kid->paint($canvas);
            $canvas->restore();
        }

        $canvas->restore();
    }

    // ── 内部 ────────────────────────────────────────────────────────

    /**
     * 单个子的约束：主轴 `0..$mainMax`（弹性且有界时由第二遍收紧），
     * 交叉轴 `Stretch` ⇒ 紧约束（拉满），否则上限界。
     */
    private function childConstraints(RenderBox $kid, float $mainMax, float $crossMax): BoxConstraints
    {
        $crossBounded = !is_infinite($crossMax);
        $stretch = $this->crossAlign === FlexAlign::Stretch && $crossBounded;

        // ★ **`Stretch` 拉的是交叉轴，不是主轴**（2026-10-17 修正）：
        //   `Column` ⇒ 宽拉满、高自然；`Row` ⇒ 高拉满、宽自然。
        //   ★ 旧实现两个分支把主轴与交叉轴**写反了**（`Row` 给了紧宽、`Column`
        //     给了紧高）—— 与 `placeChildren()` 里 `Stretch => 0.0`（交叉轴偏移 0）
        //     以及 Flutter 的同名属性都相反。
        //   ★ 潜伏原因：`FlexAlign::Stretch` 在生产代码里**零用法**（示例台的三层
        //     卡片外壳是第一个），这条分支从未被执行过 —— 死代码不会报错，
        //     而写反的紧宽会把同级 `Expanded` 挤到 0 宽（真机第一帧 FATAL）。
        if ($this->vertical) {
            return $stretch
                ? new BoxConstraints($crossMax, $crossMax, 0.0, $mainMax)
                : new BoxConstraints(0.0, $crossMax, 0.0, $mainMax);
        }

        return $stretch
            ? new BoxConstraints(0.0, $mainMax, $crossMax, $crossMax)
            : new BoxConstraints(0.0, $mainMax, 0.0, $crossMax);
    }

    /** 主轴紧约束（flex 分配结果） */
    private function tightMain(BoxConstraints $inner, float $main): BoxConstraints
    {
        $share = max(0.0, $main);

        // ★ **AOT 类型推断**：三元的分支里直接放**属性读取**（`$inner->maxWidth`）会被
        //   推成 `php::Variant`，而 `BoxConstraints` 的形参是 `float` ⇒ `C2440`。
        //   先落局部标量再传（`typephp-gui-build` 技能里记的同型坑）。
        //   ★ 这一处由 `apps/users` 的**更大用法面**首次触达：AOT 只编译**可达**代码，
        //     而 `apps/hello`（Column/Row/Text）走不到带 `Stretch` 的这条分支。
        $crossMain = 0.0;
        if ($this->vertical
            && $this->crossAlign === FlexAlign::Stretch
            && $inner->hasBoundedWidth()) {
            $crossMain = $inner->maxWidth;
        }

        // ★ 水平同理：`Row` 的交叉轴是**高** —— 弹性子也要拉满（否则同一行里
        //   非弹性子被拉满、弹性子不被拉满，视觉上高度参差）。
        $crossCross = 0.0;
        if (!$this->vertical
            && $this->crossAlign === FlexAlign::Stretch
            && $inner->hasBoundedHeight()) {
            $crossCross = $inner->maxHeight;
        }

        if ($this->vertical) {
            return new BoxConstraints($crossMain, $inner->maxWidth, $share, $share);
        }

        return new BoxConstraints(
            $share,
            $share,
            $crossCross,
            $inner->maxHeight,
        );
    }

    /** @param list<float> $mains */
    private function placeChildren(array $mains, float $crossMax): void
    {
        // ★ padding **在这里施加**（不是 paint 期的 translate）—— 子盒的
        //   `offsetX()/offsetY()` 必须包含它，否则 `hitTest()` 与
        //   `Application::absoluteOrigin()` 拿到的坐标都比画出来的位置差一个
        //   padding（2026-10-17 实证：paint 画在 (24,24)、hitTest 判定 (0,0)）。
        //   ⇒ 命中区与绘制区错位；IME 候选框坐标 / 菜单锚点同受影响。
        $pen = $this->vertical ? $this->padding->top : $this->padding->left;

        foreach ($this->kids as $index => $kid) {
            $cross = $this->vertical ? $kid->size()->width : $kid->size()->height;
            $offset = match ($this->crossAlign) {
                FlexAlign::Center  => max(0.0, ($crossMax - $cross) / 2.0),
                FlexAlign::End     => max(0.0, $crossMax - $cross),
                FlexAlign::Stretch => 0.0,
                FlexAlign::Start   => 0.0,
            };

            if ($this->vertical) {
                $kid->setOffset($offset + $this->padding->left, $pen);
            } else {
                $kid->setOffset($pen, $offset + $this->padding->top);
            }

            $pen += $mains[$index] + $this->spacing;
        }
    }

    private function handleOverflow(float $mainSize, float $maxMain): void
    {
        match ($this->overflow) {
            Overflow::Clip => null, // 自己夹住（见 performLayout 的 min），子项照自然位置排
            Overflow::Report => self::$previewTolerant
                ? null   // 预览期（拖动中）：瞬时溢出降级为裁剪，不弹 Fatal
                : Errors::report(sprintf(
                '%s 溢出：自然主轴 %.6g 超过可用 %.6g。C5 要求声明策略 —— Report 即响亮失败；'
                . '若要允许裁剪请改用 Clip',
                static::class,
                $mainSize,
                $maxMain,
            )),
            Overflow::Scroll, Overflow::Wrap => Errors::report(sprintf(
                '%s 的溢出策略 %s 尚未实现（M2 只有 Clip / Report）',
                static::class,
                $this->overflow->name,
            )),
        };
    }

    /** 主轴内禀值：所有子同轴相加 + 间距 */
    private function intrinsicMain(float $otherAxis): float
    {
        $total = 0.0;
        foreach ($this->kids as $kid) {
            $total += $this->vertical ? $kid->intrinsicHeight($otherAxis) : $kid->intrinsicWidth($otherAxis);
        }

        return $total + $this->spacing * max(0, count($this->kids) - 1);
    }

    /** 交叉轴内禀值：所有子的最大者 */
    private function intrinsicCross(float $otherAxis): float
    {
        $max = 0.0;
        foreach ($this->kids as $kid) {
            $value = $this->vertical ? $kid->intrinsicWidth($otherAxis) : $kid->intrinsicHeight($otherAxis);
            $max = max($max, $value);
        }

        return $max;
    }
}
