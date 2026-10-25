<?php
declare(strict_types=1);

namespace App\Tutorial\Stages;

use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * ② 计数器 —— 第一个有状态的控件。
 *
 * ## 三段式：Widget（不可变）+ State（可变）+ `setState()`
 *
 * ```php
 * final class CounterStage extends StatefulWidget        // ① 声明"我有状态"
 * final class CounterState extends State                 // ② 状态住这里（可变字段）
 * public function createState(): State                   // ③ 造出对应的 State
 * ```
 *
 * ★ **Widget 是不可变的**：`$count` 绝不放在 `CounterStage` 上 —— 每次重建
 *   都会造一个新的 Widget 实例，放那儿等于每帧清零。这也是为什么
 *   `apps/showcase` 的页面几乎都要写 `State`。
 *
 * ★ **`setState()` 只标脏**：闭包在**当帧**跑完，但重建发生在**帧末**
 *   （§5.2 裁决 3 的重入安全）⇒ 回调里连点十次只重建一次，
 *   "build 期 setState 触发重入"这类问题在结构上不存在。
 */
final class CounterStage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    #[\Override]
    public function createState(): State
    {
        return new CounterState();
    }
}

final class CounterState extends State
{
    /** ★ 状态就住这里。`int` 字段带默认值 = 初始状态 */
    private int $count = 0;

    #[\Override]
    public function build(BuildContext $ctx): Widget
    {
        return Column::make()->spacing(8.0)->children([
            Text::make('② 计数器')->heading(),
            Text::make('状态住 State；改状态一律走 setState() —— 只标脏，帧末统一重建。')->caption(),

            Row::make()->spacing(8.0)->children([
                Button::make('−1')->onPressed(function (): void {
                    $this->setState(function (): void {
                        $this->count--;
                    });
                }),
                Button::make('+1')->variant(ButtonVariant::Primary)->onPressed(function (): void {
                    $this->setState(function (): void {
                        $this->count++;
                    });
                }),
                Spacer::make(),
                Text::make('count = ' . $this->count)->body(),
            ]),
        ]);
    }
}
