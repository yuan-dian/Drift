<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use App\Showcase\Pages\SnackbarPage;
use Drift\App\Frame;
use Drift\Dev\Smoke\Frame\RecordingSurface;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\LayoutTolerance;
use Drift\Text\DeterministicFontManager;
use Drift\Text\DeterministicMetricsProvider;
use Drift\Text\DeterministicShaper;
use Drift\Theme\ThemeData;
use Drift\Widget\BuildContext;
use Drift\Widget\Button;
use Drift\Widget\Element;
use Drift\Widget\Snackbar;
use Drift\Widget\Text;

/**
 * Snackbar 页走新架构（`ReducerState` + `update`/`apply`）后，**行为必须与迁移前等价**。
 *
 * 判据落在真实交互链上，而不是"能不能画出东西"：
 *   点按钮 → `dispatch` → `update()` 出 effect → `apply()` 调能力束 → 通知入队；
 *   点通知上的动作 → 回主路 → 新 model → 文本跟着变。
 *
 * ★ 这条必须真的点一次「撤销」：动作回调若没接回主路，画面照样能画，
 *   只有点过才发现计数不动 —— 静态快照覆盖不到这条交互路径。
 */
final class SnackbarReducerPageTest extends SmokeTestCase
{
    /** @var list<Snackbar> */
    private array $notified = [];

    public function testInteractionsFlowThroughUpdateAndApply(): void
    {
        $root = $this->mount();

        check('Snackbar 路由 · 初始渲染显示「已撤销 0 次」', $this->hasText($root, '已撤销 0 次'));

        $this->click($root, '删除一条');
        check('Snackbar 路由 · 点删除弹出一条通知', 1 === \count($this->notified));

        $snackbar = $this->notified[0] ?? null;
        check(
            'Snackbar 路由 · 通知文案来自 effect',
            $snackbar instanceof Snackbar && '已删除「草稿.docx」' === $snackbar->messageValue(),
            $snackbar instanceof Snackbar ? $snackbar->messageValue() : '未收到通知',
        );
        check(
            'Snackbar 路由 · 带撤销动作',
            $snackbar instanceof Snackbar && '撤销' === $snackbar->actionLabelValue(),
        );

        // 动作回调必须重新走 update()：就地改 model 会让状态出现第二条写路径。
        $handler = $snackbar instanceof Snackbar ? $snackbar->actionHandlerValue() : null;
        check('Snackbar 路由 · 动作回调已接上', $handler !== null);
        $handler?->__invoke();
        $root->settle();
        check(
            'Snackbar 路由 · 点撤销后计数变 1（回调回主路）',
            $this->hasText($root, '已撤销 1 次'),
        );

        $this->click($root, '推三条');
        check('Snackbar 路由 · 推三条一次入队三条', 4 === \count($this->notified));
        check(
            'Snackbar 路由 · FIFO 顺序保持',
            '第 1 条：立刻显示' === ($this->notified[1]->messageValue() ?? '')
            && '第 3 条：排在最后' === ($this->notified[3]->messageValue() ?? ''),
        );

        $this->click($root, '弹一条错误');
        $last = $this->notified[4] ?? null;
        check(
            'Snackbar 路由 · duration(0) 落到框架（不自动消失）',
            $last instanceof Snackbar && 0 === $last->durationValue(),
            $last instanceof Snackbar ? (string) $last->durationValue() : '未收到通知',
        );

        $this->flushChecks();
    }

    private function hasText(Element $root, string $content): bool
    {
        return findElement(
            $root,
            static fn (Element $e): bool => $e->widget() instanceof Text && $e->widget()->content() === $content,
        ) !== null;
    }

    private function click(Element $root, string $label): void
    {
        $button = findElement(
            $root,
            static fn (Element $e): bool => $e->widget() instanceof Button && $e->widget()->label() === $label,
        );
        check('Snackbar 路由 · 找到按钮 ' . $label, $button !== null);
        $widget = $button?->widget();
        if ($widget instanceof Button) {
            $widget->pressedHandler()?->__invoke();
        }
        $root->settle();
    }

    private function mount(): Element
    {
        Errors::reset();
        LayoutTolerance::reset();
        $this->notified = [];

        $notified = &$this->notified;
        $notify = static function (Snackbar $snackbar) use (&$notified): void {
            $notified[] = $snackbar;
        };

        $context = new BuildContext(
            940.0,
            600.0,
            ThemeData::light(),
            new DeterministicMetricsProvider(
                DeterministicFontManager::standard()->collection(),
                new DeterministicShaper(),
            ),
        );

        $root = (new SnackbarPage($notify))->createElement();
        $root->mount(null, $context);
        LayoutTolerance::withinWindow(static function () use ($root): void {
            (new Frame(new RecordingSurface()))->run(
                $root,
                BoxConstraints::tight(new Size(940.0, 600.0)),
                0.0,
            );
        });

        return $root;
    }
}
