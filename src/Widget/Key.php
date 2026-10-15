<?php
declare(strict_types=1);

namespace Drift\Widget;

/**
 * 控件的稳定身份（§12.3）。
 *
 * 没有 Key ⇒ Element 的 diff 身份由**位置**决定 —— `children([$a, $b])` 换成
 * `[$b, $a]` 就是两个新控件，`State` 不跟过去。
 * 给了 Key ⇒ 位置变了身份不变，`State` 跟着走（长列表重排、页面换槽都靠它）。
 *
 * @api
 */
final readonly class Key
{
    public function __construct(public string|int $value)
    {
    }

    /**
     * diff 的复用判据（§12.3；与 GUI 仓实测过的 `keyEqual` 同形）。
     *
     * - 双方都没有 ⇒ `true`（身份退化为位置，位置相同即复用）
     * - 一方有一方没有 ⇒ `false`
     * - 双方都有 ⇒ 值相等才算同一身份；**`1` 与 `'1'` 不等**（不做类型互转）
     */
    public static function same(?self $a, ?self $b): bool
    {
        if ($a === null || $b === null) {
            return $a === $b;
        }

        return $a->value === $b->value;
    }
}
