<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * "记在谁的账本上" —— 树内对象取管线的**唯一**入口。
 *
 * ★★ 为什么需要它（2026-11-02，D3 收口）：D3① 把二十来个数从 `State` /
 *   `Element` / `RenderBox` / `RenderObject` / `Frame` 搬进了 `Pipeline`，但
 *   **字段搬了、门面没搬**。于是同一份数据有了两个名字
 *   （`State::rebuiltCount()` 与 `Pipeline::current()->rebuiltCount()`），
 *   新代码继续用旧名字 ⇒ 搬家只搬了一半，而且"这份数到底属于谁"被旧名字
 *   掩盖着（`State::boxEpoch()` 读的其实是渲染树的全局序号，跟 State 无关）。
 *
 * ★★ 为什么是 trait 而不是继续留静态门面：22 个门面逐个删掉之后，如果
 *   "树内对象怎么拿到管线"没有唯一答案，下一个人会再造一个门面出来。一处
 *   实现、一个名字，是这件事能被守住的最低成本。
 *
 * ★★ 为什么方法叫 `pipeline()` 而不是直接写 `Pipeline::current()`：现在两者
 *   等价，但**语义不同** —— 树内对象问的是"**我的**账本"，不是"当前账本"。
 *   多窗口（`docs/guides/platform-ports.md`）落地时，这里改成读节点上的引用（`Pipeline`
 *   类注释里候选 ①），**调用点一行都不用改**。这就是那个"扩展点"。
 *
 * @internal 框架内部所有"记本帧的账"的树内类都 use 它
 */
trait PipelineAccess
{
    /**
     * 本节点所属的管线。
     *
     * ★ 帧外调用落在"最近一次进入帧的管线"或进程默认实例上（见 `Pipeline::current()`）；
     *   这正是纯单测直接 `layout()` 也能读计数、而无需先建应用的原因。
     *
     * @internal
     */
    protected function pipeline(): Pipeline
    {
        return Pipeline::current();
    }
}
