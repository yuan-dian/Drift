<?php
declare(strict_types=1);

namespace Drift\Dev\Lint\Rules;

use Drift\Dev\Lint\Diagnostic;
use Drift\Dev\Lint\Project;
use Drift\Dev\Lint\Rule;
use Drift\Dev\Lint\SourceQuery;

/**
 * §16.6.3 #7：派生缓存失效（G2）。
 *
 * 判据：`Store` 里出现 `??=` 赋值的属性（缓存槽）⇒ **所有会修改其它字段的方法**
 * 必须把该缓存槽重置为 `null`。否则"改了数据、画面不更新"—— 缓存撒谎，且静默。
 *
 * ★ 保守定向：本规则只扫**类名以 `Store` 结尾**的类（§16.6.3 的原文范围）。
 *   框架自己内部的惰性缓存（`RenderText::$list`、`DeterministicMetricsProvider::$cache`）
 *   不在此列 —— 那是实现细节，不是"派生字段"语义，别用一个规则覆盖两个语义。
 * ★ 只认**字段级**缓存槽（`$this->x ??= …`）。`$this->cache[$k] ??= …` 是数组缓存，
 *   失效条件不在属性层 ⇒ 不在本规则范围（诚实边界）。
 * ★ `__construct` 豁免：字段与缓存的初始化顺序在那里是显式写明的。
 */
final class StaleDerived implements Rule
{
    public function name(): string
    {
        return 'stale-derived';
    }

    public function serves(): string
    {
        return 'G2';
    }

    public function summary(): string
    {
        return 'Store 改了依赖字段必须重置 ??= 缓存槽，否则缓存撒谎';
    }

    public function check(Project $project): array
    {
        $found = [];

        foreach ($project->files() as $file) {
            if ($project->isToolchain($file)) {
                continue; // 工具跑在 php.exe 里、不进 AOT 产物 ⇒ 产物纪律不适用（同 nano-hygiene）
            }
            foreach (SourceQuery::classes($file) as $class) {
                if (!str_ends_with(strtolower($class['name']), 'store')) {
                    continue;
                }

                $slots = [];
                foreach ($class['methods'] as $range) {
                    foreach ($this->cacheSlots($file, $range) as $slot) {
                        $slots[$slot] = true;
                    }
                }
                if ($slots === []) {
                    continue; // 没有缓存槽 ⇒ 本条规则无事可做
                }

                foreach ($class['methods'] as $name => $range) {
                    if ($name === '__construct') {
                        continue;
                    }

                    $writes = $this->fieldWrites($file, $range, $slots);
                    if ($writes === []) {
                        continue; // 只写缓存槽（或什么都不写）⇒ 不是失效点
                    }

                    $resets = $this->slotResets($file, $range);
                    $missing = array_diff(array_keys($slots), $resets);
                    if ($missing === []) {
                        continue;
                    }

                    $found[] = new Diagnostic(
                        $this->name(),
                        Diagnostic::ERROR,
                        $file->path(),
                        $range['line'],
                        sprintf(
                            '%s::%s() 改了 %s，但没有重置缓存槽 %s ⇒ 缓存会继续返回旧值（§16.6.3 #7）',
                            $class['name'],
                            $name,
                            implode(' / ', array_map(static fn(string $p): string => '$this->' . $p, $writes)),
                            implode(' / ', array_map(static fn(string $p): string => '$this->' . $p, $missing))
                        )
                    );
                }
            }
        }

        return $found;
    }

    /**
     * 方法体里的 `$this->X ??= …` 缓存槽。
     *
     * @param array{start: int, end: int, line: int} $range
     * @return list<string>
     */
    private function cacheSlots(\Drift\Dev\Lint\SourceFile $file, array $range): array
    {
        $tokens = $file->tokens();
        $out = [];

        for ($i = $range['start']; $i < $range['end']; $i++) {
            $prop = $this->thisPropertyAt($tokens, $i);
            if ($prop === null) {
                continue;
            }
            $next = SourceQuery::nextIndex($tokens, $i + 2);
            if ($next !== null && $next < $range['end'] && $tokens[$next][0] === T_COALESCE_EQUAL) {
                $out[] = $prop;
            }
        }

        return $out;
    }

    /**
     * 方法体里被赋值的普通字段（非缓存槽）。
     *
     * @param array{start: int, end: int, line: int} $range
     * @param array<string, true> $slots
     * @return list<string>
     */
    private function fieldWrites(\Drift\Dev\Lint\SourceFile $file, array $range, array $slots): array
    {
        $tokens = $file->tokens();
        $out = [];

        for ($i = $range['start']; $i < $range['end']; $i++) {
            $prop = $this->thisPropertyAt($tokens, $i);
            if ($prop === null || isset($slots[$prop])) {
                continue;
            }
            $next = SourceQuery::nextIndex($tokens, $i + 2);
            if ($next === null || $next >= $range['end']) {
                continue;
            }
            // ★ 不能加 `is_array` 护门：`=` 是**单字符 token**（`'='`），不是数组。
            //   加了就等于把本规则唯一要找的形态全部过滤掉 ⇒ 一条都不响。
            if ($tokens[$next] === '=') {
                $out[] = $prop;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * 方法体里被重置为 null 的缓存槽。
     *
     * @param array{start: int, end: int, line: int} $range
     * @return list<string>
     */
    private function slotResets(\Drift\Dev\Lint\SourceFile $file, array $range): array
    {
        $tokens = $file->tokens();
        $out = [];

        for ($i = $range['start']; $i < $range['end']; $i++) {
            $prop = $this->thisPropertyAt($tokens, $i);
            if ($prop === null) {
                continue;
            }
            $next = SourceQuery::nextIndex($tokens, $i + 2);
            if ($next === null || $next >= $range['end'] || $tokens[$next] !== '=') {
                continue;
            }
            $value = SourceQuery::nextIndex($tokens, $next + 1);
            if ($value !== null && $value < $range['end']
                && is_array($tokens[$value]) && $tokens[$value][0] === T_STRING
                && strcasecmp($tokens[$value][1], 'null') === 0) {
                $out[] = $prop;
            }
        }

        return $out;
    }

    /**
     * 下标 `$i` 是否落在 `$this->` 的 `->` 上；是则返回后面的属性名（小写）。
     *
     * @param list<mixed> $tokens
     */
    private function thisPropertyAt(array $tokens, int $i): ?string
    {
        $tok = $tokens[$i];
        if (!is_array($tok) || $tok[0] !== T_OBJECT_OPERATOR) {
            return null;
        }
        $prev = SourceQuery::prevIndex($tokens, $i - 1);
        if ($prev === null || $tokens[$prev][0] !== T_VARIABLE || $tokens[$prev][1] !== '$this') {
            return null;
        }
        // ★ `->` 之后的属性名是 **T_STRING**（不是 T_VARIABLE —— 只有 `$foo` 才是）
        $propI = SourceQuery::nextIndex($tokens, $i + 1);
        if ($propI === null || $tokens[$propI][0] !== T_STRING) {
            return null;
        }

        return strtolower($tokens[$propI][1]);
    }
}
