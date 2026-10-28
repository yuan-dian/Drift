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
 * `exit 3` + Errors.log，依赖入口脚本是 smoke ⇒ 留在 `tools/smoke.php`。
 */
final class AcceleratorTableTest extends SmokeTestCase
{
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
}
