<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Input;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Input\AcceleratorTable;
use Drift\Input\KeyChord;

/**
 * AcceleratorTable：快捷键表（§9.1 第 ① 环）。
 *
 * 判据全部落在值语义上，不碰树也不碰帧 ⇒ Unit 层。
 * 「同一组合绑两次 ⇒ Fatal」那条是**冲突不静默**（G2），要真起子进程验
 * `exit 3` + Errors.log ⇒ 走 `runFatalCase()`（用例在 `tests/Support/fatal_cases.php`）。
 */
final class AcceleratorTableTest extends SmokeTestCase
{
    private function logPath(): string
    {
        return \sys_get_temp_dir() . '/drift-accel-' . \getmypid() . '.log';
    }
    public function testResolveAndCount(): void
    {
        $accel = AcceleratorTable::make()
            ->bind(KeyChord::ctrl('R'), 'users.refresh')
            ->bind(KeyChord::f(5), 'users.refresh');

        check(
            'AcceleratorTable · 绑定后可查（Ctrl+R / F5 ⇒ 同一命令）',
            $accel->resolve(KeyChord::ctrl('R')) === 'users.refresh'
                && $accel->resolve(KeyChord::f(5)) === 'users.refresh',
        );
        check(
            'AcceleratorTable · 未绑定的组合 ⇒ null（调用方继续走焦点链）',
            $accel->resolve(KeyChord::ctrl('N')) === null,
        );
        check(
            'AcceleratorTable · 修饰键不同 ⇒ 不同组合（Ctrl+R ≠ Ctrl+Shift+R）',
            $accel->resolve(KeyChord::make('R', true, false, true)) === null,
        );
        check('AcceleratorTable · 计数 2 / 空表 isEmpty', $accel->count() === 2 && AcceleratorTable::make()->isEmpty());

        $this->flushChecks();
    }

    /**
     * G2：同一组合绑两次 ⇒ 响亮失败。
     *
     * ★ 后绑覆盖前绑的表现是"用户按了没反应，也不报错" —— 典型的半静默，
     *   所以这里必须验到 `exit 3`（`Errors::report()` 的 FATAL 落点）而
     *   不是"返回了什么"。
     * ★ 退出码用 `=== 3` 而不是 `!== 0`：`2` 是 case 名拼错（`unknown case`），
     *   照 `!== 0` 判会把"用例根本没跑"当成通过。
     */
    public function testDuplicateBindingFailsLoudly(): void
    {
        $log = $this->logPath();
        fresh($log);
        [$code, $out] = runFatalCase('accel-conflict', $log);

        check(
            'AcceleratorTable · 同一组合绑两次 ⇒ 退出码 = 3（不后绑覆盖前绑）',
            $code === 3,
            '实际 ' . $code . ' / ' . $out,
        );
        check(
            'AcceleratorTable · 冲突写了 Errors.log（点名 AcceleratorTable）',
            \str_contains(logText($log), 'AcceleratorTable'),
            '日志：' . \trim(logText($log)),
        );

        $this->flushChecks();
    }
}
