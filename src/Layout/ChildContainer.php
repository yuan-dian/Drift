<?php
declare(strict_types=1);

namespace Drift\Layout;

/**
 * "我的子盒是一**组**，可以整体换掉" —— `RenderFlex` / `RenderStack` / `RenderWrap` /
 * `RenderSegmentedControl` 四个盒的实现面（§DEBT D2）。
 *
 * 这四个类各自写了 `clearChildren()` + `addChild()`（逐字相同的 15 行），因为它们的
 * 共同父类是 `RenderBox` —— 而"有没有子"在那层还是可选的。接口把这件事提升成**类型**：
 * 多子 Element 只管问 `instanceof ChildContainer`，不必知道对面是 Flex 还是 Stack。
 *
 * ★ **为什么不是抽象基类**：这四个盒的布局算法毫无共同点（主轴分配 / 层叠 / 换行 /
 *   分段等宽），硬抽一个 `RenderMultiChildBox` 只能塞两个方法进去，却要它们付
 *   "换个父类"的继承位。Flutter 那边也是接口式（`ContainerRenderObjectMixin`）。
 *
 * ★ **签名跟着现状走**：`addChild()` 收非空 `RenderBox`（调用方负责跳过 `null` 子），
 *   `clearChildren()` 无参。改成 `?RenderBox` 会让四个实现齐齐变，收益为零。
 *
 * @api
 */
interface ChildContainer
{
    /** 摘掉所有子盒（换子前必调，否则旧子盒留在原位） */
    public function clearChildren(): void;

    /** 追加一个子盒（顺序即绘制/布局顺序） */
    public function addChild(RenderBox $child): void;

    /**
     * 当前挂着的子盒（按顺序）。
     *
     * 挂接方用它判"结构变没变"：相同就不必摘了重挂；不同必须 `markNeedsLayout()`
     * —— 复用盒（配置判等通过）否则会带着上一轮的几何继续画。
     *
     * @return list<RenderBox>
     */
    public function children(): array;
}
