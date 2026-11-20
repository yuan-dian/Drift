<?php
declare(strict_types=1);

namespace App\Showcase\Routes\Snackbar;

use Drift\Reducer\ReducerModel;

/**
 * Snackbar 页的状态快照。
 *
 * 只装真正会变、且要显示出来的东西：`undone`（「撤销」被点了几次，证明动作
 * 真的接上了）。不可变 + 构造参数给初值，因此不需要 `xxxNow()` 这类惰性哨兵
 * ——属性默认值必须是常量表达式那条 AOT 约束在这里天然不成立。
 *
 * @api
 */
final readonly class SnackbarModel implements ReducerModel
{
    public function __construct(
        public int $undone = 0,
    ) {
    }
}
