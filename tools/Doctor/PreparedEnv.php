<?php
declare(strict_types=1);

namespace Drift\Dev\Doctor;

/**
 * `Environment::prepare()` 的产物：构建真正要用的那份 env + 报告用的路径。
 *
 * ★ 只做数据容器 —— 定位逻辑全在 `Environment` 里（单一来源），这里不重复算。
 */
final class PreparedEnv
{
    /**
     * @param array<string, string> $env 透传给 tpc 子进程的环境变量
     * @param array<string, int>    $envPruned 为守住 Windows 环境块上限而剔除的项（变量名 => 字节）
     */
    public function __construct(
        public readonly array $env,
        public readonly string $distRoot,
        public readonly string $phpxHome,
        public readonly string $iniDir,
        public readonly string $tpcEntry,
        public readonly string $msvcVersion,
        public readonly string $sdkVersion,
        public readonly array $envPruned = [],
    ) {
    }
}
