<?php
declare(strict_types=1);

namespace App\Tutorial\Stages;

use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\Spinner;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * ④ 异步 —— 唯一需要**组合根接线**的一段。
 *
 * ## 为什么这一段要接线，前三段不要
 *
 * "延时 / 取数"的能力住 `Application` 上（`after()` / `taskHost()`），
 * 而控件层**够不到** `Application`：
 *
 * ★★★ `$this->application()` **这个方法不存在**（§4.3 禁 `Widget → App`）。
 *   而 tpc **不校验方法存在性** ⇒ 写错了照样编译通过、smoke 也全绿、
 *   `Errors.log` 里什么都没有，**真机一点就崩**。这条在示例台上真的咬过一次
 *   （`SnackbarPage`，2026-10-23）。
 *
 * 合规路径只有两条：
 *   ① **组合根接线** —— `Application::buildRoot()` 把自己的方法包成闭包递下去
 *      （本段用的这条，见 `TutorialApplication::buildRoot()`）；
 *   ② **框架托管** —— 用框架已经接好线的声明式缝（`overlayPanel()` / `tooltip()`，
 *      即上一段）。
 *
 * ## 这就是真应用的形状
 *
 * `loading → 结果到达` 两态，中间那条线由**异步**提供：
 * 本例用 `after()`（定时器）；真异步（IO / 子进程）换成 `Job` + `TaskHost`，
 * **形状完全一样** —— 发出去时 `setState(loading)`，`onDone` 里 `setState(ready)`。
 *
 * ★ 注意 `after()` 的回调是在 **UI 线程**上跑的（所以能直接 `setState`）；
 *   `Job::run()` 才是后台线程，它**不许碰** Widget / Element / State。
 */
final class AsyncStage extends StatefulWidget
{
    /**
     * @param \Closure(int, \Closure): void $after 组合根递下来的"延时回调"能力
     *
     * ★ 把能力**经构造器**传给 `State`（而不是让 State 反查 `$this->widget()`），
     *   是因为 `after` 是**稳定的能力**、不是数据：同一个 `Application` 上
     *   每次 `buildRoot()` 递下来的闭包行为完全相同 ⇒ 即使 Widget 被替换、
     *   State 留着旧闭包，也不会有任何行为差异。
     *   ★ 若递下来的是**数据**（会变的值），就必须在 `didUpdateWidget()` 里同步。
     */
    public function __construct(private readonly \Closure $after)
    {
        parent::__construct();
    }

    public static function make(\Closure $after): self
    {
        return new self($after);
    }

    #[\Override]
    public function createState(): State
    {
        return new AsyncState($this->after);
    }
}

final class AsyncState extends State
{
    private bool $loading = false;

    private string $result = '（还没加载）';

    /** @param \Closure(int, \Closure): void $after */
    public function __construct(private readonly \Closure $after)
    {
    }

    #[\Override]
    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(8.0)->children([
            Text::make('④ 异步（唯一需要接线的一段）')->heading(),
            Text::make('loading → 结果到达。能力由 buildRoot() 递进来 —— 控件层够不到 Application。')
                ->caption(),
            Text::make('★ $this->application() 这个方法并不存在（§4.3），而 tpc 不校验它。')
                ->caption(),

            Row::make()->spacing(8.0)->children([
                Button::make('开始加载')
                    ->variant(ButtonVariant::Primary)
                    ->onPressed(function (): void {
                        $this->load();
                    }),
                Spacer::make(),
            ]),

            $this->statusLine(),
        ]);
    }

    /** 两态一行：加载中转圈，否则显示结果 */
    private function statusLine(): Widget
    {
        if ($this->loading) {
            return Row::make()->spacing(8.0)->children([
                Spinner::make()->diameter(16.0),
                Text::make('加载中…（700ms 后结果到达）')->caption(),
            ]);
        }

        return Text::make($this->result)->body();
    }

    private function load(): void
    {
        $this->setState(function (): void {
            $this->loading = true;
        });

        $after = $this->after;
        $after(700, function (): void {
            // ★ 回调在 UI 线程 ⇒ 可以直接 setState。
            //   真异步换 `Job` + `TaskHost`：`Job::run()` 在后台线程，
            //   它只许算数据，碰 Widget/Element/State 会破坏单线程亲和（§5.3）。
            $this->setState(function (): void {
                $this->loading = false;
                $this->result = '结果到了：42（700ms 后送达）';
            });
        });
    }
}
