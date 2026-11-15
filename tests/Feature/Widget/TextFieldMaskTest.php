<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\Semantics;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\SemanticsRole;
use Drift\Layout\RenderBox;
use Drift\Layout\RenderTextField;
use Drift\Rendering\DisplayList;
use Drift\Rendering\RecordingCanvas;
use Drift\Text\Paragraph;
use Drift\Widget\Element;
use Drift\Widget\SemanticsBuilder;
use Drift\Widget\TextField;

/**
 * 密码遮蔽：显示串与值串分离（能力面报告 P0-1 的第一条真缺口）。
 *
 * ★ 判据读**实际绘制命令**与**语义树**，不读 obscure 字段：
 *   查「字段设没设」等于和实现同源，把遮蔽做成「只改语义值」也能全绿。
 * ★ 字节偏移契约不因遮蔽改变：中英混合串的每个码位边界都要能
 *   caretRect → offsetAt 真实往返，否则密码框里点一下就落错插入点。
 */
final class TextFieldMaskTest extends SmokeTestCase
{
    private const MASK = "\xE2\x80\xA2";

    /** @return list<string> 绘制命令流里所有段落的文本 */
    private function drawnTexts(RenderBox $box): array
    {
        $list = new DisplayList();
        $box->paint(new RecordingCanvas($list));

        $out = [];
        for ($i = 0; $i < $list->count(); $i++) {
            if ($list->opAt($i) !== DisplayList::OP_DRAW_PARAGRAPH) {
                continue;
            }
            $paragraph = $list->argAt($i, 0);
            if ($paragraph instanceof Paragraph) {
                $out[] = $paragraph->text;
            }
        }

        return $out;
    }

    private function fieldBox(Element $root): ?RenderTextField
    {
        $box = $root->findBox(
            static fn(RenderBox $candidate): bool => $candidate instanceof RenderTextField,
        );

        return $box instanceof RenderTextField ? $box : null;
    }

    private function semanticValue(Element $root, string $name): string
    {
        $tree = SemanticsBuilder::build($root, null, null);
        $index = $tree->findByRoleAndName(SemanticsRole::TextField->value, $name);

        return $index < 0 ? '<missing>' : $tree->node($index)->value;
    }

    public function testObscureDrawsMaskButKeepsSemanticValue(): void
    {
        $root = Semantics::element(
            TextField::make()->value('abc')->label('密码')->obscureText(),
        );
        $box = $this->fieldBox($root);

        check('遮蔽 · 找得到字段盒', $box instanceof RenderTextField);
        $drawn = $box === null ? [] : $this->drawnTexts($box);
        check(
            '遮蔽 · 绘制命令里是三个圆点，不含原文字形',
            $drawn === [str_repeat(self::MASK, 3)],
            implode('|', $drawn),
        );
        check(
            '遮蔽 · 语义 Value 仍是原文（读屏 / 自动化拿真值，不拿圆点）',
            $this->semanticValue($root, '密码') === 'abc',
            $this->semanticValue($root, '密码'),
        );

        $plain = Semantics::element(TextField::make()->value('abc')->label('明文'));
        $plainBox = $this->fieldBox($plain);
        check(
            '遮蔽 · 不开启时绘制源不变（旧行为不受影响）',
            $plainBox !== null && in_array('abc', $this->drawnTexts($plainBox), true),
        );

        $this->flushChecks();
    }

    public function testObscureKeepsByteOffsetsForMixedText(): void
    {
        // 'a'(1) + '中'(3) + 'b'(1) ⇒ 原文边界 [0,1,4,5]，绘制串边界 [0,3,6,9]
        $root = Semantics::element(
            TextField::make()->value('a中b')->obscureText(),
        );
        $state = $root->state();
        // 不在编辑态时 caretOffset 传 null，插入符恒在文本末尾 ⇒ 必须先进入编辑
        $state->beginEditing();
        $ok = true;
        $log = '';
        foreach ([0, 1, 4, 5] as $offset) {
            $state->moveCaret($offset);
            $root->settle();
            $box = $this->fieldBox($root);
            if ($box === null) {
                $ok = false;
                break;
            }
            $back = $box->offsetAt($box->caretRect()->left);
            if ($back !== $offset) {
                $ok = false;
                $log .= ' ' . $offset . '->' . $back;
            }
        }
        check('遮蔽 · 中英混合的原文偏移真实往返（不按绘制串字节算）', $ok, $log);

        $this->flushChecks();
    }

    public function testObscureEmptyStillShowsPlaceholder(): void
    {
        $root = Semantics::element(
            TextField::make()->value('')->placeholder('请输入密码')->obscureText(),
        );
        $box = $this->fieldBox($root);
        check(
            '遮蔽 · 空值仍显示占位符（不把占位符也变成圆点）',
            $box !== null && in_array('请输入密码', $this->drawnTexts($box), true),
        );

        $this->flushChecks();
    }
}
