<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Async\AsyncValue;
use Drift\Async\Future;
use Drift\State\AsyncCommand;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：根是一个持有 `AsyncCommand` 的页面。
 *
 * ★ 存在的理由：命令的判据全在"四态怎么流转 + 请求怎么被管"，而那只有**真挂树**
 *   （`run()` 标脏 → 帧内结算 → settle 重建 → `build()` 再读到新值）才走得到。
 *   纯单测只能验 `AsyncValue` 本身（已在 `Unit/Async`）。
 *
 * ★ `$snapshots` 是观测面：`build()` 每次被调用都记一笔，测试读最后一笔。
 *   比"从树上找 Text 再比字符串"稳 —— 后者依赖布局 / 裁剪，与语义无关。
 *
 * ★ 命令**不在 `initState()` 里自动 `run()`**：`idle` 是四态之一，要能被测到。
 *   发起由测试驱动（`$app->state()->command()->run(...)`）。
 */
final class AsyncCommandApplication extends Application
{
    /** @var list<AsyncValue> build() 每次读到的值（按时间正序） */
    public array $snapshots = [];

    /**
     * @param \Closure(mixed): Future $task 组合根接线："怎么发起一次请求"
     * @param null|\Closure(mixed, mixed): mixed $combine 追加时的合并规则
     *        （`append()` 用；`null` ⇒ 这个命令只能 `run()`）
     */
    public function __construct(private \Closure $task, private ?\Closure $combine = null)
    {
        // ★ 必须调父构造器：基类在构造器里建七个拆分片（闸门
        //   `AppSubclassCtorParent` 守着；漏了 AOT 真机 exit=255）。
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return AsyncCommandRoot::make(
            $this->task,
            function (AsyncValue $value): void {
                $this->snapshots[] = $value;
            },
            $this->combine,
        );
    }

    /** 根页面的 State（测试要拿它驱动命令） */
    public function pageState(): ?AsyncCommandRootState
    {
        $element = $this->root();

        return $element instanceof \Drift\Widget\StatefulElement
            && $element->state() instanceof AsyncCommandRootState
            ? $element->state()
            : null;
    }

    public function title(): string
    {
        return 'AsyncCommand';
    }
}

/** @internal 见 `AsyncCommandApplication` */
final class AsyncCommandRoot extends StatefulWidget
{
    /**
     * @param \Closure(mixed): Future $task
     * @param \Closure(AsyncValue): void $observe
     * @param null|\Closure(mixed, mixed): mixed $combine 追加时的合并规则
     */
    public function __construct(
        private \Closure $task,
        private \Closure $observe,
        private ?\Closure $combine = null,
    ) {
        parent::__construct();
    }

    /**
     * @param \Closure(mixed): Future $task
     * @param \Closure(AsyncValue): void $observe
     * @param null|\Closure(mixed, mixed): mixed $combine
     */
    public static function make(\Closure $task, \Closure $observe, ?\Closure $combine = null): self
    {
        return new self($task, $observe, $combine);
    }

    public function createState(): State
    {
        return new AsyncCommandRootState($this->task, $this->observe, $this->combine);
    }
}

/** @internal 见 `AsyncCommandApplication` */
final class AsyncCommandRootState extends State
{
    private AsyncCommand $command;

    /**
     * @param \Closure(mixed): Future $task
     * @param \Closure(AsyncValue): void $observe
     * @param null|\Closure(mixed, mixed): mixed $combine
     */
    public function __construct(
        private \Closure $task,
        private \Closure $observe,
        private ?\Closure $combine = null,
    ) {
    }

    public function initState(): void
    {
        // ★ 走 `State::asyncCommand()`（页面侧唯一入口）：订阅 + 卸载时自动停。
        $this->command = $this->asyncCommand($this->task, $this->combine);
    }

    public function command(): AsyncCommand
    {
        return $this->command;
    }

    public function build(BuildContext $ctx): Widget
    {
        $value = $this->command->value();

        $observe = $this->observe;
        $observe($value);

        // 四态写全（`fold` 缺键当场抛，测试里那等于红）
        return $value->fold([
            'idle' => static fn(AsyncValue $v): Widget => Text::make('idle'),
            'loading' => static fn(AsyncValue $v): Widget => Text::make('loading'),
            'error' => static fn(AsyncValue $v): Widget => Text::make('error'),
            'data' => static fn(AsyncValue $v): Widget => Text::make('data'),
        ]);
    }
}
