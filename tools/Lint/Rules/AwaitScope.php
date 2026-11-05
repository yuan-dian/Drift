<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * `->await()` / `->sleep()` 的接收者必须是**所在函数作用域内声明的 AsyncScope 形参**。
 *
 * ★ 依据（Drift3 `probe/async-scope-lint` 7/7 真机）：运行时 `assertInside()` 只能
 *   拦「协程外」越界；AOT 下 `\Fiber::getCurrent()` 在嵌套普通函数里**仍返回该 fiber**
 *   ⇒ 嵌套越界被误判合法，只有静态 lint 能 100% 拦住。
 * ★ 不照抄蓝图「必须直接写在 run 闭包体内」：本仓 `WatermarkStore` 的 6 处 await/sleep
 *   都在带 `AsyncScope $co` 形参的私有方法里（由 body 闭包调用，合法）。按「作用域
 *   显式传参」判据既零误报，又逼出一条可静态检查的纪律：不允许靠属性 / 全局 /
 *   闭包 `use` 偷偷把协程作用域带进别的函数。
 *
 * 诚实边界：不验证「这个函数真的被 fiber 调用」（要跨文件调用图），只守传递方式。
 */
final class AwaitScope implements Rule
{
    private const ACTIONS = ['await', 'sleep'];

    /** 框架自身在 `sleep()` 内调 `$this->await($timer)`，不是业务越界。 */
    private const SELF_FILE = 'src/Async/AsyncScope.php';

    public function name(): string
    {
        return 'await-scope';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '->await()/->sleep() 的接收者必须是本函数声明的 AsyncScope 形参（AOT 运行时 assert 拦不住嵌套越界）';
    }

    public function check(Project $project): array
    {
        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isToolchain($file) || $file->path() === self::SELF_FILE) {
                continue;
            }
            $tokens = $file->tokens();
            foreach (SourceQuery::methodCalls($file, 0, count($tokens), self::ACTIONS) as $hit) {
                $i = $hit['index'];
                $recv = SourceQuery::prevIndex($tokens, $i - 1);
                $var = ($recv !== null && is_array($tokens[$recv]) && $tokens[$recv][0] === T_VARIABLE)
                    ? $tokens[$recv][1] : null;

                $scope = $var === null ? null : self::enclosingFunction($tokens, $i);
                $ok = $scope !== null
                    && self::hasScopeParam($tokens, $scope['from'], $scope['to'], $var);
                if ($ok) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $hit['line'],
                    sprintf(
                        '->%s() 的接收者 %s 不是本函数声明的 AsyncScope 形参'
                        . ' ⇒ 把作用域显式传进来（AOT 下 Fiber::getCurrent() 拦不住嵌套越界）',
                        $hit['name'],
                        $var ?? '（不是裸变量）'
                    )
                );
            }
        }

        return $found;
    }

    /**
     * 找**真正包住**调用点的最近 function / fn，返回其参数表区间。
     *
     * @param list<mixed> $tokens
     * @return array{from: int, to: int}|null
     */
    private static function enclosingFunction(array $tokens, int $at): ?array
    {
        $n = count($tokens);
        for ($i = SourceQuery::prevIndex($tokens, $at - 1); $i !== null;
             $i = SourceQuery::prevIndex($tokens, $i - 1)) {
            $tok = $tokens[$i];
            if (!is_array($tok) || !in_array($tok[0], [T_FUNCTION, T_FN], true)) {
                continue;
            }

            $open = null;
            for ($j = $i + 1; $j < $n; $j++) {
                if ($tokens[$j] === '(') {
                    $open = $j;
                    break;
                }
                if ($tokens[$j] === '{' || $tokens[$j] === ';' || $tokens[$j] === '}') {
                    break;
                }
            }
            if ($open === null) {
                continue;
            }
            $close = SourceQuery::matchBracket($tokens, $open);
            if ($close === null || $close >= $at) {
                continue;
            }

            if ($tok[0] === T_FN) {
                // 箭头函数体到第一个 `;` / `,` / 右括号为止（本仓箭头函数里无 await）。
                $end = $n;
                for ($j = $close + 1; $j < $n; $j++) {
                    if (in_array($tokens[$j], [';', ',', ')'], true)) {
                        $end = $j;
                        break;
                    }
                }
                if ($at < $end) {
                    return ['from' => $open + 1, 'to' => $close - 1];
                }
                continue;
            }

            $brace = null;
            for ($j = $close + 1; $j < $n; $j++) {
                if ($tokens[$j] === '{') {
                    $brace = $j;
                    break;
                }
                if ($tokens[$j] === ';') {
                    break; // 抽象方法 / 接口声明
                }
            }
            if ($brace === null) {
                continue;
            }
            $bodyEnd = SourceQuery::matchBracket($tokens, $brace);
            if ($bodyEnd !== null && $brace < $at && $at < $bodyEnd) {
                return ['from' => $open + 1, 'to' => $close - 1];
            }
            // 这个 function 的体不包住调用点 ⇒ 继续往前找更外层。
        }

        return null;
    }

    /** @param list<mixed> $tokens */
    private static function hasScopeParam(array $tokens, int $from, int $to, string $var): bool
    {
        for ($i = $from; $i <= $to; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || !in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            if (preg_match('/(?:^|\\\\)AsyncScope$/', $tok[1]) !== 1) {
                continue;
            }
            $next = SourceQuery::nextIndex($tokens, $i + 1);
            if ($next === null || $next > $to) {
                continue;
            }
            $nt = $tokens[$next];
            if (is_array($nt) && $nt[0] === T_VARIABLE && $nt[1] === $var) {
                return true;
            }
        }

        return false;
    }
}
