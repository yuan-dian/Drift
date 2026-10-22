<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\State;

use Drift\Widget\State;

/**
 * @internal 冒烟夹具：**后代 → 祖先**脏传播的外观测量点（`03` §5.2 性质 B）。
 *
 * 单遍 `settle()` 收不住这个方向：子 rebuild 造出的新子树在 build 里标脏了祖先，
 * 祖先要等**下一遍**才重建。多轮收敛循环（W2/S3）就是为它上的。
 *
 * `outerBuilds`/`innerBuilds` 是**外部可观测量**：`build()` 自己不读任何状态，
 * 所以每次 +1 都是真的发生过一次 rebuild（断言不会自证）。
 */
final class ChainProbe
{
    public int $outerBuilds = 0;
    public int $innerBuilds = 0;
    /** 置位后，下一次 Inner 的 build 会把 Outer 标脏（模拟"子 build 写祖先"） */
    public bool $markOuterOnInnerBuild = false;
    /** @internal 由 Inner 的 build 标脏 */
    public ?State $outerState = null;
}
