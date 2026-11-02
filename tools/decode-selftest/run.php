<?php
declare(strict_types=1);

namespace Drift\Dev;

/**
 * `decode-selftest` 的驱动：编译 + 运行（供闸门调用）。
 *
 * ```
 * php tools/decode-selftest/run.php                      # 只跑色带判据
 * php tools/decode-selftest/run.php --image <真图>        # 再跑真图判据
 * ```
 *
 * 为什么需要一个驱动，而不是让闸门直接写两条命令：
 *
 *   ① `cl.exe` 要 `INCLUDE` / `LIB` / `PATH` 三件套 —— 和 `drift-build.php` 同一个坑，
 *      定位逻辑只有 `Doctor\Environment` 一份（**不在这里重算路径**）。
 *   ② 每次闸门都重编译没有必要：源码没动就复用 exe。判据是 mtime，不是"我猜"。
 *   ③ **"没测到"必须和"失败"分开报**：没有 MSVC 的机器上退出码 2（闸门会记成
 *      跳过），而源码编不过、判据不过都是 1。混在一起就是本仓最恨的"绿壳"。
 *
 * ★ 这个程序**不在内环**（§16.3：内环不编译），只在闸门里跑一次 ~2s。
 * ★ 它验的是 `cpp-src/win32/image_decode.cc` —— 那段代码在 `php.exe` 里跑不到
 *   （没有 WIC），单测覆盖不了，所以只能这样开口。
 */

use Drift\Dev\Doctor\Environment;
use Drift\Dev\Doctor\EnvironmentException;

require_once __DIR__ . '/../autoload.php';

const DECODE_DIR = __DIR__;

/**
 * @param list<string> $cmd
 * @param array<string, string> $env
 * @return array{0: int, 1: string}
 */
function decode_run(array $cmd, string $cwd, array $env): array
{
    $descriptors = [1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
    $process = proc_open($cmd, $descriptors, $pipes, $cwd, $env, ['bypass_shell' => true]);
    if (!is_resource($process)) {
        return [127, "启动失败：" . implode(' ', $cmd)];
    }

    $out = (string) stream_get_contents($pipes[1]);
    $err = (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [proc_close($process), $out . $err];
}

function decode_main(array $argv): int
{
    if (PHP_OS_FAMILY !== 'Windows') {
        fwrite(STDERR, "decode-selftest：只有 Windows 有 WIC，本机不必测\n");

        return 2;   // 没测到
    }

    try {
        $prepared = Environment::prepare(dirname(__DIR__, 2));
    } catch (EnvironmentException $e) {
        fwrite(STDERR, "decode-selftest：本机工具链不全，跳过（{$e->getMessage()}）\n");

        return 2;   // 没测到 —— 编不出来不等于判据失败
    }

    $exe = DECODE_DIR . '/selftest.exe';
    $sources = [
        DECODE_DIR . '/selftest.cc',
        dirname(__DIR__, 2) . '/cpp-src/win32/image_decode.cc',
        dirname(__DIR__, 2) . '/cpp-src/win32/image_decode.h',
    ];

    // 需要重编译吗：exe 不存在，或者任一源码比它新
    $needBuild = !is_file($exe);
    if (!$needBuild) {
        $built = (int) filemtime($exe);
        foreach ($sources as $source) {
            if (is_file($source) && (int) filemtime($source) > $built) {
                $needBuild = true;
                break;
            }
        }
    }

    if ($needBuild) {
        // ★ 绝对路径：`proc_open(bypass_shell)` 按**父进程**的 PATH 找可执行文件，
        //   `prepare()` 塞进子 env 的 PATH 帮不上忙（详见 `Environment::msvcRoot()`）。
        $cl = Environment::msvcRoot() . '/bin/Hostx64/x64/cl.exe';
        if (!is_file($cl)) {
            fwrite(STDERR, "decode-selftest：找不到 cl.exe（{$cl}）\n");

            return 2;
        }

        [$code, $output] = decode_run([
            $cl, '/nologo', '/std:c++17', '/EHsc', '/O2',
            'selftest.cc',
            dirname(__DIR__, 2) . '/cpp-src/win32/image_decode.cc',
            '/Fe:selftest.exe',
            '/link', 'windowscodecs.lib', 'ole32.lib',
        ], DECODE_DIR, $prepared->env);

        if ($code !== 0) {
            // ★ 编不过 = 真失败，不是"没测到"：源码就在仓里，能编是它的底线。
            fwrite(STDERR, "decode-selftest：编译失败\n{$output}\n");

            return 1;
        }
    }

    $args = [$exe, '--fixture', DECODE_DIR . '/fixtures/rgb-bands.png'];
    for ($i = 1; $i < count($argv); ++$i) {
        $args[] = $argv[$i];
    }

    [$code, $output] = decode_run($args, DECODE_DIR, $prepared->env);
    echo $output;

    // ★ 判据失败（1）与"跑不起来"（其他非零）不是一回事，但都不该被当成绿。
    return $code === 0 ? 0 : 1;
}

exit(decode_main($argv));
