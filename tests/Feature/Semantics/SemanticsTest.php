<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Semantics;

use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\SemanticsRole;
use Drift\Widget\Button;
use Drift\Widget\Column;
use Drift\Widget\Element;
use Drift\Widget\LazyColumn;
use Drift\Widget\Modal;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\Widget;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 语义树（§9 无障碍 / M5）。
 *
 * 这组验 SemanticsBuilder 产出的"语义树是数据 ⇒ 可序列化 ⇒ 可断言"：
 * 加性（没声明角色的应用零成本）、按角色找控件、焦点标记、几何来自渲染树、
 * hitTest 取最深、Modal dialog 角色、越界空节点、序列化、非 range 区间、
 * 虚拟列表也是 scroll 区域。
 *
 * $lay() 复用 smoke 的挂载+布局+建树闭包（几何来自渲染树 ⇒ 必须先布局再建树）。
 */
final class SemanticsTest extends SmokeTestCase
{
    private function lay(Widget $root): Element
    {
        $el = $root->createElement();
        $el->mount(null, smokeContext());
        $el->settle();
        $el->effectiveBox()?->layout(BoxConstraints::tight(new Size(320.0, 240.0)));

        return $el;
    }

    // ① 加性：没声明角色的应用 ⇒ 空树（现有应用零成本，老探针一字不改）
    public function testAdditiveEmptyTree(): void
    {
        $semPlain = SemanticsBuilder::build($this->lay(Column::make()->children([
            Text::make('标题'),
            Text::make('正文'),
        ])));
        check(
            'Sem · 未声明角色 ⇒ 空树（加性：现有应用零成本）',
            $semPlain->count() === 0,
            'count=' . $semPlain->count(),
        );
        $this->flushChecks();
    }

    // ② 角色/name/type + ⑤ 几何来自渲染树 + ⑥ hitTest + ⑧ 越界 + ⑨ 序列化 + ⑩ 区间/text
    public function testButtonTree(): void
    {
        $semBtn = SemanticsBuilder::build($this->lay(Column::make()->children([
            Button::make('保存'),
            Button::make('取消'),
        ])));
        $btnSave = $semBtn->findByRoleAndName(SemanticsRole::Button->value, '保存');

        check(
            'Sem · Button 自动带 button 角色、name = label',
            $btnSave >= 0 && $semBtn->count() === 2,
            'count=' . $semBtn->count() . ' saveIdx=' . $btnSave,
        );
        check(
            'Sem · 节点 type 是控件类名短名',
            $semBtn->node($btnSave)->type === 'Button',
            $semBtn->node($btnSave)->type,
        );
        check(
            'Sem · 几何来自渲染树（布局后有正尺寸，落在视口内）',
            $semBtn->node($btnSave)->w > 0
                && $semBtn->node($btnSave)->h > 0
                && $semBtn->node($btnSave)->x >= 0
                && $semBtn->node($btnSave)->y >= 0,
            'rect=' . $semBtn->node($btnSave)->x . ',' . $semBtn->node($btnSave)->y . ','
                . $semBtn->node($btnSave)->w . 'x' . $semBtn->node($btnSave)->h,
        );
        $hitSave = $semBtn->hitTest($semBtn->node($btnSave)->x + 1, $semBtn->node($btnSave)->y + 1);
        check(
            'Sem · hitTest 取最深节点（点在按钮上 ⇒ 命中按钮不是祖先）',
            $hitSave === $btnSave,
            'hit=' . $hitSave . ' want=' . $btnSave,
        );
        check(
            'Sem · node() 越界返回空节点（调用方不必判空）',
            $semBtn->node(999)->role === '' && $semBtn->node(-1)->role === '',
            'r999=[' . $semBtn->node(999)->role . '] r-1=[' . $semBtn->node(-1)->role . ']',
        );
        $semArr = $semBtn->toArrays();
        check(
            'Sem · toArrays：count 与节点数一致、字段齐全',
            $semArr['count'] === $semBtn->count()
                && count($semArr['nodes']) === $semBtn->count()
                && isset($semArr['nodes'][0]['role'], $semArr['nodes'][0]['name'], $semArr['nodes'][0]['x']),
            'count=' . ($semArr['count'] ?? -1) . ' nodes=' . count($semArr['nodes'] ?? []),
        );
        check(
            'Sem · 非 range 控件区间恒 0/0（不凭空给默认区间）',
            $semBtn->node(0)->rangeMin === 0.0 && $semBtn->node(0)->rangeMax === 0.0,
            'min=' . $semBtn->node(0)->rangeMin . ' max=' . $semBtn->node(0)->rangeMax,
        );
        check(
            'Sem · text() 转储含角色与名字',
            str_contains($semBtn->text(), 'button/Button[' . '保存' . ']'),
            substr($semBtn->text(), 0, 70),
        );
        $this->flushChecks();
    }

    // ⑤ 焦点标记：build(root, focused) 只给焦点元素打 focused（UIA focus changed 的数据源）
    public function testFocusFlag(): void
    {
        $semFocusRoot = $this->lay(Column::make()->children([
            Button::make('甲'),
            Button::make('乙'),
        ]));
        $semFocusKid = $semFocusRoot->childAt(0);
        $semFocus = SemanticsBuilder::build($semFocusRoot, $semFocusKid);
        check(
            'Sem · build(root, focused) 只给焦点节点打 focused 标',
            $semFocus->count() === 2
                && $semFocus->node(0)->focused === true
                && $semFocus->node(1)->focused === false,
            'count=' . $semFocus->count()
                . ' f0=' . var_export($semFocus->node(0)->focused, true)
                . ' f1=' . var_export($semFocus->node(1)->focused, true),
        );
        check(
            'Sem · 不传 focused ⇒ 全树无焦点标（默认行为不变）',
            SemanticsBuilder::build($semFocusRoot)->node(0)->focused === false,
            'f0=' . var_export(SemanticsBuilder::build($semFocusRoot)->node(0)->focused, true),
        );
        $this->flushChecks();
    }

    // ③ TextField：label 是 name、value 跟当前文本；组合型控件几何取自 effectiveBox
    public function testTextField(): void
    {
        $semField = SemanticsBuilder::build($this->lay(Column::make()->children([
            TextField::make()->label('邮箱')->value('a@b.c'),
        ])));
        $fieldIdx = $semField->findByRole(SemanticsRole::TextField->value);
        check(
            'Sem · TextField：label 是 name、value 跟当前文本',
            $fieldIdx >= 0
                && $semField->node($fieldIdx)->name === '邮箱'
                && $semField->node($fieldIdx)->value === 'a@b.c',
            $fieldIdx >= 0
                ? 'name=' . $semField->node($fieldIdx)->name . ' v=' . $semField->node($fieldIdx)->value
                : 'not found',
        );
        check(
            'Sem · 组合型控件的几何取自 effectiveBox（TextField 的 w/h 不为 0）',
            $fieldIdx >= 0
                && $semField->node($fieldIdx)->w > 0
                && $semField->node($fieldIdx)->h > 0,
            $fieldIdx >= 0
                ? 'w=' . $semField->node($fieldIdx)->w . ' h=' . $semField->node($fieldIdx)->h
                : 'not found',
        );
        $this->flushChecks();
    }

    // ⑦ Modal ⇒ dialog 角色、name = 标题
    public function testModalDialog(): void
    {
        $semModal = SemanticsBuilder::build($this->lay(
            Modal::make()->title('确认删除')->child(Column::make()->children([Button::make('删除')]))
        ));
        $dlgIdx = $semModal->findByRole(SemanticsRole::Dialog->value);
        check(
            'Sem · Modal ⇒ dialog 角色、name = 标题',
            $dlgIdx >= 0 && $semModal->node($dlgIdx)->name === '确认删除',
            $dlgIdx >= 0 ? 'name=' . $semModal->node($dlgIdx)->name : 'not found',
        );
        $this->flushChecks();
    }

    // ⑪ 虚拟列表也是"可滚动区域"
    public function testLazyColumnScroll(): void
    {
        $semLazy = SemanticsBuilder::build($this->lay(LazyColumn::make(56.0)->items(
            [0, 1, 2],
            static fn (int $i): Widget => Text::make('行 ' . $i),
        )));
        check(
            'Sem · LazyColumn ⇒ scroll 角色（虚拟列表也是可滚动区域）',
            $semLazy->findByRole(SemanticsRole::Scroll->value) >= 0,
            'count=' . $semLazy->count(),
        );
        $this->flushChecks();
    }
}
