<?php
declare(strict_types=1);

namespace Drift\Dev\Lint;

use RuntimeException;

/**
 * 仓库快照：文件表 + 符号表 + PSR-4 前缀表。
 *
 * 前缀表直接读 composer.json —— 它在本项目里的首要身份就是「物理边界表」（§4.1），
 * 所以 lint 与装载器读同一份数据，边界只有一个来源。
 */
final class Project
{
    /** 框架前缀：命名空间以此开头 = 框架自己的代码 */
    public const FRAMEWORK_PREFIX = 'Drift\\';

    /** 默认扫描根（相对仓库根） */
    public const DEFAULT_DIRS = ['src', 'tools', 'apps'];

    /**
     * 工具链目录。工具跑在 php.exe 里、**永不参与 AOT 编译** ⇒
     * 针对"产物"的纪律（nano-hygiene 等）对它们不适用。
     */
    public const TOOLCHAIN_DIR = 'tools/';

    /**
     * @param list<SourceFile> $files
     * @param array<string, array{file: string, api: bool, kind: string}> $symbols
     * @param array<string, string> $dirToPrefix
     */
    private function __construct(
        private readonly string $root,
        private readonly array $files,
        private readonly array $symbols,
        private readonly array $dirToPrefix,
    ) {
    }

    /** @param list<string> $ignore 相对路径前缀，命中即跳过 */
    public static function scan(string $root, array $ignore = [], array $dirs = self::DEFAULT_DIRS): self
    {
        $files = [];
        foreach ($dirs as $dir) {
            $base = $root . '/' . trim($dir, '/');
            if (!is_dir($base)) {
                continue;
            }
            foreach (self::phpFiles($base) as $absolute) {
                $relative = str_replace('\\', '/', substr($absolute, strlen($root) + 1));
                foreach ($ignore as $prefix) {
                    if (str_starts_with($relative, $prefix)) {
                        continue 2;
                    }
                }
                $code = (string) file_get_contents($absolute);
                $files[] = new SourceFile($root, $relative, $code);
            }
        }
        usort($files, static fn(SourceFile $a, SourceFile $b): int => strcmp($a->path(), $b->path()));

        $symbols = [];
        foreach ($files as $file) {
            foreach ($file->types() as $type) {
                $symbols[$type['fqn']] = [
                    'file' => $file->path(),
                    'api' => $type['api'],
                    'kind' => $type['kind'],
                ];
            }
        }

        return new self($root, $files, $symbols, self::readPrefixes($root));
    }

    public function root(): string
    {
        return $this->root;
    }

    /** @return list<SourceFile> */
    public function files(): array
    {
        return $this->files;
    }

    /** @return array<string, array{file: string, api: bool, kind: string}> */
    public function symbols(): array
    {
        return $this->symbols;
    }

    /** @return array<string, string> */
    public function dirToPrefix(): array
    {
        return $this->dirToPrefix;
    }

    /** 该文件是否属于工具链（不参与 AOT 编译） */
    public function isToolchain(SourceFile $file): bool
    {
        return str_starts_with($file->path(), self::TOOLCHAIN_DIR);
    }

    /**
     * 该文件是否属于框架本体。
     *
     * ★★ **不能只看命名空间**：`src/Platform/Win32/ResizeHook.php` 刻意**不带**
     *   `namespace`（AOT 下 C++ 壳按符号名直接调用一个全局具名入口，带前缀就找不到）
     *   ⇒ 只看命名空间会把它判成"消费者"，于是框架内部互调内部方法被报成泄漏。
     *   判据要用**物理位置**（composer.json 的前缀表：`src/` ⇒ `Drift`）。
     */
    public function isFramework(SourceFile $file): bool
    {
        if (str_starts_with($file->namespace(), self::FRAMEWORK_PREFIX)) {
            return true;
        }
        $framework = rtrim(self::FRAMEWORK_PREFIX, '\\');
        foreach ($this->dirToPrefix as $dir => $prefix) {
            if ($prefix === $framework && str_starts_with($file->path(), $dir)) {
                return true;
            }
        }
        return false;
    }

    /** @return null|array{file: string, api: bool, kind: string} */
    public function symbol(string $fqn): ?array
    {
        return $this->symbols[$fqn] ?? null;
    }

    /**
     * 该类型是否属于框架的"非公共面"（§10.1.3：默认 `@internal`，只有 `@api` 才进契约面）。
     *
     * 三条否判：不是框架前缀、不是本仓库声明的类型（PHP 内建 / 第三方）、
     * 属于工具链（`Drift\Dev\`，工具入口自行注册，不在应用可见范围内）。
     */
    public function isInternal(string $fqn): bool
    {
        if (!str_starts_with($fqn, self::FRAMEWORK_PREFIX) || str_starts_with($fqn, self::FRAMEWORK_PREFIX . 'Dev\\')) {
            return false;
        }
        $symbol = $this->symbols[$fqn] ?? null;
        return $symbol !== null && !$symbol['api'];
    }

    /**
     * 该 FQN 是否属于框架本体（类型一定有命名空间 ⇒ 只按命名空间判）。
     *
     * 工具链（`Drift\Dev\`）与框架同包但不算"内部面"（`10` §2：工具入口自行注册）。
     */
    public function isFrameworkFqn(string $fqn): bool
    {
        if (!str_starts_with($fqn, self::FRAMEWORK_PREFIX)) {
            return false;
        }
        return !str_starts_with($fqn, self::FRAMEWORK_PREFIX . 'Dev\\');
    }

    /** @return list<string> */
    private static function phpFiles(string $dir): array
    {
        $found = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS)
        );
        foreach ($iterator as $item) {
            if ($item->isFile() && $item->getExtension() === 'php') {
                $found[] = str_replace('\\', '/', $item->getPathname());
            }
        }
        sort($found);
        return $found;
    }

    /** @return array<string, string> 目录（带尾斜杠）=> 命名空间前缀 */
    private static function readPrefixes(string $root): array
    {
        $path = $root . '/composer.json';
        if (!is_file($path)) {
            throw new RuntimeException('composer.json 缺失 —— 物理边界表（§4.1）无从读取');
        }
        $config = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);

        $map = [];
        foreach ($config['autoload']['psr-4'] ?? [] as $prefix => $dirs) {
            foreach ((array) $dirs as $dir) {
                $map[trim($dir, '/') . '/'] = rtrim($prefix, '\\');
            }
        }
        return $map;
    }
}
