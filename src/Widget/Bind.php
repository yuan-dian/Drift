<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 把一个**读点**绑成一棵可独立重建的子树。
 *
 * ```php
 * // 页面上"已选 N 项"这一行：只有它跟着选择集重建，表格不动
 * Bind::make(fn (BuildContext $ctx): Widget => Text::make('已选 ' . $this->selection->get()->count() . ' 项'))
 *
 * // 需要在 build 上下文里取主题色时，同一个闭包就能拿到 $ctx
 * Bind::make(fn (BuildContext $ctx): Widget => Row::make()
 *     ->background($ctx->color(StatusTone::Error->surfaceToken()))
 *     ->children([Text::make($this->store->error())]))
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
 * | `Bind::make(fn ($ctx) => …)` | 这一段 | 热点：每次点击都变的局部 |
 *
 * ★ **builder 必须收一个 `BuildContext`**：与 `build()` / `LayoutBuilder` 同一条
 *   约定。订阅闭包里常常要按当前主题解析颜色，而主题只在 ctx 上；不给的话每个
 *   这类点都得额外造一个只为了"把 ctx 带进 build"的 `StatelessWidget` 壳。
 *   （Flutter 的 `ValueListenableBuilder` / `AnimatedBuilder` 的 builder 同样带
 *   `BuildContext`，同一个理由。）
 * ★ **不需要手写切片**：`Bind` 里的 builder 读了什么就依赖什么，依赖由框架采集
 *   给出（漏写依赖这个失效模式不存在）。
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
    /** @var \Closure(BuildContext): Widget */
    private \Closure $builder;

    private function __construct(\Closure $builder)
    {
        $this->builder = $builder;

        parent::__construct();
    }

    /** @param \Closure(BuildContext): Widget $builder */
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
