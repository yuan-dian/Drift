<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\App\Application;
use Drift\App\OverlayEntry;
use Drift\Widget\BuildContext;
use Drift\Widget\Element;
use Drift\Widget\Option;
use Drift\Widget\Select;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\Widget;

/**
 * `Select` 的**受控值可变**夹具（2026-10-24：三态 / 方向键 / 跨树重建）。
 *
 * ★ 为什么另起一个而不是改 `SelectApplication`：那个夹具的 30 多条断言全靠固定的
 *   孩子下标（`findButtonN()` 的 #0/#1/#2 口径），塞进"可变选项表"会把那些下标
 *   全部变成动态的 —— 得不偿失。
 * ★ 受控值住 `State`（`setState` 只标脏、帧末统一重建），与真应用逐字同形。
 *   **不要**用 `root()->update(...)` 模拟应用改状态（那是直接改树，不是应用路径）。
 *
 * @internal 测试夹具
 */
final class SelectStateApplication extends Application
{
    public function buildRoot(): Widget
    {
        return new SelectStateHost();
    }

    public function host(): ?SelectStateHostState
    {
        $root = $this->root();
        if (!$root instanceof \Drift\Widget\StatefulElement) {
            return null;
        }

        $state = $root->state();

        return $state instanceof SelectStateHostState ? $state : null;
    }

    /** 面板里恰好有几个候选按钮（三态时应当为 0） */
    public function optionButtons(): array
    {
        $out = [];
        $this->walkPanel($this->topEntry()?->element, function (Element $e) use (&$out): void {
            $w = $e->widget();
            if ($w instanceof \Drift\Widget\Button) {
                $out[] = $w;
            }
        });

        return $out;
    }

    public function overlayCount(): int
    {
        return $this->overlays()->count();
    }

    public function topEntry(): ?OverlayEntry
    {
        return $this->overlays()->top();
    }

    /** 面板里所有 `Text` 的字（三态行的判据） */
    public function panelTexts(): array
    {
        $out = [];
        $this->walkPanel($this->topEntry()?->element, function (Element $e) use (&$out): void {
            $w = $e->widget();
            if ($w instanceof \Drift\Widget\Text) {
                $out[] = $w->content();
            }
        });

        return $out;
    }

    private function walkPanel(?Element $root, callable $visit): void
    {
        if ($root === null) {
            return;
        }

        $visit($root);
        $root->visitChildren(function (Element $child) use ($visit): void {
            $this->walkPanel($child, $visit);
        });
    }
}

/** @internal 见 `SelectStateApplication` */
final class SelectStateHost extends StatefulWidget
{
    public function createState(): State
    {
        return new SelectStateHostState();
    }
}

/** @internal 见 `SelectStateApplication` */
final class SelectStateHostState extends State
{
    /** @var list<string> `onChanged` 收到的值（顺序 = 触发顺序） */
    public array $changed = [];

    /** `onOpen` 被调了几次 */
    public int $openCount = 0;

    public bool $loading = false;

    public string $error = '';

    public string $emptyText = '没有可选项';

    /** @var list<Option> */
    public array $options = [];

    #[\Override]
    public function initState(): void
    {
        $this->options = [
            Option::make('北京市', '110000'),
            Option::make('上海市', '310000'),
            Option::make('广州市', '440100'),
        ];
    }

    public function build(BuildContext $ctx): Widget
    {
        return Select::make($this->options)
            ->value('310000')
            ->loading($this->loading)
            ->errorText($this->error)
            ->emptyText($this->emptyText)
            ->onOpen(function (): void {
                $this->setState(function (): void {
                    $this->openCount++;
                });
            })
            ->onChanged(function (string $value): void {
                $this->setState(function () use ($value): void {
                    $this->changed[] = $value;
                });
            });
    }

    public function setLoading(bool $on): void
    {
        $this->setState(function () use ($on): void {
            $this->loading = $on;
        });
    }

    public function setError(string $text): void
    {
        $this->setState(function () use ($text): void {
            $this->error = $text;
        });
    }

    /** @param list<Option> $options */
    public function setOptions(array $options): void
    {
        $this->setState(function () use ($options): void {
            $this->options = $options;
        });
    }
}
