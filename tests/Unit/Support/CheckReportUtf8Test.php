<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Support;

use Drift\Dev\Support\CheckItem;
use Drift\Dev\Support\CheckReport;
use PHPUnit\Framework\TestCase;

/**
 * `CheckReport::json()` 的编码边界（2026-11-12 CI 事故，判据必须进 `unit` 套件）。
 *
 * ## 事故形状
 *
 * `gate --fast --json` 在 Windows CI 上跑完 27 项后，在**最后一步序列化报告**时
 * `JsonException: Malformed UTF-8` ⇒ 退出码 255，日志里看不到是哪条判据失败。
 * 因为 `gate` 把失败 / skip 项的子进程 stdout/stderr **原始字节**塞进
 * `items[].data`，而 Windows 本地代码页（GBK / cp1252）不是 UTF-8。
 * 本地 27 项全过、没有任何 stdout/stderr 进 JSON ⇒ 文本模式与 `--json` 都是绿的，
 * 这条路径从来没被验过 —— 正是「不报错、只是没效果」的同族。
 *
 * ## 这里钉什么
 *
 * | # | 判据 | 能红的真实事故 |
 * |---|---|---|
 * | ① | 含非法字节时 `json()` 不抛，且顶层 `encoding` 精确标出字段路径（含嵌套） | 删掉 `JSON_INVALID_UTF8_SUBSTITUTE` ⇒ 又变 Fatal 255；删掉标记 ⇒ 静默替换没人知道 |
 * | ② | 合法字段原样保留、替换真的发生、`ok` / `status` 不被洗绿 | 整段重编码 / 把失败当通过 |
 * | ③ | 全合法时**不许**冒出 `encoding` | 扫描判据恒真 ⇒ 每次都报"被动过"的假警 |
 *
 * ★ 反同源：期望值来自手写 GBK 字节（`\xB4\xED\xCE\xF3` = "错误"）与 U+FFFD
 *   字面量，不拿 `json_encode` 自己的结果当期望 —— 实现和判据一起错也绿。
 * ★ 放 `unit` 而非 `feature`：CI 的 `gate-fast` 跑 `--fast`，`feature` 是 slow 项。
 */
final class CheckReportUtf8Test extends TestCase
{
    public function testNonUtf8ChildOutputDoesNotKillTheReport(): void
    {
        $r = new CheckReport('gate', [
            new CheckItem('decode', false, '图片通道序：编译失败', [
                'stdout' => "cl : \xB4\xED\xCE\xF3 GBK \xD6\xD0\xCE\xC4\n",
                'stderr' => 'ascii only',
                'nested' => ['msg' => "\xFF\xFE"],
            ]),
            new CheckItem('lint', true, '通过'),
        ], 'x');

        $decoded = json_decode($r->json(), true, 512, JSON_THROW_ON_ERROR);

        // ① 能走到这里就是第一条判据；标记必须精确到字段路径（含嵌套），且不放过合法字段
        self::assertTrue($decoded['encoding']['substituted']);
        self::assertContains('items.0.data.stdout', $decoded['encoding']['nonUtf8Fields']);
        self::assertContains('items.0.data.nested.msg', $decoded['encoding']['nonUtf8Fields']);
        self::assertNotContains('items.0.data.stderr', $decoded['encoding']['nonUtf8Fields']);
        // ② 替换**真的发生**（不是靠"没检测到"侥幸）；合法 ASCII 与中文原样保留
        self::assertStringContainsString("\xEF\xBF\xBD", $decoded['items'][0]['data']['stdout']);
        self::assertStringContainsString('ascii only', $decoded['items'][0]['data']['stderr']);
        self::assertSame('图片通道序：编译失败', $decoded['items'][0]['text']);
        // 编码处理不许把「失败」洗成「通过」
        self::assertFalse($decoded['ok']);
        self::assertSame('fail', $decoded['items'][0]['status']);
    }

    /** ③ 全合法时不许冒出 encoding（否则扫描判据恒真 = 绿壳）。 */
    public function testValidUtf8HasNoEncodingMarker(): void
    {
        $decoded = json_decode(
            (new CheckReport('demo', [
                new CheckItem('①', true, '精确命中'),
                new CheckItem('pixels', true, '本机没 python', ['exitCode' => null], true),
            ], '中文收尾 ✓'))->json(),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        self::assertArrayNotHasKey('encoding', $decoded);
        self::assertSame('中文收尾 ✓', $decoded['summary']);
    }
}
