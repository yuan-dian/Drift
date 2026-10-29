<?php
declare(strict_types=1);

namespace App;

/**
 * stale-capability-claim 的**正例**夹具：声明"缺"的符号确实还缺，或压根没打标记。
 *
 * @capability-gap: class|Drift\Never\Exists 类还不存在，声明仍诚实
 * @capability-gap: method|App\CapGapGoodHost|missingMethod 方法还没有
 * @capability-gap: const|App\CapGapGoodHost|MISSING 常量还没有
 *
 * 这里写"框架从来没有 XXX 能力"只是自由中文，没有标记，规则不该碰它。
 */
final class CapGapGoodHost
{
    public const EXISTING = 1;

    public function present(): void
    {
    }
}
