<?php
declare(strict_types=1);

namespace App;

/**
 * stale-capability-claim 的**反例**夹具：声明"缺"的符号其实都在，或探针写错。
 *
 * @capability-gap: class|App\CapGapBadHost 类已存在，声明过期
 * @capability-gap: method|App\CapGapBadHost|mono 方法已存在
 * @capability-gap: const|App\CapGapBadHost|OP_ARC 常量已存在
 * @capability-gap: widget|App\CapGapBadHost 未知探针类型
 * @capability-gap: class 探针分节数不足
 */
final class CapGapBadHost
{
    public const OP_ARC = 42;

    public function mono(): void
    {
    }
}
