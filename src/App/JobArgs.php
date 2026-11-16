<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * `NamedJob` 的默认实现 —— **名字 + 参数映射**，让任务类只需声明"有什么参数"。
 *
 * ## 它消掉的抄写
 *
 * 跨进程只能传「名字 + 参数数组」，于是任务必须自己说清被怎么重建。手写一遍是
 * 三份同样的信息：
 *
 * ```php
 * public function __construct(private readonly array $objectKeys, private readonly string $time) {}
 * public function args(): array      { return ['objectKeys' => $this->objectKeys, 'time' => $this->time]; }
 * public static function fromArgs(array $a): self { return new self(self::argList($a, 'objectKeys'), self::argString($a, 'time')); }
 * ```
 *
 * 三处漏一处就是**静默错**：漏掉的字段在两侧一起退化成默认值，UI 全正常，只有
 * 真机 worker 里跑错参数。⇒ 用本 trait 后只留一份名单：
 *
 * ```php
 * final class AddTaskJob implements NamedJob
 * {
 *     use JobArgs;
 *
 *     public const NAME = 'watermark.addTask';
 *     private const FIELDS = ['objectKeys', 'time'];   // ← 顺序 == 构造器参数顺序
 *
 *     public function __construct(private readonly array $objectKeys, private readonly string $time) {}
 *     public function run(): mixed { … }
 * }
 * ```
 *
 * `args()` 的值从同名属性读（构造器提升保证了"参数名 == 属性名"），`fromArgs()`
 * 按 `FIELDS` 的顺序位置填。名单与构造器签名的一致性由 `NamedJobContractTest`
 * 用反射守 —— 漏字段、顺序错、键名分叉都会红。
 *
 * ## 为什么用 trait 而不是基类
 *
 * `Job` 必须是类（§23.2 O-03："凡是要跨边界的东西都是类"），但实现类可能已有
 * 继承关系；基类会占掉唯一那次继承位。trait 在 AOT 下已大量使用（`Widget` 由四个
 * trait 合入）。
 *
 * ## 为什么 `FIELDS` 是常量而不是抽象方法
 *
 * 漏写 `FIELDS` 应当是「未定义常量」的**显式**失败，而不是抽象方法被实现成
 * 空数组的静默失败。
 *
 * ## 为什么 `fromArgs()` 不给默认值
 *
 * `args()` 恒输出全部字段 ⇒ 正常往返闭环。手工拼的 `args` 缺字段会让构造器收到
 * `null` 并触发 `TypeError`（显式失败）。默认值只在**构造器签名**里存在一份，
 * 不在这里复制第二份。
 *
 * @api
 */
trait JobArgs
{
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
     * 键名清单，**顺序必须等于构造器参数顺序**（判据用反射比）。
     *
     * ★ 宿主类必须定义 `private const FIELDS`。trait 里**故意不给默认值**：
     *   漏写要让「未定义常量」当场炸，而不是静默映射出空数组。
     *
     * @api
     */
    public function args(): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = $this->{$field};
        }

        return $out;
    }

    /**
     * worker 侧重建（按 `FIELDS` 的顺序位置填参数）。
     *
     * ★ 位置展开而不是"按名还原"（`new $class(...$args)` / `get_object_vars`）：
     *   后两者在 AOT 下零验证记录，本仓不赌。这里生成的是 `appendUnpacked` +
     *   显式属性读取，两条都在 tpc 的正规路径上。
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
