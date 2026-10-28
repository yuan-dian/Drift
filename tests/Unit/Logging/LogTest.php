<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Logging;

use Drift\Dev\Smoke\CollectSink;
use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Errors;
use Drift\Logging\Log;
use Drift\Logging\LogEntry;
use Drift\Logging\LogSink;
use Drift\Logging\PatternFormatter;
use Drift\Logging\RingSink;
use ReflectionProperty;

/**
 * 日志核心（`12` §6 P0，从 smoke 迁出）。
 *
 * ## 迁移时的有效性审计（2026-10-28 第 24 批）
 *
 * - **隔离**：smoke 用 `{ Log::reset(); … }` 内联块；这里统一 `setUp()/tearDown()`
 *   `Log::reset() + Errors::reset()`，全局静态不互相污染、也不会因提前返回漏复位。
 * - **假绿修正（重要）**：原「同一 pattern 1000 次 format 逐字节相同（**编译一次，
 *   不再解析**）」的标题**过度承诺** —— 1000 次输出相同只证"确定性"，重复解析也能
 *   相同。改为：① 标题只说"确定性"；② **另加一条反射判据** `compile()` 必须把
 *   pattern 拆成 5 片段（3 占位 + 2 字面量），`format()` 只拼接。解析若被挪回
 *   `format()`，`$parts` 会退化成 1 个字面量 ⇒ 该条红。这才真能证伪"编译一次"。
 * - **有效性**：其余每条都落在被测语义上（门槛 / 分类 / 环 / seq / disable /
 *   每 sink 独立 formatter / 占位符 / recent 过滤 / Errors 桥 / flush·close 转发）。
 */
final class LogTest extends SmokeTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Log::reset();
        Errors::reset();
    }

    protected function tearDown(): void
    {
        Log::reset();
        Errors::reset();
        parent::tearDown();
    }

    public function testThresholdSuppressesBelowLevel(): void
    {
        // ① 未达门槛不写（DEBUG 在 INFO 门槛下消失）
        $collect = new CollectSink();
        Log::addSink($collect);
        Log::setThreshold(Log::INFO);
        Log::get('layout')->debug('不到门槛');
        Log::get('layout')->info('到门槛了');
        check(
            'Log · 未达门槛不写（DEBUG 在 INFO 门槛下消失）',
            \count($collect->got) === 1 && $collect->got[0]->message === '到门槛了',
            'got=' . \count($collect->got),
        );
    }

    public function testCategoryThresholdOverrides(): void
    {
        // ② 分类点名覆盖门槛（layout=DEBUG 时只 layout 进）
        $collect = new CollectSink();
        Log::addSink($collect);
        Log::setThreshold(Log::INFO);
        Log::setCategoryThreshold('layout', Log::DEBUG);
        Log::get('layout')->debug('layout 的 debug');
        Log::get('render')->debug('render 的 debug');
        check(
            'Log · 分类点名覆盖门槛（layout=DEBUG 时只 layout 进）',
            \count($collect->got) === 1 && $collect->got[0]->category === 'layout',
            'got=' . \count($collect->got),
        );
    }

    public function testRingDropsOldest(): void
    {
        // ③ 环满丢最旧（容量 3 写 4 条，第一条不在）
        $ring = new RingSink(3);
        Log::addSink($ring);
        foreach ([1, 2, 3, 4] as $i) {
            Log::get('t')->info('第 ' . $i . ' 条');
        }
        $keep = \array_map(static fn(LogEntry $e): string => $e->message, $ring->recent());
        check(
            'Log · 环满丢最旧（容量 3 写 4 条，第一条不在）',
            $keep === ['第 2 条', '第 3 条', '第 4 条'],
            \implode(',', $keep),
        );
    }

    public function testSeqMonotonicFromOne(): void
    {
        // ④ seq 单调递增且从 1 起
        $ring = new RingSink(10);
        Log::addSink($ring);
        foreach (\range(1, 5) as $i) {
            Log::get('t')->info('n' . $i);
        }
        $seqs = \array_map(static fn(LogEntry $e): int => $e->seq, $ring->recent());
        check(
            'Log · seq 单调递增且从 1 起',
            $seqs === [1, 2, 3, 4, 5],
            \implode(',', $seqs),
        );
    }

    public function testDiagnosticBridgeToLog(): void
    {
        // ⑤ Errors::report(DIAGNOSTIC) 落到 Log（WARN 级）—— 同时 Errors::$ring 不受影响
        $ring = new RingSink(10);
        Log::addSink($ring);
        Errors::report('诊断桥测试', Errors::DIAGNOSTIC);
        check(
            'Log · Errors::report(DIAGNOSTIC) 落到 Log 环（WARN 级）',
            \count($ring->recent()) === 1
                && $ring->recent()[0]->level === Log::WARN
                && $ring->recent()[0]->category === 'framework'
                && $ring->recent()[0]->message === '诊断桥测试',
            'got=' . \count($ring->recent()),
        );
        check(
            'Log · 桥不动 Errors::$ring（测试契约，78 处断言靠它）',
            Errors::recentDiagnostics() === ['诊断桥测试'],
            \json_encode(Errors::recentDiagnostics()),
        );
    }

    public function testDisableStopsAllWrites(): void
    {
        // ⑥ disable() 后零写入（连 FATAL 也不写）
        $collect = new CollectSink();
        Log::addSink($collect);
        Log::disable();
        Log::get('t')->error('不该出现');
        Log::get('t')->log(Log::FATAL, 'FATAL 也不该出现');
        Log::enable();
        check(
            'Log · disable() 后零写入（连 FATAL 也不写）',
            $collect->got === [],
            'got=' . \count($collect->got),
        );
    }

    public function testPatternIsDeterministicAndCompiledOnce(): void
    {
        $fmt = PatternFormatter::compile('%l %c: %m');
        $sample = new LogEntry(7, Log::INFO, 'layout', '测量', ['h' => 320], 1_000_000, 0);

        // ⑦a 确定性：同一 entry 反复 format 逐字节相同（★ 标题只说确定性）
        $one = $fmt->format($sample);
        $same = true;
        for ($i = 0; $i < 1000; $i++) {
            if ($fmt->format($sample) !== $one) {
                $same = false;
                break;
            }
        }
        check(
            'Log · 同一 pattern 1000 次 format 逐字节相同（确定性）',
            $same && $one === 'INFO layout: 测量',
            'got=' . $one,
        );

        // ⑦b ★ 真能证伪"编译一次"：compile() 必须把 pattern 拆成片段，format() 只拼接。
        //   '%l %c: %m' ⇒ [占位 l][字面 ' '][占位 c][字面 ': '][占位 m] 共 5 片段。
        //   解析若被挪回 format()，$parts 会退化成 1 个字面量 ⇒ 本条红。
        //   类型也钉（LITERAL=0 / PLACEHOLDER=1）：只比文本会漏"占位符被误存成字面量"
        //   （文本仍是 'l'，但 format() 会原样输出 'l' 而不是 INFO）。
        $parts = (new ReflectionProperty(PatternFormatter::class, 'parts'))->getValue($fmt);
        $splitOk = \is_array($parts) && \count($parts) === 5
            && $parts[0][0] === 1 && $parts[0][1] === 'l'
            && $parts[1][0] === 0 && $parts[1][1] === ' '
            && $parts[2][0] === 1 && $parts[2][1] === 'c'
            && $parts[3][0] === 0 && $parts[3][1] === ': '
            && $parts[4][0] === 1 && $parts[4][1] === 'm';
        check(
            'Log · compile() 已拆成 5 片段（3 占位 + 2 字面量，类型也对），format() 只拼接',
            $splitOk,
            \is_array($parts) ? 'parts=' . \count($parts) : 'not-array',
        );
    }

    public function testPerSinkFormatter(): void
    {
        // ⑧ 每 sink 独立 formatter（同一条 entry 两种成型）
        $a = new RingSink(4);
        $b = new CollectSink();
        Log::addSink($a);
        Log::addSink($b);
        Log::get('render')->info('独立格式', ['k' => 1]);
        $viaA = PatternFormatter::compile('%s %c %m')->format($a->recent()[0]);
        $viaB = PatternFormatter::compile('%m')->format($b->got[0]);
        check(
            'Log · 每 sink 独立 formatter（同一条 entry 两种成型）',
            $viaA === '1 render 独立格式' && $viaB === '独立格式',
            'a=' . $viaA . ' b=' . $viaB,
        );
    }

    public function testAllPlaceholders(): void
    {
        // ⑨ 占位符全集（含 %T 墙钟来自 entry，不是 format() 里的 date()）
        $all = PatternFormatter::compile('%T|%t|%l|%c|%m|%f|%s|%%');
        $e = new LogEntry(9, Log::WARN, 'net', '掉线', ['ip' => '127.0.0.1', 'retry' => true], 5_500_000_000, 3_661_234);
        // wallMs=3_661_234 ⇒ 3661 秒 ⇒ 01:01:01；monotonic=5.5s 相对启动
        $line = $all->format($e);
        check(
            'Log · 占位符全集（%T 由 entry 的 wallMs 算，不是 format() 里取的）',
            \str_contains($line, '01:01:01') && \str_contains($line, 'WARN')
                && \str_contains($line, 'net') && \str_contains($line, '掉线')
                && \str_contains($line, 'ip=127.0.0.1 retry=true')
                && \str_contains($line, '|9|%'),
            'got=' . $line,
        );
    }

    public function testRecentFiltersByCategoryAndLevel(): void
    {
        // ⑩ 过滤在数据层（RingSink::recent($category, $minLevel)），两个参数各自独立
        $ring = new RingSink(10);
        Log::addSink($ring);
        Log::get('layout')->info('layout 的 info');
        Log::get('layout')->warn('layout 的 warn');
        Log::get('net')->info('net 的 info');
        Log::get('net')->error('net 的 error');
        $mAll = \array_map(static fn(LogEntry $e): string => $e->message, $ring->recent());
        $mLayout = \array_map(static fn(LogEntry $e): string => $e->message, $ring->recent('layout'));
        $mWarnUp = \array_map(static fn(LogEntry $e): string => $e->message, $ring->recent(null, Log::WARN));
        $mBoth = \array_map(static fn(LogEntry $e): string => $e->message, $ring->recent('net', Log::WARN));
        check(
            'Log · recent() 按分类 / 级别过滤（两个参数各自独立，可叠加）',
            $mAll === ['layout 的 info', 'layout 的 warn', 'net 的 info', 'net 的 error']
                && $mLayout === ['layout 的 info', 'layout 的 warn']
                && $mWarnUp === ['layout 的 warn', 'net 的 error']
                && $mBoth === ['net 的 error'],
            'all=' . \count($mAll) . ' layout=' . \count($mLayout)
                . ' warnUp=' . \count($mWarnUp) . ' both=' . \count($mBoth),
        );
    }

    public function testFlushCloseForwardToAllSinks(): void
    {
        // ⑫ flush() / close() 逐个转发到全部 sink
        $collect = new CollectSink();
        $ring = new RingSink(4);
        Log::addSink($collect);
        Log::addSink($ring);
        Log::flush();
        Log::close();
        check(
            'Log · flush() / close() 逐个转发到全部 sink',
            $collect->flushes === 1 && $collect->closes === 1,
            'flushes=' . $collect->flushes . ' closes=' . $collect->closes,
        );
    }

    public function testSinkCloseFailureDoesNotAffectOthers(): void
    {
        // ⑬ 一个 sink 收不了口不影响其他（逐个 catch，不冒泡到业务）
        $collect = new CollectSink();
        $boom = new class implements LogSink {
            public function write(LogEntry $entry): void
            {
            }

            public function flush(): void
            {
                throw new \RuntimeException('sink 炸了');
            }

            public function close(): void
            {
                throw new \RuntimeException('sink 炸了');
            }
        };
        Log::addSink($boom);
        Log::addSink($collect);
        $threw = false;
        try {
            Log::flush();
            Log::close();
        } catch (\Throwable) {
            $threw = true;
        }
        check(
            'Log · 一个 sink 收不了口不影响其他（且不冒泡到业务）',
            $threw === false && $collect->flushes === 1 && $collect->closes === 1,
            'threw=' . \var_export($threw, true),
        );
    }

    public function testBadPatternFailsLoudly(): void
    {
        // ⑭ 未知占位符 ⇒ Fatal（G2 不静默，不产出缺字段的行）
        $log = \sys_get_temp_dir() . '/drift-logbadpattern-' . \getmypid() . '.log';
        @\unlink($log);
        [$code, $out] = runFatalCase('log-bad-pattern', $log);
        $text = \is_file($log) ? (string) \file_get_contents($log) : '';
        @\unlink($log);
        check(
            'Log · 未知占位符 ⇒ Fatal（G2 不静默，不产出缺字段的行）',
            $code === 3 && \str_contains($text, '未知占位符'),
            'code=' . $code . ' out=' . $out,
        );
    }
}
