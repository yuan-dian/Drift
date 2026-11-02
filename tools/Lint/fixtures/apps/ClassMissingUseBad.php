<?php
declare(strict_types=1);

namespace App;

/**
 * 夹具：`new` / `::` / `catch` 三处都用了 `Drift\PublicThing` 的短名、却没 use
 * ⇒ 解析全部落到 `App\PublicThing`。
 *
 * 三处的后果不一样，但**加载时都不报错**：
 *   - `new` ⇒ 运行期 `class not found`（真事故：watermark 的结果图下载）
 *   - `X::` ⇒ 运行期 `class not found`（`X::class` 更糟：静默返回字符串）
 *   - `catch` ⇒ 连错都不抛，这条 catch **永远不命中**
 */
final class ClassMissingUseBad
{
    public function probe(): string
    {
        $thing = new PublicThing();
        $marker = PublicThing::marker();

        try {
            $thing->marker();
        } catch (PublicThing $e) {
            return $e->getMessage();
        }

        return $marker;
    }
}
