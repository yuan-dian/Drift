<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Core\Pipeline;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Layout\LayoutSelfCheck;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderObject;
use Drift\Rendering\FrameStage;
use Drift\Rendering\RenderSurface;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\ConstraintObserver;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Throwable;

/**
 * 一帧：flush 脏 Element → layout → paint → present。
 * 不拥有循环：等待由 Platform::waitEvents() 给，编排由 EventLoop 做；
 * 本类只是被告知该跑一帧时把三段按顺序做一遍（测试可手动驱动任意帧序列）。
 * @api platform
 */
final class Frame
{
    /**
     * 帧内约束反馈的轮数上限。正常 2 轮（首轮不知道约束 / 约束刚变），
     * 4 轮是防御上限：超了就是 builder 在"按约束返回又改变约束"地打转。
     */
    private const int MAX_CONSTRAINT_ROUNDS = 4;

    /**
     * 上一帧 settle 时看到的标脏序号（管线 markedCount）。
     * 用只增不减的序号而非全局 pendingDirty()：后者会漂移（已卸载的脏 State 永远 >0），
     * 拿它判"要不要 settle"会退化成永不跳过。
     */
    private int $lastMarkedCount = -1;

    /** 上一帧 settle 时看到的结构版本号；节点增删/重排要求父级 adoptBoxes()。 */
    private int $lastSettleStructure = -1;

    private bool $laidOut = false;

    /** 本帧所属的管线（构造参数，见构造器注）；量具与"像素脏了没"都读它 */
    private readonly Pipeline $pipeline;

    /**
     * 上一帧的约束，摊平存四个 float（不包成 ?BoxConstraints）：AOT 下可选对象字段
     * 退化成 Variant，相等判断变慢且类型不确定，这里只做相等判断无需对象。
     */
    private float $lastMinWidth = 0.0;

    private float $lastMaxWidth = 0.0;

    private float $lastMinHeight = 0.0;

    private float $lastMaxHeight = 0.0;

    /**
     * 上一帧排版时看到的盒换代序号（管线 layoutEpoch）。
     * ★ 判据是"盒换过代吗"而非"本帧重建过吗"：Element::syncBox() 是唯一盒创建点，
     *   任何来路的换盒都会让序号变。帧外路径（Element::update()/applyContext()/Offstage
     *   放回）换盒却不进 settle 计数 ⇒ 只靠前两条会判成"没变"而跳过 layout ⇒ 新盒
     *   size 恒 null ⇒ 整棵子树命中测试落空（静默，overlay/焦点/声明都看似正常）。
     */
    private int $lastBoxEpoch = -1;

    /**
     * 上一帧 paint 时看到的重绘序号（管线 paintEpoch）。
     * 初值 -1 同时充当"还没画过"：首帧 paintEpoch() 至少 0 ⇒ 一定画。
     * 不另设 hasPainted：与 $laidOut 重复表达同一件事，两份记录迟早漂。
     */
    private int $lastPaintEpoch = -1;

    /**
     * 上一帧画过的浮层集合签名（OverlayEntry::$id 序列）。
     * ★ 浮层增删不进主树：它在 OverlayHost 的栈里，structureVersion / boxEpoch /
     *   paintEpoch 三条信号一条都不动。少了这一条，"关掉模态后主树没有别的变化"
     *   这一帧会被整段跳过 ⇒ 屏幕上永远残留遮罩（静默：不报错，且被 hover 重绘掩盖，最难查）。
     * 用 $id 而非 spl_object_id()：后者对象释放后会被复用 ⇒ "关一个再开一个"可能拿到
     *   同一 id ⇒ 判成没变 ⇒ 永久漏画一帧。$id 是 OverlayEntry 的单调序号，不复用。
     */
    private ?array $lastOverlayIds = null;

    /** @internal 量具：paint 段真跑 / 整段跳过，值住 Pipeline */

    public function __construct(
        private readonly RenderSurface $surface,
        ?Pipeline $pipeline = null,
    ) {
        // 一帧属于一条管线：本类不是树节点（无 Pipeline::enter() 护着），
        // run() 的 paint 门控读 paintEpoch 时 Pipeline::current() 可能是另一个应用的。
        // 可选参数：测试里 new Frame($surface) 各自独立跑帧，落到当前管线，不必改 smoke。
        $this->pipeline = $pipeline ?? Pipeline::current();
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
     * 本帧 settle 是否有事可做 —— 无事则整段跳过（含浮层那棵第二根树）。
     * 三条信号覆盖 adoptBoxes() 的必要性：
     *   ① markedCount 变 —— 有新 setState() 等着被消费；
     *   ② boxEpoch 变 —— 换过盒（Element::syncBox() 是唯一创建点）；
     *   ③ structureVersion 变 —— 节点增删或重排。
     * 三者都没动 ⇒ 树与上一帧逐位相同 ⇒ 既不重建也不挂接。
     */
    private function needsSettle(): bool
    {
        return $this->pipeline->markedCount() !== $this->lastMarkedCount
            || $this->pipeline->layoutEpoch() !== $this->lastBoxEpoch
            || $this->pipeline->structureVersion() !== $this->lastSettleStructure;
    }

    /**
     * 跑一帧。
     * @param BoxConstraints $constraints 表面给出的约束（通常 = 表面尺寸的紧约束）
     * @param float $timestampMs 帧时间戳，由 FrameClock 注入，不读环境时钟
     * @param list<\Drift\App\OverlayEntry> $overlays 已由 OverlayLayout::placeOverlays() 算好 x/y
     * @param bool $paintRequested 应用级叠加（焦点环 / 面板落后）要求本帧画 —— 它们
     *        不经过树，Frame 看不见，只能由 Application 说
     * @return bool 本帧真的 paint + present 了吗（false = 整段跳过，或 paint 抛异常）。
     *         返回 false 时屏幕是上一帧内容 —— 这是设计意图，不是失败。
     * 浮层的布局段不在本类：settle 与 paint 在 Frame，layout 在
     * OverlayLayout::placeOverlays()（要锚点与客户区尺寸，锚点住 FocusManager）；
     * Frame 只拿到算好的 $e->x/$e->y，画时 translate 过去。
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
        // ① settle → ② layout → ②c 约束反馈：约束**真变了**就同帧再来一轮。
        // 稳态 1 轮；只有 LayoutBuilder / 按真实尺寸分窗口的容器会多一轮（上限见常量）。
        // 收敛完才 paint ⇒ 不存在"先画错的版式、下一帧再跳一下"的中间态（commit 原子）。
        $didRebuild = false;
        $layoutRan = false;
        $drift = '';
        /** @var list<string> $seenDrifts 本帧见过的漂移描述（A→B→A 的重复即震荡） */
        $seenDrifts = [];
        $round = 0;

        for ($round = 1; ; $round++) {
            // ① 帧末统一重建：派发中途的 setState 只标脏。
            // 异常从子树一路冒到帧末（沿途无 ErrorBoundary 捕获）⇒ 不吞：吞掉就是窗口冻结在上一帧且不报错。
            // 分段计时（SurfaceProfile，默认关）。
            $prof = SurfaceProfile::$on;
            $t = $prof ? \hrtime(true) : 0;
            try {
                // 第 1 轮按序号跳过；收敛轮必然刚标过脏，settle 是这一轮的全部意义。
                if ($round > 1 || $this->needsSettle()) {
                    $this->pipeline->bumpSettleRun();
                    $didRebuild = $root->settle() || $didRebuild;
                    foreach ($overlays as $e) {
                        // 浮层各自的帧末重建；任一浮层重建 ⇒ 本帧不能跳过 layout
                        $didRebuild = ($e->element?->settle() ?? false) || $didRebuild;
                    }
                } else {
                    // 树与上一帧逐位相同 ⇒ 既不重建也不挂接（判据见 needsSettle()）
                    $this->pipeline->bumpSettleSkip();
                }
            } catch (Throwable $e) {
                Errors::report(sprintf(
                    '帧循环：未捕获异常冒到帧末（沿途无错误边界）—— %s',
                    Errors::describe($e),
                ));
            }
            // 结构快照必须在 settle 之后取：本帧的增删/重排要进下一帧的判据
            $this->lastSettleStructure = $this->pipeline->structureVersion();
            $this->lastMarkedCount = $this->pipeline->markedCount();
            if ($prof) {
                SurfaceProfile::add(FrameStage::SETTLE, \hrtime(true) - $t);
            }

            // ② layout
            // 必须用 effectiveBox() 而不是 renderObject()：应用根常是 StatelessWidget，
            // 自己没有盒、只有底下的 Column 有；用 renderObject() 拿 null ⇒ 直接返回
            // ⇒ 整屏空白且不报错（不画任何东西也不算违反）。
            // ★ 每轮都重取：settle 可能换了盒（新盒 size 恒 null ⇒ 命中测试全落空）。
            $box = $root->effectiveBox();
            if ($box === null) {
                return false; // 整棵子树没有任何可绘制内容（例如空页）⇒ 这一帧无事可做
            }

            $t = $prof ? \hrtime(true) : 0;
            // 纯动画帧跳过 layout：前提是 layout() 是纯函数，由 LayoutSelfCheck 守。
            // 第三条判据 $boxEpoch（见字段注）：帧外换盒路径不进 settle 计数，
            // 只靠前两条会判成没变而跳过 layout ⇒ 命中测试全落空。
            // 读序号必须在 settle 之后（本帧重建造成的换盒也算进来）。
            $boxEpoch = $this->pipeline->layoutEpoch();
            if ($round > 1 || $didRebuild || $boxEpoch !== $this->lastBoxEpoch || $this->constraintsChanged($constraints)) {
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
                        '帧循环：layout 阶段未捕获异常（沿途无错误边界）—— %s',
                        Errors::describe($e),
                    ));
                }
            }
            if ($prof) {
                SurfaceProfile::add(FrameStage::LAYOUT, \hrtime(true) - $t);
            }

            // ②c 约束反馈：布局之后才知道"这层实际拿到多大"。变了 ⇒ 同帧重建。
            // ★ 表通常为空（只有登记过的节点），不遍历树、不 instanceof 具体控件。
            $drift = '';
            foreach ($this->pipeline->constraintObservers() as $observer) {
                if (!$observer instanceof ConstraintObserver) {
                    continue;
                }

                $reported = $observer->pollConstraintDrift();
                if ($reported !== null && $reported !== '') {
                    $drift = $reported;
                    $this->pipeline->noteConstraintDrift($reported);
                }
            }

            if ($drift === '') {
                break;
            }

            // 有界 + 震荡检测：A→B→A 会让同一句描述重复出现，那比"轮数用光"更能说明问题。
            $repeated = \in_array($drift, $seenDrifts, true);
            if ($round >= self::MAX_CONSTRAINT_ROUNDS || $repeated) {
                Errors::report(sprintf(
                    '一帧内布局约束反馈跑了 %d 轮仍未收敛%s：%s。'
                        . "\n" . '修法：检查 LayoutBuilder 的 builder 是否返回了"尺寸又反过来依赖约束"的子树'
                        . '（A→B→A 震荡），或把分支条件改成只依赖约束本身的稳定判据。',
                    $round,
                    $repeated ? '（检测到震荡：同一句漂移重复出现）' : '（轮数上限）',
                    $drift,
                ), Errors::DIAGNOSTIC);

                break;
            }

            $seenDrifts[] = $drift;
        }

        $this->pipeline->noteConstraintRounds($round);

        // ②b 布局自校验（默认关，build/layout.check 存在时开）
        // 必须放在**最后一轮** layout 之后：它用同一约束再排一次，排在前面等于替真实帧排两遍。
        // 只覆盖主树：浮层 layout 在 OverlayLayout::placeOverlays()（要锚点与客户区尺寸），自校验看不见。
        LayoutSelfCheck::maybeCheck($box, $constraints);

        // ③ paint → ④ present（有门控，见 $paintEpoch 的注）
        // 判据：缺一条就是一类静默（都有真实通道）。
        //   ① 本帧 layout 跑过（几何变必重画，也覆盖首帧）；
        //   ② paintEpoch 变 —— setCompose()/setOffset()/setBase()/setBarOpacity()/
        //      tickAnimations() 都不经 settle/layout，只看 ① 会全漏；
        //   ③ 应用级叠加要求画（$paintRequested：焦点环 afterPaint、面板落后）；
        //   ④ 序号初值 -1 兜首帧；
        //   ⑤ 浮层集合变了 —— 浮层栈不属主树，①②③ 看不见（关模态/菜单/Snackbar
        //      残留，被 hover/重排掩盖 ⇒ 极难复现）。
        // paintEpoch 必须在 layout 之后读（布局期 setOffset() 推它）。
        $overlayIds = [];
        foreach ($overlays as $overlay) {
            $overlayIds[] = $overlay->id;
        }
        $paintEpoch = $this->pipeline->paintEpoch();
        if (
            !$layoutRan
            && $paintEpoch === $this->lastPaintEpoch
            && !$paintRequested
            && $overlayIds === $this->lastOverlayIds
        ) {
            $this->pipeline->bumpPaintSkip();

            return false;
        }

        // 本段含 beginFrame 与 endFrame（各自另有计时），报告里扣掉才是「画」自己（DisplayList + Skia 光栅）。
        $t = $prof ? \hrtime(true) : 0;
        $painted = false;
        try {
            $canvas = $this->surface->beginFrame($timestampMs);
            $box->paintComposed($canvas);

            // 浮层画在主树之后，按栈顺序逐层 —— 绘制顺序即层叠，不引入 z-index。
            // scrim 不在这里画：模态遮罩由 ModalHost 自绘，再画一次会双绘。
            foreach ($overlays as $e) {
                $popupBox = $e->element?->effectiveBox();
                if ($popupBox !== null && $popupBox->hasSize()) {
                    $canvas->save();
                    $canvas->translate($e->x, $e->y);
                    $popupBox->paintComposed($canvas);
                    $canvas->restore();
                }
            }

            // afterPaint：树与浮层画完、endFrame 之前，给应用级叠加一个口子
            // （焦点环：不侵入控件，一次覆盖全仓可聚焦点）。
            if ($afterPaint !== null) {
                $afterPaint($canvas);
            }

            // 帧末自证：直接画路径没有录制侧的深度计数兜底。
            $canvas->assertFrameBalanced();
            $this->surface->endFrame();
            $painted = true;
        } catch (Throwable $e) {
            Errors::report(sprintf(
                '帧循环：paint 阶段未捕获异常（沿途无错误边界）—— %s',
                Errors::describe($e),
            ));
        }

        // ★ 失败时不记账：paint 抛异常 ⇒ 屏幕可能只上了半帧（或没 endFrame）⇒ 序号必须停在本帧值上
        // ⇒ 下一帧仍会画。记成"已画"= 静默停在残缺画面。
        if ($painted) {
            $this->pipeline->bumpPaintRun();
            $this->lastPaintEpoch = $paintEpoch;
            $this->lastOverlayIds = $overlayIds;
        }

        if ($prof) {
            SurfaceProfile::add(FrameStage::PAINT, \hrtime(true) - $t);
        }

        return $painted;
    }
}
