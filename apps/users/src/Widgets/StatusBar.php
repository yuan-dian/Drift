<?php
declare(strict_types=1);

namespace App\Users\Widgets;

use Drift\State\Subject;
use Drift\State\SubscribingWidget;
use Drift\Widget\BuildContext;
use Drift\Widget\Row;
use Drift\Widget\Spacer;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 状态栏（§A.2.11）：**专职订阅者** —— 只订阅 store 的一行文本。
 *
 * ★ `SubscribingWidget` 是**空标记接口**（`src/State/SubscribingWidget.php`）——
 *   框架用它认出"这个控件自己管订阅"（订阅袋由 `State::watch()` 记，dispose 统一退）。
 *   源的读取方法（`getSource()` / `getSelect()`）是控件自己的，不是接口要求。
 */
final class StatusBar extends StatefulWidget implements SubscribingWidget
{
    private ?Subject $source = null;

    /** @var null|callable(Subject): mixed */
    private $select = null;

    public function __construct()
    {
        parent::__construct();
    }

    public static function make(): self
    {
        return new self();
    }

    public function source(Subject $s): self
    {
        $this->mutate();
        $this->source = $s;

        return $this;
    }

    public function select(callable $f): self
    {
        $this->mutate();
        $this->select = $f;

        return $this;
    }

    public function createState(): State
    {
        return new StatusBarState();
    }

    public function getSource(): ?Subject
    {
        return $this->source;
    }

    public function getSelect(): ?callable
    {
        return $this->select;
    }
}

final class StatusBarState extends State
{
    private string $text = '';

    public function initState(): void
    {
        $w = $this->widget();
        if ($w->getSource() === null || $w->getSelect() === null) {
            return;
        }

        // ★ 订阅袋：dispose() 基类统一全退（§11.3 RT-1）
        $this->watch($w->getSource(), function (): void {
            $v = ($this->widget()->getSelect())($this->widget()->getSource());
            if ($v === $this->text) {
                return; // ★ 相等门控
            }

            $this->setState(function () use ($v): void {
                $this->text = (string) $v;
            });
        });
    }

    public function build(BuildContext $ctx): Widget
    {
        return Row::make()->children([
            Text::make($this->text)->caption(),
            Spacer::make(),
        ]);
    }
}
