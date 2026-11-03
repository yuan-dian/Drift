<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * **文本输入盒**的公共面（`RenderTextField` / `RenderTextArea`）。
 *
 * ★ 存在的理由只有一条：指针框选（`TextEditingBridge`）要"把屏幕点换算成插入点"，
 *   而它原先写死 `findBoxOf(RenderTextField::class)` ⇒ 多行框的拖选**静默失效**
 *   （点下去没反应、拖不动，且没有任何报错）。
 * ★ 只收**两种盒都有**的那一个方法。别把 `caretRect()` / `lineRect()` 也塞进来：
 *   单行盒没有"行"的概念，硬凑一个只会让两个实现各自编一套假答案。
 *
 * @api
 */
interface TextInputBox
{
    /**
     * 盒内 `(x, y)` ⇒ 最近的插入点（字节偏移，恒字符边界）。
     *
     * ★ 单行实现**忽略 `y`**：鼠标点在这条文本框的上下边缘之外，落点仍是同一个
     *   字符间隙 —— 那正是单行输入框该有的答案（不是"点空了"）。
     * ★ 收**盒内坐标**（含内边距）：各实现自己扣内边距与滚动偏移。
     */
    public function offsetAtPoint(float $x, float $y): int;
}
