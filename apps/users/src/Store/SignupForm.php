<?php
declare(strict_types=1);

namespace App\Users\Store;

use Drift\App\Restoration\RestorationBucket;
use Drift\State\Subject;

/** 注册表单。绑定 bucket 后，字段值读写都走同一份业务状态存档。 */
final class SignupForm extends Subject
{
    private ?RestorationBucket $bucket = null;

    public string $name = '' {
        set (string $v) {
            $this->name = $v;
            $this->bucket?->write('form.name', $v);
            unset($this->errors['name']);
            $this->submitError = '';
            $this->notifyChanged();
        }
    }

    public string $email = '' {
        set (string $v) {
            $this->email = $v;
            $this->bucket?->write('form.email', $v);
            unset($this->errors['email']);
            $this->submitError = '';
            $this->notifyChanged();
        }
    }

    /** @var array<string, string> 字段 → 错误文案；缺键 = 该字段没毛病 */
    private array $errors = [];

    public string $submitError = '';

    /** 冷启动复原；同值写入不标脏，绑定本身不会产生一次写盘。 */
    public function bindRestoration(RestorationBucket $bucket): void
    {
        $this->bucket = $bucket;
        $this->name = (string) $bucket->read('form.name', '');
        $this->email = (string) $bucket->read('form.email', '');
    }

    public function errorOf(string $field): string
    {
        return $this->errors[$field] ?? '';
    }

    /** 校验并落到字段错误表（供 TextField::error() 直接读）；true = 全过 */
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