<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\App;

use Drift\Dev\Smoke\App\ScrollApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;

/**
 * UI 线程身份（§17.4.2.1）—— WPF `VerifyAccess()` 的等价物。
 *
 * ## 为什么值得单独一个文件
 *
 * 它只有三行实现，但失效形态是**数据竞争**：只在压力下偶发、日志里看不出、
 * 复现不了。所以它必须是**响亮失败**，而不是"记个 id 备用"。
 *
 * ## 两条判据（原来的写法两条都是恒真）
 *
 * ① **未挂载 = 0**：`uiThreadId()` 在 `attach()` 之前必须是 0。
 *   原来只断言"挂载后 `!== 0`" —— 那在"恒返 1"的实现下也绿，验不出"记下了"。
 * ② **非 UI 线程必须 Fatal**：原来那行是 `check(..., true, 'ok')`，恒真
 *   （"调用不炸"这件事本身没有断言能表达，只能靠子进程验反向）。
 *   用例在 `tests/Support/fatal_cases.php: ui-thread-mismatch`。
 *
 * ★ 解释器面的 `drift_thread_id()` 是固定值替身（`tools/autoload.php`），
 *   造不出第二个真线程 ⇒ 反向用例用反射把 `uiThreadId` 设成"别的线程"。
 *   AOT 产物里它是 `GetCurrentThreadId()`，语义一致。
 */
final class UiThreadTest extends SmokeTestCase
{
    private function logPath(): string
    {
        return \sys_get_temp_dir() . '/drift-uithread-' . \getmypid() . '.log';
    }

    public function testThreadIdIsZeroBeforeAttachAndCurrentAfter(): void
    {
        $unmounted = new ScrollApplication();
        check(
            'App · 未挂载时 uiThreadId = 0（"还没挂"与"挂在 0 号线程"必须分得开）',
            $unmounted->uiThreadId() === 0,
            (string) $unmounted->uiThreadId()
        );

        $app = new ScrollApplication();
        $app->attach(new SurfaceProbe(), BoxConstraints::tight(new Size(320.0, 200.0)));
        check(
            'App · UI 线程身份在 attach 时记下（!= 0；0 = 未挂载）',
            $app->uiThreadId() !== 0,
            (string) $app->uiThreadId()
        );
        check(
            'App · 当前线程就是 UI 线程（uiThreadId == drift_thread_id()）',
            $app->uiThreadId() === \drift_thread_id(),
            $app->uiThreadId() . ' vs ' . \drift_thread_id()
        );
    }

    /** 非 UI 线程 ⇒ Fatal，且报错里带"两个 id"（不然查不出是哪个线程） */
    public function testAssertUiThreadFailsOnForeignThread(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('ui-thread-mismatch', $log);
        check(
            'App · 非 UI 线程上 assertUiThread ⇒ Fatal（退出码 3，不静默放行）',
            $code === 3,
            '实际 ' . $code . ' / ' . \trim($out)
        );
        $text = logText($log);
        check(
            'App · 线程断言的报错点明"非 UI 线程"并给出 UI / 当前两个 id',
            \str_contains($text, '非 UI 线程') && \str_contains($text, 'UI=') && \str_contains($text, '当前='),
            $text === '' ? '空日志' : \strlen($text) . ' 字节'
        );
    }
}
