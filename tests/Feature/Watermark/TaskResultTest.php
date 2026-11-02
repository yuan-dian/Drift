<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Watermark;

use App\Watermark\Api\TaskResult;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * `TaskResult`：任务状态的**五态 + 兜底**（`apps/watermark`）。
 *
 * ## 钉的是三条"会静默"的语义
 *
 * | # | 语义 | 错了会怎样 |
 * |---|---|---|
 * | ① | `-1` / 未知状态**算终点** | 每 10 秒重试一个状态码都不认识的任务，永远转圈 |
 * | ② | 状态 3 **且**有结果图才算成功 | "完成但没图"当成功 ⇒ 界面永远空白、用户以为在下载 |
 * | ③ | `status` 认数字**也认数字字符串** | 服务端换一种给法 ⇒ 全部落进"未知状态 -1" |
 *
 * ★ 不继承 `SmokeTestCase`：纯值对象，无帧、无夹具。
 */
final class TaskResultTest extends TestCase
{
    /**
     * @param list<string> $urls
     */
    private static function makeResult(int $status, array $urls = [], string $errorMsg = ''): TaskResult
    {
        return new TaskResult('t-1', $status, '模板A', '2K', '1.5', '2026-11-02 10:00:00', '2026-11-02 10:00:02', $errorMsg, $urls);
    }

    #[Test]
    public function testPendingStates(): void
    {
        foreach ([TaskResult::STATUS_CREATED, TaskResult::STATUS_QUEUED, TaskResult::STATUS_RUNNING] as $status) {
            $r = self::makeResult($status);
            $this->assertTrue($r->isPending(), '状态 ' . $status . ' 该继续轮询');
            $this->assertFalse($r->isTerminal());
            $this->assertFalse($r->isSuccess());
        }
    }

    #[Test]
    public function testDoneWithUrlsIsSuccess(): void
    {
        $r = self::makeResult(TaskResult::STATUS_DONE, ['https://x/1.jpg', 'https://x/2.jpg']);

        $this->assertTrue($r->isSuccess());
        $this->assertTrue($r->isTerminal());
        $this->assertSame('处理完成', $r->statusLabel());
        $this->assertSame('', $r->failureReason(), '成功时不该有失败理由');
    }

    #[Test]
    public function testDoneWithoutUrlsIsNotSuccess(): void
    {
        $r = self::makeResult(TaskResult::STATUS_DONE, []);

        // ★ 这是原文的判据（`'任务完成但无结果图片'`）：状态 3 不等于成功
        $this->assertFalse($r->isSuccess(), '完成但没有结果图 ⇒ 不算成功');
        $this->assertTrue($r->isTerminal(), '已经到终点了，别继续轮询');
        $this->assertSame('处理完成（但没有结果图）', $r->statusLabel());
        $this->assertStringContainsString('模板', $r->failureReason(), '"完成但没图"多半是模板配错 —— 文案要指向它');
    }

    #[Test]
    public function testFailedWithServerMessage(): void
    {
        $r = self::makeResult(TaskResult::STATUS_FAILED, [], '图片分辨率过低');

        $this->assertTrue($r->isTerminal());
        $this->assertFalse($r->isSuccess());
        $this->assertSame('处理失败', $r->statusLabel());
        $this->assertSame('图片分辨率过低', $r->failureReason(), '服务端给了原因就原样用它');
    }

    #[Test]
    public function testFailedWithoutServerMessageStillSaysSomething(): void
    {
        $r = self::makeResult(TaskResult::STATUS_FAILED);

        $this->assertSame(
            '服务端未给出失败原因（状态 4）',
            $r->failureReason(),
            '空白理由会让用户以为界面坏了 —— 至少把状态码带上'
        );
    }

    #[Test]
    public function testUnknownStatusIsTerminalAndShowsRawCode(): void
    {
        // ★ -1 是原文里与 4 并列的"失败"；99 是没见过的值。两者都必须**停下来**。
        foreach ([-1, 99] as $status) {
            $r = self::makeResult($status);

            $this->assertTrue($r->isTerminal(), '状态 ' . $status . ' 必须是终点（否则每 10 秒重试同一个错）');
            $this->assertFalse($r->isPending());
            $this->assertStringContainsString(
                (string) $status,
                $r->statusLabel(),
                '未知状态的文案要带原始码 —— 用户截图给运维时那是唯一线索'
            );
        }
    }

    #[Test]
    public function testStatusAcceptsNumericString(): void
    {
        $r = TaskResult::fromArray(['status' => '2'], 't-9');

        $this->assertNotNull($r);
        $this->assertSame(2, $r->status, '数字字符串也要认（服务端两种都给过）');
        $this->assertTrue($r->isPending());
    }

    #[Test]
    public function testStatusRejectsGarbageWithoutThrowing(): void
    {
        // ★ 一个状态码格式变了不该让整轮轮询停摆 ⇒ 归 -1，不抛
        $r = TaskResult::fromArray(['status' => 'abc'], 't-9');

        $this->assertNotNull($r);
        $this->assertSame(-1, $r->status);
        $this->assertTrue($r->isTerminal());
    }

    #[Test]
    public function testNonArrayInputMeansNoResult(): void
    {
        $this->assertNull(TaskResult::fromArray(null));
        $this->assertNull(TaskResult::fromArray('done'));
        $this->assertNull(TaskResult::fromArray(3));
    }

    #[Test]
    public function testTaskIdFallsBackToRequestOne(): void
    {
        // 服务端不一定回 taskId ⇒ 用请求时那个（否则界面上的"任务 ID"会变空）
        $r = TaskResult::fromArray(['status' => 2], 'req-123');

        $this->assertNotNull($r);
        $this->assertSame('req-123', $r->taskId);

        // 服务端给了就以它为准
        $r2 = TaskResult::fromArray(['status' => 2, 'taskId' => 'srv-9'], 'req-123');
        $this->assertNotNull($r2);
        $this->assertSame('srv-9', $r2->taskId);
    }

    #[Test]
    public function testUrlsKeepOrderAndDropGarbage(): void
    {
        $r = TaskResult::fromArray([
            'status' => 3,
            'downloadUrls' => ['https://x/a.jpg', '', 42, 'https://x/b.jpg', null],
        ], 't-1');

        $this->assertNotNull($r);
        $this->assertSame(
            ['https://x/a.jpg', 'https://x/b.jpg'],
            $r->downloadUrls,
            '顺序 = 服务端给的顺序（界面按它排），非字符串/空串丢掉'
        );
    }

    #[Test]
    public function testArrayRoundTrip(): void
    {
        // ★ 跨进程契约：`PollResultJob` 回来的是数组，`fromArray()` 要能还原
        $original = self::makeResult(TaskResult::STATUS_DONE, ['https://x/1.jpg']);
        $restored = TaskResult::fromArray($original->toArray(), 'ignored');

        $this->assertNotNull($restored);
        $this->assertSame($original->toArray(), $restored->toArray());
    }
}
