<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderPopupPanel;

/**
 * 浮层**几何布局**策略（arch-v2 `01` §4）—— 帧循环每帧一次的纯几何段。
 *
 * ★ 与输入 / 命令 / snackbar / tooltip **零耦合**：只读栈、读盒、写盒。
 *   host 契约只有两个口子（{@see OverlayLayoutHost}）。
 * ★ 它是 {@see OverlayHost} 主体（步 8）与 `FrameEngine`（步 9）的双重前哨：
 *   "settle → 清扫 → 定位"的帧内次序整体住在这里，两处调用点
 *   （`frame()` / `repaintForResize()`）原样调用。
 *
 * @internal S1 第 7 步从 Application 拆出（原 private 方法，无外部影响）
 */
final class OverlayLayout
{
    public function __construct(private OverlayLayoutHost $host)
    {
    }

    /**
     * 浮层布局段（arch-v2 `01` §4）：**每层独立**量 + 定位 + 夹取。
     *
     * ★ 写 `$e->x/$e->y`（条目持有落点），不再写应用级单槽位字段。
     * ★ 每帧把条目根的盒 `setBase($e->x, $e->y)` 写一次 —— **写在这里而不是
     *   `Frame::run`**：Frame 只拿到算好的原点，它不该知道 base 的存在（`01` §4.4）。
     * ★ **锚点没了 ⇒ 自动 pop**（`01` §9 边界 #2）：锚点被虚拟化回收 / 所在行被
     *   删掉之后，浮层悬在左上角是**错形态**（用户看到的是一个不知道属于谁的框）。
     *   先清扫再定位 —— 清扫在快照上做，避免边遍历边改栈。
     */
    public function placeOverlays(BoxConstraints $constraints): void
    {
        // ★★ **浮层的重建必须早于浮层的布局**（2026-10-24 立的 invariant）。
        //
        //   为什么：`Element::update()` 会**换一个新渲染盒**（§13.2"Widget 不可变 ⇒
        //   换盒与换 Widget 是同一件事的两次表达"），`adoptBoxes()` 再把新盒挂进祖先。
        //   本方法原先只做"摆盒"，而 `Frame::run()` 的 settle 段排在它**后面** ⇒
        //   settle 换上的新盒这一帧**没排过**（`size` 为 null、offset 为默认值），
        //   于是 `paintContent()` 第一句"没排过 ⇒ 无墨"早退 ⇒ **面板空白一帧**。
        //
        //   首个暴露者：`Select` 面板里的 `ScrollView`（2026-10-24）—— 滚一格换一次盒、
        //   空白一格，连续滚动时面板几乎全白；而滚动本身是对的（下一帧 `appliedOffset`
        //   就到位），所以极像"渲染偶发抽风"，不像结构错。
        //   ★ 这不是 Select 特有的洞：**任何浮层里的有态控件**都中招（模态里放个
        //     `TextField`，每敲一键空白一帧）—— 只是以前没有浮层装过会自重建的内容。
        //
        //   ★ 放本方法里而不是调用点：两个调用点（`frame()` / `repaintForResize()`）
        //     都要这条顺序，而 resize 那条路更需要（约束变了 + 树也重建了）。
        //   ★ `Frame::run()` 里那段浮层 settle 保留不动，而且是**必须的**：它的返回值
        //     进 `$didRebuild`（"任一浮层重建 ⇒ 本帧不能跳过 layout"）。只在本方法 settle
        //     会把"早 settle 引发的重建"漏出那个判据 ⇒ layout 被跳过 ⇒ 新盒仍没排过。
        //     两遍 settle 幂等（`settle()` 只标脏 / 重建，无副作用累积）。
        //   ★ 异常仍走帧末那套上报口径（与 `Frame::run()` 的 settle 段同措辞）。
        try {
            // ★ 受控值 ⇒ 面板重读（W8-2，2026-10-24）：**必须在 settle 之前**，
            //   理由同下一条 invariant —— settle 换上的新盒要这一帧就排过。
            $this->syncPanelSignatures();

            foreach ($this->host->overlays()->all() as $e) {
                $e->element?->settle();
            }
        } catch (Throwable $t) {
            Errors::report(sprintf(
                '帧循环：浮层 settle 段未捕获异常冒到帧末（沿途无错误边界）—— %s（%s:%d）',
                $t->getMessage(),
                basename($t->getFile()),
                $t->getLine(),
            ));
        }

        foreach ($this->host->overlays()->all() as $e) {
            if ($e->anchor !== null && !$e->anchor->isMounted()) {
                $this->host->dismissOverlay($e);

                continue;
            }

            // ★ `owner` 清扫（`01` §7）：锚点与归属者是**两个**概念 —— 锚点管"挂在哪"，
            //   归属者管"谁的生命周期决定我该不该在"。Tooltip 两者同为悬停目标，
            //   但下拉这类"锚在按钮、归属在控件"的浮层只能靠 owner 收尸。
            //   ★ 2026-10-23 之前本字段**零消费**（`01` §7 承诺了、代码没做）——
            //     死字段比缺失更贵：读代码的人会以为防线已经在。
            if ($e->owner !== null && !$e->owner->isMounted()) {
                $this->host->dismissOverlay($e);

                continue;
            }

            $this->placeEntry($e, $constraints);
        }
    }

    /**
     * 受控值变了 ⇒ 面板重读（W8-2，2026-10-24）。
     *
     * ## 它补的是哪条缝
     *
     * ★★ **浮层是第二棵根渲染树**（`OverlayEntry::$panel` 的字段注释就是这条设计）：
     *   主树重建**不带动**面板。而 `Select` / `ComboBox` 的面板内容来自**受控属性**
     *   （`loading` / 选项表 / `emptyText`）—— 应用 `setState` 之后面板不会自己重读。
     *   2026-10-24 之前这条职责在每个控件身上（Subject + `initState` 订阅 +
     *   `didUpdateWidget` 比对签名 + notify，四步漏一步就全静默），现在收到这里。
     *
     * ## 两道闸（顺序不能换）
     *
     * ① **锚点 Widget 换了** ⇒ 那个 `State` 重建过 ⇒ 受控属性**可能**变了。
     *    `Widget` 不可变 ⇒ `===` 就是判据，O(1)。没换 ⇒ 连签名都不算
     *    （签名要拼整张选项表，5000 项的远程列表每帧算一遍是白烧）。
     * ② **签名变了** ⇒ 面板**真的**在意的东西变了 ⇒ 标脏。这一步是必需的：
     *    应用每次重建都造新的 `Option` 实例，只看①会让面板每帧重建 ⇒ 滚动位置回顶部。
     *
     * ## 为什么必须在 `placeOverlays()` 的 settle 段**之前**调
     *
     * 标脏只是置标，重建发生在紧随的 settle 里；而 `placeEntry()` 排在 settle
     * **之后** ⇒ "重建 → 布局"的顺序成立。反过来（放到 `Frame::run()` 的浮层
     * settle 段）会得到"这一帧排过的还是旧盒"，即 `76549c7` 修掉的那个"面板空白一帧"。
     *
     * ## 代价（如实记录）
     *
     * ★ 面板内容比主树**晚一帧**：本帧 `placeOverlays()` 跑的时候，主树还没 settle
     *   （`Frame::run()` 在它后面）⇒ 锚点还是旧 Widget ⇒ 这一帧看不见变化。
     *   60fps 下 16ms，用户不可见；但**用例必须跑两帧**（`tools/smoke.php` 的
     *   `$ssSettle` 就是这条）。
     *
     * @internal
     */
    private function syncPanelSignatures(): void
    {
        foreach ($this->host->overlays()->all() as $e) {
            // 还没挂载（本帧刚 push）⇒ 面板内容本来就是按当前值造的
            if ($e->element === null) {
                continue;
            }

            $current = $e->anchor?->widget();
            if ($current === null || $current === $e->seenAnchor) {
                continue;
            }
            $e->seenAnchor = $current;

            // 没声明签名 ⇒ 不传播（**加性**：Menu / Tooltip / Modal / 静态面板逐字节不变）
            $signature = $current->overlaySignatureValue();
            if ($signature === null) {
                continue;
            }

            $value = $signature();
            if ($value === $e->lastSignature) {
                continue;
            }
            $e->lastSignature = $value;

            // 无态面板（静态内容）⇒ 无事发生，且是对的：它的内容只由 Widget 决定
            $e->element->markNeedsBuild();
        }
    }

    /**
     * 单层定位（`01` §4.1）：
     *   ① 量（模态 tight 盖满；其余无界自尺寸，超大退化 tight）
     *   ② 锚矩形（`absoluteOrigin` 沿父链，遇浮层根停止）
     *   ③ 首选方向 → 候选原点
     *   ④ 翻转（只翻一次）
     *   ⑤ 夹取
     *   ⑥ 写 `$e->x/$e->y` + 盒 `setBase`
     */
    private function placeEntry(OverlayEntry $e, BoxConstraints $constraints): void
    {
        $box = $e->element?->effectiveBox();
        if ($box === null) {
            return;
        }

        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;

        // ① 量：模态紧约束盖满（原点归零，面板自居中靠 Modal::build 的 Center）
        if ($e->prefer === OverlayEntry::CENTER) {
            if ($constraints->hasBoundedWidth() && $constraints->hasBoundedHeight()) {
                $box->layout(BoxConstraints::tight(
                    new \Drift\Geometry\Size($maxW, $maxH),
                ));
            } else {
                $box->layout(BoxConstraints::unbounded());
            }

            $e->x = 0.0;
            $e->y = 0.0;
            $box->setBase(0.0, 0.0);

            return;
        }

        // ★ 宽度上限（W8-2）：`Tooltip` 这类"按内容自尺寸、但可能很长"的层必须给
        //   有界宽 —— `RenderPopupPanel` 只在约束有界时才夹宽，而 ⑤ 的夹紧**只挪 x
        //   不改宽** ⇒ 不限宽就是"右边内容溢出到客户区外"。
        //   ★ 高度仍无界：菜单靠 `innerMaxH` 的 `maxHeight === INF` 分支决定"不限高"，
        //     改成有界会连带把菜单限高（行为变更，与本步无关）。
        $capW = $e->maxWidth > $maxW ? $maxW : $e->maxWidth;
        if (\is_infinite($capW)) {
            $box->layout(BoxConstraints::unbounded());
        } else {
            // ★ 走 `capWidth()` 工厂，**不要**在这里手写 `BoxConstraints::INFINITY`
            //   —— 外部类常量引用在 AOT 下求值成 0（面板宽高全 0、画面一丝不变，
            //   而 smoke 全绿）。详见 `BoxConstraints::capWidth()` 的注释。
            $box->layout(BoxConstraints::capWidth($capW));
        }

        // 超大面板退化：夹进客户区重量一次（内容溢出由面板自己 Overflow::Clip）
        $size = $box->size();
        if ($size !== null && ($size->width > $maxW || $size->height > $maxH)) {
            $box->layout(BoxConstraints::tight(new \Drift\Geometry\Size($maxW, $maxH)));
        }

        // ② 面板矩形（含投影留白的盒要内缩 `elevation` 才是"面板"本身）
        if ($box instanceof RenderPopupPanel) {
            $panel = $box->panelRect();
            $w = $panel->width;
            $h = $panel->height;
            $pad = $panel->left;
        } else {
            $size = $box->size();
            $w = $size?->width ?? 0.0;
            $h = $size?->height ?? 0.0;
            $pad = 0.0;
        }

        // ② 锚矩形
        [$ax, $ay, $aw, $ah] = $this->anchorRect($e);

        // ③④ 首选方向 + 翻转
        [$x, $y] = self::originFor($e->prefer, $ax, $ay, $aw, $ah, $w, $h, $maxW, $maxH, $e->inset);

        // ⑤ 夹取
        $x = min(max($x, 0.0), max(0.0, $maxW - $w));
        $y = min(max($y, 0.0), max(0.0, $maxH - $h));

        // ⑥ 写落点（盒原点 = 面板原点 − 投影留白）
        $e->x = $x - $pad;
        $e->y = $y - $pad;
        $box->setBase($e->x, $e->y);
    }

    /**
     * 锚矩形（客户区坐标）：`[x, y, w, h]`。
     *
     * ★ 无锚（模态已提前返回）/ 锚点已卸载 ⇒ `[0,0,0,0]`（落左上角，夹取兜底）。
     * ★ `AT_POINTER` 暂退化为锚点（右键菜单当前用 `anchor`，不用指针坐标）。
     *
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function anchorRect(OverlayEntry $e): array
    {
        $anchor = $e->anchor;
        if ($anchor === null || !$anchor->isMounted()) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        $box = $anchor->effectiveBox();
        if ($box === null) {
            return [0.0, 0.0, 0.0, 0.0];
        }

        [$x, $y] = self::absoluteOrigin($box);
        $size = $box->size();

        return [$x, $y, $size?->width ?? 0.0, $size?->height ?? 0.0];
    }

    /**
     * 首选方向 → 候选原点，放不下就**只翻一次**（`01` §4.2 / §4.3：不做通用避让，
     * 否则同一控件在不同窗口尺寸下弹出方向不同 ⇒ golden 无法比）。
     *
     * @return array{0: float, 1: float}
     */
    private static function originFor(
        int $prefer,
        float $ax,
        float $ay,
        float $aw,
        float $ah,
        float $w,
        float $h,
        float $maxW,
        float $maxH,
        float $inset = 0.0,
    ): array {
        // ★ 贴底居中（Snackbar）：**不做翻转** —— 一条通知没有"上面放不下就翻到下面"
        //   这种语义（它本来就贴底）。放不下（窗口比横条还矮）由 ⑤ 的夹取兜住。
        if ($prefer === OverlayEntry::BOTTOM_CENTER) {
            return [($maxW - $w) / 2.0, $maxH - $h - $inset];
        }

        if ($prefer === OverlayEntry::ABOVE) {
            if ($ay - $h >= 0.0) {
                return [$ax, $ay - $h];
            }
            if ($ay + $ah + $h <= $maxH) {
                return [$ax, $ay + $ah];   // 翻到下方
            }

            return [$ax, $ay - $h];
        }

        if ($prefer === OverlayEntry::RIGHT) {
            if ($ax + $aw + $w <= $maxW) {
                return [$ax + $aw, $ay];
            }
            if ($ax - $w >= 0.0) {
                return [$ax - $w, $ay];    // 翻到左侧
            }

            return [$ax + $aw, $ay];
        }

        if ($prefer === OverlayEntry::LEFT) {
            if ($ax - $w >= 0.0) {
                return [$ax - $w, $ay];
            }
            if ($ax + $aw + $w <= $maxW) {
                return [$ax + $aw, $ay];
            }

            return [$ax - $w, $ay];
        }

        // ★ 居中两向（W8-2 Tooltip）：水平对准锚点中心，垂直与 BELOW/ABOVE 同规则。
        //   夹取仍由调用方的 ⑤ 兜底 —— 锚点贴边时 tooltip 会偏离中心而不出界，
        //   这是刻意的（`01` §4.2「不做通用避让」：同一控件在不同窗口尺寸下弹出
        //   方向不同 ⇒ golden 无法比）。
        if ($prefer === OverlayEntry::BELOW_CENTER) {
            $cx = $ax + ($aw - $w) / 2.0;
            if ($ay + $ah + $h <= $maxH) {
                return [$cx, $ay + $ah];
            }
            if ($ay - $h >= 0.0) {
                return [$cx, $ay - $h];    // 翻到上方
            }

            return [$cx, $ay + $ah];
        }

        if ($prefer === OverlayEntry::ABOVE_CENTER) {
            $cx = $ax + ($aw - $w) / 2.0;
            if ($ay - $h >= 0.0) {
                return [$cx, $ay - $h];
            }
            if ($ay + $ah + $h <= $maxH) {
                return [$cx, $ay + $ah];   // 翻到下方
            }

            return [$cx, $ay - $h];
        }

        // BELOW（默认）/ AT_POINTER
        if ($ay + $ah + $h <= $maxH) {
            return [$ax, $ay + $ah];
        }
        if ($ay - $h >= 0.0) {
            return [$ax, $ay - $h];        // 翻到上方
        }

        return [$ax, $ay + $ah];
    }

    /**
     * 盒在客户区的绝对原点 —— 委派到 `RenderBox::absoluteOrigin()`（单一出处，
     * Layout 拥有盒几何）。历史：2026-10-18 语义树落地时把这段 8 行父链累加上提，
     * 免得 Input/Widget 侧为了算几何再抄一份（或反向依赖 App）。
     */
    private static function absoluteOrigin(RenderBox $box): array
    {
        return RenderBox::absoluteOrigin($box);
    }

    /**
     * 有浮层的锚点 Widget 换了、但 `syncPanelSignatures()` 还没看到 ⇒ 必须再跑一帧。
     *
     * ★★ 为什么这条判据是**必需**的：`syncPanelSignatures()` 跑在 `placeOverlays()`
     *   里，而主树在它**之后**才 settle ⇒ 那一帧它看到的锚点还是**旧** Widget
     *   （`Widget` 不可变 ⇒ 旧实例的受控属性也是旧的），只能等下一帧再看一次。
     *   - smoke 里"跑两帧"是**显式写的**（`$cbSettle` / `$ssSettle`）⇒ 从来看不出问题；
     *   - 真机上**没有下一帧** —— 应用处理完一次 `setState` 就静默了（前五条判据全假），
     *     帧时钟不上膛 ⇒ 面板永远停在旧内容。2026-10-24 实测：远程搜索的
     *     `deliver()` 明明把选项换成了 2 项（日志 n=2），面板还列着旧的 4 项。
     *
     * ★ **自终止**：`syncPanelSignatures()` 无论签名变没变都会更新 `seenAnchor`
     *   ⇒ 下一帧这个判据即为假，不会空转。这一点是它能放进 `wantsFrame()` 的前提。
     * ★ 成本 O(浮层数)，通常是 0 或 1 次 `===`。
     */
    public function panelsLagBehind(): bool
    {
        foreach ($this->host->overlays()->all() as $e) {
            if ($e->element === null) {
                continue;
            }

            $current = $e->anchor?->widget();
            if ($current !== null && $current !== $e->seenAnchor) {
                return true;
            }
        }

        return false;
    }
}
