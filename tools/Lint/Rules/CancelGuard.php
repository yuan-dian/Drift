<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * 可取消上下文里的 `catch (\Throwable)` 前必须先 `catch (AsyncCancelled) { throw; }`。
 *
 * ★ 依据（`WatermarkStore::fetchResult()` 注释 + `DEBT §33`）：宽 catch 是为了
 *   「查一次失败不该停轮询」，但它会把用户点「停止」投进来的 `AsyncCancelled`
 *   一起接住 ⇒ 循环继续、又排一个定时器，界面显示「点了停止还在查」，
 *   且取消被当成业务错误报出来。运行时 `AsyncScope::guardCancelled()` 只在
 *   **下一个让出点**补抛，宽 catch 到让出点之间的错误文案已经写出去了。
 *   ⇒ 只有静态门禁能把「顺序」钉死，运行时守卫做不到。
 *
 * 「可取消上下文」= 同一个 try 块里出现了 `->await()` / `->sleep()`。
 * 不在这个上下文里的 catch（纯 IO / 渲染 / 日志）不报，避免误伤全仓。
 *
 * 诚实边界：按 token 回扫到最近的 `T_TRY`，不建 AST；嵌套 try 会取内层，
 * 只可能少报、不会误报。也不检查 guard 里是不是真的 `throw`，那是运行期的事。
 */
final class CancelGuard implements Rule
{
    public function name(): string
    {
        return 'cancel-guard';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '含 await()/sleep() 的 try 若 catch (\\Throwable)，前面必须先 catch (AsyncCancelled) 并抛出';
    }

    public function check(Project $project): array
    {
        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue;
            }
            $tokens = $file->tokens();
            foreach ($tokens as $i => $tok) {
                if (!is_array($tok) || $tok[0] !== T_CATCH) {
                    continue;
                }
                $open = SourceQuery::nextIndex($tokens, $i + 1);
                if ($open === null || $tokens[$open] !== '(') {
                    continue;
                }
                $close = SourceQuery::matchBracket($tokens, $open);
                if ($close === null || !self::catchesThrowable($tokens, $open + 1, $close - 1)) {
                    continue;
                }

                $try = null;
                for ($j = SourceQuery::prevIndex($tokens, $i - 1); $j !== null;
                     $j = SourceQuery::prevIndex($tokens, $j - 1)) {
                    if (is_array($tokens[$j]) && $tokens[$j][0] === T_TRY) {
                        $try = $j;
                        break;
                    }
                }
                if ($try === null) {
                    continue;
                }

                $await = false;
                $guard = false;
                for ($k = $try; $k < $i; $k++) {
                    $one = $tokens[$k];
                    if (!is_array($one)) {
                        continue;
                    }
                    if ($one[0] === T_STRING && strcasecmp($one[1], 'AsyncCancelled') === 0) {
                        $guard = true;
                    }
                    if ($one[0] !== T_OBJECT_OPERATOR) {
                        continue;
                    }
                    $nameI = SourceQuery::nextIndex($tokens, $k + 1);
                    if ($nameI !== null && is_array($tokens[$nameI]) && $tokens[$nameI][0] === T_STRING
                        && in_array(strtolower($tokens[$nameI][1]), ['await', 'sleep'], true)) {
                        $await = true;
                    }
                }

                if (!$await || $guard) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $tok[2],
                    'catch (\\Throwable) 会吞掉 AsyncCancelled：先加 '
                    . 'catch (AsyncCancelled $e) { throw $e; }（取消不是业务错误，也不该让循环多跑一轮）'
                );
            }
        }

        return $found;
    }

    /** @param list<mixed> $tokens */
    private static function catchesThrowable(array $tokens, int $from, int $to): bool
    {
        for ($i = $from; $i <= $to; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok)) {
                continue;
            }
            if (!in_array($tok[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                continue;
            }
            $name = ltrim($tok[1], '\\');

            return strcasecmp($name, 'Throwable') === 0;
        }

        return false;
    }
}
