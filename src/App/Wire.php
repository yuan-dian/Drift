<?php
declare(strict_types=1);

namespace Drift\App;

/**
 * 跨进程线协议的值对象 —— **一份名单，两个方向**。
 *
 * ## 它消掉的抄写
 *
 * 凡是跨进程的东西（任务参数、任务返回值）都只能过 JSON，于是每个值对象都要
 * 手写"摊平"和"读回"。摊平那一侧是**逐字重复**：
 *
 * ```php
 * public function toArray(): array
 * {
 *     return [
 *         'account'       => $this->account,        // ← 键名恒等于属性名
 *         'policy'        => $this->policy,         //   9 个属性 = 18 行零信息量
 *         …
 *     ];
 * }
 * ```
 *
 * ⇒ 骨架收进 trait，类只写一份名单：
 *
 * ```php
 * final class SignInfo
 * {
 *     use Wire;
 *     private const FIELDS = ['account', 'policy', 'signature', …];   // 顺序 == 构造器参数顺序
 *     public function __construct(public readonly string $account, …) {}
 * }
 * ```
 *
 * ## 为什么名单能同时喂两个方向
 *
 * `toArray()` 从同名属性读（构造器提升保证"参数名 == 属性名"），`fromArgs()`
 * 按名单顺序位置填 —— 两者是**同一份事实的两面**。写错一个字段名，两侧一起错，
 * 所以判据只比一次：`FieldsContractTest` 拿**反射的属性名集**比名单
 * （名单自己比自己永远发现不了漏字段）。
 *
 * ## 为什么读回那一侧**不**自动生成
 *
 * 参数是平铺的（构造器能按位置接），返回值没有这个性质：状态码可能是 int 也可能是
 * 数字字符串、缺失字段要退化成默认值、有的缺一个就整份作废。这些是**业务判断**，
 * 硬塞进通用映射只会逼出一个没人看得懂的迷你 schema。
 * ⇒ 读回由类自己写（`fromArray()` / `fromPayload()`），本 trait 只提供两个收窄原语
 * （`wireString` / `wireStrings`）——它们有 **3 个应用**在用，且每个都省 3–5 行。
 *
 * ## 为什么用 trait 而不是基类
 *
 * `Job` 必须是类（§23.2 O-03），但实现类可能已有继承关系；基类会占掉唯一那次继承位。
 *
 * @api
 */
trait Wire
{
    /**
     * 摊平成数组（键名**恒等于**属性名，由 `FIELDS` 决定）。
     *
     * ★ 宿主类必须定义 `private const FIELDS`。trait 里**故意不给默认值**：
     *   漏写要让「未定义常量」当场炸，而不是静默映射出空数组 —— 后者在跨进程时
     *   表现为"worker 收到的参数全是默认值"，UI 侧一点异常都看不到。
     *
     * @return array<string, mixed>
     *
     * @api
     */
    public function toArray(): array
    {
        $out = [];
        foreach (self::FIELDS as $field) {
            $out[$field] = $this->{$field};
        }

        return $out;
    }

    /**
     * 从一个**不可信**的数组里取一个字符串（缺字段 / 类型不对 ⇒ 用 `$fallback`）。
     *
     * ★ 不抛：这是**数据**不是参数。服务端把 `templateName` 换成数字、或整段
     *   `data` 是个字符串，都不该让一次轮询停摆。
     *
     * @param array<array-key, mixed> $data
     */
    protected static function wireString(array $data, string $key, string $fallback = ''): string
    {
        $value = $data[$key] ?? null;

        return \is_scalar($value) ? (string) $value : $fallback;
    }

    /**
     * 收窄成 `list<string>`（丢非字符串、丢空串、**键归零**）。
     *
     * ★ 必须 `array_values`：JSON 的 list 过一趟 `json_decode` 仍是 list，但
     *   关联数组传过来会保持键；不归零则 `===` 比较与 `list<>` 契约都会错位。
     *
     * @return list<string>
     */
    protected static function wireStrings(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }

        $out = [];
        foreach ($raw as $one) {
            if (\is_string($one) && $one !== '') {
                $out[] = $one;
            }
        }

        return $out;
    }
}
