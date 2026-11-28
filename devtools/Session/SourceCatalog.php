<?php
declare(strict_types=1);

namespace Drift\DevTools\Session;

/**
 * 项目源码索引：文件 → 方法行区间。
 *
 * 用 `PhpToken` 扫文件，不依赖类是否已加载；`SourceLocator` 只负责把 site 翻成答案。
 *
 * @internal L2 内省，不进证据包、不进指纹、不进 AOT 产物
 */
final class SourceCatalog
{
    /** 只扫会出现在因果 site 里的目录。 */
    private const array DIRS = ['src', 'apps', 'devtools', 'tests', 'tools'];

    /** @var array<string, list<array{0: string, 1: int, 2: int}>>|null */
    private ?array $methods = null;

    public function __construct(private readonly string $root)
    {
    }

    /** @return array<string, list<array{0: string, 1: int, 2: int}>> */
    public function methods(): array
    {
        return $this->methods ??= $this->scan();
    }

    /** @return array<string, list<array{0: string, 1: int, 2: int}>> */
    private function scan(): array
    {
        $out = [];
        foreach (self::DIRS as $dir) {
            $path = $this->root . '/' . $dir;
            if (!\is_dir($path)) {
                continue;
            }

            try {
                $files = new \RecursiveIteratorIterator(
                    new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                );
                foreach ($files as $file) {
                    if (!$file->isFile() || \strtolower($file->getExtension()) !== 'php') {
                        continue;
                    }
                    $full = \str_replace('\\', '/', $file->getPathname());
                    if (\str_contains($full, '/vendor/')) {
                        continue;
                    }
                    $found = $this->parse($full);
                    if ($found !== []) {
                        $out[$this->relative($full)] = $found;
                    }
                }
            } catch (\Throwable) {
                // 目录不可读时宁可少索引，也不让源码查询把整张卡片拖垮。
            }
        }

        return $out;
    }

    /** @return list<array{0: string, 1: int, 2: int}> */
    private function parse(string $file): array
    {
        $code = @\file_get_contents($file);
        if (!\is_string($code) || $code === '') {
            return [];
        }

        $tokens = \PhpToken::tokenize($code);
        $count = \count($tokens);
        $classes = [];
        $method = null;
        $pendingClass = null;
        $pendingMethod = null;
        $depth = 0;
        $out = [];

        for ($i = 0; $i < $count; ++$i) {
            $token = $tokens[$i];

            if ($token->is([\T_CLASS, \T_INTERFACE, \T_TRAIT, \T_ENUM])) {
                $prev = $this->prev($tokens, $i);
                if ($prev !== null && $prev->is(\T_DOUBLE_COLON)) {
                    continue;
                }
                $pendingClass = ['name' => null, 'line' => $token->line];
                continue;
            }

            if ($pendingClass !== null && $token->is(\T_STRING) && $pendingClass['name'] === null) {
                $pendingClass['name'] = $token->text;
                continue;
            }

            if ($token->is(\T_FUNCTION)) {
                if ($classes === [] || $method !== null || $pendingMethod !== null) {
                    continue;
                }
                $name = $this->functionName($tokens, $i);
                if ($name !== null) {
                    $pendingMethod = ['name' => $name, 'line' => $token->line];
                }
                continue;
            }

            if ($token->text === '{' || $token->is([\T_CURLY_OPEN, \T_DOLLAR_OPEN_CURLY_BRACES])) {
                ++$depth;
                if ($pendingClass !== null) {
                    if ($pendingClass['name'] !== null) {
                        $classes[] = ['name' => $pendingClass['name'], 'depth' => $depth];
                    }
                    $pendingClass = null;
                } elseif ($pendingMethod !== null) {
                    $method = ['name' => $pendingMethod['name'], 'line' => $pendingMethod['line'], 'depth' => $depth];
                    $pendingMethod = null;
                }
                continue;
            }

            if ($token->text === '}') {
                if ($method !== null && $depth === $method['depth']) {
                    $class = $classes === [] ? null : $classes[\count($classes) - 1];
                    if ($class !== null) {
                        $out[] = [$class['name'] . '::' . $method['name'], $method['line'], $token->line];
                    }
                    $method = null;
                }
                if ($classes !== [] && $depth === $classes[\count($classes) - 1]['depth']) {
                    \array_pop($classes);
                }
                --$depth;
                continue;
            }

            if ($pendingMethod !== null && $token->text === ';') {
                $class = $classes === [] ? null : $classes[\count($classes) - 1];
                if ($class !== null) {
                    $out[] = [
                        $class['name'] . '::' . $pendingMethod['name'],
                        $pendingMethod['line'],
                        $pendingMethod['line'],
                    ];
                }
                $pendingMethod = null;
            }
        }

        return $out;
    }

    private function functionName(array $tokens, int $at): ?string
    {
        $i = $this->nextIndex($tokens, $at + 1);
        if ($i !== null && $tokens[$i]->text === '&') {
            $i = $this->nextIndex($tokens, $i + 1);
        }

        return $i !== null && $tokens[$i]->is(\T_STRING) ? $tokens[$i]->text : null;
    }

    private function prev(array $tokens, int $at): ?\PhpToken
    {
        for ($i = $at - 1; $i >= 0; --$i) {
            if (!$tokens[$i]->isIgnorable()) {
                return $tokens[$i];
            }
        }

        return null;
    }

    private function nextIndex(array $tokens, int $at): ?int
    {
        $count = \count($tokens);
        for ($i = $at; $i < $count; ++$i) {
            if (!$tokens[$i]->isIgnorable()) {
                return $i;
            }
        }

        return null;
    }

    private function relative(string $file): string
    {
        $root = \rtrim(\str_replace('\\', '/', $this->root), '/');
        if (\str_starts_with($file, $root . '/')) {
            return \substr($file, \strlen($root) + 1);
        }

        return $file;
    }
}
