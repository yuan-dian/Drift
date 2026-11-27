<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * 面板里的一行：一个**稳定键**加一段文本。
 *
 * ## 为什么行要有键，而不是下标
 *
 * 面板每重算一次（切帧、展开、有新帧进来），行表就换一份。若光标记的是"第 7 行"，
 * 一旦上面少了一行，光标指的就是**另一个东西**——而屏幕看起来完全正常。这是所有
 * 树控件最常见的坑：Chrome DevTools 重开面板、React DevTools 换 tab 时选中的节点
 * 跑掉，根因都是位置当身份。
 *
 * 键由数据决定（树行是 `t:<debugId>`，帧行是 `f:<帧号>`），两者都在记录里稳定：
 * `debugId` 在元素生命周期内不变，帧号单调递增。于是"换了行表也不换身份"是结构性的，
 * 不需要在补丁里小心翼翼地搬状态。
 *
 * @internal 面板内部协议；`src/` 不依赖它
 */
interface Line
{
    public function key(): string;

    public function text(): string;
}
