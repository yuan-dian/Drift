<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\State\Subject;

/**
 * 注册表单（§A.2.8）。
 *
 * ★ **property hooks**（§11.4.1）：字段名只写一次、且是「属性」⇒ 拼错是编译错误；
 *   每个 setter 里顺手清掉提交错误（用户改了就该重新给机会）。
 * ★ 提交结果**属于业务状态** ⇒ 放 store（§11.4.3），不放 `State`。
 */
final class SignupForm extends Subject
{
    public string $name = '' {
        set (string $v) {
            $this->name = $v;
            $this->submitError = '';
            $this->notifyChanged();
        }
    }

    public string $email = '' {
        set (string $v) {
            $this->email = $v;
            $this->submitError = '';
            $this->notifyChanged();
        }
    }

    public string $submitError = '';

    /** @return list<string> */
    public function validate(): array
    {
        $e = [];
        if ($this->name === '') {
            $e[] = '姓名不能为空';
        }
        if (!str_contains($this->email, '@')) {
            $e[] = '邮箱格式不正确';
        }

        return $e;
    }

    /** ★ 唯一序列化出口（§11.4.3：序列化只在边界发生一次） */
    public function toApiPayload(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    public function fail(string $msg): void
    {
        $this->submitError = $msg;
        $this->notifyChanged();
    }

    public function reset(): void
    {
        $this->name = '';
        $this->email = '';
        $this->submitError = '';
        $this->notifyChanged();
    }
}
