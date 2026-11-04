<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Input;

use Drift\App\FocusManager;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\StatefulElement;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;
use Drift\Widget\Widget;

/**
 * 自动聚焦：焦点空着时落到声明 `autoFocus()` 的控件上。
 *
 * 钉四件事：空着才接管、只认第一个、有人聚焦就不抢、陷阱（模态）里优先于主树。
 * 另外两条是"声明失效"的边界：`skipFocus()` 与 `disabled()` 都排除在 Tab 序外，
 * 也就不参与自动聚焦。
 */
final class AutoFocusTest extends SmokeTestCase
{
    /** @param list<Widget> $fields */
    private function column(array $fields): Element
    {
        $root = Column::make()->spacing(8)->children($fields)->createElement();
        $root->mount(null, smokeContext());
        $root->effectiveBox()?->layout(BoxConstraints::loose(new Size(300.0, 200.0)));

        return $root;
    }

    private static function stateOf(?Element $element): ?TextFieldState
    {
        $state = $element instanceof StatefulElement ? $element->state() : null;

        return $state instanceof TextFieldState ? $state : null;
    }

    public function testTakesEmptyFocus(): void
    {
        $root = $this->column([
            TextField::make()->value('ab'),
            TextField::make()->value('cd')->autoFocus(),
        ]);

        $fm = new FocusManager();
        $fm->collect([$root]);

        $second = $fm->order()[1] ?? null;
        check(
            '自动聚焦 · 焦点空着 ⇒ 落到声明 autoFocus 的字段（并进编辑态）',
            $fm->focused() === $second && self::stateOf($second)?->isEditing() === true,
            'focused=' . ($fm->focused() === $second ? 'second' : 'other') . ' n=' . \count($fm->order()),
        );

        $this->flushChecks();
    }

    public function testFirstDeclarationWins(): void
    {
        $root = $this->column([
            TextField::make()->value('ab')->autoFocus(),
            TextField::make()->value('cd')->autoFocus(),
        ]);

        $fm = new FocusManager();
        $fm->collect([$root]);

        check(
            '自动聚焦 · 两个都声明 ⇒ 文档序第一个赢（不是最后一个）',
            $fm->focused() === ($fm->order()[0] ?? null),
            'focused=' . ($fm->focused() === ($fm->order()[0] ?? null) ? 'first' : 'other'),
        );

        $this->flushChecks();
    }

    public function testDoesNotStealExistingFocus(): void
    {
        $root = $this->column([
            TextField::make()->value('ab')->autoFocus(),
            TextField::make()->value('cd'),
        ]);

        $fm = new FocusManager();
        $fm->collect([$root]);

        $second = $fm->order()[1] ?? null;
        $fm->focus($second);

        // 换一份等价树触发重收（门控按 `contentVersion` 放行）
        $root->update(Column::make()->spacing(8)->children([
            TextField::make()->value('ab')->autoFocus(),
            TextField::make()->value('cd'),
        ]));
        $fm->collect([$root]);

        check(
            '自动聚焦 · 已经有人聚焦 ⇒ 不抢（重收后焦点仍在原处）',
            $fm->focused() === $second,
            'focused=' . ($fm->focused() === $second ? 'second' : 'moved'),
        );

        $this->flushChecks();
    }

    public function testTrapWinsOverMainTree(): void
    {
        $root = $this->column([TextField::make()->value('page')->autoFocus()]);
        $modal = Column::make()->children([TextField::make()->value('dialog')->autoFocus()])->createElement();
        $modal->mount(null, smokeContext());

        $fm = new FocusManager();
        $fm->collect([$root, $modal], [$modal]);

        $inModal = $fm->order()[0] ?? null;
        check(
            '自动聚焦 · 模态开着 ⇒ 焦点落进对话框，不落到背后的页面',
            $fm->focused() === $inModal && self::stateOf($inModal)?->text() === 'dialog',
            'text=' . (self::stateOf($fm->focused())?->text() ?? 'null'),
        );

        // 关掉模态：旧焦点不在主树的 byId 里 ⇒ 清空后由主树的声明接手
        $fm->collect([$root]);

        check(
            '自动聚焦 · 模态关掉 ⇒ 焦点回到主树的声明者',
            self::stateOf($fm->focused())?->text() === 'page',
            'text=' . (self::stateOf($fm->focused())?->text() ?? 'null'),
        );

        $this->flushChecks();
    }

    public function testSkipAndDisabledAreExcluded(): void
    {
        $skipped = $this->column([
            Column::make()->skipFocus()->children([TextField::make()->value('skip')->autoFocus()]),
        ]);
        $fmSkip = new FocusManager();
        $fmSkip->collect([$skipped]);

        check(
            '自动聚焦 · skipFocus 的子树不进 Tab 序 ⇒ 声明也不生效',
            $fmSkip->focused() === null && \count($fmSkip->order()) === 0,
            'focused=' . ($fmSkip->focused() === null ? 'null' : 'set'),
        );

        $off = $this->column([Button::make('off')->autoFocus()->disabled()]);
        $fmOff = new FocusManager();
        $fmOff->collect([$off]);

        check(
            '自动聚焦 · disabled 的控件不进焦点表 ⇒ 声明也不生效',
            $fmOff->focused() === null && \count($fmOff->order()) === 0,
            'focused=' . ($fmOff->focused() === null ? 'null' : 'set'),
        );

        $this->flushChecks();
    }
}
