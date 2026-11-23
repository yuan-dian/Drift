<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 树内对象取管线的唯一入口：一份数只留一个名字。
 *
 * 账本字段住在 Pipeline；门面删掉后这里给出"我的账本"的统一取法。
 * 方法名保留语义差别：多窗口落地时改成读节点引用，调用点不用改。
 *
 * @internal 框架内部所有"记本帧的账"的树内类都 use 它
 */
trait PipelineAccess
{
    /**
     * 本节点所属的管线。帧外调用落在最近一次进入帧的管线或默认实例
     * （`Pipeline::current()`），所以纯单测直接 `layout()` 也能读计数。
     *
     * @internal
     */
    protected function pipeline(): Pipeline
    {
        return Pipeline::current();
    }
}
