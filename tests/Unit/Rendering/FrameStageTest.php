<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\FrameStage;

/**
 * `FrameStage` 的**表完备性** —— 这张表是全仓帧段名的唯一来源，它错了不会崩。
 *
 * ## 为什么这张表要有测试（2026-11-02）
 *
 * 名字原先散在五处，已经漂了两处，两次都不报错：
 *
 * · 三个段**两个名字**（`tickAnimations` / `anim`、`focusCollect` / `focus`、
 *   `pumpSemantics` / `semantics`）⇒ 两份报告对不上，交叉分析靠人脑。
 * · `apps/bench/src/M4.php` 的泵段名单里写着 `placeMenu` —— **没有这个名字**
 *   ⇒ 浮层布局的耗时从未进过泵合计，一直被算进「未归属(帧头尾)」，
 *   于是"帧头尾很贵"这个结论本身是被拼写错误撑起来的。
 *
 * 收成一张表之后，"段名拼错"只剩一种形态：**表本身缺项/重名**。下面的检查
 * 就是冲它去的 —— 用反射拿全部 public 常量，与表双向比对。
 *
 * ## 为什么是常量不是 enum
 *
 * 见 `FrameStage` 的类注释：enum 的穷尽性检查会让 20 个调用点写 `->value`，
 * 而 `SurfaceProfile::add()` 还要收任意段名（测试用 `probe`）。所以「拼错 =
 * 未定义常量」靠语言，**「表与常量集一致」靠本文件**。
 */
final class FrameStageTest extends SmokeTestCase
{
    /**
     * ① 双向比对：每个 public 段名常量都进了表，表里每项都有常量。
     *
     * ★ 这条是"改表忘改常量"与"加常量忘进表"的公共出口 —— 两个方向都堵住，
     *   而不是只测其中一个（只测一个的话，新增段最容易漏的那个方向恰好没测）。
     */
    public function testEveryDeclaredConstantIsInTheTable(): void
    {
        $declared = self::declaredStages();
        $fail = FrameStage::selfTest($declared);

        check(
            \sprintf('FrameStage 表完备（%d 个常量）', \count($declared)),
            $fail === [],
            \implode('；', $fail),
        );

        $this->flushChecks();
    }

    /**
     * ② 九段名单逐位钉住 —— 顺序本身就是契约（`ALL` 按正确顺序排，测试拿它
     *   当"顺序正确的样本"喂 `assertOrder()`）。
     *
     * ★ 不用"长度是 9"这种弱断言：漏一段、重一段、换一段都还是 9 或不是 9，
     *   而逐位对上是唯一能把三者都抓住的形状。
     */
    public function testAllIsExactlyTheNineStagesInOrder(): void
    {
        $want = [
            'pumpIo', 'pumpTasks', 'pumpTimers', 'tickAnimations', 'placeOverlays',
            'settle', 'tickLateMount', 'focusCollect', 'pumpSemantics',
        ];

        check(
            'FrameStage::all() 就是九段，且顺序 = 一帧内的正确顺序',
            FrameStage::all() === $want,
            '实得 ' . \implode(',', FrameStage::all()),
        );

        $this->flushChecks();
    }

    /**
     * ③ 反例：漏一个常量 ⇒ 必须报（否则"新增段忘了登记"会静默）。
     */
    public function testMissingConstantIsReported(): void
    {
        $declared = self::declaredStages();
        $dropped = \array_values(\array_diff($declared, [FrameStage::PUMP_SEMANTICS]));
        $fail = FrameStage::selfTest($dropped);

        check(
            '反例：表里有 pumpSemantics 但没有对应常量 ⇒ 报',
            \count($fail) === 1 && \str_contains($fail[0], FrameStage::PUMP_SEMANTICS),
            $fail === [] ? '(一条都没报)' : \implode('；', $fail),
        );

        check(
            '反例：漏项**只**报那一条（不是一片红 —— 否则真出问题时看不出是哪条）',
            \count($fail) === 1,
            \sprintf('报了 %d 条', \count($fail)),
        );

        $this->flushChecks();
    }

    /**
     * ④ 反例：多一个常量但表里没有 ⇒ 必须报（"加了常量忘了进表"那个方向）。
     */
    public function testUnregisteredConstantIsReported(): void
    {
        $declared = [...self::declaredStages(), 'ghostStage'];
        $fail = FrameStage::selfTest($declared);

        check(
            '反例：有个段名常量不在表里 ⇒ 报（它永远不会被顺序校验看见）',
            \count($fail) === 1 && \str_contains($fail[0], 'ghostStage'),
            $fail === [] ? '(一条都没报)' : \implode('；', $fail),
        );

        $this->flushChecks();
    }

    /**
     * ⑤ 每个有顺序约束的段都登记了后果 —— 否则断言消息只能给一句废话。
     *
     * ★ 这条单独测而不是只靠 `selfTest()`：后果文案是**给人的**，
     *   它写成空字符串时 `selfTest()` 会报，但报的是"没有登记后果"，
     *   而这里直接钉"拿得到非空字符串"。
     */
    public function testEveryOrderedStageExplainsItsConsequence(): void
    {
        foreach (FrameStage::all() as $stage) {
            $why = FrameStage::consequence($stage);
            check(
                \sprintf('段 %s 的后果非空', $stage),
                $why !== null && $why !== '',
                $why === null ? 'null' : '空字符串',
            );
        }

        check(
            '表外的段名没有后果（不兜底 —— 让"漏登记"看得见）',
            FrameStage::consequence('noSuchStage') === null,
            '实得 ' . \var_export(FrameStage::consequence('noSuchStage'), true),
        );

        $this->flushChecks();
    }

    /**
     * ⑥ 三个组的成员逐位钉住。
     *
     * ★ 组名合法但**分错了组**是最隐蔽的一种表错：`selfTest()` 只查"组名在不在
     *   三个合法值里"，而把 `focusCollect` 挪进 `BEFORE` 之后，越界检查会**反向**
     *   报错 —— 正确代码被红，错误代码被放行。只能靠逐位断言。
     */
    public function testGroupsMatchTheOrderingContract(): void
    {
        check(
            'settle 之前 = 四个泵',
            FrameStage::beforeSettle() === ['pumpIo', 'pumpTasks', 'pumpTimers', 'tickAnimations'],
            \implode(',', FrameStage::beforeSettle()),
        );

        check(
            'settle 之后 = 三个观察者（focusCollect 在 pumpSemantics 前 —— 语义树要带焦点）',
            FrameStage::afterSettle() === ['tickLateMount', 'focusCollect', 'pumpSemantics'],
            \implode(',', FrameStage::afterSettle()),
        );

        check(
            '中间 = 浮层落位 + settle 标记',
            FrameStage::core() === ['placeOverlays', 'settle'],
            \implode(',', FrameStage::core()),
        );

        $this->flushChecks();
    }

    /**
     * ⑦ 泵段名单：九段里除 `settle`。
     *
     * ★ 这不是"再断言一次实现"：`apps/bench` 的「未归属」归因直接吃这个名单，
     *   少一段就等于把那段耗藏进"帧头尾"（`placeMenu` 事故就是这样）。
     */
    public function testPumpStagesIsTheNineMinusSettle(): void
    {
        $pump = FrameStage::pumpStages();

        check(
            'pumpStages() 不含 settle（它单独列）',
            !\in_array(FrameStage::SETTLE, $pump, true),
            'settle 混进来了',
        );

        check(
            'pumpStages() 恰好是九段减 settle（8 段）',
            $pump === ['pumpIo', 'pumpTasks', 'pumpTimers', 'tickAnimations', 'placeOverlays', 'tickLateMount', 'focusCollect', 'pumpSemantics'],
            \implode(',', $pump),
        );

        $this->flushChecks();
    }

    /**
     * @return list<string> 全部 public 段名常量的值
     */
    private static function declaredStages(): array
    {
        $out = [];
        $class = new \ReflectionClass(FrameStage::class);
        foreach ($class->getConstants(\ReflectionClassConstant::IS_PUBLIC) as $value) {
            if (\is_string($value)) {
                $out[] = $value;
            }
        }

        return $out;
    }
}
