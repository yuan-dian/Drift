<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Api\ApiClient;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `ApiClient::extractTaskId()` —— 建任务响应的**四种兜底形状**（`apps/watermark`）。
 *
 * ## 为什么这一条值得单独钉
 *
 * 删掉任何一种兜底，症状都是**"建任务成功但界面没反应"**：`taskId` 拿到空串 ⇒
 * 轮询不启动 ⇒ 用户盯着"正在查询结果…"永远转圈，而日志里一条错误都没有。
 * 这条兜底链是原作者在真实联调里试出来的（`shuiyin.html` 的 `||` 链）。
 *
 * ## 四种形状
 *
 * | # | 响应 `data` | 期望 |
 * |---|---|---|
 * | ① | `"1234567890"`（裸字符串） | trim 后原样 |
 * | ② | `{taskId: 123}` | `'123'` |
 * | ③ | `{id: 123}` | `'123'`（字段名换过） |
 * | ④ | 都没有 | `''`（调用方据此报错，而不是拿个假 id 去轮询） |
 *
 * ★ `taskId` 是 `0` / `''` 时**跳过**（原文的 `||` 语义）：`'0'` 不是合法任务号，
 *   拿它去查只会把错误推到"查不到结果"那一步 —— 归因跑偏。
 */
final class ApiClientParseTest extends TestCase
{
    #[Test]
    public function testBareStringShape(): void
    {
        $this->assertSame('1234567890', ApiClient::extractTaskId('1234567890'));
        $this->assertSame('1234567890', ApiClient::extractTaskId("  1234567890\n"));
    }

    #[Test]
    public function testTaskIdKeyShape(): void
    {
        $this->assertSame('1234567890', ApiClient::extractTaskId(['taskId' => '1234567890']));
        // 数字（服务端把 id 当 int 给）
        $this->assertSame('123', ApiClient::extractTaskId(['taskId' => 123]));
    }

    #[Test]
    public function testIdKeyShape(): void
    {
        $this->assertSame('777', ApiClient::extractTaskId(['id' => '777']));
    }

    #[Test]
    public function testTaskIdWinsOverId(): void
    {
        $this->assertSame(
            'from-taskId',
            ApiClient::extractTaskId(['taskId' => 'from-taskId', 'id' => 'from-id']),
            '两个都在时以 taskId 为准（id 可能是别的业务号）'
        );
    }

    #[Test]
    public function testEmptyTaskIdFallsThroughToId(): void
    {
        $this->assertSame('fallback', ApiClient::extractTaskId(['taskId' => '', 'id' => 'fallback']));
    }

    #[Test]
    public function testZeroTaskIdIsTreatedAsAbsent(): void
    {
        // ★ 原文 `||` 的语义：0 是假值 ⇒ 跳到下一项。`'0'` 拿去做 taskId 会让
        //   轮询查一个不存在的任务，错误出现在"查不到"那一步。
        $this->assertSame('real', ApiClient::extractTaskId(['taskId' => 0, 'id' => 'real']));
        $this->assertSame('real', ApiClient::extractTaskId(['taskId' => '0', 'id' => 'real']));
        $this->assertSame('', ApiClient::extractTaskId(['taskId' => 0]));
    }

    #[Test]
    public function testUnknownShapeMeansEmpty(): void
    {
        $this->assertSame('', ApiClient::extractTaskId(['foo' => 'bar']));
        $this->assertSame('', ApiClient::extractTaskId([]));
        // 非字符串/非数组：不崩、不猜
        $this->assertSame('', ApiClient::extractTaskId(null));
        $this->assertSame('', ApiClient::extractTaskId(42));
        $this->assertSame('', ApiClient::extractTaskId(true));
    }
}
