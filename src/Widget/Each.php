<?php
declare(strict_types=1);

namespace Drift\Widget;

use Drift\Errors;
use Drift\Layout\RenderFlex;

/**
 * 列表（§16.6.1 R4：key **必填**）。
 *
 * ```php
 * Each::make(fn(User $u): int => $u->id)->items($rows, fn(User $u): Widget => UserRow::make($u));
 * ```
 *
 * ★ **key 进 `make()` 必填**，不是可选的 `->key()`：理由 G1 —— `fn(User $u): int`
 *   的类型就在签名里 ⇒ **编译器知道列表项有身份**。漏传是 TypeError，不是运行期
 *   才发现的 diff 错乱（§16.6.1 R4 从"lint 提醒"升格为"签名强制"）。
 *
 * ★ **继承 `Flex` 而不是另写一套**：`Each` = "由 items 生成子层的 Column"。
 *   子层一旦由 `items()` 产出，`spacing` / `cross` / `overflow` / 造盒 / diff /
 *   Element 全部沿用 `Flex` 的 —— 另写一份就等于把 §13.1 的六条契约抄第二遍。
 *
 * ★ **没有虚拟化**（M5）：`items()` 在构建期把每个 item 造成一个 Widget。
 *   1000 行 ⇒ 1000 个实例。虚拟化要动的是"按视口造子层"这一处，接口形状不变。
 */
final class Each extends Flex
{
    /** @param callable(mixed): (string|int) $keyFn */
    private readonly \Closure $keyFn;

    /** @param callable(mixed): (string|int) $keyFn */
    public function __construct(callable $keyFn)
    {
        parent::__construct();
        $this->keyFn = \Closure::fromCallable($keyFn);
    }

    /** @param callable(mixed): (string|int) $keyFn */
    public static function make(callable $keyFn): self
    {
        return new self($keyFn);
    }

    /**
     * @param list<mixed> $items
     * @param callable(mixed): Widget $builder
     */
    public function items(array $items, callable $builder): static
    {
        $this->mutate();

        /** @var array<string, true> $seen key 查重（§16.6.1 R4） */
        $seen = [];
        $kids = [];

        foreach ($items as $item) {
            $key = ($this->keyFn)($item);

            // var_export 区分 `1` 与 `'1'` —— 与 Key::same "不做类型互转"同口径
            $tag = var_export($key, true);
            if (isset($seen[$tag])) {
                // R4：同层重复 Key ⇒ diff 复用错乱。绝不静默（G2）
                Errors::report(sprintf(
                    'Each 的 keyFn 产出了重复的 key %s —— 同层兄弟 key 必须唯一（§16.6.1 R4）',
                    $tag,
                ));
            }
            $seen[$tag] = true;

            $kids[] = $builder($item)->key($key);
        }

        $this->kids = $kids;

        return $this;
    }

    /** @return list<Widget> */
    public function itemWidgets(): array
    {
        return $this->kids;
    }

    protected function isVertical(): bool
    {
        return true;
    }
}
