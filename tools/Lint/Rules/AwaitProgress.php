<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * 会 `JobProgress::report()` 的 Job 被 `await()` 时，必须传第二参订进度。
 *
 * ★ 真事故（§68）：`DownloadResultsJob` 每张结果图都报进度，`downloadResults()`
 *   的 `$co->await($this->exec(new DownloadResultsJob($urls)))` 没传第二参 ⇒
 *   「Job 发了、没人订」，N 张图下载阶段界面只有转圈。不抛异常、不改结果，
 *   单元测试若只断言落地结果也全绿 —— 本仓老话：**未消费的分支 = 未实现**。
 *   上传侧 `$co->await($future, fn(float $f) => ...)` 是现成模板。
 *
 * 判据是跨文件的：先认出「哪些 Job 会报进度」，再看 `await` 站点订没订。
 * 两种形状都管：内联 `await(exec(new XJob(...)))` 与 `$f = exec(new XJob(...)); await($f)`。
 *
 * 诚实边界：变量形式只在本文件内按变量名做最后一次赋值追溯，不跨文件、不进调用图；
 * 进度集合按类短名匹配（本仓 Job 短名唯一）。收窄是刻意避开误报，不是漏做。
 */
final class AwaitProgress implements Rule
{
    public function name(): string
    {
        return 'await-progress';
    }

    public function serves(): string
    {
        return 'G1';
    }

    public function summary(): string
    {
        return '会报 JobProgress 的 Job 被 await() 时必须传第二参（否则进度静默丢失）';
    }

    public function check(Project $project): array
    {
        $reporting = self::reportingJobs($project);
        if ($reporting === []) {
            return [];
        }

        $found = [];
        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue;
            }
            $tokens = $file->tokens();
            $assignments = self::jobAssignments($tokens);
            foreach (SourceQuery::methodCalls($file, 0, count($tokens), ['await']) as $hit) {
                $nameI = SourceQuery::nextIndex($tokens, $hit['index'] + 1);
                if ($nameI === null) {
                    continue;
                }
                $open = SourceQuery::nextIndex($tokens, $nameI + 1);
                if ($open === null || $tokens[$open] !== '(') {
                    continue;
                }
                $close = SourceQuery::matchBracket($tokens, $open);
                if ($close === null) {
                    continue;
                }

                $commas = self::topLevelCommas($tokens, $open + 1, $close - 1);
                if ($commas !== []) {
                    continue; // 第二参（或更多）已经订了
                }

                $job = self::jobInRange($tokens, $open + 1, $close - 1, $assignments);
                if ($job === null || !isset($reporting[strtolower($job)])) {
                    continue;
                }

                $found[] = new Diagnostic(
                    $this->name(),
                    Diagnostic::ERROR,
                    $file->path(),
                    $hit['line'],
                    sprintf(
                        'await(%s) 没订进度：该 Job 会 JobProgress::report()，'
                        . '传第二参 function (float $fraction): void 才能把进度送到界面',
                        $job
                    )
                );
            }
        }

        return $found;
    }

    /** @return array<string, true> 类短名 => 会报进度 */
    private static function reportingJobs(Project $project): array
    {
        $out = [];
        foreach ($project->files() as $file) {
            $tokens = $file->tokens();
            $n = count($tokens);
            $reports = false;
            for ($i = 0; $i < $n; $i++) {
                $tok = $tokens[$i];
                if (!is_array($tok) || $tok[0] !== T_STRING
                    || strcasecmp($tok[1], 'JobProgress') !== 0) {
                    continue;
                }
                $colon = SourceQuery::nextIndex($tokens, $i + 1);
                if ($colon === null || !is_array($tokens[$colon]) || $tokens[$colon][0] !== T_DOUBLE_COLON) {
                    continue;
                }
                $method = SourceQuery::nextIndex($tokens, $colon + 1);
                if ($method === null || !is_array($tokens[$method]) || $tokens[$method][0] !== T_STRING
                    || strcasecmp($tokens[$method][1], 'report') !== 0) {
                    continue;
                }
                $call = SourceQuery::nextIndex($tokens, $method + 1);
                if ($call !== null && $tokens[$call] === '(') {
                    $reports = true;
                    break;
                }
            }
            if (!$reports) {
                continue;
            }
            foreach (self::declaredClasses($tokens) as $class) {
                $out[$class] = true;
            }
        }

        return $out;
    }

    /** @param list<mixed> $tokens @return list<string> 小写类短名 */
    private static function declaredClasses(array $tokens): array
    {
        $out = [];
        foreach ($tokens as $i => $tok) {
            if (!is_array($tok) || $tok[0] !== T_CLASS) {
                continue;
            }
            $at = SourceQuery::nextIndex($tokens, $i + 1);
            if ($at !== null && is_array($tokens[$at]) && $tokens[$at][0] === T_STRING) {
                $out[] = strtolower($tokens[$at][1]);
            }
        }

        return $out;
    }

    /** @param list<mixed> $tokens @return list<int> 顶层逗号下标 */
    private static function topLevelCommas(array $tokens, int $from, int $to): array
    {
        $out = [];
        $depth = 0;
        for ($i = $from; $i <= $to; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok)) {
                continue;
            }
            if (in_array($tok, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($tok, [')', ']', '}'], true)) {
                $depth--;
            } elseif ($tok === ',' && $depth === 0) {
                $out[] = $i;
            }
        }

        return $out;
    }

    /** @param list<mixed> $tokens @return array<string, string> 变量名 => Job 短名 */
    private static function jobAssignments(array $tokens): array
    {
        $out = [];
        $n = count($tokens);
        for ($i = 0; $i < $n; $i++) {
            $tok = $tokens[$i];
            if (!is_array($tok) || $tok[0] !== T_VARIABLE) {
                continue;
            }
            $eq = SourceQuery::nextIndex($tokens, $i + 1);
            if ($eq === null || $tokens[$eq] !== '=') {
                continue;
            }
            $end = $eq + 1;
            while ($end < $n && $tokens[$end] !== ';') {
                $end++;
            }
            $job = self::jobInRange($tokens, $eq + 1, min($end - 1, $n - 1), []);
            if ($job !== null) {
                $out[$tok[1]] = $job;
            }
        }

        return $out;
    }

    /**
     * @param list<mixed> $tokens
     * @param array<string, string> $assignments
     */
    private static function jobInRange(array $tokens, int $from, int $to, array $assignments): ?string
    {
        for ($i = $from; $i <= $to; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok) && $tok[0] === T_NEW) {
                $at = SourceQuery::nextIndex($tokens, $i + 1);
                if ($at !== null && is_array($tokens[$at])
                    && in_array($tokens[$at][0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
                    $name = $tokens[$at][1];
                    $pos = strrpos($name, '\\');
                    return $pos === false ? $name : substr($name, $pos + 1);
                }
            }
        }
        // 变量形式：$future = exec(new XJob(...)); await($future)
        for ($i = $from; $i <= $to; $i++) {
            $tok = $tokens[$i];
            if (is_array($tok) && $tok[0] === T_VARIABLE && isset($assignments[$tok[1]])) {
                return $assignments[$tok[1]];
            }
        }

        return null;
    }
}
