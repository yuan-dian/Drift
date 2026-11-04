<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderFlex;
use Drift\Layout\RenderTextField;
use Drift\Rendering\ColorToken;
use Drift\Widget\Element;
use Drift\Widget\Text;
use Drift\Widget\TextField;
use Drift\Widget\TextFieldState;

/**
 * TextField 的字段装饰 + 长度约束。
 *
 * 钉四件事：错误抢占辅助行、红边只在出错时出现、长度按**码位**截、外部受控值不被截。
 */
final class TextFieldValidationTest extends SmokeTestCase
{
    /** @return list<string> 子树里所有 Text 的内容（先序） */
    private function texts(Element $element): array
    {
        $out = [];
        $widget = $element->widget();
        if ($widget instanceof Text) {
            $out[] = $widget->content();
        }
        for ($i = 0; $i < $element->childCount(); ++$i) {
            $child = $element->childAt($i);
            if ($child !== null) {
                $out = [...$out, ...$this->texts($child)];
            }
        }

        return $out;
    }

    private function mount(TextField $field): Element
    {
        $root = $field->createElement();
        $root->mount(null, smokeContext());
        $root->effectiveBox()?->layout(BoxConstraints::loose(new Size(320.0, 120.0)));

        return $root;
    }

    public function testErrorReplacesHelperAndPaintsDangerBorder(): void
    {
        $ctx = smokeContext();

        $withError = $this->mount(
            TextField::make()->value('x')->label('邮箱')
                ->helper('用于登录')->error('邮箱格式不正确'),
        );
        check('TextField · 错误文案进入字段子树', \in_array('邮箱格式不正确', $this->texts($withError), true));
        check('TextField · 有错误时辅助文本让位', !\in_array('用于登录', $this->texts($withError), true));

        $box = $withError->findBox(static fn(RenderBox $b): bool => $b instanceof RenderTextField);
        check('TextField · 找到字段盒', $box instanceof RenderTextField);
        if ($box instanceof RenderTextField) {
            check(
                'TextField · 出错时描边转 Danger',
                $box->boxStyle()->borderColor?->toArgb() === $ctx->color(ColorToken::Danger)->toArgb(),
            );
        }

        $clean = $this->mount(TextField::make()->value('x')->label('邮箱')->helper('用于登录'));
        $cleanBox = $clean->findBox(static fn(RenderBox $b): bool => $b instanceof RenderTextField);
        check(
            'TextField · 无错误时描边不变红',
            $cleanBox instanceof RenderTextField
                && $cleanBox->boxStyle()->borderColor?->toArgb() === $ctx->color(ColorToken::Border)->toArgb(),
        );
        check('TextField · 无错误时辅助文本在', \in_array('用于登录', $this->texts($clean), true));

        $this->flushChecks();
    }

    public function testCounterAndComposition(): void
    {
        $noLabel = $this->mount(TextField::make()->value('ab'));
        check('TextField · 无 label / 无提示 ⇒ 根就是字段盒', $noLabel->effectiveBox() instanceof RenderTextField);

        $withLabel = $this->mount(TextField::make()->value('ab')->label('姓名'));
        check('TextField · 有 label ⇒ 根是 Column', $withLabel->effectiveBox() instanceof RenderFlex);

        $withCounter = $this->mount(TextField::make()->value('ab')->maxLength(5));
        check('TextField · 只有计数也出一行（根是 Column）', $withCounter->effectiveBox() instanceof RenderFlex);
        check('TextField · 计数显示 2/5（码位）', \in_array('2/5', $this->texts($withCounter), true));

        $cjk = $this->mount(TextField::make()->value('中文')->maxLength(5));
        check('TextField · 计数数码位不是字节（中文 = 2）', \in_array('2/5', $this->texts($cjk), true));

        $this->flushChecks();
    }

    public function testMaxLengthClampsTyping(): void
    {
        $root = $this->mount(TextField::make()->value('')->maxLength(3));
        $state = $root->state();
        check('TextField · state 是 TextFieldState', $state instanceof TextFieldState);

        $state?->insert('abcde');
        check(
            'TextField · 超出上限的键入被截到 3 个码位',
            $state?->text() === 'abc',
            '实际 ' . ($state?->text() ?? 'null'),
        );

        $seen = [];
        $full = TextField::make()->value('')->maxLength(3)
            ->onChanged(static function (string $v) use (&$seen): void {
                $seen[] = $v;
            });
        $root2 = $full->createElement();
        $root2->mount(null, smokeContext());
        $state2 = $root2->state();
        $state2?->insert('abcd');
        $state2?->insert('x');   // 已满 ⇒ 无事发生
        check('TextField · 满了再敲不再通知', $seen === ['abc'], '实际 ' . json_encode($seen));

        $this->flushChecks();
    }

    public function testMaxLengthCountsCodePoints(): void
    {
        $root = $this->mount(TextField::make()->value('')->maxLength(2));
        $state = $root->state();
        $state?->insert('中文字符');
        check(
            'TextField · 上限按码位截（不切半个汉字）',
            $state?->text() === '中文' && $state?->caretOffset() === 6,
            '实际 ' . ($state?->text() ?? 'null') . ' @' . (string) ($state?->caretOffset() ?? -1),
        );

        $this->flushChecks();
    }

    public function testMaxLengthAccountsForSelection(): void
    {
        $root = $this->mount(TextField::make()->value('abcd')->maxLength(4));
        $state = $root->state();
        $state?->moveCaret(1);
        $state?->extendSelectionTo(3);
        $state?->replaceSelection('XYZW');
        check(
            'TextField · 替换选区时可用额度 = 上限 − 未被选中的长度',
            $state?->text() === 'aXYd',
            '实际 ' . ($state?->text() ?? 'null'),
        );

        $this->flushChecks();
    }

    public function testExternalValueIsNotClamped(): void
    {
        $root = $this->mount(TextField::make()->value('abcdef')->maxLength(2));
        $state = $root->state();
        check(
            'TextField · 外部受控值不被截（应用是真相源）',
            $state?->text() === 'abcdef',
            '实际 ' . ($state?->text() ?? 'null'),
        );

        $this->flushChecks();
    }
}
