<?php
declare(strict_types=1);

namespace Drift\State;

/**
 * 一步转移的结果：新状态 + 要执行的命令。
 *
 * ★ 命令是 mixed 而非接口：框架不知道"排定时器 / 弹对话框"长什么样，那是应用的事。
 *   `Machine` 保证命令按序原样交回，不合并、不丢弃；执行由持有环境能力的调用方做。
 * ★ 状态与命令分开返回，而不是让 step 直接改状态 + 直接执行：step 因此是纯函数，
 *   可脱离窗口单测（同一 (state, event) 必得同一结果）。
 *
 * @api
 */
final class Effects
{
    /** @param list<mixed> $commands */
    public function __construct(
        public readonly mixed $state,
        public readonly array $commands = [],
    ) {
    }
}
