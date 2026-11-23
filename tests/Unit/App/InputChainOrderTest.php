<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\App;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * 键盘六环的派发顺序 —— 写错不报错，只是行为错。
 *
 * Esc 晚一步会被输入框吃掉、菜单关不掉；激活早一步会让应用绑的 Enter 失效。
 * 这里按调用表达式的位置守序，不复制环的逻辑。
 */
final class InputChainOrderTest extends TestCase
{
    #[Test]
    public function 六环按契约顺序派发(): void
    {
        $src = (string) file_get_contents(__DIR__ . '/../../../src/App/InputRouter.php');
        $from = strpos($src, 'public function dispatch(');
        $to = strpos($src, 'private function dispatchPointer(');
        self::assertNotFalse($from);
        self::assertNotFalse($to);
        $body = substr($src, $from, $to - $from);

        $anchors = [
            'Esc 前置'      => 'KeyChord::escape()',
            '⓪ 编辑命令'    => 'dispatchEditCommand($event)',
            '① 焦点链'      => '$focus->dispatchKey($event)',
            '② 加速键'      => '->accelerators()->resolve($event->chord)',
            '③ 激活'        => 'activateFocused($event)',
            '④ 上下文菜单键' => "KeyChord::shift('F10')",
        ];

        $previous = -1;
        foreach ($anchors as $label => $needle) {
            $at = strpos($body, $needle);
            self::assertNotFalse($at, "找不到 {$label} 的派发点（表达式改名 ⇒ 守卫一起改）");
            self::assertGreaterThan($previous, $at, "{$label} 顺序颠倒：它必须在上一环之后");
            $previous = $at;
        }
    }
}
