<?php
declare(strict_types=1);

namespace App\Showcase\Pages;

use Drift\Geometry\EdgeInsets;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Card;
use Drift\Widget\Checkbox;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\SegmentedControl;
use Drift\Widget\Slider;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 页 28「禁用态 disabled()」—— 一个声明、四处消费（2026-10-29）。
 *
 * 演四件事，都要求**动手点**才看得出：
 *
 * 1. 禁用的按钮点不动，而它旁边的可用按钮照常累加（基线：同一条点击链）；
 * 2. `->disabled(false)` 是"恢复"，不是"声明过就永久禁用"；
 * 3. 禁用一个 `Column` ⇒ 里面的控件一起点不动（子树级）；
 * 4. 切换锁定后整块**变淡** —— 变淡是合成 alpha 折进去的，不是各控件自己调色。
 *
 * ★ 页面上写明 `enabled(false)` 与 `disabled()` 的关系：**同一个状态**（前者是后者按
 *   布尔取反的别名），不是两套并存的样式开关。2026-10-29 收敛掉了四份局部实现，
 *   连带裁决"禁用不进 Tab 序"（见 `REVIEW-2026-10-28.md` §21）。
 */
final class DisabledPage extends StatefulWidget
{
    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function createState(): State
    {
        return new DisabledPageState();
    }
}

final class DisabledPageState extends State
{
    private int $clicks = 0;

    /** 整块的锁：由下面那个 Checkbox 控制，用来现场看"变淡 + 点不动" */
    private bool $locked = false;

    public function initState(): void
    {
        // 值全是写死的常量，没有需要初始化的外部状态
    }

    public function build(BuildContext $ctx): Widget
    {
        return Card::make(
            Column::make()->spacing(14.0)->padding(EdgeInsets::all(20.0))->children([
                Text::make('禁用态 disabled()')->title(),
                Text::make('一个声明、四处消费：命中测试 / 焦点表 / 无障碍语义 / 绘制。')->caption(),
                Text::make('它住在修饰符上，所以对所有控件一次生效 —— 不需要每个控件各加一个字段。')->caption(),
                $this->buttonsRow(),
                Text::make('点上面三个按钮：第一个与第三个累加，第二个点不动。')->caption(),
                Text::make('已点击：' . $this->clicks . ' 次')->caption(),
                $this->lockRow(),
                $this->lockedBlock(),
                $this->enabledAliasRow(),
                Text::make('上一行三件用的是 enabled(false)，等价于 disabled()。')->caption(),
                Text::make('边界：ToggleBase / Slider / Segment 上的 enabled(false) 已经是 disabled() 的别名 —— 同一个状态、同一份实现。禁用的控件不进 Tab 序，也不吃指针。')->caption(),
            ])
        )->paddingAll(0.0);
    }

    private function buttonsRow(): Widget
    {
        return Row::make()->spacing(10.0)->children([
            Button::make('可用按钮')->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->clicks++;
                });
            }),
            Button::make('禁用按钮')->disabled()->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->clicks++;
                });
            }),
            Button::make('disabled(false)')->disabled(false)->onPressed(function (): void {
                $this->setState(function (): void {
                    $this->clicks++;
                });
            }),
        ]);
    }

    private function lockRow(): Widget
    {
        return Checkbox::make('锁定下面整块（看变淡 + 点不动）')
            ->checked($this->locked)
            ->onChanged(function (bool $on): void {
                $this->setState(function () use ($on): void {
                    $this->locked = $on;
                });
            });
    }

    /**
     * `enabled(false)` 等价于 `disabled()` 的三件（2026-10-29 收敛的验收面）。
     *
     * ★ 三者都声明了 `paintsDisabledLook()` ⇒ 换 `ColorToken::Muted`（**灰**），
     *   不叠框架的 0.38 alpha（**淡**）。与上面"锁定整块"对比着看：那块是淡、这里是灰 ——
     *   两种禁用外观同屏可见，且都没有二次衰减。
     */
    private function enabledAliasRow(): Widget
    {
        return Row::make()->spacing(18.0)->children([
            Checkbox::make('复选框')->checked(true)->enabled(false),
            Slider::make()->value(40.0)->width(120.0)->enabled(false),
            SegmentedControl::make(['日', '周'])->selected(0)->enabled(false),
        ]);
    }

    /**
     * 整块内容 —— 外层 `Column` 直接声明 `disabled($this->locked)`。
     *
     * ★ 禁用的是**容器**，里面的按钮一个字都没改：子树级语义由 `hitTest()` 的递归
     *   与 `FocusManager::walk()` 的 `$disabled` 传递共同保证。
     */
    private function lockedBlock(): Widget
    {
        return Card::make(
            Column::make()->spacing(8.0)->padding(EdgeInsets::all(12.0))->disabled($this->locked)->children([
                Text::make('这一块被容器统一禁用 —— 里面的按钮没有一处写了 disabled()')->caption(),
                Row::make()->spacing(10.0)->children([
                    Button::make('里面的按钮 A')->onPressed(function (): void {
                        $this->setState(function (): void {
                            $this->clicks++;
                        });
                    }),
                    Button::make('里面的按钮 B')->onPressed(function (): void {
                        $this->setState(function (): void {
                            $this->clicks++;
                        });
                    }),
                ]),
            ])
        )->paddingAll(0.0);
    }
}
