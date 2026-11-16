<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * `NamedJob::fromArgs()` 的参数读取原语 —— 给"从 worker 侧重建任务"用。
 *
 * ## 它解决的 DX 问题：参数映射的**局部性**
 *
 * 迁移前，一个任务的参数信息被写在**两个文件**里：
 *
 * ```
 * Jobs/AddTaskJob.php     args(): ['objectKeys' => …, 'imageSize' => …]
 * Jobs/JobRegistry.php    new AddTaskJob((string) ($args['keys'] ?? '2K'))   ← 键名还不一样
 * ```
 *
 * 加一个构造参数要同时改两边，而"改了一边忘另一边"的症状是**静默的**：
 * 漏掉的那个参数在两侧都退化成默认值，UI 侧一切正常，只有真机 worker 里跑的是
 * 错参数（`NamedJobContractTest` 就是为了把这条变成红）。
 *
 * ⇒ 把读取方式搬进任务类自己（`static fromArgs()`），与 `args()` **上下相邻**：
 *   写 `args()` 的时候，对应的读法就在几行之下，对称可见。registry 退化成
 *   `名字 => 类::fromArgs` 的一行表。
 *
 * ## 为什么用 trait 而不是基类
 *
 * `Job` 必须是 final 类（§23.2 O-03 裁决的"凡是要跨边界的东西都是类"），而基类会
 * 占用唯一的那次继承位。（trait 在 AOT 下已大量使用：`Widget` 由四个 trait 合入。）
 *
 * ## 为什么每个读取都要显式给默认值
 *
 * 跨进程回来的数组**不可信**：worker 侧拿到的是 JSON 解出来的 `mixed`，AOT 下
 * 类型漂了不会报错、只会静默用错值。所以这里逐个 `is_*` 收窄 + 显式默认，
 * 与 `JobRunner` 那句"两条路的返回值形状必须一致"是同一条纪律。
 *
 * @api
 */
trait JobArgs
{
    /** 读一个字符串参数（类型不对 ⇒ 用默认值，不抛：这是**数据**不是参数） */
    protected static function argString(array $args, string $key, string $default = ''): string
    {
        $value = $args[$key] ?? null;

        return \is_string($value) ? $value : $default;
    }

    /** 读一个整数参数（跨进程可能变成字符串或浮点，逐个收窄） */
    protected static function argInt(array $args, string $key, int $default = 0): int
    {
        $value = $args[$key] ?? null;

        if (\is_int($value)) {
            return $value;
        }
        if (\is_float($value)) {
            return (int) $value;
        }
        if (\is_string($value) && $value !== '' && \is_numeric($value)) {
            return (int) $value;
        }

        return $default;
    }

    /** 读一个布尔参数 */
    protected static function argBool(array $args, string $key, bool $default = false): bool
    {
        $value = $args[$key] ?? null;

        return \is_bool($value) ? $value : $default;
    }

    /**
     * 读一个**列表**参数（`list<…>`）—— 归一化掉键，否则重建后 `args()` 与原值不等。
     *
     * ★ 关联数组也接受：`array_values()` 之后就是 list。跨进程的 JSON 对象解出来是
     *   关联数组，"调用方传 list、worker 收回 map"是常态，不该让调用点各自处理。
     *
     * @return list<mixed>
     */
    protected static function argList(array $args, string $key): array
    {
        $value = $args[$key] ?? null;

        return \is_array($value) ? \array_values($value) : [];
    }

    /**
     * 读一个**映射**参数（`array<string, mixed>`）—— 保留键。
     *
     * @return array<array-key, mixed>
     */
    protected static function argMap(array $args, string $key): array
    {
        $value = $args[$key] ?? null;

        return \is_array($value) ? $value : [];
    }
}
