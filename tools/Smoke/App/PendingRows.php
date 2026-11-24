<?php
declare(strict_types=1);

namespace Drift\Dev\Smoke\App;

use Drift\Async\Future;

/**
 * @internal 调试夹具：**手动结算**的取数替身（`drift debug --rows=N --resolve`）。
 *
 * ## 为什么不是 `Future::resolved`
 *
 * 立刻结算的 Future 会在 `attach()` 里就到齐 —— 那时候录制器还没装、帧还没开始，
 * 记录里一片空白（实测：`0 重建 / 0 节点`，报告看起来像"这页什么都不做"）。
 * 异步页唯一值得看的那段因果是"**数据到达那一帧**"：写入发生在帧外，由下一帧的
 * settle 消费。所以这里先挂住（`Future::pending()`），由命令行按顺序结算。
 *
 * ## 为什么用 `Future::resolve()`
 *
 * 它标着 `@internal`（"后端与 all/race 专用"）。这个工具**就是**那个后端：
 * 真实后端在别的线程回贴，调试工具在帧与帧之间回贴，位置与时机等价。
 *
 * 行形状照 `AsyncTablePage` 的消费口径（`items` + `total` + `source`）；
 * 内容只求可复现，不求像真数据。
 */
final class PendingRows
{
    /** @var list<Future> 尚未结算的请求，先进先出 */
    private array $pending = [];

    public function __construct(private readonly int $rows)
    {
    }

    /**
     * 作为 `\Closure(int): Future` 递给页面（`$source->fetch(...)`）。
     */
    public function fetch(int $page): Future
    {
        $future = Future::pending();
        $this->pending[] = $future;

        return $future;
    }

    /**
     * 结算最早的一次请求。
     *
     * @return bool 有没有结算到东西（`false` = 没有在途请求，调用方该出提示）
     */
    public function resolveNext(): bool
    {
        $future = \array_shift($this->pending);
        if (!$future instanceof Future) {
            return false;
        }

        $items = [];
        for ($i = 1; $i <= $this->rows; ++$i) {
            // 页号只影响 id 的连续段；报告不展示内容本身，所以按第一页算即可。
            $items[] = [
                'id' => $i,
                'name' => '用户 ' . $i,
                'email' => 'user' . $i . '@example.test',
                'city' => '城市' . ($i % 7),
            ];
        }

        $future->resolve(['items' => $items, 'total' => $this->rows * 8, 'source' => 'debug']);

        return true;
    }
}
