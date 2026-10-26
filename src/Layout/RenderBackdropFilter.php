<?php
declare(strict_types=1);

namespace Drift\Layout;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;

/**
 * 毛玻璃盒：把**背后的内容**糊掉，再画自己的子树（`BackdropFilter` 的渲染侧）。
 *
 * ## 三条不变量
 *
 * ① **只糊背后，不糊自己** —— 顺序必须是"裁剪 → `blurBackdrop()` → 画子"。
 *    反了就是"面板自己也被糊"，字会烂成一团；而 `Canvas::blurBackdrop()`
 *    被设计成一次性操作，正是为了让这个顺序**结构上无法搞反**（没有层可钻）。
 * ② **裁剪范围 = 内容盒** —— 模糊只发生在本盒里，盒外一个像素不动。裁剪是
 *    必须的：`blurBackdrop()` 的矩形只是"糊哪一块"，不做收口的话面板外的
 *    兄弟内容也会被糊。
 * ③ **不覆写 `clipsOverflow()`** —— 本类已在 `paintContent()` 里压了裁剪，
 *    再让基类 `paint()` 压一次就是双重压栈（`RenderScrollView` 同款取舍）。
 *
 * ## 为什么用 `$child->size()` 而不是 `$this->size()`
 *
 * `paintContent()` 拿到画布时，基类已经 `translate(padLeft, padTop)` 到**内容盒
 * 原点**。`$this->size()` 是**边框盒**尺寸，直接拿来当矩形会在有内边距时多糊
 * 出一圈。而 `RenderProxyBox` 的布局契约是"孩子铺满内容盒" ⇒ 孩子尺寸就是
 * 内容盒尺寸，且已经在本坐标系里（偏移恒为 0）。
 *
 * @api
 */
final class RenderBackdropFilter extends RenderProxyBox
{
    /**
     * ★ **不调 `parent::__construct()`**：`RenderBox` / `RenderObject` 没有构造器
     *   （字段靠初值），显式调它会 `Cannot call constructor`。`RenderProxy` 同款。
     */
    public function __construct(private readonly float $sigma)
    {
    }

    protected function performLayout(BoxConstraints $constraints): Size
    {
        $child = $this->child;
        if ($child === null) {
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        $child->layout($constraints);
        $child->setOffset(0.0, 0.0);

        $size = $child->size();
        if ($size === null) {
            // 不可能：`layout()` 返回前一定写了 `size`。留着是为了 AOT 能推成
            // `Size` 而不是 `?Size`（`constrain()` 收的是非空值）。
            return $constraints->constrain(new Size(0.0, 0.0));
        }

        return $constraints->constrain($size);
    }

    protected function paintContent(Canvas $canvas): void
    {
        $child = $this->child;
        if ($child === null) {
            return;
        }

        $size = $child->size();
        if ($size === null || $size->width <= 0.0 || $size->height <= 0.0) {
            // 没有可模糊的面积（零尺寸）⇒ 什么都不做。不是错误：
            // `Offstage` 或最小化那一帧都会走到这里。
            parent::paintContent($canvas);

            return;
        }

        $rect = new Rect(0.0, 0.0, $size->width, $size->height);

        $canvas->save();
        $canvas->pushClip($rect);
        $canvas->blurBackdrop($this->sigma, $rect);
        parent::paintContent($canvas);
        $canvas->popClip();
        $canvas->restore();
    }
}
