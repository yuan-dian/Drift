<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Layout;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 布局的**失败路径**：五条"该响亮失败"的约束违反（§13.1 的 C5 / C6 与 G2）。
 *
 * ## 为什么必须起子进程
 *
 * `Errors::report()` 的 FATAL 落点是 `exit(3)` —— 进程内**抓不到**
 * （`try/catch` 接不住，`set_exception_handler` 也不生效）。
 * 用例代码在 `tests/Support/fatal_cases.php`，调用走 `runFatalCase()`。
 *
 * ## 每条都是"两条一起"
 *
 * ① 退出码 `=== 3`（该崩的真崩了）；
 * ② 日志里的人话（用户照它就能改）+ **不含内部条款编号**（`C5` / `C6` 是册子里的
 *    编号，应用开发者看不懂）。
 *
 * ★ 只验 ① 会奖励"崩了但只说 'illegal state'"的实现 —— 那种失败等于没崩。
 * ★ 退出码 `2` 是 case 名拼错（`fatal_cases.php` 打 `unknown case`），照 3 判会假绿；
 *   所以 ① 用 `=== 3` 而不是 `!== 0`。
 *
 * ## 与 `RenderBoxContractTest` 的分工
 *
 * | 文件 | 层 | 手段 |
 * |---|---|---|
 * | `RenderBoxContractTest` | 基类契约（排得对不对） | 进程内，裸盒 |
 * | **本文件** | 违反契约时**响不响** | 子进程，退出码 + 日志 |
 */
final class LayoutFailureTest extends SmokeTestCase
{
    private function logPath(): string
    {
        return \sys_get_temp_dir() . '/drift-layoutfail-' . \getmypid() . '.log';
    }

    /**
     * C5 `Report`：溢出了就必须中止，**不是**画到盒外面去。
     *
     * ★ 日志里要同时有"放不下"与差额 —— 只说"溢出"的话，用户不知道该缩谁。
     */
    public function testReportOverflowAborts(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('flex-report-overflow', $log);

        check(
            'C5 Report · 列放不下 ⇒ 退出码 = 3（不画到盒外）',
            $code === 3,
            '实际 ' . $code . ' / ' . $out,
        );
        check(
            'C5 Report · 日志写了"放不下"与差额，且不含内部条款编号 C5',
            \str_contains(logText($log), '放不下')
                && \str_contains(logText($log), '差 ')
                && !\str_contains(logText($log), 'C5'),
            '日志：' . \trim(logText($log)),
        );
    }

    /**
     * ★★ **窗口帧不中止**：同一个溢出放进 `LayoutTolerance::withinWindow()` 里，
     * 退出码必须是 0（裁剪 + 留痕），而不是 3。
     *
     * ## 这条为什么重要
     *
     * 窗口的客户区是**用户的东西**：用户把窗口拖小、或上次把窗口停在很小的尺寸
     * 然后重启 —— 都不该看到 Fatal 框，更不该因此再也打不开（状态文件会把那个
     * 尺寸一直带回来）。成熟框架全是这个口径：Flutter release 静默裁剪，Qt 给
     * `minimumSizeHint` 之后就是裁，WPF / CSS / Android 根本没有"中止"这条路。
     *
     * ## 与 `testReportOverflowAborts` 成对
     *
     * 两条一起才证明宽容是**按层分的**（窗口帧 vs 纯布局），而不是全局把校验
     * 关掉了 —— 后者会让"页面写错布局"再也没人管。
     */
    public function testWindowFrameOverflowIsClippedNotAborted(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('window-frame-overflow-clipped', $log);

        check(
            '窗口帧 · 内容比窗口大 ⇒ 退出码 = 0（裁剪，不中止）',
            $code === 0,
            '实际 ' . $code . ' / ' . $out,
        );
        check(
            '窗口帧 · 连跑三次同一个溢出，诊断只有 1 条（不刷屏）',
            \str_contains($out, 'clipped diag=1'),
            $out,
        );
        check(
            '窗口帧 · 裁剪消息说清了"已裁掉 / 不是崩溃 / 怎么恢复"',
            \str_contains($out, '已裁掉')
                && \str_contains($out, '这不是崩溃')
                && \str_contains($out, '拖大'),
            $out,
        );
    }

    /**
     * C6 的同一分裂：控件自己报出比可用空间更大的尺寸。窗口帧里裁剪（退出码 0），
     * 纯布局里中止（见 `testConstraintViolationAborts`）。
     */
    public function testWindowFrameConstraintViolationIsClipped(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('window-frame-c6-clipped', $log);

        check(
            '窗口帧 · 尺寸超出可用空间 ⇒ 退出码 = 0（裁剪）',
            $code === 0,
            '实际 ' . $code . ' / ' . $out,
        );
        check(
            '窗口帧 · C6 裁剪也留了诊断（不是静默裁掉）',
            \str_contains($out, 'clipped-c6 diag=1'),
            $out,
        );
    }

    /**
     * C5 兜底：`shrink` 只能压弹性子，间距与固定子压不动。
     * 压到 0 之后**仍然**放不下 ⇒ 必须中止，而不是给个负宽度继续排。
     */
    public function testShrinkExhaustedStillAborts(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('flex-shrink-exhausted', $log);

        check(
            'C5 shrink 压到 0 仍放不下 ⇒ 退出码 = 3',
            $code === 3,
            '实际 ' . $code . ' / ' . $out,
        );
        check(
            'C5 shrink 兜底消息说的是"放不下"（不是"所有子都变 0 宽"）',
            \str_contains(logText($log), '放不下'),
            '日志：' . \trim(logText($log)),
        );
    }

    /**
     * 容器上声明 `Overflow::Scroll` **不支持** —— 这与"尚未实现"是两种消息：
     * 前者永远不会做（容器不滚，要滚包一层 `ScrollView`），后者等版本。
     *
     * ★ 判据必须同时查"不支持"与"ScrollView"：只查前半句的话，一条
     *   "尚未实现"的旧文案也能过 —— 而它给用户的指引是"等"，方向就错了。
     */
    public function testScrollOnAContainerIsUnsupported(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('flex-scroll-unsupported', $log);

        check(
            'C5 Scroll · 容器上声明 Scroll ⇒ 退出码 = 3（不静默按 Clip 走）',
            $code === 3,
            '实际 ' . $code . ' / ' . $out,
        );
        check(
            'C5 Scroll · 消息说"不支持"并**指路 ScrollView**（不是"尚未实现"）',
            \str_contains(logText($log), '不支持')
                && \str_contains(logText($log), 'ScrollView')
                && !\str_contains(logText($log), '尚未实现'),
            '日志：' . \trim(logText($log)),
        );
    }

    /**
     * `flex` 份额 < 1 是**构造成员错误**（G2：绝不静默夹紧）。
     *
     * ★ 静默夹到 1 的表现是"给了 `Expanded` 却不生效" —— 用户看不出是自己写错了。
     */
    public function testFlexShareBelowOneIsAMemberError(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('flex-value-below-one', $log);

        check(
            'G2 flex · 份额 0 ⇒ 退出码 = 3（不静默当 1）',
            $code === 3,
            '实际 ' . $code . ' / ' . $out,
        );
        check(
            'G2 flex · 日志点了"flex 份额"这个具体字段',
            \str_contains(logText($log), 'flex 份额'),
            '日志：' . \trim(logText($log)),
        );
    }

    /**
     * C6：`performLayout()` 返回的尺寸超出父给的上界 ⇒ 中止。
     *
     * ★ 夹具（`OversizeBox`）专挑"只超上界 1"的形态 —— 静默夹紧在这儿最难发现：
     *   夹紧之后画面看起来完全正常，只是某个元素比它该有的位置短了 1 像素。
     */
    public function testConstraintViolationAborts(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('layout-oversize-c6', $log);

        check(
            'C6 · 返回尺寸超出上界 ⇒ 退出码 = 3（不静默夹紧）',
            $code === 3,
            '实际 ' . $code . ' / ' . $out,
        );
        check(
            'C6 · 消息是人话（"超出父容器给的可用空间"）且不含条款编号',
            \str_contains(logText($log), '超出父容器给的可用空间')
                && !\str_contains(logText($log), 'C6'),
            '日志：' . \trim(logText($log)),
        );
    }
}
