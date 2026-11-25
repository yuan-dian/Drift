<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\Dev\Debug\Cli\CliError;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Cli\Opt;
use Drift\Dev\Debug\Cli\OptionSet;
use Drift\Dev\Debug\Cli\Registry;
use Drift\Dev\Tests\Support\SmokeTestCase;

/**
 * 驱动词表是十一个出口共用的协议。这里的判据全在说同一件事：
 * "同一个词在任何一个出口都必须是同一个意思"，而且漏声明不能静默过去。
 */
final class CliTest extends SmokeTestCase
{
    private const array WORDS = [
        'debug-x', '--page=async-table', '--rows=6', '--resolve', '--frames=2', '--click=400,300',
    ];

    public function testEveryCommandBuildsTheSameScriptFromTheSameWords(): void
    {
        $digests = [];
        foreach (\array_keys(Commands::extras()) as $name) {
            $digests[$name] = Commands::set($name)->parse(self::WORDS)->script()->digest();
            check("{$name} 能由词表建出脚本", $digests[$name] !== '', $name);
        }
        check('11 个出口', \count($digests) === 11, (string) \count($digests));
        check('同一份词只有一份指纹', \count(\array_unique($digests)) === 1, \implode(' ', $digests));
        $this->flushChecks();
    }

    public function testSchemaRefusesToDefineTheSameNameTwice(): void
    {
        foreach ([Opt::int('width', 1), Opt::str('view-width'), Opt::flag('json')] as $clash) {
            try {
                OptionSet::for('bad', [$clash]);
                check("重名 {$clash->name} 被拒", false, '没抛');
            } catch (\LogicException $e) {
                check("重名 {$clash->name} 被拒", true, $e->getMessage());
            }
        }
        $this->flushChecks();
    }

    public function testWidthIsViewportEverywhereAndTextWidthIsSeparate(): void
    {
        $a = Commands::set('brief')->parse(['x', '--width=640']);
        check('--width 改视口', $a->float('view-width') === 640.0, (string) $a->float('view-width'));
        check('--width 不改排版', $a->int('text-width') === 100, (string) $a->int('text-width'));

        $b = Commands::set('brief')->parse(['x', '--text-width=120']);
        check('--text-width 只改排版', $b->int('text-width') === 120 && $b->float('view-width') === 800.0);

        $panel = Commands::set('panel')->parse(['x', '--width=640']);
        check('panel 的 --width 与 brief 同义', $panel->float('view-width') === 640.0);
        $this->flushChecks();
    }

    public function testAtSelectsAFrameInAnEvidencePackage(): void
    {
        foreach (['brief', 'network', 'web'] as $name) {
            try {
                $p = Commands::set($name)->parse(['x', '--from=case.ev', '--at=2']);
                check("{$name} 允许 --from + --at", $p->at() === 2 && $p->label() === 'case.ev');
            } catch (CliError $e) {
                check("{$name} 允许 --from + --at", false, $e->getMessage());
            }
        }
        $this->flushChecks();
    }

    public function testBodyBudgetIsExplicitAndDoesNotChangeTheDriveFingerprint(): void
    {
        $base = ['x', '--page=async-table', '--frames=2'];
        $off = Commands::set('network')->parse($base);
        $on = Commands::set('network')->parse([...$base, '--bodies']);

        check('默认不抓正文', $off->recordOptions()->bodies() === false);
        check('--bodies 显式开启', $on->recordOptions()->bodies() === true);
        check('采集预算不改驱动指纹', $off->script()->digest() === $on->script()->digest());
        $this->flushChecks();
    }

    public function testBadNumbersAreRejectedButRangesStillClamp(): void
    {
        try {
            Commands::set('brief')->parse(['x', '--page=table', '--frames=abc']);
            check('--frames=abc 被拒', false, '静默收下了');
        } catch (CliError $e) {
            check('--frames=abc 被拒', \str_contains($e->getMessage(), '--frames'), $e->getMessage());
        }
        $p = Commands::set('brief')->parse(['x', '--page=table', '--rows=-5', '--hold=0']);
        check('越界归一', $p->int('rows') === 0 && $p->int('hold') === 1);
        $this->flushChecks();
    }

    public function testUnknownOptionListsOnlyTheCommandsOwnWords(): void
    {
        try {
            Commands::set('loop')->parse(['x', '--page=table', '--bogus=1']);
            check('未知参数被拒', false, '静默收下了');
        } catch (CliError $e) {
            $msg = $e->getMessage();
            check('列出本出口的选项', \str_contains($msg, '--runs') && \str_contains($msg, '--view-width'), $msg);
            check('不列出别人的选项', !\str_contains($msg, '--budget') && !\str_contains($msg, '--port'), $msg);
        }
        $this->flushChecks();
    }

    public function testHelpIsGeneratedFromTheSameSchema(): void
    {
        foreach (\array_keys(Commands::extras()) as $name) {
            $set = Commands::set($name);
            \preg_match_all('/--([a-zA-Z][a-zA-Z0-9-]*)/', $set->help(), $m);
            $shown = \array_unique($m[1]);
            // 用解析结果反推接受集合：help 里写的每个词都得被认得。
            $unknown = [];
            foreach ($shown as $opt) {
                try {
                    $set->parse(['x', "--{$opt}=1"]);
                } catch (CliError $e) {
                    if (\str_contains($e->getMessage(), '未知参数')) {
                        $unknown[] = $opt;
                    }
                }
            }
            check("{$name} 的 help 没写不认识的参数", $unknown === [], \implode(' ', $unknown));
        }
        $this->flushChecks();
    }

    public function testAnUndeclaredActionCannotSlipThrough(): void
    {
        // 判据 1 依赖"每个出口都声明了词表"：漏一个不会静默变成默认行为。
        $lean = OptionSet::for('lean');
        try {
            $lean->parse(['x', '--page=table', '--not-a-word=1']);
            check('漏声明即拒绝', false, '静默收下了');
        } catch (CliError $e) {
            check('漏声明即拒绝', \str_contains($e->getMessage(), '未知参数'), $e->getMessage());
        }
        check('词表本身含 click', \in_array('click', \array_map(
            static fn(Opt $o): string => $o->name,
            OptionSet::driver(),
        ), true));
        $this->flushChecks();
    }

    public function testEveryCommandStartsAndShowsTheSharedWords(): void
    {
        foreach (\array_keys(Commands::extras()) as $name) {
            [$code, $out, $err] = $this->cli($name, ['--help']);
            check("{$name} --help 能启动", $code === 0 && \str_contains($out, '--frames'), \trim($err . $out));
        }
        $this->flushChecks();
    }

    public function testEveryOneShotCommandActuallyRuns(): void
    {
        // 只测 schema 会漏掉"解析入口没接上"：node/pick/props 迁移时少了
        // 一行 parseOrExit，八条 schema 判据全绿，手跑才看见 undefined $args。
        $cases = [
            'brief' => ['--page=table', '--frames=1', '--json'],
            'network' => ['--page=table', '--frames=1', '--json'],
            'loop' => ['--page=table', '--runs=1', '--json'],
            'node' => ['--page=table', '--frames=1', '--node=1', '--frame=1', '--json'],
            'pick' => ['--page=table', '--frames=1', '--x=1', '--y=1', '--json'],
            'props' => ['--page=table', '--frames=1', '--node=1', '--json'],
            'report' => ['--page=table', '--frames=1', '--json'],
        ];
        foreach ($cases as $name => $args) {
            [$code, $out, $err] = $this->cli($name, $args);
            check("{$name} 真跑一次", $code === 0, \trim($err . $out));
        }
        $this->flushChecks();
    }

    public function testRegistryIsTheOnlyListOfDebugScripts(): void
    {
        $root = \dirname(__DIR__, 3);
        $onDisk = [];
        foreach (\glob($root . '/tools/debug-*.php') ?: [] as $file) {
            $onDisk[] = \basename($file);
        }
        \sort($onDisk);
        $registered = \array_map(static fn($e): string => $e->script, \array_values(Registry::all()));
        \sort($registered);

        check('注册表的出口 == 磁盘脚本', $onDisk === $registered, \implode(' ', \array_diff($onDisk, $registered)) . ' | ' . \implode(' ', \array_diff($registered, $onDisk)));
        foreach (Registry::all() as $verb => $entry) {
            check("{$verb} 的脚本存在", \is_file($entry->path()), $entry->path());
        }
        check('13 个出口', \count(Registry::all()) === 13, (string) \count(Registry::all()));
        $this->flushChecks();
    }

    public function testBothHelpSurfacesAreViewsOfTheRegistry(): void
    {
        [$code, $top, $err] = $this->drift(['help']);
        check('总帮助能跑', $code === 0, \trim($err . $top));
        [$code, $verbs, $err] = $this->drift(['debug', '--help']);
        check('debug --help 能跑', $code === 0, \trim($err . $verbs));

        foreach (Registry::lines(8) as $line) {
            check('总帮助含注册表行', \str_contains($top, $line . "\n"), \trim($line));
        }
        foreach (Registry::lines(2) as $line) {
            check('动词表含注册表行', \str_contains($verbs, $line . "\n"), \trim($line));
        }
        \preg_match_all('/^  (\S+)\s{2,}/m', $verbs, $m);
        check('动词表没多出未注册动词', $m[1] === \array_keys(Registry::all()), \implode(' ', $m[1]));
        $this->flushChecks();
    }

    public function testTwoLevelsOfHelpAreByteIdentical(): void
    {
        foreach (Registry::all() as $verb => $entry) {
            if (!$entry->driver) {
                continue;
            }
            [$directCode, $direct] = $this->cli($verb, ['--help']);
            [$driftCode, $via] = $this->drift(['debug', $verb, '--help']);
            check("{$verb} 两级 help 逐字节相同", $directCode === 0 && $driftCode === 0 && $direct === $via);

            $schemaHelp = Commands::set($verb)->help();
            foreach (\explode("\n", $schemaHelp) as $line) {
                if (\str_starts_with($line, '  --')) {
                    $name = \trim(\explode(' ', $line)[0]);
                    check("{$verb} 两级 help 都含 {$name}", \str_contains($direct, $name) && \str_contains($via, $name));
                }
            }
        }
        $this->flushChecks();
    }

    public function testExamplesNeverNameAnUndeclaredOption(): void
    {
        foreach (Registry::all() as $verb => $entry) {
            if (!$entry->driver) {
                continue;
            }
            $examples = Registry::examples($entry);
            if ($examples === null) {
                continue;
            }
            $schemaHelp = Commands::set($verb)->help();
            \preg_match_all('/--([A-Za-z][A-Za-z0-9-]*)/', $examples, $m);
            foreach ($m[1] as $name) {
                check("{$verb} 示例里的 --{$name} 在 schema 里", \str_contains($schemaHelp, '--' . $name));
            }
        }
        $this->flushChecks();
    }

    public function testDispatchUsesTheRegisteredScript(): void
    {
        [$code, $out, $err] = $this->drift(['debug', 'brief', '--page=table', '--frames=1', '--json']);
        check('总入口分发到注册脚本', $code === 0 && \str_contains($out, 'digest'), \trim($err . $out));

        [$code, $out, $err] = $this->drift(['debug', 'loop', '--page=table', '--runs=1', '--json']);
        check('新出口 loop 已注册可分发', $code === 0 && \str_contains($out, 'consistent'), \trim($err . $out));

        foreach (['ctl', 'host'] as $verb) {
            $entry = Registry::get($verb);
            check("{$verb} 是协议出口", !$entry->driver);
            [$code, $out, $err] = $this->drift(['debug', $verb, '--help']);
            check("{$verb} --help 有内容", $code === 0 && \trim($out) !== '', \trim($err . $out));
        }

        [$code, $out, $err] = $this->drift(['debug', 'nope']);
        check('未知动词退出 2', $code === 2, \trim($err . $out));
        check('未知动词列出可用清单', \str_contains($out, 'brief') && \str_contains($out, 'loop'));
        $this->flushChecks();
    }

    /** @return array{int, string, string} */
    private function drift(array $args): array
    {
        $root = \dirname(__DIR__, 3);
        $proc = \proc_open(
            \array_merge([\PHP_BINARY, $root . '/bin/drift'], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($proc, 'bin/drift 起不来');
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $out, $err];
    }

    /** @return array{int, string, string} */
    private function cli(string $name, array $args): array
    {
        $root = \dirname(__DIR__, 3);
        $proc = \proc_open(
            \array_merge([\PHP_BINARY, $root . "/tools/debug-{$name}.php"], $args),
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            $root,
            null,
            ['bypass_shell' => true],
        );
        self::assertIsResource($proc, "debug-{$name} 起不来");
        \fclose($pipes[0]);
        $out = (string) \stream_get_contents($pipes[1]);
        $err = (string) \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);

        return [\proc_close($proc), $out, $err];
    }
}
