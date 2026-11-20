<?php
declare(strict_types=1);

namespace Drift\Platform;

/**
 * 进程级静态钩子的装配端口：fatal box、resize pump 这类"早于建窗装好、
 * 循环退出后必须卸掉"的槽。平台实现自己持有，`WindowSet` 代管安装与卸载，
 * 组合根不再逐字抄这两段（漏卸 ⇒ AOT 关窗必崩）。
 *
 * 跨平台约定：不支持某类钩子的平台给空实现，而不是返回 null ——
 * 调用方只认这一个端口，不写平台判断。
 * @api platform
 */
interface PlatformHooks
{
    /** 装钩子；`WindowSet` 构造时调一次，早于任何建窗。 */
    public function install(): void;

    /**
     * 接 resize 回调：模态尺寸循环把主循环挂起时，壳同步调回 PHP 跑轻量重绘。
     * 回调按窗口句柄分发，多窗不能共用"一个闭包打天下"。
     * @param null|\Closure(int):void $pump
     */
    public function setResizePump(?\Closure $pump): void;

    /** 卸掉 install()/setResizePump() 装的一切；`WindowSet::run()` 的 finally 调。 */
    public function release(): void;
}
