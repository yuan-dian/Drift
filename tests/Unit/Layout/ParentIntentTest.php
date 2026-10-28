<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Layout;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Layout\RenderBox;

/**
 * **"父意图"不许再扩族**（A4 的收口判据，2026-10-28）。
 *
 * ## 什么是"父意图"
 *
 * `grow` / `shrink` / `basis` / `alignSelf`（`RenderFlex` 读）与
 * `insetLeft..Bottom`（`RenderStack` 读）是同一类东西：**子声明、父消费**。
 * 子自己不读它们 ⇒ 没有那个父 ⇒ 它们是死字段、**不报错**。
 *
 * 这类字段今天是 `Modifier` 上的 8 个 + `RenderBox` 上的镜像 8 个，靠
 * `RenderBox::setMods()` 里的两个 `set*Intent()` 一次性派发。
 *
 * ## 为什么这条判据要存在
 *
 * A4 加 `inset` 时，`Modifier` 已经是第 27 个字段。再往下走，`Dock` 的槽名、
 * `Table` 的列提示都会想要同一条通道 —— **每个族都要在 `Modifier` 加字段、
 * 在 `RenderBox` 加镜像与 setter、在 `isDefault()` 加一行**，而漏任何一处的表现
 * 都是"这个修饰静默失效"。
 *
 * ★ 所以这里钉的不是"现在是几个"，而是**族的数目**：
 *   第三族出现 ⇒ 本测试红 ⇒ 逼一次明确决定（引入 `ParentData` 值对象，
 *   而不是第 3 个 `set*Intent()`）。**"再加一组"不是一次小改动，是一次设计决定。**
 *
 * ## 为什么判 `set*Intent()` 的方法数
 *
 * 它是"有几个父在向子收意图"的**机械可观测量**：加族必然加方法（否则没法派发），
 * 而数 `Modifier` 的私有字段数要区分"父意图"与"自己就用"（`width` / `clip`…），
 * 得维护一份硬编码名单 —— 那份名单本身会腐坏。**数方法不需要名单。**
 *
 * ★ 第二条配对：声明的族必须**都被 `setMods()` 派发**。只有第一条的话，
 *   "加了一族但忘了派发"（= 那族全是死字段）反而更绿。
 */
final class ParentIntentTest extends SmokeTestCase
{
    /** 现状 2：`setFlexIntent`（Row/Column）与 `setStackIntent`（Stack） */
    private const FAMILY_LIMIT = 2;

    /** @return list<string> 形如 `setXxxIntent` 的派发口 */
    private static function intentSetters(): array
    {
        $names = [];
        foreach ((new \ReflectionClass(RenderBox::class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            if (\preg_match('/^set[A-Za-z]*Intent$/', $method->name) === 1) {
                $names[] = $method->name;
            }
        }
        \sort($names);

        return $names;
    }

    public function testParentIntentFamiliesStayAtTwo(): void
    {
        $families = self::intentSetters();

        check(
            '布局 · "父意图"只有两族（Row/Column 与 Stack）；第三族 ⇒ 该引入 ParentData，不是再加一个 set*Intent()',
            \count($families) <= self::FAMILY_LIMIT,
            '实际 ' . \count($families) . ' 族：' . \implode(',', $families),
        );

        $this->flushChecks();
    }

    /**
     * 配对判据：**每一族都被 `setMods()` 派发**（声明了却不派发 ⇒ 整族是死字段）。
     *
     * ★ 读的是 `setMods()` 的**源码**（反射拿不到方法体），断言每个 `set*Intent`
     *   的名字都在里面出现。粗，但正好：它要抓的是"忘了写那一行"。
     */
    public function testEveryIntentFamilyIsDispatchedBySetMods(): void
    {
        $method = new \ReflectionMethod(RenderBox::class, 'setMods');
        $src = (string) \file_get_contents((string) $method->getFileName());
        $body = \implode("\n", \array_slice(
            \explode("\n", $src),
            $method->getStartLine() - 1,
            $method->getEndLine() - $method->getStartLine() + 1,
        ));

        $missing = [];
        foreach (self::intentSetters() as $setter) {
            if (!\str_contains($body, $setter . '(')) {
                $missing[] = $setter;
            }
        }

        check(
            '布局 · 每一族父意图都被 setMods() 派发（声明了却不派发 ⇒ 整族死字段）',
            $missing === [],
            $missing === [] ? '' : '没派发：' . \implode(',', $missing),
        );

        $this->flushChecks();
    }

    /**
     * 量具自证：`set*Intent()` 真的存在（≥ 2）。
     *
     * ★ 没有这条，"≤ 2" 在正则写错时（匹配 0 个）也绿 —— 恒真的上限判据。
     */
    public function testTheGaugeItselfSeesTheFamilies(): void
    {
        $families = self::intentSetters();

        check(
            '布局 · 量具自证：真的数到 2 族（正则没写坏）',
            $families === ['setFlexIntent', 'setStackIntent'],
            '实际：' . \implode(',', $families),
        );

        $this->flushChecks();
    }
}
