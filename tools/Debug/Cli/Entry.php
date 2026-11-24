<?php
declare(strict_types=1);

namespace Drift\Dev\Debug\Cli;

/**
 * 一个调试出口的元数据。动词、脚本、摘要只在这里声明一次。
 *
 * `driver` 表示它走共享驱动词表（`Commands::set()` 有 schema）；`protocol` 的两个
 * 出口（控制面、宿主进程）认的是自己的参数，不进 schema —— 但它们仍然要在
 * 注册表里，否则「有哪些出口」这件事又多了一份清单。
 */
final class Entry
{
    public function __construct(
        public readonly string $verb,
        public readonly string $script,
        public readonly string $about,
        public readonly bool $driver,
    ) {
    }

    /** 绝对路径；出口脚本不保证以仓库根为 cwd。 */
    public function path(): string
    {
        return \dirname(__DIR__, 2) . '/' . $this->script;
    }
}
