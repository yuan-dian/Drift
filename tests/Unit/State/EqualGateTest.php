<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\State;

use Drift\Dev\Smoke\State\HoverWidget;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * **"设同值不重建"的标准写法**：门控写在调用点，用 `if` 包住 `setState()`。
 *
 * ★★ 这条用例的前身是 `CellTest`（2026-11-02 删掉 `Cell` 之后重写）。删掉的是
 *   一个只省两行样板的包装类，**留下的是一条必须继续成立的性质**：同值写不得
 *   触发 `build()`。判据用 `$builds` 这个外部可观测量 —— `build()` 自己不读那个
 *   值，所以"门控漏了"会让 `builds` 增长，断言立刻红；若只看 State 内部字段，
 *   漏了比较也照样绿（自证）。
 *
 * ★ 为什么这条性质值得单独一个用例而不是散在各控件里：它是"高频 UI 态"的
 *   唯一防泄漏手段（鼠标在行间移动会反复触发同一行），而漏写的症状只是**慢**
 *   —— 没有任何行为差异，重建次数才是唯一可断言的形式。
 */
final class EqualGateTest extends SmokeTestCase
{
    public function testSameValueDoesNotRebuild(): void
    {
        $element = (new HoverWidget())->createElement();
        $element->mount(null, smokeContext());
        $state = $element->state();

        check('State · initState 只跑一次（重复挂载不重复订阅）', $state->inits === 1, '实际 ' . $state->inits);

        $state->onRowHover(2);
        check('State · 换值 ⇒ 标脏', $state->needsBuild(), '没有标脏');

        $element->rebuild();
        $state->onRowHover(2);   // 同一行
        check('State · 设同值不标脏（`if` 门控在调用点）', !$state->needsBuild(), '同值也标了脏');

        $state->onRowHover(3);
        check('State · 换新值 ⇒ 再标脏', $state->needsBuild(), '换值没标脏');

        $element->unmount();

        $this->flushChecks();
    }
}
