<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Debug;

use Drift\App\Application;
use Drift\App\DebugSession;
use Drift\Debug\Session\Evidence;
use Drift\Debug\Session\MemoryProbe;
use Drift\Debug\Session\SourceLocator;
use Drift\Dev\Debug\Cli\Commands;
use Drift\Dev\Debug\Cli\Registry;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\BuildContext;
use Drift\Widget\Center;
use Drift\Widget\State;
use Drift\Widget\StatefulWidget;
use Drift\Widget\StatelessWidget;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * `debug probe` 的判据：内存与源码位置是 L2 内省，不是证据（A55）。
 *
 * ## 判据立在"边界"上，不是立在"能读出数"上
 *
 * "usage 是正数""能找到 build 方法"谁都能写对，咬不住任何东西。真正会漂的是：
 * ① 台账跨查询累积（否则"存活跨度"永远是 0，等于没有这个功能）；
 * ② WeakMap 不阻止回收（换成强持有 array 就是一个泄漏源）；
 * ③ 两件都不进证据包、不改指纹（A55 的核心禁令）；
 * ④ 源码定位命中正确方法、三种 miss 分开说；
 * ⑤ 出口注册与两级 help 同源。
 *
 * 变异测试改的是这五处，不是把 usage 改成另一个数。
 */
final class ProbeTest extends SmokeTestCase
{
    private ?DebugSession $session = null;

    protected function tearDown(): void
    {
        $this->session?->close();
        $this->session = null;
        SourceLocator::reset();
        parent::tearDown();
    }

    /** @return array{0: Application, 1: DebugSession} */
    private function open(Widget $root): array
    {
        $constraints = BoxConstraints::loose(new Size(300.0, 200.0));
        $app = new ProbeApplication($root);
        $app->attach(new SurfaceProbe(), $constraints);
        $this->session = DebugSession::open($app, $constraints, 600, null, true);
        $this->session->pump(0.0);

        return [$app, $this->session];
    }

    /** @param list<string> $cmd @return string 合并 stdout+stderr */
    private static function runCli(array $cmd): string
    {
        $p = \proc_open($cmd, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!\is_resource($p)) {
            return '';
        }
        \fclose($pipes[0]);
        $out = \stream_get_contents($pipes[1]);
        $err = \stream_get_contents($pipes[2]);
        \fclose($pipes[1]);
        \fclose($pipes[2]);
        \proc_close($p);

        return $out . $err;
    }

    public function testMemoryReportsProcessScalarsAndLiveTree(): void
    {
        [$app, $session] = $this->open(new Text('hello'));
        $answer = $session->query('memory');

        check('内存内省可用', ($answer['found'] ?? null) === true, self::show($answer));
        check('usage 为正', ((int) ($answer['process']['usage'] ?? 0)) > 0, self::show($answer['process'] ?? null));
        check('peak ≥ usage', ((int) ($answer['process']['peak'] ?? 0)) >= ((int) ($answer['process']['usage'] ?? 0)));
        check('gc 字段齐全', \array_diff(['running', 'roots', 'collected', 'threshold'], \array_keys((array) ($answer['process']['gc'] ?? []))) === []);
        check('活树有对象', ((int) ($answer['live']['objects'] ?? 0)) >= 2, self::show($answer['live'] ?? null));
        check('elements 计数 > 0', ((int) ($answer['live']['elements'] ?? 0)) >= 1);
        check('有类型行', (\count((array) ($answer['live']['rows'] ?? []))) > 0);
        check('不是证据标记', ($answer['isEvidence'] ?? null) === false);
        $this->flushChecks();
    }

    public function testMemoryAccumulatesAcrossQueries(): void
    {
        [, $session] = $this->open(new Text('hello'));
        $first = $session->query('memory');
        $session->pump(1.0);
        $second = $session->query('memory');
        $session->pump(2.0);
        $third = $session->query('memory');

        // 台账累积：第二次查询时，第一次就存在的对象 age 应随帧增长。
        // 只把 WeakMap 换成"每次 new 一个"会让所有 maxAge 恒为 0 —— 这正是要咬的牙。
        $maxAge = static fn(array $a): int => \max([0, ...\array_map(
            static fn($r): int => (int) (\is_array($r) ? ($r['maxAge'] ?? 0) : 0),
            (array) ($a['live']['rows'] ?? []),
        )]);
        check('第 1 帧 age 为 0', $maxAge($first) === 0, self::show($first['live']['rows'] ?? null));
        check('第 2 帧 age ≥ 1', $maxAge($second) >= 1, self::show($second['live']['rows'] ?? null));
        check('第 3 帧 age ≥ 2', $maxAge($third) >= 2, self::show($third['live']['rows'] ?? null));
        check('帧号随查询推进', ((int) ($third['frame'] ?? 0)) > ((int) ($first['frame'] ?? 0)));
        $this->flushChecks();
    }

    public function testMemoryIsNotEvidenceAndDoesNotChangeDigest(): void
    {
        [, $session] = $this->open(new Text('hello'));
        $digestBefore = $session->query('digest');
        $session->query('memory');
        $digestAfter = $session->query('digest');

        check('读内存不改指纹', $digestBefore === $digestAfter, self::show([$digestBefore, $digestAfter]));

        $path = \tempnam(\sys_get_temp_dir(), 'probe-evidence-');
        try {
            $manifest = Evidence::write($session->recorder(), $path, Evidence::ALL_DOMAINS);
            $json = (string) \file_get_contents($path);
            check('证据包可编码', \is_string($json) && $json !== '');
            check('证据域无 memory', !\in_array('memory', (array) ($manifest['domains'] ?? []), true));
            check('证据包无存活跨度字段', !\str_contains($json, 'maxAge'));
        } finally {
            @\unlink($path);
        }
        $this->flushChecks();
    }

    public function testSourceLocatesMethodAndDefinitionLine(): void
    {
        // 用已加载类里一个确切的方法：SourceLocator::locate() 本身就在本测试进程里。
        $self = SourceLocator::class;
        $site = self::siteOf($self, 'locate');
        check('夹具 site 取到', $site !== '', '无法取到 locate 的 file:line');

        SourceLocator::reset();
        $answer = SourceLocator::locate($site);
        check('命中', ($answer['found'] ?? null) === true, self::show($answer));
        check('方法是 SourceLocator::locate', ($answer['method'] ?? null) === 'SourceLocator::locate', self::show($answer));
        check('定义行是起点', ((int) ($answer['line'] ?? 0)) >= ((int) ($answer['startLine'] ?? 0)));
        check('定义行落在方法体', ((int) ($answer['line'] ?? 0)) <= ((int) ($answer['endLine'] ?? 999999)));
        check('扫过方法数 > 0', ((int) ($answer['scanned'] ?? 0)) > 0);
        check('definedAt 非空', \is_string($answer['definedAt'] ?? null) && \str_contains((string) $answer['definedAt'], ':'));
        $this->flushChecks();
    }

    public function testSourceMissesAreDistinct(): void
    {
        check('畸形 site', (SourceLocator::locate('no-colon')['reason'] ?? null) === 'malformed-site');
        check('空行号', (SourceLocator::locate('src/Foo.php:abc')['reason'] ?? null) === 'malformed-site');
        check('未加载文件', (SourceLocator::locate('nonexistent/NoSuchFile.php:1')['reason'] ?? null) === 'file-not-loaded');
        // 已加载文件里一个没有方法覆盖的行：SourceLocator 自己的文件，行号 1（namespace 声明处）。
        $site = self::siteOf(SourceLocator::class, 'locate');
        $file = \substr($site, 0, (int) \strrpos($site, ':'));
        check('无方法覆盖', (SourceLocator::locate($file . ':1')['reason'] ?? null) === 'no-method-covers-line');
        $this->flushChecks();
    }

    public function testSourceIsNotEvidence(): void
    {
        [, $session] = $this->open(new Text('hello'));
        $before = $session->query('digest');
        $site = self::siteOf(SourceLocator::class, 'locate');
        $session->query('source', ['site' => $site]);
        check('读源码位置不改指纹', $before === $session->query('digest'));

        $path = \tempnam(\sys_get_temp_dir(), 'probe-source-');
        try {
            Evidence::write($session->recorder(), $path, Evidence::ALL_DOMAINS);
            $json = (string) \file_get_contents($path);
            check('证据包无 definedAt 字段', !\str_contains($json, 'definedAt'));
            check('证据包无 source 标记', !\str_contains($json, '"source"'));
        } finally {
            @\unlink($path);
        }
        $this->flushChecks();
    }

    public function testProbeIsRegisteredAndHelpIsSame(): void
    {
        check('probe 出口已注册', Registry::get('probe')->script === 'debug-probe.php');
        check('probe 走 schema', Registry::get('probe')->driver === true);
        check('probe 有 what 参数', \in_array('what', Commands::set('probe')->words(), true));
        check('probe 有 source 参数', \in_array('source', Commands::set('probe')->words(), true));

        $root = \dirname(__DIR__, 3);
        $direct = self::runCli([PHP_BINARY, $root . '/tools/debug-probe.php', '--help']);
        $viaDrift = self::runCli([PHP_BINARY, $root . '/bin/drift', 'debug', 'probe', '--help']);
        check('probe 两级 help 逐字节相同', $direct === $viaDrift && $direct !== '', self::show([$direct, $viaDrift]));
        $this->flushChecks();
    }

    /** 反射取一个方法的 file:line（与 Recorder::callSite 同形状）。 */
    private static function siteOf(string $class, string $method): string
    {
        $r = new \ReflectionMethod($class, $method);
        $file = $r->getFileName();
        if ($file === false) {
            return '';
        }
        $cwd = \getcwd();
        if ($cwd !== false && \str_starts_with($file, $cwd)) {
            $file = \ltrim(\substr($file, \strlen($cwd)), '/\\');
        }

        return \str_replace('\\', '/', $file) . ':' . $r->getStartLine();
    }

    private static function show(mixed $value): string
    {
        return \json_encode($value, \JSON_UNESCAPED_UNICODE | \JSON_PRESERVE_ZERO_FRACTION) ?: \get_debug_type($value);
    }
}

/** @internal 判据夹具 */
final class ProbeApplication extends Application
{
    public function __construct(private readonly Widget $rootWidget)
    {
        parent::__construct();
    }

    public function buildRoot(): Widget
    {
        return $this->rootWidget;
    }
}
