<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

/**
 * 把文件行号翻成本地编辑器 URI。
 *
 * 只生成 URI，不启动进程；`DRIFT_EDITOR` 可选 `vscode`（默认）或 `phpstorm`。
 *
 * @internal 展示层辅助，不进证据包
 */
final class SourceLink
{
    public static function uri(string $file, int $line, ?string $root = null): string
    {
        $path = \str_replace('\\', '/', $file);
        if (!\str_starts_with($path, '/') && !\preg_match('#^[A-Za-z]:/#', $path)) {
            $root ??= (string) \getcwd();
            $path = \rtrim(\str_replace('\\', '/', $root), '/') . '/' . $path;
        }

        $encoded = \implode('/', \array_map('rawurlencode', \explode('/', $path)));
        $editor = \strtolower((string) (\getenv('DRIFT_EDITOR') ?: 'vscode'));

        return $editor === 'phpstorm'
            ? 'phpstorm://open?file=' . $encoded . '&line=' . $line
            : 'vscode://file/' . $encoded . ':' . $line;
    }
}
