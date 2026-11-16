<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * **可跨进程描述**的 `Job`（§17.4.5，P3）—— `ProcessTaskHost` 只能跑它。
 *
 * ★ 为什么需要这一层：跨进程传不了对象图（tpc 的类实例没有稳定的序列化格式，
 *   而且 `serialize()` 在 AOT 下没验过）。能传的只有 **名字 + 参数数组**，
 *   所以任务必须自己说清"我叫什么、我怎么被重新构造出来"。
 *
 * ★ 与 `Job` 的关系：`NamedJob extends Job` —— `run()` 仍然是本体，
 *   多出来的两个方法是**给 worker 侧用的地址**：
 *   ```php
 *   // UI 进程：拿到一个 NamedJob
 *   $host->submit(new FetchUsersJob('ada'), $onDone);
 *
 *   // worker 进程：同一个注册表按名字重建
 *   $registry = ['users.fetch' => fn (array $a): Job => new FetchUsersJob($a['q'])];
 *   ```
 *   ⇒ **注册表必须是"组合根的一行"**，两侧（UI / worker）用同一份（§17.2.3）。
 *
 * ★ **参数必须是 JSON 能表达的东西**（标量 / 数组）：对象、resource、闭包一律不行。
 *   要传对象就先摊平成数组（这也是跨进程的通用纪律，不是 Drift 的限制）。
 *
 * @api
 */
interface NamedJob extends Job
{
    /** 注册表里的键（两侧必须一致；建议 `域.动作`，如 `users.fetch`） */
    public function name(): string;

    /**
     * 重建这个任务所需的参数（JSON 可序列化）。
     *
     * ★★ **键名必须等于构造参数名，一个不多一个不少**（`args()` 的键集 ==
     *   `__construct` 的参数名集）。这不是风格问题：registry 那侧是手工
     *   `new XxxJob($args['…'], …)`，两处名字一旦分叉，"改一处忘另一处"就是
     *   **静默**错 —— 漏掉的那个参数在两侧都退化成默认值，UI 一切正常，只有
     *   worker 里跑的是错参数。判据：`NamedJobContractTest`（反射比对 + 重建
     *   roundtrip），加参数忘了同步会红。
     * ★ 值只能是标量或标量数组（`ThreadTaskHost` 只搬 int/str 两个槽，
     *   `ProcessTaskHost` 只能 JSON）：传对象在进程池里看着能过，换线程池就静默。
     *
     * @return array<string, mixed>
     */
    public function args(): array;
}
