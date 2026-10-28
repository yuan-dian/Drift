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

    /** 本次方法已经 flush 过（显式调用后置钩子不再重复） */
    private bool $flushed = false;

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

    /**
     * 取走并清空本方法累积的失败（**给"量具自检"用**）。
     *
     * ## 为什么需要它
     *
     * `expectBox()` / `childBoxes()` 这类全局助手本身也要有"**会红**"的证明 ——
     * 否则它们恒绿时，所有依赖它们的断言一起变成假绿，而且**永远查不出来**
     * （G2：量具要有反向自检，与 `lint --selftest` 同理）。
     *
     * 自检的做法只能是"故意喂错期望 ⇒ 读回失败列表"，而 `$failed` 是 private。
     *
     * ## 为什么是"取走"而不是"读"
     *
     * 自检故意制造的失败必须在**同一方法内**被消费掉：留着的话方法末尾的
     * `flushChecks()` 会把它们当成真失败报出去。取走 = 读 + 清，一次完成。
     *
     * ★ 不碰 `$passed`：自检之前跑过的断言仍然算数（计数不会倒退）。
     *
     * @return list<string> 每条是 `标题  —— 实测值`
     */
    final protected function takeFailures(): array
    {
        $failed = $this->failed;
        $this->failed = [];

        return $failed;
    }

    /** 把本方法累积的失败一次性抛给 PHPUnit（每个测试方法的最后一句话） */
    final protected function flushChecks(): void
    {
        $this->flushed = true;
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

    /**
     * 后置条件：方法没显式 `flushChecks()` 时**兜住**。
     *
     * ★ 为什么需要这道兜底（2026-10-28 第 19 批实测踩到）：`check()` 只往
     *   `$failed` 里追加，从不主动 fail —— 谁忘了在方法末尾写那一句，整个方法就
     *   变成"断言全记录、一条也不报"的**绿壳**。本批 5 个新文件 46 条断言一次都没
     *   调 flush，跑出来是 `OK (46 assertions)`，变异自检六组**全绿**才暴露。
     *   ★ 这种错不会自然变红，只能靠"不可能忘"的结构。`assertPostConditions()`
     *   是 PHPUnit 在方法体正常返回后必然调用的钩子，抛出的失败会计为该测试的
     *   failure（不是 error），正好是这里要的语义。
     *   ★ 已有 244 处显式调用照旧生效：`$flushed` 让第二次不再重复（否则第二次
     *   total=0 会误报"一条断言都没跑"）。
     */
    protected function assertPostConditions(): void
    {
        parent::assertPostConditions();

        if ($this->flushed) {
            return;
        }

        $this->flushChecks();
    }
}
