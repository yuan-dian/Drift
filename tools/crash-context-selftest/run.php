<?php
declare(strict_types=1);

namespace Drift\Dev;

/**
 * 原生崩溃上下文自检：真崩一次，确认 `crash-latest.log` 带上 PHP 黑匣子 sidecar。
 *
 * 为什么是独立可执行程序：`SetUnhandledExceptionFilter` 只在 AOT / 原生进程里有意义，
 * PHP 单测既触发不到过滤器，也盖不住“过滤器里读文件”这条路径。 modeled on
 * `tools/decode-selftest/run.php`：编译复用 mtime，工具链由 `Environment` 唯一提供。
 *
 * 退出码：0 全过；1 判据失败或源码编不过；2 本机没 Win32 工具链（没测到）。
 */

use Drift\Dev\Doctor\Environment;
use Drift\Dev\Doctor\EnvironmentException;
use Drift\Dev\Support\CheckItem;
use Drift\Dev\Support\CheckReport;

require_once __DIR__ . '/../autoload.php';

/** @param list<string> $cmd @param array<string, string> $env @return array{0: int, 1: string} */
function crashctxRun(array $cmd, string $cwd, array $env): array
{
    $process = proc_open(
        $cmd,
        [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $cwd,
        $env,
        ['bypass_shell' => true],
    );
    if (!is_resource($process)) {
        return [127, '启动失败：' . implode(' ', $cmd)];
    }
    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out . $err];
}

function crashctxRemoveTree(string $dir): void
{
    if (!is_dir($dir)) {
        return;
    }
    foreach (scandir($dir) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $dir . '/' . $entry;
        if (is_dir($path) && !is_link($path)) {
            crashctxRemoveTree($path);
            continue;
        }
        @unlink($path);
    }
    @rmdir($dir);
}

function crashctxMain(array $argv): int
{
    if (PHP_OS_FAMILY !== 'Windows') {
        fwrite(STDERR, "crash-context-selftest：只有 Win32 有进程级异常过滤器，跳过\n");

        return 2;
    }

    try {
        $prepared = Environment::prepare(dirname(__DIR__, 2));
    } catch (EnvironmentException $e) {
        fwrite(STDERR, "crash-context-selftest：工具链不全，跳过（{$e->getMessage()}）\n");

        return 2;
    }

    $root = dirname(__DIR__, 2);
    $exe = __DIR__ . '/probe.exe';
    $sources = [
        __DIR__ . '/probe.cc',
        $root . '/cpp-src/win32/crash_report.cc',
        $root . '/cpp-src/win32/crash_report.h',
    ];
    $built = is_file($exe) ? (int) filemtime($exe) : 0;
    $needBuild = $built === 0;
    foreach ($sources as $source) {
        if (is_file($source) && (int) filemtime($source) > $built) {
            $needBuild = true;
            break;
        }
    }

    if ($needBuild) {
        $cl = Environment::msvcRoot() . '/bin/Hostx64/x64/cl.exe';
        if (!is_file($cl)) {
            fwrite(STDERR, "crash-context-selftest：找不到 cl.exe（{$cl}）\n");

            return 2;
        }
        [$code, $output] = crashctxRun([
            $cl, '/nologo', '/std:c++17', '/utf-8', '/EHsc', '/O2',
            'probe.cc',
            $root . '/cpp-src/win32/crash_report.cc',
            '/Fe:probe.exe',
        ], __DIR__, $prepared->env);
        if ($code !== 0) {
            fwrite(STDERR, "crash-context-selftest：编译失败\n{$output}\n");

            return 1;
        }
    }

    $tmp = sys_get_temp_dir() . '/drift-crashctx-' . bin2hex(random_bytes(6));
    mkdir($tmp . '/.drift', 0700, true);
    mkdir($tmp . '/appdata', 0700, true);
    file_put_contents(
        $tmp . '/.drift/blackbox-4242.json',
        '{"protocol":3,"probe":"a62-sidecar","lastFlushFrame":30}',
    );

    try {
        [$code, $output] = crashctxRun(
            [$exe],
            $tmp,
            array_merge($prepared->env, ['APPDATA' => $tmp . '/appdata']),
        );
        $log = $tmp . '/appdata/Drift/crash-latest.log';
        $raw = is_file($log) ? (string) file_get_contents($log) : '';

        /** @var list<CheckItem> $items */
        $items = [];
        $items[] = new CheckItem(
            '①',
            stripos($raw, 'exception=0xC0000005') !== false,
            stripos($raw, 'exception=0xC0000005') !== false
                ? '访问冲突被过滤器接住'
                : '没有原生异常头：' . substr($raw, 0, 160) . ' / ' . substr($output, 0, 160),
        );
        $items[] = new CheckItem(
            '②',
            str_contains($raw, 'file=blackbox-4242.json'),
            str_contains($raw, 'file=blackbox-4242.json')
                ? 'sidecar 文件名进了崩溃日志'
                : '没枚到 .drift/blackbox-*.json',
        );
        $items[] = new CheckItem(
            '③',
            str_contains($raw, 'a62-sidecar'),
            str_contains($raw, 'a62-sidecar')
                ? 'sidecar 正文原样附上'
                : '正文没进去（只写了文件名也没用）',
        );

        $json = in_array('--json', $argv, true);
        $report = new CheckReport('crash-context-selftest', $items, '3 项原生上下文判据');
        $report->emit($json);

        return $report->ok() ? 0 : 1;
    } finally {
        crashctxRemoveTree($tmp);
    }
}

exit(crashctxMain($argv));
