<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

/**
 * @internal 冒烟夹具：任务结果**是类实例**（真实形态 —— `fetchPage()` 返回 `Page`）。
 *
 * ★ 名字带 `Task` 前缀是有意的：`Row` 在 `Drift\Widget` 已有一个（结构控件），
 *   同名的冒烟夹具会让 `use` 撞车（第一版就叫 `Row`，`php -l` 当场报
 *   "Cannot use Drift\Widget\Row as Row"）。
 *
 * ★ 它同时是"**类 + 对象 + 属性访问**"这个构造集的最小样本 —— 正是探针 K 里
 *   **worker 线程会 SIGSEGV** 的那一批（`probes/RESULTS.md` §11）。放这里是为了
 *   让"端口能搬对象"这件事在解释器面上有断言；AOT 可达性靠 T2 的真机构建兜。
 */
final class TaskRow
{
    public function __construct(public string $name, public int $len)
    {
    }
}
