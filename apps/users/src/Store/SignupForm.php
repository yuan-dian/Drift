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
            unset($this->errors['name']);
            $this->submitError = '';
            $this->notifyChanged();
        }
    }

    public string $email = '' {
        set (string $v) {
            $this->email = $v;
            unset($this->errors['email']);
            $this->submitError = '';
            $this->notifyChanged();
        }
    }

    /** @var array<string, string> 字段 → 错误文案；缺键 = 该字段没毛病 */
    private array $errors = [];

    /** 提交级错误（不属于任何字段） */
    public string $submitError = '';

    /** 某个字段当前的错误；空串 = 没有 */
    public function errorOf(string $field): string
    {
        return $this->errors[$field] ?? '';
    }

    /** 校验并落到字段错误表（供 `TextField::error()` 直接读）；true = 全过 */
    public function validate(): bool
    {
        $this->errors = [];
        if ($this->name === '') {
            $this->errors['name'] = '姓名不能为空';
        }
        if (!str_contains($this->email, '@')) {
            $this->errors['email'] = '邮箱格式不正确';
        }

        $this->notifyChanged();

        return $this->errors === [];
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
        $this->errors = [];
        $this->submitError = '';
        $this->notifyChanged();
    }
}
