<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * `NamedJob` 的默认实现：用 `FIELDS` 一份名单生成 name / args / fromArgs。
 *
 * 跨进程只传名字 + 参数数组，手写三处漏一处就会静默用错参数。
 * `FIELDS` 顺序必须等于构造器参数顺序；漏写常量应显式失败，不给默认值。
 * 用 trait 是因为 Job 实现类可能已有继承关系，基类会占掉唯一继承位。
 * `fromArgs()` 按位置展开，不用 AOT 零验证的按名还原。
 *
 * @api
 */
trait JobArgs
{
    use Wire;

    /**
     * 注册表里的键（`NAME` 常量是唯一真相源）。
     *
     * @api
     */
    public function name(): string
    {
        return self::NAME;
    }

    /**
     * 键名清单，顺序必须等于构造器参数顺序（反射判据守）。
     * 宿主类必须定义 `private const FIELDS`，trait 故意不给默认值。
     *
     * @api
     */
    public function args(): array
    {
        return $this->toArray();
    }

    /**
     * worker 侧重建，按 `FIELDS` 顺序位置填参数；缺字段得到 null 并触发 TypeError。
     * 用 `appendUnpacked` + 显式属性读取，不用 AOT 零验证的按名还原。
     *
     * @param array<string, mixed> $args
     *
     * @api
     */
    public static function fromArgs(array $args): self
    {
        $values = [];
        foreach (self::FIELDS as $field) {
            $values[] = $args[$field] ?? null;
        }

        return new self(...$values);
    }
}
