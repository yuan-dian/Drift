<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Layout\LayoutSelfCheck;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Element;
use Drift\Widget\State;
use Drift\Widget\StatefulElement;
use Throwable;

/**
 * 一帧：flush 脏 Element → layout → paint → present（§5.2）。
 *
 * ★ **为什么这类东西必须住 `Drift\App`**：它同时要碰 `Element`（Widget）与
 *   `CommandRouter`（Commands），而 §4.3 禁 `Widget → Commands`。放进 Widget 层
 *   就得为它再开一个协议例外 —— 而例外是**按文件**登记的，多一个文件多一条缝。
 *
 * ★ 它**不拥有循环**（§5.2 裁决 1）：等待由 `Platform::waitEvents()` 给，编排由 `EventLoop` 做。
 *   本类只是"被告知该跑一帧时"把三段按顺序做一遍 —— 于是测试可以**手动**驱动
 *   任意帧序列，gif/golden 也能喂固定时间戳（G3 的物质基础）。
 *
 * @api platform
 */
final class Frame
{
    /**
     * 上一帧的约束（`05` §5.4 第三步的判据之一）。
     *
     * ★ **四个 float + 一个 bool，而不是一个 `?BoxConstraints`**：可选对象字段在
     *   AOT 下退化成 `php::Var`（`08` 的类型退化表）⇒ 比较与取值都变慢且类型
     *   不确定。这里只做相等判断，摊平存储没有代价。
     */
    /**
     * 上一帧 settle 时看到的**结构版本号**（`Element::structureVersion()`）。
     *
     * ★ 为什么 settle 要单独记结构（而盒换代复用 `$lastBoxEpoch`）：节点的
     *   增删/重排会让**父的 `adoptBoxes()`** 变成必需，这件事不被盒换代覆盖
     *   （重排复用子、`mount()`/`unmount()` 之外不换盒）。
     */
    /**
     * 上一帧 settle 时看到的**标脏序号**（`State::markedCount()`）。
     *
     * ★ 用只增不减的序号而不是 `pendingDirty()`：后者是全局的且**会漂移**
     *   （已卸载 / 不在本树的 State 脏着不被消费 ⇒ 永远 >0），拿它判"要不要
     *   settle"会退化成永不跳过。看"有没有**新**标脏"则不受存量影响。
     */
    private int $lastMarkedCount = -1;

    private int $lastSettleStructure = -1;

    private bool $laidOut = false;

    /** @internal 量具：settle 段**真跑**的帧数（对 `$settleSkipCount`） */
    private static int $settleRunCount = 0;

    /** @internal 量具：settle 段**整段跳过**的帧数 */
    private static int $settleSkipCount = 0;

    private float $lastMinWidth = 0.0;

    private float $lastMaxWidth = 0.0;

    private float $lastMinHeight = 0.0;

    private float $lastMaxHeight = 0.0;

    /**
     * 上一帧排版时看到的**盒换代序号**（`State::boxEpoch()`）。
     *
     * ★★ 存在的理由是一条真实缺陷（2026-10-24 定位）：`$didRebuild` 来自
     *   `settle()` 的**帧内**重建差，而 `Element::update()` / `applyContext()`
     *   这些**帧外**路径会换盒却不在 settle 里计数 ⇒ 判成"本帧没变" ⇒ 跳过
     *   layout ⇒ 新盒 `size()` 恒 `null` ⇒ **整棵子树命中测试落空**，而 overlay
     *   计数、焦点、声明看起来全对。
     *
     * ★ 判据从"本帧重建过吗"改成"**盒换过代吗**"：`Element::syncBox()` 是唯一的
     *   盒创建点，任何来路的换盒都会让序号变 ⇒ 这条缝在结构上不可能再漏。
     *   而"没重建 ⇒ 序号不变 ⇒ 照旧跳过 layout"的原优化（`05` §5.4 第三步）保留。
     */
    private int $lastBoxEpoch = -1;

    /**
     * 上一帧 paint 时看到的**重绘序号**（`RenderObject::paintEpoch()`）。
     *
     * ★★ 初值 `-1` 同时充当"还没画过"的标记：首帧 `paintEpoch()` 至少是 0 ⇒
     *   `0 !== -1` ⇒ 一定画。**不另设 `hasPainted` 字段** —— 那份状态与 layout
     *   段的 `$laidOut` 会重复表达同一件事（首帧必排 ⇒ 必画），两份记录迟早漂。
     */
    private int $lastPaintEpoch = -1;

    /** @internal 量具：paint 段**真跑**的帧数（对 `$paintSkipCount`） */
    private static int $paintRunCount = 0;

    /** @internal 量具：paint 段**整段跳过**的帧数 */
    private static int $paintSkipCount = 0;

    public function __construct(
        private readonly RenderSurface $surface,
    ) {
    }

    /** 约束与上一帧是否不同（没排过 ⇒ 一定不同 ⇒ 必须排） */
    private function constraintsChanged(BoxConstraints $constraints): bool
    {
        return !$this->laidOut
            || $this->lastMinWidth !== $constraints->minWidth
            || $this->lastMaxWidth !== $constraints->maxWidth
            || $this->lastMinHeight !== $constraints->minHeight
            || $this->lastMaxHeight !== $constraints->maxHeight;
    }

    /**
     * 本帧 settle 是否**有事可做** —— 无事则整段跳过（含浮层那棵第二根树）。
     *
     * ★★ 三条信号覆盖 `adoptBoxes()` 的**全部**必要性（漏一条就是静默的：
     *   祖先握着旧盒、画面不更新、不报错）：
     *   ① `markedCount` 变 —— 有新 `setState()` 等着被消费；
     *   ② `boxEpoch` 变 —— 换过盒（`Element::syncBox()` 是唯一创建点）；
     *   ③ `structureVersion` 变 —— 节点增删或**重排**（`updateChildren()` 里
     *      `compositionChanged()` 单独判的那一次，复用子不 mount/unmount）。
     *   三者都没动 ⇒ 树与上一帧逐位相同 ⇒ 既不重建也不挂接。
     *
     * ★★ 变异自检：拆 ① ⇒ 大面积红（setState 全不生效）；拆 ② ⇒ 帧外直调
     *   `Element::update()` 那条红（它换盒却**不标脏**，是 ② 的专属路径）；
     *   **拆 ③ ⇒ 全绿** —— 已知路径里结构变化的源头都是某次 `setState`
     *   （`updateChildren()` 由 rebuild 调）⇒ 被 ① 挡住。③ 因此是**防御性
     *   冗余**，不是被证明的必需 —— 留着的理由只是"万一将来出现只变结构的
     *   路径，漏了就是静默的"，而成本是每帧一次整数比较。别误读成有测试守着。
     *
     * ★ 收益：干净帧与滚动帧的 settle 从 O(N) 全遍（实测 0.9–1.5 µs/节点，
     *   475 节点 0.716ms、1503 节点 1.345ms）降到 O(1)。
     *
     * ★ 盒换代**复用** `$lastBoxEpoch`（不另记一份）：settle 与 layout 对"这帧
     *   变没变"必须是同一个看法，两份记录迟早漂。
     */
    private function needsSettle(): bool
    {
        return State::markedCount() !== $this->lastMarkedCount
            || State::boxEpoch() !== $this->lastBoxEpoch
            || Element::structureVersion() !== $this->lastSettleStructure;
    }

    /** @internal 量具：settle 段真跑的累计帧数 */
    public static function settleRunCount(): int
    {
        return self::$settleRunCount;
    }

    /** @internal 量具：settle 段整段跳过的累计帧数 */
    public static function settleSkipCount(): int
    {
        return self::$settleSkipCount;
    }

    /** @internal 量具：paint 段真跑的累计帧数 */
    public static function paintRunCount(): int
    {
        return self::$paintRunCount;
    }

    /** @internal 量具：paint 段整段跳过的累计帧数 */
    public static function paintSkipCount(): int
    {
        return self::$paintSkipCount;
    }

    /**
     * 跑一帧。
     *
     * @param BoxConstraints $constraints 表面给出的约束（通常 = 表面尺寸的紧约束）
     * @param float $timestampMs 帧时间戳，由 `FrameClock` 注入，**不读环境时钟**（§5.2 裁决 2）
     * @param list<\Drift\App\OverlayEntry> $overlays 已由 `Application::placeOverlays()` 算好 x/y
     * @param bool $paintRequested 应用级叠加（焦点环 / 面板落后）要求本帧画 —— 它们
     *        不经过树，`Frame` 看不见，只能由 `Application` 说
     *
     * @return bool 本帧**真的 paint + present 了吗**（`false` = 整段跳过，或 paint 抛了异常）。
     *         调用方用它决定"应用级叠加算不算已经上屏"（`Application::$paintedFocus`）。
     *         ★ 返回 `false` 时屏幕上是**上一帧**的内容 —— 这是设计意图，不是失败。
     *
     * ★ **浮层的布局段不在这里**：settle 与 paint 两段在本类，**layout 段在
     *   `Application::placeOverlays()`** —— 因为浮层是"自尺寸 + 按锚点钉原点 +
     *   夹进客户区"，三件事都要**锚点**与**客户区尺寸**，而锚点住 `FocusManager`。
     *   Frame 只拿到算好的 `$e->x/$e->y`，画的时候 `translate` 过去。
     *
     * @api
     */
    public function run(
        Element $root,
        BoxConstraints $constraints,
        float $timestampMs,
        array $overlays = [],
        ?\Closure $afterPaint = null,
        bool $paintRequested = false,
    ): bool {
        // ① 帧末统一重建（§5.2 裁决 3）：派发中途的 setState 只标脏
        // ★ O-12 A′ 帧循环异常策略 S3 的"无边界"半边：异常从子树一路冒到这里
        //   （沿途没有 ErrorBoundary 捕获）⇒ Fatal 序列。**不吞**——吞掉就是
        //   窗口冻结在上一帧且不报错（G2 明令禁止的半静默）。
        // ★ 分段计时（SurfaceProfile，默认关）：M4 拆「其余(PHP)」黑箱用（`26` §7）。
        $prof = SurfaceProfile::$on;
        $t = $prof ? \hrtime(true) : 0;
        $didRebuild = false;
        try {
            if ($this->needsSettle()) {
                self::$settleRunCount++;
                $didRebuild = $root->settle();
                foreach ($overlays as $e) {
                    // 浮层各自的帧末重建（W3）；任一浮层重建 ⇒ 本帧不能跳过 layout
                    $didRebuild = ($e->element?->settle() ?? false) || $didRebuild;
                }
            } else {
                // 树与上一帧逐位相同 ⇒ 既不重建也不挂接（判据见 `needsSettle()`）
                self::$settleSkipCount++;
            }
        } catch (Throwable $e) {
            Errors::report(sprintf(
                '帧循环：未捕获异常冒到帧末（沿途无错误边界）—— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }
        // ★ 结构快照必须在 settle **之后**取：本帧的增删/重排要进下一帧的判据
        $this->lastSettleStructure = Element::structureVersion();
        $this->lastMarkedCount = State::markedCount();
        if ($prof) {
            SurfaceProfile::add('settle', \hrtime(true) - $t);
        }

        // ② layout
        // ★ 必须用 `effectiveBox()` 而不是 `renderObject()`：应用根常是
        //   `StatelessWidget`（§A.2 的 `UsersRoot` 就是这个形态），它自己没有盒、
        //   只有它底下的 `Column` 有。用 `renderObject()` ⇒ 拿 null ⇒ 直接返回
        //   ⇒ **整屏空白且不报错**（C6 只查上界，不画任何东西也不算违反）。
        //   这正是 G2 要消灭的静默形态。
        $box = $root->effectiveBox();
        if ($box === null) {
            return false; // 整棵子树没有任何可绘制内容（例如空页）⇒ 这一帧无事可做
        }

        $t = $prof ? \hrtime(true) : 0;
        // ★ **纯动画帧跳过 layout**（arch-v2 `05` §5.4 第三步，W4-L3-5）：
        //   "没 rebuild + 约束没变 ⇒ 盒的 size 与子 offset 与上一帧逐位相同"
        //   —— 前提是 `layout()` 是纯函数，这一点由 `LayoutSelfCheck`（W0）在
        //   真机上守（`04` §5.2：先证明纯，才有资格说不缓存）。
        //   ★ 自校验必须先于本步上线：没有它，跳过 layout 会把"布局不纯"的
        //     后果从**漂移**放大成**完全不更新**（`05` §13 的推翻条件）。
        //
        //   ★★ **第三条判据 `$boxEpoch`**（2026-10-24）：`$didRebuild` 只覆盖
        //     "settle 期间重建过"。帧**外**换盒的路径（直调 `Element::update()`、
        //     `applyContext()` 切主题、`OffstageElement` 放回停放子树）换盒却不
        //     进 settle 计数 ⇒ 只靠前两条会判成"没变"而跳过 layout ⇒ 新盒没有
        //     `size` ⇒ 命中测试全落空（静默）。序号来自唯一盒创建点
        //     `Element::syncBox()`，所以这条判据**不可能漏**。
        //   ★ 读序号的位置必须在 settle **之后**（这样本帧重建造成的换盒也算进来）。
        $boxEpoch = State::boxEpoch();
        $layoutRan = false;
        if ($didRebuild || $boxEpoch !== $this->lastBoxEpoch || $this->constraintsChanged($constraints)) {
            $layoutRan = true;
            $this->laidOut = true;
            $this->lastBoxEpoch = $boxEpoch;
            $this->lastMinWidth = $constraints->minWidth;
            $this->lastMaxWidth = $constraints->maxWidth;
            $this->lastMinHeight = $constraints->minHeight;
            $this->lastMaxHeight = $constraints->maxHeight;

            try {
                $box->layout($constraints);
            } catch (Throwable $e) {
                Errors::report(sprintf(
                    '帧循环：layout 阶段未捕获异常（沿途无错误边界）—— %s（%s:%d）',
                    $e->getMessage(),
                    basename($e->getFile()),
                    $e->getLine(),
                ));
            }
        }
        if ($prof) {
            SurfaceProfile::add('layout', \hrtime(true) - $t);
        }

        // ②b 布局自校验（`04` §5；默认关，`build/layout.check` 存在时开）
        // ★ 必须放在 layout **之后**：它做的是"用同一约束再排一次"，
        //   排在前面就等于替真实帧排了两遍。
        // ★ **只覆盖主树**：浮层的 layout 在 `Application::placeOverlays()`
        //   （要锚点与客户区尺寸），不在本类 ⇒ 自校验看不见它。
        LayoutSelfCheck::maybeCheck($box, $constraints);

        // ③ paint → ④ present（**有门控**，见 `$paintEpoch` 的注）
        // ★★ 判据四条，缺一条就是一类静默（全部有真实通道，不是假想）：
        //   ① 本帧 **layout 跑过** —— 几何变了一定要重画；同时也覆盖首帧
        //      （`!$laidOut` ⇒ `constraintsChanged()` 为真 ⇒ layout 必跑）。
        //   ② **`paintEpoch` 变了** —— 一批"不重建不重排"的通道：`setCompose()`
        //      （动画）、`setOffset()`（滚动 / 对齐补差）、`setBase()`（浮层落点）、
        //      `setBarOpacity()`（滚动条淡出）、`tickAnimations()`（hover 过渡）。
        //      这五条**都不经过 settle / layout** ⇒ 只看 ① 会全部漏掉。
        //   ③ **应用级叠加要求画**（`$paintRequested`）—— 焦点环是 `afterPaint`
        //      回调，不经过任何树，`Application` 用 `focusRingTarget()` 比对得出；
        //      浮层"锚点换了但还没同步"（`panelsLagBehind()`）也走这一条。
        //   ④ 序号**初值 `-1`** 兜首帧（见 `$lastPaintEpoch` 的注）。
        // ★ 顺序要紧：`paintEpoch` 必须在 layout **之后**读（布局期 `setOffset()` 会
        //   推它；在之前读就等于把本帧自己的变化算成"下一帧的"）。
        $paintEpoch = RenderObject::paintEpoch();
        if (!$layoutRan && $paintEpoch === $this->lastPaintEpoch && !$paintRequested) {
            self::$paintSkipCount++;

            return false;
        }

        // ★ 本段**含** beginFrame 与 endFrame（各自另有计时）⇒ 报告里要扣掉，
        //   剩下的才是「画」自己的（DisplayList 记录 + Skia 光栅）。
        $t = $prof ? \hrtime(true) : 0;
        $painted = false;
        try {
            $canvas = $this->surface->beginFrame($timestampMs);
            $box->paintComposed($canvas);

            // 浮层画在主树**之后**，按栈顺序逐层 —— 绘制顺序即层叠，不引入 z-index。
            // ★ scrim 不在这里画：模态的遮罩由 `ModalHost`（RenderModalHost）自绘，
            //   再画一次会双绘（`01` §5 的理想形态与本仓现状的差异）。
            foreach ($overlays as $e) {
                $popupBox = $e->element?->effectiveBox();
                if ($popupBox !== null && $popupBox->hasSize()) {
                    $canvas->save();
                    $canvas->translate($e->x, $e->y);
                    $popupBox->paintComposed($canvas);
                    $canvas->restore();
                }
            }

            // ★ `afterPaint`：树与浮层都画完、`endFrame` 之前 —— 给"应用级叠加"
            //   一个口子（焦点环就是它：不侵入任何控件，一次覆盖全仓可聚焦点）。
            if ($afterPaint !== null) {
                $afterPaint($canvas);
            }

            $this->surface->endFrame();
            $painted = true;
        } catch (Throwable $e) {
            Errors::report(sprintf(
                '帧循环：paint 阶段未捕获异常（沿途无错误边界）—— %s（%s:%d）',
                $e->getMessage(),
                basename($e->getFile()),
                $e->getLine(),
            ));
        }

        // ★★ **失败时不记账**：paint 抛了异常 ⇒ 屏幕上可能只上去了半帧（或者
        //   压根没 `endFrame`）⇒ 序号必须停在本帧的值上 ⇒ 下一帧（或下一次请求）
        //   仍然会画。失败后把序号记成"已画"= 从此静默停在残缺画面上，正是 G2
        //   明令禁止的半静默。
        if ($painted) {
            self::$paintRunCount++;
            $this->lastPaintEpoch = $paintEpoch;
        }

        if ($prof) {
            SurfaceProfile::add('paint', \hrtime(true) - $t);
        }

        return $painted;
    }
}
