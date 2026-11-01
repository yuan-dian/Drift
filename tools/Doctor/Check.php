<?php
declare(strict_types=1);

namespace Drift\Dev\Doctor;

/**
 * 一条环境检查结果。
 *
 * ★ `required` 决定「不满足时构建还能不能跑」：`true` ⇒ 缺了必然失败（fail），
 *   `false` ⇒ 只是偏离推荐配置（warn），构建可能仍能过 —— 两类混在一起报，
 *   就会出现"一堆红字但其实能跑"⇒ 红字被忽略 ⇒ 网失效。
 */
final class Check
{
    public const OK = 'ok';
    public const WARN = 'warn';
    public const FAIL = 'fail';

    /**
     * @param string      $id       稳定标识（`--json` 消费方按它取字段，别改）
     * @param string      $label     人读的一行标题
     * @param string      $status    self::OK / WARN / FAIL
     * @param string      $detail    实测值或缺失原因（要能直接看出"实际是什么"）
     * @param bool        $required  构建必需项
     * @param string|null $fix       不满足时的修法（一句可执行的指引）
     * @param string[]    $affects   不满足时受影响的动作（build / gate / golden ...）
     */
    public function __construct(
        public readonly string $id,
        public readonly string $label,
        public readonly string $status,
        public readonly string $detail,
        public readonly bool $required,
        public readonly ?string $fix = null,
        public readonly array $affects = [],
    ) {
    }

    public function ok(): bool
    {
        return $this->status === self::OK;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'label' => $this->label,
            'status' => $this->status,
            'detail' => $this->detail,
            'required' => $this->required,
            'fix' => $this->fix,
            'affects' => $this->affects,
        ];
    }
}
