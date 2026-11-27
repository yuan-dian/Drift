<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\DevTools\Cli\Registry;

/**
 * DevTools 物理边界（A73）。
 *
 * A72 把审查面搬出了 `src/`，但宿主 / 视图 / CLI 仍在 `tools/Debug\` 下，命名空间
 * `Drift\Dev\Debug` 与框架工具链的 `Drift\Dev\` 只差一个段名——导入错 PHP 不报错，
 * 要等运行时才发现。A73 把这一半也收进 `devtools/`，让整目录可独立拆仓。
 *
 * 这里的判据全部针对**结构**而不是行为：旧名零残留、出口路径自解析、装载器往返、
 * 真 CLI 往返、宿主 / Web 路径跟随边界。行为判据仍住各自的测试文件。
 */
final class DevToolsBoundaryTest extends SmokeTestCase
{
    /** @return list<string> */
    private function sources(): array
    {
        $root = \dirname(__DIR__, 3);
        $files = [];
        foreach (['src', 'tools', 'tests', 'apps', 'devtools', 'docs', 'bin'] as $dir) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root . '/' . $dir, \FilesystemIterator::SKIP_DOTS),
            );
            foreach ($it as $f) {
                // 只扫 PHP：文档允许在历史叙述里写出旧名（A73 裁决就要解释它为什么消失）。
                if (!$f->isFile() || $f->getExtension() !== 'php') {
                    continue;
                }
                $files[] = $f->getPathname();
            }
        }

        return $files;
    }

    public function testOldBoundaryLeavesNoResidue(): void
    {
        $root = \dirname(__DIR__, 3);
        check('tools/Debug 目录已不存在', !\is_dir($root . '/tools/Debug'));

        $old = [];
        foreach ($this->sources() as $path) {
            // 判据自己必须写出旧名才能判旧名，是唯一允许提到的文件。
            if (\basename($path) === 'DevToolsBoundaryTest.php') {
                continue;
            }
            if (\str_contains((string) \file_get_contents($path), 'Drift\\Dev\\Debug')) {
                $old[] = \substr($path, \strlen($root) + 1);
            }
        }
        check('旧命名空间在 PHP 源码里零匹配', $old === [], \implode(', ', $old));

        $stray = \glob($root . '/tools/debug-*.php') ?: [];
        check('tools/ 下没有遗留出口脚本', $stray === [], \implode(', ', $stray));
        check('出口脚本全在 devtools/bin', \count(\glob($root . '/devtools/bin/debug-*.php') ?: []) === 22);

        $composer = (array) \json_decode((string) \file_get_contents($root . '/composer.json'), true);
        $psr4 = (array) ($composer['autoload']['psr-4'] ?? []);
        check('composer 声明 Drift\DevTools => devtools/', (array) ($psr4['Drift\\DevTools\\'] ?? null) === ['devtools/']);
        check('composer 不再声明旧命名空间', !\array_key_exists('Drift\\Dev\\Debug\\', $psr4));
    }

    public function testEveryEntryResolvesInsideDevtoolsBin(): void
    {
        $root = \dirname(__DIR__, 3);
        $entries = Registry::all();
        check('出口数仍是 22', \count($entries) === 22, (string) \count($entries));

        $bad = [];
        foreach ($entries as $verb => $entry) {
            $path = $entry->path();
            if (!\is_file($path)) {
                $bad[] = "{$verb}: 文件不存在 ({$path})";
                continue;
            }
            $real = \str_replace('\\', '/', (string) \realpath($path));
            if (!\str_starts_with($real, \str_replace('\\', '/', $root) . '/devtools/bin/')) {
                $bad[] = "{$verb}: 不在 bin 内 ({$real})";
            }
        }
        check('每个出口都解析到 devtools/bin 内的真实文件', $bad === [], \implode('; ', $bad));

        $entry = Registry::get('brief');
        check('出口路径不靠仓库根推导', !\str_contains((string) \file_get_contents($root . '/devtools/Cli/Entry.php'), 'dirname(__DIR__, 2)'));
        check('出口入口统一 bootstrap', \str_contains((string) \file_get_contents($entry->path()), "require __DIR__ . '/../autoload.php';"));
    }

    public function testAutoloadCoversAllFourDevToolsSections(): void
    {
        require_once \dirname(__DIR__, 3) . '/devtools/autoload.php';

        foreach ([
            'Drift\\DevTools\\Cli\\Registry',
            'Drift\\DevTools\\Host\\Runner',
            'Drift\\DevTools\\View\\Brief',
            'Drift\\DevTools\\View\\Rules\\RuleRegistry',
            'Drift\\DevTools\\Protocol\\Wire',
            'Drift\\DevTools\\Session\\Query',
            'Drift\\DevTools\\App\\DebugSession',
        ] as $class) {
            check($class . ' 可装载', \class_exists($class));
        }
        check('旧 FQN 已不可装载', !\class_exists('Drift\\Dev\\Debug\\Runner'));
    }

    /** @return array{int, string} */
    private function runCli(string $script, string $args): array
    {
        $root = \dirname(__DIR__, 3);
        $cmd = \escapeshellarg(\PHP_BINARY) . ' '
            . \escapeshellarg($root . '/devtools/bin/' . $script) . ' ' . $args;
        $out = [];
        $code = 0;
        \exec($cmd . ' 2>&1', $out, $code);

        return [$code, \implode("\n", $out)];
    }

    public function testRealCliStillRunsFromTheNewLocation(): void
    {
        [$code, $out] = $this->runCli('debug-capabilities.php', '--json');
        check('capabilities 出口退出码 0', $code === 0, $out);
        $json = \json_decode($out, true);
        check('capabilities 输出是合法 JSON', \is_array($json), \substr($out, 0, 120));
        check('capabilities 仍报协议版本', ($json['protocol'] ?? null) === 1);

        [$helpCode, $help] = $this->runCli('debug-brief.php', '--help');
        check('brief --help 退出码 0', $helpCode === 0, $help);
        check('brief --help 仍列参数', \str_contains($help, '--page'));
    }

    public function testHostAndWebRoutesFollowTheBoundary(): void
    {
        $root = \dirname(__DIR__, 3);

        $source = (string) \file_get_contents($root . '/devtools/Host/Source.php');
        check('宿主路径相对 devtools 推导', \str_contains($source, "dirname(__DIR__) . '/bin/debug-host.php'"));
        check('宿主路径不再绕仓库根', !\str_contains($source, "dirname(__DIR__, 2) . '/devtools/bin/"));

        $webCheck = (string) \file_get_contents($root . '/tools/check-web-routes.php');
        check('Web 路由检查指向新视图目录', \str_contains($webCheck, "\$root . '/devtools/View'"));

        $bad = [];
        foreach (['debug-highlight.php', 'debug-panel.php', 'debug-pick.php', 'debug-report.php'] as $script) {
            $src = (string) \file_get_contents($root . '/devtools/bin/' . $script);
            if (!\str_contains($src, "dirname(__DIR__) . '/bin/debug-host.php'")) {
                $bad[] = $script;
            }
        }
        check('四个 --remote 出口的宿主路径同形', $bad === [], \implode(', ', $bad));

        // 共享启动器同时服务 debug 出口与工具链：只把前者搬走，后者必须仍在 tools/。
        $cmd = \escapeshellarg(\PHP_BINARY) . ' ' . \escapeshellarg($root . '/bin/drift') . ' build --help';
        $out = [];
        $code = 0;
        \exec($cmd . ' 2>&1', $out, $code);
        $text = \implode("\n", $out);
        check('非 debug 工具仍从 tools/ 启动', $code === 0 && \str_contains($text, 'tools/drift-build.php'), $text);
    }
}
