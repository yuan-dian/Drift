<?php
declare(strict_types=1);

namespace Drift\Dev\Doctor;

/**
 * 环境不满足构建前置 ⇒ 调用方（`drift-build`）转成自己的 `fail()` 口径。
 *
 * ★ 用异常而非 `exit`：`doctor` 要的是"逐项报告完再汇总"，只有真正要动手时
 *   （`prepare()` 写 ini / 拼 env）才允许中断 —— 那是另一条路径。
 */
final class EnvironmentException extends \RuntimeException
{
}
