<?php
declare(strict_types=1);

namespace App\Showcase\Models;

/**
 * "加载更多"的载荷 —— 一次请求回来的**这一页**，以及两页怎么接起来。
 *
 * ★ 存在的理由：页面上原来有三个函数（`itemsOf` / `totalOf` / `nextPage`）加一个
 *   合并回调，全都靠 `is_array($data['items'])` 这类判空活着。那些判空就是
 *   "载荷是什么形状"这条知识散在四个地方。收进这里之后，形状只在 `from()` 认一次。
 *
 * ★ **合并规则是业务知识，不是框架默认**（`AsyncCommand` 的类注释有完整论证）：
 *   后端回的是 `{items, total, source}`，`items` 要接上、`total` / `source` 以最新
 *   一次为准。框架若猜成 `array_merge`，猜错时的症状是"数据少了一半"却不报错。
 *
 * ★ 畸形载荷一律降级成空页而不是抛：翻页失败时页面还要画旧行，这时候再抛一次
 *   只会把"下一页失败"变成"整页白"。
 */
final readonly class PagedRows
{
    /**
     * @param list<array<string, mixed>> $items
     */
    private function __construct(
        private array $items,
        private int $total,
        private string $source,
    ) {
    }

    /**
     * 唯一解码口 —— 载荷形状只有这里认识。
     *
     * 接受 `null`（还没请求）、非数组、缺键、`items` 不是数组：全都当空页。
     *
     * ★ **幂等**：合并之后载荷就是 `PagedRows` 本身（命令不再回头把它变成数组），
     *   所以再喂回来必须原样返回。少了这一条，第二次追加时 `from()` 会把它当
     *   "认不出的载荷"降级成空页 —— 症状正好是"追加完行全没了"。
     */
    public static function from(mixed $payload): self
    {
        if ($payload instanceof self) {
            return $payload;
        }

        if (!\is_array($payload)) {
            return new self([], 0, '');
        }

        $items = isset($payload['items']) && \is_array($payload['items'])
            ? \array_values($payload['items'])
            : [];

        return new self(
            $items,
            (int) ($payload['total'] ?? 0),
            (string) ($payload['source'] ?? ''),
        );
    }

    /**
     * 追加一页 —— `items` 接在后面，其余字段以**新的**为准。
     *
     * ★ 后端每页都会回同一个 `total`，所以"取新的"与"取旧的"等价；写"取新的"
     *   是因为它没有隐含假设（万一下一版后端只在第一页回 total）。
     */
    public function mergedWith(self $newer): self
    {
        return new self(
            [...$this->items, ...$newer->items],
            $newer->total,
            $newer->source,
        );
    }

    /** @return list<array<string, mixed>> */
    public function items(): array
    {
        return $this->items;
    }

    public function count(): int
    {
        return \count($this->items);
    }

    public function total(): int
    {
        return $this->total;
    }

    public function source(): string
    {
        return $this->source;
    }

    /**
     * 下一页页码 —— **由已加载行数推出来，不另存计数器**。
     *
     * ★ 存在两个地方（值里的行数 + 一个 `$nextPage` 字段）就一定会不同步：失败后
     *   重试、取消、卸载兜底，每条路都得记得别动它。派生量只跟着值走。
     */
    public function nextPage(int $perPage): int
    {
        return $perPage > 0 ? \intdiv($this->count(), $perPage) + 1 : 1;
    }

    /** 已经取全了（`total` 未知时保守地说"没全"，否则一进去就不让翻页） */
    public function isComplete(): bool
    {
        return $this->total > 0 && $this->count() >= $this->total;
    }
}
