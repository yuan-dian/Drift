<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\App\Restoration\RestorationBucket;
use Drift\State\Cell;

/**
 * 注册表单。绑定 bucket 后，字段值读写都走同一份业务状态存档。
 *
 * ★ **读 `name()`、写 `setName()`，不用属性钩子**（裁决 C8）：钩子会让"这一行在读一个
 *   会变的值"从源码里消失 —— `grep ->get()` 找不到它，而 A19 的判据正是读点可审计。
 *   每字段 4 行钩子也比省下的 `notifyChanged()` 长，多字段之间还会撕裂读。
 * ★ **每字段一格而不是一个大数组**：改邮箱不该让姓名框重建（光标/组合输入都在
 *   那个控件里）。错误表单独一格：它只在提交校验时变，频率与输入无关。
 */
final class SignupForm
{
    private ?RestorationBucket $bucket = null;

    private Cell $name;

    private Cell $email;

    /** @var Cell<array<string, string>> 字段 → 错误文案；缺键 = 该字段没毛病 */
    private Cell $errors;

    private Cell $submitError;

    public function __construct()
    {
        $this->name = Cell::of('')->named('signup.name');
        $this->email = Cell::of('')->named('signup.email');
        $this->errors = Cell::of([])->named('signup.errors');
        $this->submitError = Cell::of('')->named('signup.submitError');
    }

    public function name(): string
    {
        return self::text($this->name->get());
    }

    public function email(): string
    {
        return self::text($this->email->get());
    }

    /** 提交失败的原因，由 `fail()` 写（外部没有写的场景） */
    public function submitError(): string
    {
        return self::text($this->submitError->get());
    }

    public function setName(string $value): void
    {
        $this->name->set($value);
        $this->bucket?->write('form.name', $value);
        $this->clearFieldError('name');
        $this->submitError->set('');
    }

    public function setEmail(string $value): void
    {
        $this->email->set($value);
        $this->bucket?->write('form.email', $value);
        $this->clearFieldError('email');
        $this->submitError->set('');
    }

    /** 冷启动复原；存档读出来的值与原值相同 ⇒ 格子不广播，绑定本身不惊动界面。 */
    public function bindRestoration(RestorationBucket $bucket): void
    {
        $this->bucket = $bucket;
        $this->setName((string) $bucket->read('form.name', ''));
        $this->setEmail((string) $bucket->read('form.email', ''));
    }

    public function errorOf(string $field): string
    {
        $errors = $this->errors->get();
        if (!\is_array($errors)) {
            return '';
        }

        return self::text($errors[$field] ?? '');
    }

    /** 校验并落到字段错误表（供 TextField::error() 直接读）；true = 全过 */
    public function validate(): bool
    {
        $errors = [];
        if (self::text($this->name->peek()) === '') {
            $errors['name'] = '姓名不能为空';
        }
        if (!\str_contains(self::text($this->email->peek()), '@')) {
            $errors['email'] = '邮箱格式不正确';
        }
        $this->errors->set($errors);

        return $errors === [];
    }

    /** ★ 唯一序列化出口（§11.4.3：序列化只在边界发生一次） */
    public function toApiPayload(): array
    {
        return ['name' => $this->name(), 'email' => $this->email()];
    }

    public function fail(string $msg): void
    {
        $this->submitError->set($msg);
    }

    public function reset(): void
    {
        $this->setName('');
        $this->setEmail('');
        $this->errors->set([]);
    }

    /**
     * 该字段值改了 ⇒ 它的错误作废，提交错误也作废（用户已经在改了）。
     *
     * ★ 用 `peek()`：这是一次写，不该顺手建立"读了错误表"的依赖。
     */
    private function clearFieldError(string $field): void
    {
        $errors = $this->errors->peek();
        if (!\is_array($errors) || !\array_key_exists($field, $errors)) {
            return;
        }
        unset($errors[$field]);
        $this->errors->set($errors);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
