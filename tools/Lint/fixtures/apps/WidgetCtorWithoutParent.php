<?php
declare(strict_types=1);

namespace App;

use Drift\Widget\State;
use Drift\Widget\StatefulWidget;

/**
 * `app-subclass-ctor-parent` 的**反例**夹具：`Widget` 子类覆写构造器却**不调**
 * `parent::__construct()`。
 *
 * ★ 为什么值得单独一个反例：`Widget` 在构造器里建 `$mods`（`Modifier`）。漏调之后
 *   解释器侧只是"未初始化属性"（读成 null），而 **AOT 报的是**
 *   `The parameter object must be object, got null`，栈顶落在 `Widget::mods()` /
 *   `Element::syncBox()` —— 看不出是哪一层忘了调父构造器。2026-10-30 在
 *   `InspectorRoot`（第二个窗口的根部件）上实测踩过一次。
 *
 * ★ 不要"修好"这个文件：它是**反例**。真实代码在 `apps/` 下。
 */
final class WidgetCtorWithoutParent extends StatefulWidget
{
    public function __construct(private readonly int $count)
    {
        // ← 少了 `parent::__construct();`：`$mods` 从此是未初始化的 null
    }

    public function createState(): State
    {
        return new WidgetCtorState($this->count);
    }
}

/** 夹具配套：只为让反例是一个完整可编译的部件（`State::build()` 有默认实现，不必覆写） */
final class WidgetCtorState extends State
{
    public function __construct(private readonly int $count)
    {
    }

    /** 读一下字段，免得"未使用的属性"看起来像笔误 */
    public function count(): int
    {
        return $this->count;
    }
}
