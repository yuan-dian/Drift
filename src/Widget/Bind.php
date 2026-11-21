<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 把一个**读点**绑成一棵可独立重建的子树。
 *
 * ```php
 * // 页面上"已选 N 项"这一行：只有它跟着选择集重建，表格不动
 * Bind::make(fn(): Widget => Text::make('已选 ' . $this->selection->get()->count() . ' 项'))
 * ```
 *
 * ## 什么时候需要它
 *
 * `State` 的 `build()` 里读 `Cell` / `Computed` 就已经会自动重建了 —— 粒度是
 * **那个 State 的整棵子树**（页面级）。`Bind` 把粒度收窄到**它自己这一层**：
 * 读一个格子、只重跑这一小段。
 *
 * | 写法 | 粒度 | 用在哪 |
 * |---|---|---|
 * | 页面 `build()` 里直接 `get()` | 整页 | 默认；状态少、页面小 |
 * | `Bind::make(fn() => …)` | 这一段 | 热点：每次点击都变的局部 |
 *
 * ★ **不需要手写切片**：`Bind` 里的 builder 读了什么就依赖什么，等价于
 *   `SubjectBuilder` 的 `select` + `builder` 两段闭包合成一段，且依赖由采集给出
 *   （漏写依赖这个失效模式不存在）。
 * ★ **壳是 Element，不是盒**：`Bind` 走 `build()`，自己不产盒、不加边距 ——
 *   排版由子树决定（与 `LayoutBuilder` 同一条约定）。多一个 Element 约 0.45µs/帧，
 *   拿它换"只重建一格"是划算的；不需要细粒度就别包。
 * ★ **别在 builder 里写格子**：build 期写会从当帧活锁成每帧重建（`Cell::set()`
 *   当场报 `E-FLUSH-CYCLE`）。
 *
 * @api
 */
final class Bind extends StatefulWidget
{
    /** @var \Closure(): Widget */
    private \Closure $builder;

    private function __construct(\Closure $builder)
    {
        $this->builder = $builder;

        parent::__construct();
    }

    /** @param \Closure(): Widget $builder */
    public static function make(\Closure $builder): self
    {
        return new self($builder);
    }

    /** @internal 只有 `BindState::build()` 调 */
    public function builderClosure(): \Closure
    {
        return $this->builder;
    }

    public function createState(): State
    {
        return new BindState();
    }
}
