<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Widget;

use Drift\Dev\Tests\Support\LazyFixtures;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 虚拟列表的**滚动位置保持**（`LazyListState` 的 key 锚点，`04` §7.3）。
 *
 * 问题：搜索把 10000 行筛成 30 行时，滚动位置怎么办？
 * 按**行号**保持会跳到列表末尾（锚点那一行已经不存在了）⇒ 必须按**身份 key** 保持。
 *
 * ★ 每条都写了它抓的反例。行高 40 + 视口 240 ⇒ 滚到 4000 时顶部是第 100 行，
 *   "删掉第 1 行 ⇒ 3960" 这个减法才只有一个正确值。
 */
final class LazyAnchorTest extends SmokeTestCase
{
    public function testKeyedListKeepsTheSameRowOnTop(): void
    {
        // ⑩ 有 keyOf：删掉第 1 行 ⇒ **同一行**仍在视口顶部（offset 4000 → 3960）
        //    反例：按行号保持 ⇒ offset 仍是 4000 ⇒ 顶部变成"行 101"（跳了一行）。
        $root = LazyFixtures::mount(LazyFixtures::keyed(LazyFixtures::range(0, 500), true));
        $state = LazyFixtures::stateOf($root);
        $state?->scrollBy(4000.0);
        LazyFixtures::layout($root);

        $topBefore = LazyFixtures::topRow($root);
        $root->update(LazyFixtures::keyed(LazyFixtures::range(1, 500), true));
        LazyFixtures::layout($root);

        check(
            'Lazy 锚点 · 有 keyOf：删掉第 1 行 ⇒ 原第 100 行仍在顶部（4000 → 3960）',
            $state?->offset() === 3960.0
                && $topBefore === '行 100'
                && LazyFixtures::topRow($root) === '行 100',
            'offset=' . ($state?->offset() ?? -1.0)
                . ' 前=' . $topBefore . ' 后=' . LazyFixtures::topRow($root),
        );
    }

    public function testUnkeyedListFallsBackToRowIndex(): void
    {
        // ⑪ 无 keyOf：同样的操作 ⇒ 按行号夹，顶部换行（**允许跳**，但行为可预测）
        //    反例：没有 keyOf 也想按身份保持 ⇒ 只能靠猜，猜错就是随机跳。
        $root = LazyFixtures::mount(LazyFixtures::keyed(LazyFixtures::range(0, 500), false));
        $state = LazyFixtures::stateOf($root);
        $state?->scrollBy(4000.0);
        LazyFixtures::layout($root);

        $root->update(LazyFixtures::keyed(LazyFixtures::range(1, 500), false));
        LazyFixtures::layout($root);

        check(
            'Lazy 锚点 · 无 keyOf：按行号夹（offset 仍 4000，顶部变成"行 101"）',
            $state?->offset() === 4000.0 && LazyFixtures::topRow($root) === '行 101',
            'offset=' . ($state?->offset() ?? -1.0) . ' top=' . LazyFixtures::topRow($root),
        );
    }

    public function testRebuildWithoutContentChangeLeavesOffsetAlone(): void
    {
        // ⑫ 父重建但内容没变 ⇒ 偏移一点不动（**守门夹具**）
        //    反例：`didUpdateWidget` 里无条件重算 offset ⇒ 每帧按锚点重算一遍，
        //    浮点误差把滚动位置慢慢磨掉（"滚到 4000 变成 3999.98"）。
        $root = LazyFixtures::mount(LazyFixtures::keyed(LazyFixtures::range(0, 500), true));
        $state = LazyFixtures::stateOf($root);
        $state?->scrollBy(4000.0);
        LazyFixtures::layout($root);

        for ($i = 0; $i < 3; $i++) {
            $root->update(LazyFixtures::keyed(LazyFixtures::range(0, 500), true));
            LazyFixtures::layout($root);
        }

        check(
            'Lazy 锚点 · 内容没变的重建不碰偏移（连重建 3 次仍是 4000）',
            $state?->offset() === 4000.0,
            (string) ($state?->offset() ?? -1.0),
        );
    }

    public function testVanishedAnchorReturnsToTop(): void
    {
        // ⑬ 锚点行被筛掉 ⇒ 回顶部（不夹到末尾）
        //    反例：`min($index, $count - 1)` 不加 key 查找 ⇒ 10000 行筛成 30 行时
        //    跳到列表末尾 —— 比跳回顶部更糟。
        $root = LazyFixtures::mount(LazyFixtures::keyed(LazyFixtures::range(0, 500), true));
        $state = LazyFixtures::stateOf($root);
        $state?->scrollBy(4000.0);
        LazyFixtures::layout($root);

        $root->update(LazyFixtures::keyed(LazyFixtures::range(0, 51), true));   // 锚点行 100 被筛掉
        LazyFixtures::layout($root);

        check(
            'Lazy 锚点 · 锚点行被筛掉 ⇒ 回顶部（offset 0，不是跳到末尾）',
            $state?->offset() === 0.0 && LazyFixtures::topRow($root) === '行 0',
            'offset=' . ($state?->offset() ?? -1.0) . ' top=' . LazyFixtures::topRow($root),
        );
    }
}
