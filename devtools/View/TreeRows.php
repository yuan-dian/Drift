<?php
declare(strict_types=1);

namespace Drift\DevTools\View;

/**
 * `cascade` 的森林 ⇒ 可显示的行表（展开状态由调用方给）。
 *
 * ## 查询给结构，视图决定显示
 *
 * `cascade` 的两个截断参数（`maxDepth` / `maxChildren`）是**报告**需要的东西：文本
 * 输出有预算，只能给几层几支。面板不是——它能滚。所以面板要的是**完整结构**，
 * 由视图按"用户展开了哪几行"决定显示多少，而不是让查询在数据层就丢掉分支。
 *
 * 这也是"单链折叠"搬到视图层的原因：`A > B` 是**排版**，不是数据。查询里那条链
 * 仍然是三个节点，面板将来要逐层展开时得能拿到它们。
 *
 * ## 单链折叠的三个断开条件
 *
 * 与 CLI 一字不差（同一段逻辑，现在只有一处）：写入点要留在自己那一行（`←` 必须
 * 紧挨着写它的那个类型）、`omitted > 0` 时规模不能再当"下一层减一"往回推、
 * 只有单实例能当链中间的跳板（折叠组可以当链尾）。
 */
final class TreeRows
{
    /**
     * @param list<array<string, mixed>> $roots     `cascade()['roots']`
     * @param array<string, bool>        $collapsed 键 => 已折叠（缺省 = 展开）
     *
     * @return list<TreeRow>
     */
    public static function flatten(array $roots, array $collapsed = []): array
    {
        /** @var list<TreeRow> $rows */
        $rows = [];

        $visit = function (array $node, int $depth, ?string $parentKey) use (&$visit, &$rows, $collapsed): void {
            [$tail, $label] = self::chain($node);
            $id = (int) $tail['id'];
            $key = 't:' . $id;
            /** @var list<array<string, mixed>> $kids */
            $kids = $tail['children'];

            $rows[] = new TreeRow(
                nodeId: $id,
                parentKey: $parentKey,
                depth: $depth,
                label: $label,
                count: (int) $tail['count'],
                subtree: (int) $tail['subtree'],
                kind: (string) $tail['kind'],
                site: (string) $tail['site'],
                detail: (string) $tail['detail'],
                omitted: (int) $tail['omitted'],
                hidden: (int) $tail['hidden'],
                hasChildren: $kids !== [],
            );

            // ★ 折叠按**链尾**的键记：链上中间那些节点本来就没有自己的行，
            //   给它们记一个"已折叠"永远作用不到任何东西。
            if ($kids === [] || isset($collapsed[$key])) {
                return;
            }
            foreach ($kids as $kid) {
                $visit($kid, $depth + 1, $key);
            }
        };

        foreach ($roots as $root) {
            $visit($root, 0, null);
        }

        return $rows;
    }

    /**
     * 从 `$node` 往下吃单链，返回 `[链尾, 标签]`。
     *
     * @param array<string, mixed> $node
     *
     * @return array{0: array<string, mixed>, 1: string}
     */
    private static function chain(array $node): array
    {
        $chain = [$node];
        while (true) {
            /** @var array<string, mixed> $last */
            $last = $chain[\count($chain) - 1];
            if ((string) $last['kind'] !== 'parent' || (int) $last['omitted'] > 0 || (int) $last['count'] !== 1) {
                break;
            }
            /** @var list<array<string, mixed>> $kids */
            $kids = $last['children'];
            if (\count($kids) !== 1) {
                break;
            }
            $chain[] = $kids[0];
        }

        /** @var array<string, mixed> $tail */
        $tail = $chain[\count($chain) - 1];
        $names = [];
        foreach ($chain as $hop) {
            $names[] = (string) $hop['type'];
        }
        $label = \implode(' > ', $names);
        if ((int) $tail['count'] > 1) {
            // 折叠行给一个入口 id：`--why=<id>` 与面板下钻都从这里开始。
            // 单个节点不印 id —— 树讲形状，名单（重建聚合）讲 id，两处各给一份不重复。
            $label .= ' ×' . (int) $tail['count'] . ' #' . (int) $tail['id'];
        }

        return [$tail, $label];
    }
}
