<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

use ReflectionClass;
use ReflectionMethod;

/**
 * 把 `site`（`file:line`）解释成"这是哪个类的哪个方法、定义在第几行"（A55：L2）。
 *
 * ## 为什么不自己记调用栈
 *
 * `Recorder::callSite()` 已经在写入点抓了 `file:line` 并进证据包 —— 那是可复算的
 * 事实。这里补的是**当场翻译**：`apps/.../TablePage.php:142` 落在哪个方法体内。
 * 当场栈（`debug_backtrace`）交给 `debug eval` 就行，不在这里重造第二套（A56）。
 *
 * ## 为什么用反射而不是解析源码文本
 *
 * `ReflectionMethod::getStartLine()` / `getEndLine()` 是语言给的权威方法边界，
 * 不用自己写词法分析去猜 `{` / `}`。类方法的 [start, end] 在同一文件里不重叠
 * （PHP 方法不能嵌套声明），所以覆盖目标行的至多一个，取到即答案。早先写过
 * "取 start 最大的最内层"做防御，但没有任何真实夹具能让它与"第一个"给出不同
 * 结果 —— 那是没有判据的分支，不如删掉。
 *
 * ## 能力探测与范围
 *
 * AOT 下 `getFileName()` 可能缺源码路径，缺了就整域自报不可用。`get_declared_classes()`
 * 只看**已加载**的类 —— 证据里那条 site 来自本次运行，它的类必然已加载，所以这个
 * 范围正好。扫过的类 / 方法数随答案一起给，"没找到"才不会被读成"不存在"。
 *
 * @internal L2 内省；不进证据包、不进指纹、不进 AOT 产物
 */
final class SourceLocator
{
    /** @var array<string, list<array{0: string, 1: int, 2: int}>>|null 规范化 file => [qualified, start, end] */
    private static ?array $index = null;

    public static function available(): bool
    {
        if (!\class_exists(ReflectionMethod::class)) {
            return false;
        }
        try {
            $m = new ReflectionMethod(self::class, 'available');

            return $m->getFileName() !== false;
        } catch (\Throwable) {
            return false;
        }
    }

    /** 清缓存：进程里新加载了类时要重扫（测试里定义探针类后会用到）。 */
    public static function reset(): void
    {
        self::$index = null;
    }

    /**
     * @return array<string, mixed>
     */
    public static function locate(string $site): array
    {
        // ★ 不用 `base + [...]`：PHP 的 + 是"左边优先"，base 里的 null 会盖掉右边的
        //   reason —— 早先这里 miss 全回 reason=null，视图显示成 unknown，正是这个坑。
        $miss = static fn(string $reason, ?string $file = null, ?int $line = null, int $scanned = 0): array => [
            'site' => $site, 'file' => $file, 'line' => $line,
            'found' => false, 'reason' => $reason, 'scanned' => $scanned,
        ];
        if (!self::available()) {
            return $miss('reflection-unavailable');
        }

        // site 形如 "apps/.../Page.php:142"，行号在最后一个冒号后。
        $pos = \strrpos($site, ':');
        if ($pos === false || $pos === 0) {
            return $miss('malformed-site');
        }
        $file = self::normalise(\substr($site, 0, $pos));
        $line = \filter_var(\substr($site, $pos + 1), \FILTER_VALIDATE_INT);
        if ($file === '' || $line === false || $line < 1) {
            return $miss('malformed-site');
        }

        $index = self::index();
        $scanned = 0;
        foreach ($index as $_file => $_methods) {
            $scanned += \count($_methods);
        }

        $best = null;
        foreach ($index[$file] ?? [] as [$qualified, $start, $end]) {
            if ($line < $start || $line > $end) {
                continue;
            }
            // 同一文件里类方法的行区间不重叠：覆盖目标行的至多一个，取到即止。
            $best = [$qualified, $start, $end];
            break;
        }

        if ($best === null) {
            return $miss(isset($index[$file]) ? 'no-method-covers-line' : 'file-not-loaded', $file, $line, $scanned);
        }

        return [
            'site' => $site,
            'file' => $file,
            'line' => $line,
            'found' => true,
            'reason' => null,
            'method' => $best[0],
            'definedAt' => $file . ':' . $best[1],
            'startLine' => $best[1],
            'endLine' => $best[2],
            'scanned' => $scanned,
        ];
    }

    /**
     * 建一次索引：file => [[qualified, start, end]]，按 file 归并，查找时只扫一个文件的方法。
     *
     * @return array<string, list<array{0: string, 1: int, 2: int}>>
     */
    private static function index(): array
    {
        if (self::$index !== null) {
            return self::$index;
        }

        $index = [];
        foreach (\get_declared_classes() as $class) {
            try {
                $rc = new ReflectionClass($class);
            } catch (\Throwable) {
                continue;
            }
            foreach ($rc->getMethods() as $method) {
                $file = $method->getFileName();
                $start = $method->getStartLine();
                $end = $method->getEndLine();
                if ($file === false || $start === false || $end === false) {
                    continue;
                }
                $key = self::normalise($file);
                if ($key === '') {
                    continue;
                }
                $qualified = self::shortClass($method->getDeclaringClass()->getName()) . '::' . $method->getName();
                $index[$key][] = [$qualified, $start, $end];
            }
        }

        return self::$index = $index;
    }

    /** 统一成正斜杠 + 剥 cwd 前缀：与 `Recorder::callSite()` 同一套口径，两边才对得上。 */
    private static function normalise(string $file): string
    {
        $file = \str_replace('\\', '/', $file);
        $cwd = \getcwd();
        if ($cwd !== false) {
            $prefix = \str_replace('\\', '/', $cwd);
            if (\str_starts_with($file, $prefix)) {
                $file = \ltrim(\substr($file, \strlen($prefix)), '/');
            }
        }

        return $file;
    }

    private static function shortClass(string $class): string
    {
        $pos = \strrpos($class, '\\');

        return $pos === false ? $class : \substr($class, $pos + 1);
    }
}
