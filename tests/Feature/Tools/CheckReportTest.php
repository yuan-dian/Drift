<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Tools;

use Drift\Dev\Support\CheckItem;
use Drift\Dev\Support\CheckReport;
use PHPUnit\Framework\TestCase;

/**
 * 判据的**一份账本两种渲染**（2026-11-03）。
 *
 * ## 为什么要测一个"打印格式"
 *
 * `tools/` 里"编号 + 过没过 + 一行说明"这套三元组此前手写了 **6 份**：
 * `frame-tax-probe`（两处）、`rva2sym`、`M2Conformance`、`smoke` 的 `check()`、
 * `gate` 的编排项。每份自带 `$fail++` 和自己的收尾打印 ⇒「判了几条」「红了几条」
 * 「退出码」是三个各自维护的数。`golden-raster` 更典型：它**已经**有结构化账本
 * （`_build/golden-manifest.json`，呈现层按它消费），但那份账本是另 push 的 ——
 * 同一件事 `echo` 一遍、数组里再说一遍。
 *
 * 于是"加一列"要改两处，"改一句话"要改两处。本仓对这种形状有明确判据
 * （§15 九段的两个账本、§16 活跃表与全扫的标记位）：**同一个事实必有一个来源**。
 *
 * ## 这里钉的是什么
 *
 * | # | 判据 | 挡什么 |
 * |---|---|---|
 * | ① | 全过：每行 `  ok    id text`、收尾 `--- summary ---`、退出码 0 | 格式与退出码各算一次 |
 * | ② | 失败：收尾是 `FAIL（n / m 条）`，且**带总数** | 471 行的 smoke 里只有失败数等于没信息 |
 * | ③ | `skip` 印成 `skip`，不是 `ok` | "跳过"被伪装成"通过"（`gate` 的 `$may_skip` 纪律） |
 * | ④ | **文本与 JSON 的 `(status,id)` 序列逐条相等** | 两段代码各渲染一次，今天一致、下月漂 |
 * | ⑤ | 空 items / 重复 id / 非 CheckItem / 空 tool **全部抛** | "0 项全过"这种绿壳 |
 * | ⑥ | `data` 为空时 JSON 里不出现该键 | 无条件塞 `data:{}` 让"有没有补充信息"不可分辨 |
 * | ⑦ | `emitLine()` 印出来的那一行与 `text()` 的对应行**逐字相同** | 边跑边打的调用方抄一份格式 |
 */
final class CheckReportTest extends TestCase
{
    /** @return list<CheckItem> */
    private static function sample(): array
    {
        return [
            new CheckItem('①', true, '精确命中'),
            new CheckItem('②', false, '偏移不对：给了 +0x8'),
            new CheckItem('pixels', true, '本机没 python', ['exitCode' => null], true),
            new CheckItem('④', true, '', ['w' => 900]),
        ];
    }

    /** ① 全过：格式 + 收尾 + 退出码。 */
    public function testAllPass(): void
    {
        $r = new CheckReport('demo', [
            new CheckItem('①', true, '精确命中'),
            new CheckItem('②', true, '偏移正确'),
        ], '2 项判据全过（500 行 / 30 帧）');

        self::assertTrue($r->ok());
        self::assertSame(0, $r->exitCode());
        self::assertSame(
            "  ok    ① 精确命中\n  ok    ② 偏移正确\n",
            $r->text(),
        );
        self::assertSame("\n--- 2 项判据全过（500 行 / 30 帧） ---\n", $r->summaryLine());
    }

    /** ② 失败：收尾带总数（`n / m`），退出码 1。 */
    public function testFailureSummaryCarriesTotal(): void
    {
        $r = new CheckReport('demo', self::sample(), '不该被印出来');

        self::assertFalse($r->ok());
        self::assertSame(1, $r->exitCode());
        self::assertCount(1, $r->failures());
        self::assertSame("\n--- FAIL（1 / 4 条）---\n", $r->summaryLine());

        // 全过的那句话在失败时**不许**出现（本仓踩过的绿壳：两种说法并存）
        self::assertStringNotContainsString('不该被印出来', $r->summaryLine());
    }

    /** ③ 跳过不是通过：三态各自可分辨。 */
    public function testSkipIsNotPass(): void
    {
        $r = new CheckReport('demo', self::sample(), 'x');

        self::assertCount(1, $r->skipped());
        self::assertSame('skip', $r->skipped()[0]->status());
        // ★ ok() 仍是 true（它没失败），所以正文那一行必须自己说清"没测到"
        self::assertStringContainsString('  skip  pixels 本机没 python', $r->text());
        self::assertStringNotContainsString('ok    pixels', $r->text());
    }

    /**
     * ④ 文本与 JSON **同源** —— 本期唯一真正的判据。
     *
     * 前三条只证"我打印得对"，只有这一条证"两个渲染说的是一回事"。
     * 做法：从 `text()` 里解析 `(status, id)`，与 `json()['items']` 逐条比。
     */
    public function testTextAndJsonAgreeItemByItem(): void
    {
        $r = new CheckReport('demo', self::sample(), 'x');

        $fromText = [];
        foreach (explode("\n", rtrim($r->text(), "\n")) as $line) {
            // `  ok    ① 精确命中` / `  FAIL  ② …` / `  skip  pixels …`
            self::assertMatchesRegularExpression('/^  (ok|FAIL|skip) +\S/', $line, '正文行格式变了：' . $line);
            preg_match('/^  (\S+) +(\S+)/', $line, $m);
            // ★ 显示用 `FAIL`、JSON 用 `fail`：映射一次，两边的"状态"才能比
            $fromText[] = [$m[1] === 'FAIL' ? 'fail' : $m[1], $m[2]];
        }

        $decoded = json_decode($r->json(), true, 512, JSON_THROW_ON_ERROR);
        $fromJson = array_map(
            static fn(array $i): array => [$i['status'], $i['id']],
            $decoded['items'],
        );

        self::assertSame($fromJson, $fromText, '文本与 JSON 的条目序列不一致 —— 两个渲染漂了');
        self::assertSame($r->ok(), $decoded['ok']);
        self::assertSame(count($r->items()), count($decoded['items']));
    }

    /** ⑤ 四条构造期不变量。 */
    public function testEmptyItemsThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/0 项全过/u');
        new CheckReport('demo', [], 'x');
    }

    public function testDuplicateIdThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/判据 id 重复/u');
        new CheckReport('demo', [
            new CheckItem('①', true, 'a'),
            new CheckItem('①', false, 'b'),
        ], 'x');
    }

    public function testNonItemThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/不是 CheckItem/u');
        /** @phpstan-ignore-next-line 故意传错类型 */
        new CheckReport('demo', [['①', true, 'a']], 'x');
    }

    public function testEmptyToolThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CheckReport('', [new CheckItem('①', true, 'a')], 'x');
    }

    /** ⑥ `data` 为空时不出现（"有没有补充信息"必须可分辨）。 */
    public function testEmptyDataIsOmitted(): void
    {
        $r = new CheckReport('demo', self::sample(), 'x');
        $decoded = json_decode($r->json(), true, 512, JSON_THROW_ON_ERROR);

        self::assertArrayNotHasKey('data', $decoded['items'][0]);
        self::assertSame(['w' => 900], $decoded['items'][3]['data']);
    }

    /** ⑦ 边跑边打的那一行与 `text()` 逐字相同（`golden` 走的就是这条路）。 */
    public function testEmitLineMatchesText(): void
    {
        $r = new CheckReport('demo', self::sample(), 'x');
        $lines = explode("\n", rtrim($r->text(), "\n"));

        foreach ($r->items() as $i => $item) {
            self::assertSame($lines[$i], CheckReport::line($item));
        }
    }

    /**
     * 端到端：`--json` 时 stdout 上**只有** JSON，且退出码与 JSON 的 `ok` 一致。
     *
     * ★ 选 `rva2sym --selftest`：合成 map、毫秒级，不需要构建产物。
     *   两个方向都判 —— 只有"能 json_decode"还不够，绿壳的典型形状是
     *   "JSON 说 ok、退出码是 1"（或反过来）。
     */
    public function testRva2symJsonIsTheOnlyThingOnStdout(): void
    {
        $cmd = [PHP_BINARY, \dirname(__DIR__, 3) . '/tools/rva2sym.php', '--selftest', '--json'];
        $proc = proc_open($cmd, [0 => ['file', 'nul', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, null, null, ['bypass_shell' => true]);
        self::assertIsResource($proc, '起不了子进程');
        $out = (string) stream_get_contents($pipes[1]);
        $err = (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($proc);

        // stdout 必须**整段**是 JSON（前后没有半行表头）
        $decoded = json_decode(trim($out), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('rva2sym', $decoded['tool']);
        self::assertTrue($decoded['ok']);
        self::assertSame(0, $code, 'JSON 说 ok 但退出码不是 0；stderr=' . $err);
        self::assertCount(6, $decoded['items'], '判据条数变了（此前是 6 条）');
    }
}
