<?php
declare(strict_types=1);

namespace Drift\Core;

/**
 * 夹具：**该响**。D3 收口之后不许再长出这种"一行转发"的静态门面。
 *
 * 两个方法覆盖两种形态：带 `return` 的读、不带 `return` 的写。文案里还要能
 * 报出**转发到了哪个方法**（`rebuiltCount` / `bumpPaintEpoch`）—— 提示不能只说
 * "这有个门面"，得让人知道该改成哪一句。
 */
final class PipelineFacadeBad
{
    public static function rebuiltCount(): int
    {
        return Pipeline::current()->rebuiltCount();
    }

    public static function markPainted(): void
    {
        Pipeline::current()->bumpPaintEpoch();
    }
}
