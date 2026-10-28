<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Support;

use PHPUnit\Framework\TestCase;

/**
 * 冒烟断言的 PHPUnit 基类。
 *
 * ## 为什么不让它"失败即停"
 *
 * PHPUnit 的默认语义是第一条断言失败就中止方法。GUI 断言不适用：一个布局 bug
 * 会连带 10 条红（`offsetY` 错 → 对齐错 → 墨迹错），失败即停只能看到第一条；
 * 修完再跑，会接连看到第 2、3… 条。`tools/smoke.php` 一直用"跑到底 + 末尾汇总"，
 * 一次能把连带面看全 —— 这个口径保留。
 *
 * 所以：`check()` 只记录，`flush()` 在方法末尾把全部失败一次性抛给 PHPUnit。
 * 断言计数照常交给 `addToAssertionCount()`，PHPUnit 的统计、`--testdox`、
 * JUnit XML 都是真的。
 *
 * ## 为什么段代码能一行不改地搬过来
 *
 * 段里调的是**全局函数** `check()`（`tests/Support/functions.php`），它把调用
 * 转发给"当前活动的 TestCase"。于是搬迁 = 把代码整段放进方法体，`check(...)`
 * 一个字都不用动 —— 这是本方案唯一能承受 1727 条规模的做法。
 */
abstract class SmokeTestCase extends TestCase
{
    private static ?self $current = null;

    private int $passed = 0;

    /** @var list<string> */
    private array $failed = [];

    protected function setUp(): void
    {
        parent::setUp();
        self::$current = $this;
    }

    protected function tearDown(): void
    {
        self::$current = null;
        parent::tearDown();
    }

    final public static function currentCase(): ?self
    {
        return self::$current;
    }

    /** 供全局 `check()` 转发；**不要**在测试代码里直接调 */
    final public function recordCheck(string $name, bool $ok, string $detail): void
    {
        $this->addToAssertionCount(1);

        if ($ok) {
            ++$this->passed;

            return;
        }

        $this->failed[] = $name . ($detail === '' ? '' : '  —— ' . $detail);
    }

    /** 把本方法累积的失败一次性抛给 PHPUnit（每个测试方法的最后一句话） */
    final protected function flushChecks(): void
    {
        $total = $this->passed + \count($this->failed);

        // 一条都没跑 = 段没搬进来 / require 静默失败。放过去就是"绿着的空壳"。
        self::assertGreaterThan(0, $total, '这个测试方法一条断言都没跑');

        if ($this->failed === []) {
            return;
        }

        self::fail(\sprintf(
            "%d / %d 条失败：\n  - %s",
            \count($this->failed),
            $total,
            \implode("\n  - ", $this->failed),
        ));
    }
}
