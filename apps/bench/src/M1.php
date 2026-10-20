<?php
declare(strict_types=1);

namespace Drift\Bench;

/**
 * M1：`toObject` 的成本（v7 的 V3 未知数）。
 *
 * ── 这组基准要回答的唯一问题 ──────────────────────────────────────────────
 * v7 的"一棵树"让**孩子存在 `array` 里**，遍历时用 `toObject(Node::class)` 把
 * `php::Var` 恢复成 `Node` 再调静态方法。实现的形状不同：`RenderFlex::$kids`
 * 也是裸 `array`，遍历时直接 `$kid->layout($sub)` —— 那是**动态方法分派**。
 *
 * ⇒ 所以判据**不是**"toObject 比类型化引用贵多少"（那个差距今天也存在），
 *   而是"**1 次 toObject + N 次静态调用** 与 **N 次动态分派** 谁更便宜"。
 *
 * ── 四组 ────────────────────────────────────────────────────────────────
 *   A  toObject + 静态调用         ← v7 的形状
 *   B  直接 `$v->layout()`         ← 实现的形状（动态分派）
 *   C  父持类型化引用（8 度 unroll）← 理想下界，**形状不同，只作信息量**
 *   D  纯 foreach（不调方法）      ← 把"迭代本身"的成本分离出来
 *
 * 所有结果都归一化成 **ns / 节点**，所以 A/B/C/D 可以直接比。
 */
final class M1
{
    /** 夹具规模（v7 的 `Node` 数量级；5000 足够压过计时噪声） */
    private const NODES = 5000;

    /** 每组的重复轮数（取中位数） */
    private const ROUNDS = 200;

    /**
     * @return array<string, array{ns: int, acc: float}> 组名 → (每轮中位纳秒, 累加值防优化)
     */
    public static function run(): array
    {
        $nodes = self::fixture(self::NODES);
        $fanout = self::fanout($nodes);
        $out = [];

        $out['A toObject+static'] = self::measure(self::NODES, static function () use ($nodes): float {
            $acc = 0.0;
            foreach ($nodes as $v) {
                $n = $v->toObject(BenchNode::class);
                $n->layout(1.0);
                $acc += $n->w;
            }

            return $acc;
        });

        $out['B dynamic dispatch'] = self::measure(self::NODES, static function () use ($nodes): float {
            $acc = 0.0;
            foreach ($nodes as $v) {
                $v->layout(1.0);
            }

            return $acc;
        });

        $out['C typed refs x8'] = self::measure(self::NODES, static function () use ($fanout): float {
            foreach ($fanout as $f) {
                $f->layoutAll(1.0);
            }

            return 0.0;
        });

        $out['D foreach only'] = self::measure(self::NODES, static function () use ($nodes): float {
            $acc = 0.0;
            foreach ($nodes as $v) {
                $acc += 1.0;
            }

            return $acc;
        });

        return $out;
    }

    /**
     * @return array<int, BenchNode>
     */
    private static function fixture(int $n): array
    {
        $nodes = [];
        for ($i = 0; $i < $n; $i++) {
            $node = new BenchNode();
            $node->kind = $i % 7;
            $node->x = (float) ($i % 40);
            $node->y = (float) ($i % 30);
            $nodes[] = $node;
        }

        return $nodes;
    }

    /**
     * 把 `$nodes` 每 8 个装进一个 `Fanout8`（C 组的输入）。
     *
     * @param array<int, BenchNode> $nodes
     * @return array<int, Fanout8>
     */
    private static function fanout(array $nodes): array
    {
        $groups = [];
        $count = \count($nodes);
        for ($i = 0; $i + 7 < $count; $i += 8) {
            $groups[] = new Fanout8(
                $nodes[$i],
                $nodes[$i + 1],
                $nodes[$i + 2],
                $nodes[$i + 3],
                $nodes[$i + 4],
                $nodes[$i + 5],
                $nodes[$i + 6],
                $nodes[$i + 7],
            );
        }

        return $groups;
    }

    /**
     * 跑 `ROUNDS` 轮，返回**每节点**的中位纳秒数。
     *
     * @param callable():float $body
     * @return array{ns: int, acc: float}
     */
    private static function measure(int $nodeCount, callable $body): array
    {
        $samples = [];
        $acc = 0.0;

        // 预热：让常量池 / 分支预测 / 缓存进入稳态（第一轮永远偏慢）
        for ($i = 0; $i < 10; $i++) {
            $acc += $body();
        }

        for ($i = 0; $i < self::ROUNDS; $i++) {
            $t0 = \hrtime(true);
            $acc += $body();
            $t1 = \hrtime(true);
            $samples[] = $t1 - $t0;
        }

        $median = Stats::median($samples);
        // 归一化：A/B/D 每轮遍历 NODES 个节点；C 每轮遍历 (NODES/8)*8 = NODES 个
        $perNode = (int) \round((float) $median / (float) $nodeCount);

        return ['ns' => $perNode, 'acc' => $acc];
    }
}
