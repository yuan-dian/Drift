<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

/**
 * 把 site（file:line）解释成方法定义位置。
 *
 * 索引来自 {@see SourceCatalog}：按文件扫描，类没加载也能查。
 *
 * @internal L2 内省；不进证据包、不进指纹、不进 AOT 产物
 */
final class SourceLocator
{
    private static ?SourceCatalog $catalog = null;

    public static function reset(): void
    {
        self::$catalog = null;
    }

    public static function available(): bool
    {
        return \function_exists('token_get_all') && \is_dir((string) \getcwd());
    }

    /** @return array<string, mixed> */
    public static function locate(string $site): array
    {
        $miss = static fn(string $reason, ?string $file = null, ?int $line = null, int $scanned = 0): array => [
            'site' => $site, 'file' => $file, 'line' => $line,
            'found' => false, 'reason' => $reason, 'scanned' => $scanned,
        ];
        if (!self::available()) {
            return $miss('reflection-unavailable');
        }

        $pos = \strrpos($site, ':');
        if ($pos === false || $pos === 0) {
            return $miss('malformed-site');
        }
        $file = self::normalise(\substr($site, 0, $pos));
        $line = \filter_var(\substr($site, $pos + 1), \FILTER_VALIDATE_INT);
        if ($file === '' || $line === false || $line < 1) {
            return $miss('malformed-site');
        }

        $index = self::catalog()->methods();
        $scanned = 0;
        foreach ($index as $rows) {
            $scanned += \count($rows);
        }

        $best = null;
        foreach ($index[$file] ?? [] as [$qualified, $start, $end]) {
            if ($line < $start || $line > $end) {
                continue;
            }
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

    private static function catalog(): SourceCatalog
    {
        return self::$catalog ??= new SourceCatalog((string) \getcwd());
    }

    private static function normalise(string $file): string
    {
        $file = \str_replace('\\', '/', $file);
        $cwd = \getcwd();
        if ($cwd !== false) {
            $prefix = \rtrim(\str_replace('\\', '/', $cwd), '/');
            if (\str_starts_with($file, $prefix . '/')) {
                $file = \substr($file, \strlen($prefix) + 1);
            }
        }

        return $file;
    }
}
