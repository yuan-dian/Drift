<?php
declare(strict_types=1);

namespace Drift\App;

use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Layout\RenderBox;

/**
 * 浮层**几何布局**：帧循环每帧一次的纯几何段（量 + 定位 + 夹取）。
 * host 契约即 {@see OverlayManager}：`overlays()` 读栈、`dismissOverlay()` 关条目。
 * 只读栈、读盒、写盒，与输入 / 命令 / snackbar / tooltip 零耦合。
 * "settle → 清扫 → 定位"的帧内次序整体住在这里。
 * @internal
 */
final class OverlayLayout
{
    public function __construct(private OverlayManager $host)
    {
    }

    /**
     * 浮层布局段：**每层独立**量 + 定位 + 夹取，落点写回 `$e->x/$e->y`（条目持有）。
     * 锚点被虚拟化回收 / 所在行被删 ⇒ 条目自动 pop，否则浮层悬在左上角。
     * 先清扫再定位：清扫在快照上做，避免边遍历边改栈。
     */
    public function placeOverlays(BoxConstraints $constraints): void
    {
        try {
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

            // ★ 锚点与归属者是**两个**概念：锚点管"挂在哪"，归属者管"谁的生命周期决定我该不该在"。
            //   下拉这类"锚在按钮、归属在控件"的浮层只能靠 owner 收尸，否则该没却留着。
            if ($e->owner !== null && !$e->owner->isMounted()) {
                $this->host->dismissOverlay($e);

                continue;
            }

            $this->placeEntry($e, $constraints);
        }
    }

    /**
     * 受控值变了 ⇒ 面板重读：浮层是第二棵根渲染树，主树重建不带动面板。
     * `Select` / `ComboBox` 的面板内容来自受控属性，应用 `setState` 后不会自己重读。
     * 两道闸：① 锚点 Widget 换了（`===` 判据，O(1)）；② 签名变了 ⇒ 标脏。
     *   只看①会每帧重建面板 ⇒ 滚动位置回顶部，所以必须再比签名。
     * 必须在 `placeOverlays()` 的 settle 段之前调：标脏只在 settle 里重建，
     * 排在之后 ⇒ 这一帧排过的还是旧盒，面板空白一帧。
     * 面板内容比主树晚一帧：本帧主树还没 settle，这一帧看不见变化。
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

            // 没声明签名 ⇒ 不传播（Menu / Tooltip / Modal / 静态面板逐字节不变）
            $signature = $current->overlaySignatureValue();
            if ($signature === null) {
                continue;
            }

            $value = $signature();
            if ($value === $e->lastSignature) {
                continue;
            }
            $e->lastSignature = $value;

            // 无态面板（静态内容）⇒ 无事发生：它的内容只由 Widget 决定
            $e->element->markNeedsBuild();
        }
    }

    /**
     * 单层定位：量 → 锚矩形 → 首选方向 → 翻转（只翻一次）→ 夹取 → 写落点 + 盒 `setBase`。
     */
    private function placeEntry(OverlayEntry $e, BoxConstraints $constraints): void
    {
        $box = $e->element?->effectiveBox();
        if ($box === null) {
            return;
        }

        $maxW = $constraints->maxWidth;
        $maxH = $constraints->maxHeight;

        // ① 量：模态紧约束盖满（原点归零，居中靠 Modal::build 的 Center）
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

        // 宽度上限：Tooltip 这类"按内容自尺寸、但可能很长"的层必须有界宽，
        //   否则 ⑤ 的夹紧只挪 x 不改宽 ⇒ 右边内容溢出客户区外。
        $capW = $e->maxWidth > $maxW ? $maxW : $e->maxWidth;
        if (\is_infinite($capW)) {
            $box->layout(BoxConstraints::unbounded());
        } else {
            // ★ 走 `capWidth()` 工厂，**不要**在这里手写 `BoxConstraints::INFINITY`：
            //   外部类常量在 AOT 下求值成 0 ⇒ 面板宽高全 0、画面一丝不变，而 smoke 全绿。
            $box->layout(BoxConstraints::capWidth($capW));
        }

        // 超大面板退化：夹进客户区重量一次（内容溢出由面板自己 Overflow::Clip）
        $size = $box->size();
        if ($size !== null && ($size->width > $maxW || $size->height > $maxH)) {
            $box->layout(BoxConstraints::tight(new \Drift\Geometry\Size($maxW, $maxH)));
        }

        // ② 面板矩形：含投影留白的盒要内缩 `elevation` 才是"面板"本身。
        $panel = $box->panelRect();
        if ($panel !== null) {
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
     * 点锚（右键菜单）优先 ⇒ `[x, y, 0, 0]`：菜单弹出点就是锚点，不再看元素盒。
     * 无锚（模态已提前返回）/ 锚点已卸载 ⇒ `[0,0,0,0]`（落左上角，夹取兜底）。
     * @return array{0: float, 1: float, 2: float, 3: float}
     */
    private function anchorRect(OverlayEntry $e): array
    {
        if ($e->atX !== null && $e->atY !== null) {
            return [$e->atX, $e->atY, 0.0, 0.0];
        }

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
     * 首选方向 → 候选原点，放不下就**只翻一次**（不通用避让：方向要在各窗口尺寸下稳定）。
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
        // 贴底居中（Snackbar）：不做翻转（通知本就贴底），放不下由 ⑤ 的夹取兜住。
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

        // 居中两向（Tooltip）：水平对准锚点中心，垂直与 BELOW/ABOVE 同规则；
        //   锚点贴边时 tooltip 偏离中心但不出界（夹取兜底）。
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

        // 点锚（右键菜单）：左上角对准点；右/下放不下就**向另一侧展开**（Windows 的
        //   贴边行为）—— 靠最后的夹取也能不出界，但右缘不贴光标，看着像"飘走了"。
        if ($prefer === OverlayEntry::AT_POINTER) {
            $px = $ax + $w <= $maxW ? $ax : $ax - $w;
            $py = $ay + $h <= $maxH ? $ay : $ay - $h;

            return [$px, $py];
        }

        // BELOW（默认）
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
     * 避免别处再抄一份几何或反向依赖 App）。
     */
    private static function absoluteOrigin(RenderBox $box): array
    {
        return RenderBox::absoluteOrigin($box);
    }

    /**
     * 有浮层的锚点 Widget 换了、但 `syncPanelSignatures()` 还没看到 ⇒ 必须再跑一帧。
     * `syncPanelSignatures()` 在 `placeOverlays()` 里，主树在它之后才 settle ⇒
     * 那一帧看到的锚点还是旧 Widget，只能等下一帧。
     * 自终止：无论签名变没变都会更新 `seenAnchor` ⇒ 下一帧判据即假，不会空转。
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
