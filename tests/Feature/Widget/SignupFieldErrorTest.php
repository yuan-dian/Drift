<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;

use App\Users\Store\SignupForm;

/**
 * 注册表单的字段级错误：错误落到"哪个字段"，而不是堆成一句话在底部。
 */
final class SignupFieldErrorTest extends SmokeTestCase
{
    public function testValidateFillsPerFieldErrors(): void
    {
        $form = new SignupForm();

        check('SignupForm · 空表单 ⇒ validate 失败', $form->validate() === false);
        check('SignupForm · 姓名错误落到 name', $form->errorOf('name') === '姓名不能为空');
        check('SignupForm · 邮箱错误落到 email', $form->errorOf('email') === '邮箱格式不正确');

        $form->name = '张三';
        check('SignupForm · 改了字段 ⇒ 该字段错误清掉', $form->errorOf('name') === '');
        check('SignupForm · 其他字段的错误还在', $form->errorOf('email') === '邮箱格式不正确');

        $form->email = 'a@b.com';
        check(
            'SignupForm · 全填对 ⇒ validate 通过且错误清空',
            $form->validate() === true && $form->errorOf('email') === '' && $form->errorOf('name') === '',
        );

        $this->flushChecks();
    }
}
