<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\Semantics;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Text\FilteringTextInputFormatter;
use Drift\Text\TextDraft;
use Drift\Widget\TextField;

/**
 * 输入格式化器的边界（能力面报告 P0-1 的第二条真缺口）。
 *
 * 判据盯的是「谁能改文本」：只有用户输入被格式化，外部受控值与撤销历史
 * 都不被重排 —— 否则应用写进去的业务值会在控件里悄悄变样。
 */
final class TextFieldFormatterTest extends SmokeTestCase
{
    private function draft(): TextDraft
    {
        $d = new TextDraft('');
        $d->setFormatters([new FilteringTextInputFormatter('/\s+/u')]);

        return $d;
    }

    public function testInsertIsFormattedBeforeNotification(): void
    {
        $seen = [];
        $root = Semantics::element(TextField::make()->value('')
            ->inputFormatters(new FilteringTextInputFormatter('/\s+/u'))
            ->onChanged(function (string $v) use (&$seen): void {
                $seen[] = $v;
            }));
        $state = $root->state();
        $state->insert('a b@c');

        check('格式化 · 粘贴含空格的邮箱后文本无空白', $state->text() === 'ab@c', $state->text());
        check('格式化 · onChanged 收到的是格式化后的值（不是先通知再变换）', $seen === ['ab@c'], implode('|', $seen));

        $this->flushChecks();
    }

    public function testCaretFollowsDeletedCharacters(): void
    {
        $d = $this->draft();
        $d->insert('ab');
        $d->moveCaret(1);
        $d->insert(' c');   // 未格式化是 'a cb'（caret 3）；删掉空格后应是 'acb'（caret 2）

        check('格式化 · 插入符前的字符被删掉后光标不漂', $d->text() === 'acb' && $d->caretOffset() === 2, $d->text() . ' caret=' . $d->caretOffset());

        $this->flushChecks();
    }

    public function testExternalValueAndUndoAreNotReformatted(): void
    {
        $d = $this->draft();
        $d->setText('a b');   // 外部受控值：应用是真相源，控件不许改它
        check('格式化 · 外部推值不被改写', $d->text() === 'a b', $d->text());

        $d->insert('c');
        check('格式化 · 用户输入仍被格式化', $d->text() === 'abc', $d->text());

        $d2 = $this->draft();
        $d2->insert('a b');
        check('格式化 · 一次粘贴只留非空白字符', $d2->text() === 'ab', $d2->text());
        $d2->undo();
        check('格式化 · 撤销一步退掉整次输入（不是只退掉被删的空格）', $d2->text() === '', $d2->text());

        $this->flushChecks();
    }

    public function testFrameworkCapabilityHasRealConsumer(): void
    {
        $page = (string) file_get_contents(\dirname(__DIR__, 3) . '/apps/users/src/Pages/SignupPage.php');
        check(
            '格式化 · apps/users 注册页真的接了格式化器（不是框架死代码）',
            str_contains($page, 'inputFormatters(') && str_contains($page, 'FilteringTextInputFormatter'),
        );

        $this->flushChecks();
    }
}
