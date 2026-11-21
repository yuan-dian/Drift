<?php
declare(strict_types=1);

namespace App\Showcase;

use Drift\App\AppScope;
use Drift\App\Application;
use Drift\Widget\Button;
use Drift\Widget\BuildContext;
use Drift\Widget\Column;
use Drift\Widget\Padding;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;

/**
 * **第二个真实窗口**（M3）：一个独立的工具窗，与主窗同进程。
 *
 * ## 它验的是什么（不是"再做一个演示页"）
 *
 * | 验证点 | 判据 |
 * |---|---|
 * | 两个 `Application` 实例共存 | 两个窗各自跑帧、各自焦点（点这个窗的输入框，主窗不受影响） |
 * | `AppScope` 真的共享 | 这里读到的"已登记窗口数"是 **2**（`enter()` 由 `attach()` 调） |
 * | 关闭语义 | 关这个窗主窗还在（`EventLoop` 的窗口表），关主窗它也还在 |
 *
 * ★ **不复用 `ShowcaseApplication`**：它带 34 个演示页，第二窗装它等于把整棵
 *   导航树再建一遍（内存翻倍、首帧慢），而这里要验的是**基础设施**不是页面。
 *   多窗口评审的判断也指向同一处：多窗口的第一消费者是"独立工具窗"，不是"第二个完整应用"。
 */
final class InspectorApplication extends Application
{
    public function __construct(AppScope $scope)
    {
        // ★ 共享同一个 `AppScope`：字体解析、任务池、IO 轮询器、主题都只有一份。
        //   两个窗口各建一个 scope 才是要避免的形态（字体解析两次、同一 socket
        //   被两个 IoPoller watch ⇒ 回调跑两次）。
        // ★ **不另存字段**：基类已有 `private readonly $scope`，同名的子类属性在
        //   AOT 编译期直接 Fatal（`property shadowing across inheritance`）。
        //   要用就走 `Application::scope()`。
        parent::__construct([], $scope);
    }

    public function buildRoot(): Widget
    {
        return new InspectorRoot($this->scope());
    }
}

/** @internal 同文件里的小部件（与 `ShowcaseRoot` / `ShowcaseState` 同一惯例） */
final class InspectorRoot extends StatefulWidget
{
    /**
     * ★ **必须调 `parent::__construct()`**：`Widget` 在构造器里建 `$mods`
     *   （`Modifier`）。不调 ⇒ 它是未初始化的 `null`，而 AOT 在 `Element::syncBox()`
     *   读 `mods()` 时报的是 **`The parameter object must be object, got null`** ——
     *   指向 `Widget::mods()`，看不出是哪一层忘了调父构造器。
     *   同 `ShowcaseApplication` 那个 `Application` 子类的坑。
     */
    public function __construct(private readonly AppScope $scope)
    {
        parent::__construct();
    }

    public function createState(): State
    {
        return new InspectorState($this->scope);
    }
}

/** @internal */
final class InspectorState extends State
{
    private int $count = 0;

    public function __construct(private readonly AppScope $scope)
    {
    }

    public function build(BuildContext $ctx): Widget
    {
        return Padding::make(
            Column::make()->children([
                Text::make('多窗口 · 检查器')->title(),
                Text::make(
                    '这个窗口和主窗在同一个进程里：字体只解析一次、一个任务池、'
                    . '一个 IO 轮询器、一份主题。它们只是两块画布和两条事件队列。'
                )->caption(),
                // ★ 这个数字来自共享的 `AppScope`（`Application::attach()` 里 `enter()`），
                //   所以它是"两个窗口确实共用一个 scope"的**运行期证据**，不是常量。
                Text::make('进程内已登记窗口：' . $this->scope->windows()),
                TextField::make()->label('本窗独立的输入框')->placeholder('在这里打字，主窗不受影响'),
                Text::make('本窗计数：' . $this->count),
                Button::make('计数 +1')->onPressed(function (): void {
                    // ★ 计数住在**本窗的 State** 上：点这里主窗的数字不动。
                    //   这是"窗口级状态留在 Application 侧"的可观察形式。
                    $this->setState(function (): void {
                        ++$this->count;
                    });
                }),
            ]),
        )->all(16);
    }
}
