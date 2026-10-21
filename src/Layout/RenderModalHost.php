<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Point;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;

/**
 * 模态宿主盒（§A.3.7 / §12.1 第 6 条）：**盖满客户区 + 一层遮罩**，面板自己居中。
 *
 * ```
 * ModalHost                 ← 本盒：遮罩 + 盖满
 *   └ Center                ← 居中（约束 tight ⇒ 自身也盖满，不产生偏移）
 *       └ PopupPanel        ← 面板形体（投影 / 边框 / 底）
 *           └ Column        ← 标题 + 内容
 * ```
 *
 * ★ **遮罩就是本盒 `paint()` 的前半段** —— 不做独立的 `ModalBarrier` 控件：
 *   遮罩没有独立存在意义（模态关了它必然一起没），拆成控件只会多一层
 *   永远只有一个实例的 Element。§A.3.7 里 `ModalBarrier` 描述的是**职责**
 *   （半透明自绘、吃掉指针），落点在这里。
 *
 * ★ **"盖满"由本盒保证，不靠调用方**：有界轴取 `max`（不是子的自然尺寸），
 *   无界轴退化为子的自然尺寸（无界轴上没有"满"可言）。于是遮罩永远覆盖
 *   整个客户区 —— 这正是"命中优先于主树"的物质基础（O-20 差异表 #1）。
 *
 * ★ **点遮罩 ≠ 点面板**：命中测试走 `RenderBox` 默认实现（先越界、再逆序问子、
 *   都不中返回自己）⇒ 面板外的一切命中都停在本盒的子树里（`Center` 或本盒），
 *   **父链上没有 `RenderPopupPanel`** ⇒ `Application` 用这一条判"点在遮罩上"
 *   （见 `Application::hitsModalPanel()`）。
 *
 * @internal `Modal` 的 build 产物；应用不该直接 new 它
 */
final class RenderModalHost extends RenderProxyBox
{
    /**
     * @param Color $barrier 遮罩色（**带 alpha** —— 混色由画布负责；光栅后端
     *   `RasterCanvas` 的 alpha 混色 2026-10-17 已实测：128 → `rgb(127,127,127)`、
     *   64 → `rgb(191,191,191)` 叠在纯白上）
     *
     * ★ **没有默认值**：默认色只住 `ModalHost::createRenderObject()` 一处。
     *   这里再给一份就是"两处真相" —— 改一处、另一处静默生效，最难查。
     */
    public function __construct(
        private readonly Color $barrier,
    ) {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $child = $this->child;

        if ($child === null) {
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        // 有界轴 ⇒ tight（盖满）；无界轴 ⇒ 0..INF（让子自尺寸，绝不产生 INF 尺寸）
        $minW = $constraints->hasBoundedWidth() ? $constraints->maxWidth : 0.0;
        $minH = $constraints->hasBoundedHeight() ? $constraints->maxHeight : 0.0;

        $child->layout(new BoxConstraints($minW, $minW, $minH, $minH));
        $child->setOffset(0.0, 0.0);

        $size = $child->size() ?? new Size(0.0, 0.0);

        return $constraints->constrain(new Size(
            $constraints->hasBoundedWidth() ? $constraints->maxWidth : $size->width,
            $constraints->hasBoundedHeight() ? $constraints->maxHeight : $size->height,
        ));
    }

    #[\Override]
    public function paint(Canvas $canvas): void
    {
        $size = $this->size;
        if ($size === null) {
            return; // 没排过 ⇒ 无墨（同 RenderPopupPanel）
        }

        // ① 遮罩：整盒一层半透明（`BoxStyle` 画不到"自己矩形之外"，而这里正好是
        //    整盒 ⇒ 用不着它；直接 drawRect 少一层对象）
        $canvas->drawRect(
            Rect::fromOrigin(new Point(0.0, 0.0), $size),
            Paint::fill($this->barrier),
            0.0,
        );

        // ② 内容（面板）盖在遮罩上
        parent::paint($canvas);
    }
}
