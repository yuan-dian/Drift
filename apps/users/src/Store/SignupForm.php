<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\App\Restoration\RestorationBucket;
use Drift\State\Cell;

/**
 * 注册表单。绑定 bucket 后，字段值读写都走同一份业务状态存档。
 *
 * ★ **字段是虚拟属性 + 格子**（2026-11-22）：`$form->name` 读的是格子（读即订阅），
 *   写的是格子 + 存档 + 清该字段错误。调用点仍是一句 `$form->name = $v`，
 *   但**通知不再是"记得调 `notifyChanged()`"** —— 格子自己广播。
 *   ⇒ 以前"漏调一次 ⇒ 输入框不回显"这类静默 bug 结构上不存在。
 * ★ **每字段一格而不是一个大数组**：改邮箱不该让姓名框重建（光标/组合输入都在
 *   那个控件里）。错误表单独一格：它只在提交校验时变，频率与输入无关。
 */
final class SignupForm
{
    private ?RestorationBucket $bucket = null;

    private Cell $nameCell;

    private Cell $emailCell;

    /** @var Cell<array<string, string>> 字段 → 错误文案；缺键 = 该字段没毛病 */
    private Cell $errorsCell;

    private Cell $submitErrorCell;

    public string $name {
        get => self::text($this->nameCell->get());

        set (string $v) {
            $this->nameCell->set($v);
            $this->bucket?->write('form.name', $v);
            $this->clearFieldError('name');
            $this->submitErrorCell->set('');
        }
    }

    public string $email {
        get => self::text($this->emailCell->get());

        set (string $v) {
            $this->emailCell->set($v);
            $this->bucket?->write('form.email', $v);
            $this->clearFieldError('email');
            $this->submitErrorCell->set('');
        }
    }
    /** 只读：提交失败的原因，由 `fail()` 写（外部没有写的场景） */
    public string $submitError {
        get => self::text($this->submitErrorCell->get());
    }

    public function __construct()
    {
        $this->nameCell = Cell::of('')->named('signup.name');
        $this->emailCell = Cell::of('')->named('signup.email');
        $this->errorsCell = Cell::of([])->named('signup.errors');
        $this->submitErrorCell = Cell::of('')->named('signup.submitError');
    }

    /** 冷启动复原；存档读出来的值与原值相同 ⇒ 格子不广播，绑定本身不惊动界面。 */
    public function bindRestoration(RestorationBucket $bucket): void
    {
        $this->bucket = $bucket;
        $this->name = (string) $bucket->read('form.name', '');
        $this->email = (string) $bucket->read('form.email', '');
    }

    public function errorOf(string $field): string
    {
        $errors = $this->errorsCell->get();
        if (!\is_array($errors)) {
            return '';
        }

        return self::text($errors[$field] ?? '');
    }

    /** 校验并落到字段错误表（供 TextField::error() 直接读）；true = 全过 */
    public function validate(): bool
    {
        $errors = [];
        if (self::text($this->nameCell->peek()) === '') {
            $errors['name'] = '姓名不能为空';
        }
        if (!str_contains(self::text($this->emailCell->peek()), '@')) {
            $errors['email'] = '邮箱格式不正确';
        }
        $this->errorsCell->set($errors);

        return $errors === [];
    }

    /** ★ 唯一序列化出口（§11.4.3：序列化只在边界发生一次） */
    public function toApiPayload(): array
    {
        return ['name' => $this->name, 'email' => $this->email];
    }

    public function fail(string $msg): void
    {
        $this->submitErrorCell->set($msg);
    }

    public function reset(): void
    {
        $this->name = '';
        $this->email = '';
        $this->errorsCell->set([]);
        $this->submitErrorCell->set('');
    }

    /**
     * 该字段值改了 ⇒ 它的错误作废，提交错误也作废（用户已经在改了）。
     *
     * ★ 用 `peek()`：这是一次写，不该顺手建立"读了错误表"的依赖。
     */
    private function clearFieldError(string $field): void
    {
        $errors = $this->errorsCell->peek();
        if (!\is_array($errors) || !\array_key_exists($field, $errors)) {
            return;
        }
        unset($errors[$field]);
        $this->errorsCell->set($errors);
    }

    private static function text(mixed $value): string
    {
        return \is_string($value) ? $value : '';
    }
}
