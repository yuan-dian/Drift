<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\State;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 帧内重建的**收敛守卫**：build 期标脏必须当帧响亮失败，合法的脏传播必须收敛。
 *
 * 三个用例都在子进程里跑（`Errors::report()` 走 `abort()`，进程内接不住），
 * 用例代码在 `tests/Support/fatal_cases.php`：`flushcycle` / `chainwrite` / `watchbuild`。
 *
 * ## ★★★ 这一批（2026-10-28）顺手挖出一个**绿了很久的假绿**
 *
 * `chainwrite` 原本在 smoke 里的判据是 `$code !== 3`（"不 Fatal 就算收敛"）。
 * 而 `ChainPage` / `InnerPage` 两个夹具都**漏了 `parent::__construct()`**
 * —— `Widget::$mods` 是无默认值的类型化属性，读它就是
 * "must not be accessed before initialization"、退出码 **255**。
 * `!== 3` 被崩溃满足 ⇒ 那条"跨 State 一次性写会收敛"的断言**从未验过收敛**，
 * 它验的是"进程没以 3 退出"。
 *
 * ⇒ 现在判据是 `code === 0` **且**输出含 `reached-end-without-fatal`：
 *   崩溃（255）/ 提前退出（1）/ Fatal（3）三种都不是"收敛"。
 *   夹具已补 `parent::__construct()`，这条断言第一次真的在验传播。
 *
 * ★ 教训与 `20` §"exit=2 是崩了不是变红"同族，但这次错的是**判据本身**：
 *   否定式判据（`!== 3` / `!== 0`）对"别的失败形态"天然免疫，必须配一条正向的
 *   "走到末尾"证据。
 */
final class FlushCycleTest extends SmokeTestCase
{
    /**
     * build 期标脏**自己** ⇒ 当帧 Fatal（`E-FLUSH-CYCLE`）。
     *
     * 不守的后果：每帧默默重建一遍、CPU 上去但没人知道（G2 的半静默）。
     */
    public function testMarkingSelfDirtyDuringBuildFailsOnTheSameFrame(): void
    {
        $log = \sys_get_temp_dir() . '/drift-flushcycle-' . \getmypid() . '.log';
        @\unlink($log);

        [$code, $out] = runFatalCase('flushcycle', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);

        check(
            'settle · build 期标脏自己 ⇒ 当帧 Fatal（退出码 3）',
            $code === 3,
            'code=' . $code . ' out=' . \trim($out)
        );
        check(
            'settle · 不收敛写了 Errors.log（含 E-FLUSH-CYCLE）',
            \str_contains($text, 'E-FLUSH-CYCLE'),
            $text === '' ? '日志为空' : \mb_substr($text, 0, 120)
        );

        $this->flushChecks();
    }

    /**
     * build 期**跨 State** 一次性写祖先 ⇒ 合法脏传播，必须收敛、不误杀。
     *
     * ★ 判据见类注释：`=== 0` + "走到末尾"。**不要**退回 `!== 3`。
     */
    public function testCrossStateWriteDuringBuildConverges(): void
    {
        [$code, $out] = runFatalCase('chainwrite');

        check(
            'settle · build 期跨 State 一次性写 ⇒ 收敛不 Fatal（守卫不误杀合法传播）',
            $code === 0 && \str_contains($out, 'reached-end-without-fatal'),
            'code=' . $code . ' out=' . \trim($out)
        );

        $this->flushChecks();
    }

    /**
     * 订阅写在 build 体内 ⇒ 每重建一次多订一份（A4）⇒ 运行期守卫必须响。
     */
    public function testSubscribingDuringBuildFailsLoudly(): void
    {
        $log = \sys_get_temp_dir() . '/drift-watchbuild-' . \getmypid() . '.log';
        @\unlink($log);

        [$code, $out] = runFatalCase('watchbuild', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);

        check(
            'State · build 期订阅 ⇒ Fatal（退出码 3，A4 运行期守卫）',
            $code === 3,
            'code=' . $code . ' out=' . \trim($out)
        );
        check(
            'State · build 期订阅写了日志（指向 initState）',
            \str_contains($text, 'initState'),
            $text === '' ? '日志为空' : \mb_substr($text, 0, 120)
        );

        $this->flushChecks();
    }
}
