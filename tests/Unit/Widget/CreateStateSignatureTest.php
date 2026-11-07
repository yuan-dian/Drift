<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Widget;

use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * `createState()` 的返回类型必须停在被覆盖的契约 `State` 上（§67）。
 *
 * AOT 注册期 Zend 要解析覆盖方法的签名，而 tpc 的类注册顺序只按继承拓扑排，
 * 窄到 `FooState` 且它排在宿主后面就会 `Fatal: X must be registered before Y`。
 * 解释器侧永远测不到，所以这里是源码扫描。边界：不认类型别名，一律写 `State`。
 */
final class CreateStateSignatureTest extends SmokeTestCase
{
    public function testCreateStateNeverNarrowsReturnType(): void
    {
        $root = repoRoot() . '/src';
        $narrowed = [];
        $seen = 0;

        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root));
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $src = (string) \file_get_contents($file->getPathname());
            $count = \preg_match_all(
                '/function\s+createState\s*\([^)]*\)\s*:\s*([^\s;{]+)/',
                $src,
                $matches,
            );
            if ($count === 0 || $count === false) {
                continue;
            }

            foreach ($matches[1] as $type) {
                $seen++;
                $pos = \strrpos((string) $type, '\\');
                $short = $pos === false ? (string) $type : \substr((string) $type, $pos + 1);
                if ($short !== 'State') {
                    $narrowed[] = \str_replace($root . '/', '', $file->getPathname()) . ' → ' . $type;
                }
            }
        }

        \sort($narrowed);

        check(
            'AOT · createState() 一律返回 State，不窄到具体 State 子类（§67）',
            $narrowed === [],
            $narrowed === [] ? '' : \implode(', ', $narrowed),
        );
        check(
            'AOT · 量具自证：至少扫到 16 个 createState() 声明（扫不到会恒绿）',
            $seen >= 16,
            '实际 ' . $seen . ' 个',
        );

        $this->flushChecks();
    }
}
