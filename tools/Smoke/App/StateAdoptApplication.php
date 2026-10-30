<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\Widget\BuildContext;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * @internal 冒烟夹具：验证「覆写 `didUpdateWidget()` **漏调** `parent::` 也能读到
 *   新描述」（2026-10-30 `State::adoptWidget()` 的判据）。
 *
 * ## 为什么需要它（不能只靠现有页面）
 *
 * 本仓四处覆写 `didUpdateWidget()` 的地方**全都老实写了** `parent::didUpdateWidget()`
 * —— 所以既有测试永远覆盖不到"漏写"这一支。而"漏写"正是这条契约唯一的失效形态：
 * `$this->widget` 停在旧参数 ⇒ 读到旧值 ⇒ **不报错**（典型症状是"改了筛选条件，
 * 结果没变"）。
 *
 * ⇒ 夹具里 {@see ProbeState::didUpdateWidget()} **故意不调 parent**，并只从
 *   `$this->widget()`（框架落的那份）读值。修复前读到 `'a'`，修复后读到 `'b'`。
 *
 * ## 三层的用意
 *
 * - {@see ProbeHost} / {@see ProbeHostState}：能在帧间改参数（外面拿不到 State 引用，
 *   所以用 `onReady` 把 State 交出去）；
 * - {@see ProbeWidget}：**类型不变、参数变** —— 这是 `Element::update()` 而非
 *   `createElement()` 的路径，也是 `didUpdateWidget()` 唯一会被调用的路径；
 * - {@see ProbeState}：漏调 parent 的覆写者。
 */
final class StateAdoptApplication extends Application
{
    /** 观测面：host State（测试用它改参数） */
    public ?ProbeHostState $host = null;

    public function buildRoot(): Widget
    {
        return ProbeHost::make(function (ProbeHostState $state): void {
            $this->host = $state;
        });
    }

    public function title(): string
    {
        return 'StateAdopt';
    }
}

final class ProbeHost extends StatefulWidget
{
    /** @param \Closure(ProbeHostState): void $onReady */
    public function __construct(private \Closure $onReady)
    {
        parent::__construct();
    }

    /** @param \Closure(ProbeHostState): void $onReady */
    public static function make(\Closure $onReady): self
    {
        return new self($onReady);
    }

    public function onReady(): \Closure
    {
        return $this->onReady;
    }

    public function createState(): State
    {
        return new ProbeHostState($this->onReady);
    }
}

final class ProbeHostState extends State
{
    /** 被子层读到的参数（测试改它 ⇒ 子 Widget 换新描述） */
    public string $inner = 'a';

    /** @param \Closure(ProbeHostState): void $onReady */
    public function __construct(private \Closure $onReady)
    {
    }

    public function initState(): void
    {
        ($this->onReady)($this);
    }

    /** 测试入口：改参数并标脏（下一帧 settle 时重建子树） */
    public function change(string $inner): void
    {
        $this->setState(function () use ($inner): void {
            $this->inner = $inner;
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return ProbeWidget::make($this->inner);
    }
}

final class ProbeWidget extends StatefulWidget
{
    /** 观测面：最近一次 `createState()` 造出来的 State（element update 不会换它） */
    public static ?ProbeState $last = null;

    public function __construct(private string $value)
    {
        parent::__construct();
    }

    public static function make(string $value): self
    {
        return new self($value);
    }

    public function value(): string
    {
        return $this->value;
    }

    public function createState(): State
    {
        $state = new ProbeState();
        self::$last = $state;

        return $state;
    }
}

final class ProbeState extends State
{
    /** `didUpdateWidget()` 里从 **`$this->widget()`** 读到的值（不是从形参读） */
    public ?string $seenFromField = null;

    public int $updates = 0;

    /**
     * ★★ **故意不调** `parent::didUpdateWidget($widget)`。模拟开发者漏写。
     *
     * 2026-10-30 之前：`$this->widget` 停在旧描述 ⇒ `$seenFromField` 是 `'a'`。
     * 现在：`StatefulElement` 在调本方法**之前**已用 `adoptWidget()` 落好 ⇒ `'b'`。
     */
    public function didUpdateWidget(Widget $widget): void
    {
        ++$this->updates;

        $current = $this->widget();
        $this->seenFromField = $current instanceof ProbeWidget ? $current->value() : null;
    }

    public function build(BuildContext $ctx): Widget
    {
        $current = $this->widget();

        return Text::make($current instanceof ProbeWidget ? $current->value() : '?');
    }
}
