<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Logging;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Logging\Log;
use Drift\Logging\PatternFormatter;
use Drift\Logging\StreamSink;

/**
 * 文件 sink（`12` §6，从 smoke 迁出）。
 *
 * ## 迁移时的有效性审计（2026-10-28 第 24 批）
 *
 * - **有效**：落盘逐字节比（含行尾 `\n`）；轮转的**关键夹具是"单条就超上限的长消息"**
 *   —— 只有"轮转检查在 `write()` 开头"才能保证它整条落进新文件；若检查在结尾，
 *   它会被劈成"尾巴在旧文件、没有在新文件"，第二条必红。
 * - **隔离**：`setUp()` 建独立临时目录 + `Log::reset()`，`tearDown()` 删目录 + 复位，
 *   文件 sink 不会跨用例残留。
 */
final class LogStreamSinkTest extends SmokeTestCase
{
    private string $tmp = '';

    protected function setUp(): void
    {
        parent::setUp();
        Log::reset();
        $this->tmp = \sys_get_temp_dir() . '/drift-log-' . \getmypid();
        if (\is_dir($this->tmp)) {
            foreach (\glob($this->tmp . '/*') ?: [] as $f) {
                @\unlink($f);
            }
        } else {
            \mkdir($this->tmp, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        Log::reset();
        if ($this->tmp !== '') {
            foreach (\glob($this->tmp . '/*') ?: [] as $f) {
                @\unlink($f);
            }
            @\rmdir($this->tmp);
        }
        parent::tearDown();
    }

    public function testStreamSinkWritesLines(): void
    {
        // ⑩ StreamSink 落盘 + 每行按 pattern 成型
        $sink = new StreamSink($this->tmp . '/app.log', 0, PatternFormatter::compile('%c: %m'));
        Log::addSink($sink);
        Log::get('net')->info('第一次');
        Log::get('net')->warn('第二次');
        $text = \is_file($this->tmp . '/app.log') ? (string) \file_get_contents($this->tmp . '/app.log') : '';
        check(
            'Log · StreamSink 落盘，两行各自成型',
            $text === "net: 第一次\nnet: 第二次\n",
            'got=' . \var_export($text, true),
        );
    }

    public function testRotationKeepsWholeMessage(): void
    {
        // ⑪ 轮转：上限 10 字节。**一条就超上限的长消息**是关键夹具 ——
        //    只有"轮转检查在 write() 开头"才能保证它整条落进新文件。
        $sink = new StreamSink($this->tmp . '/rot.log', 10, PatternFormatter::compile('%m'));
        Log::addSink($sink);
        Log::get('t')->info('AAAAAAAAAA'); // 11 字节（含 \n）⇒ 已超
        Log::get('t')->info('BBBBBBBBBB'); // 开头检查 ⇒ 先转，整条进新文件
        $cur = \is_file($this->tmp . '/rot.log') ? (string) \file_get_contents($this->tmp . '/rot.log') : '';
        $old = \is_file($this->tmp . '/rot.log.1') ? (string) \file_get_contents($this->tmp . '/rot.log.1') : '';
        check(
            'Log · 超上限轮转（旧的成 .1；单条超上限也整条落新文件，不被劈开）',
            $old === "AAAAAAAAAA\n" && $cur === "BBBBBBBBBB\n",
            'old=' . \var_export($old, true) . ' cur=' . \var_export($cur, true),
        );
    }
}
