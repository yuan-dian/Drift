<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Unit\Debug;

use Drift\Debug\Protocol\Wire;
use PHPUnit\Framework\TestCase;

/**
 * D3 判据之一：**过一趟 JSON，值的类型不变**。
 *
 * ## 为什么这条不能靠"看起来对"
 *
 * PHP 的 JSON 只有一种数字。`json_encode(0.0)` 写出 `0`，读回来是 int —— 而 Drift 的
 * 帧记录里到处都是"整数取值的浮点"（时间戳 `0.0 / 16.0 / 32.0`、耗时 `40.0`）。
 * 少了 `JSON_PRESERVE_ZERO_FRACTION`，跨进程的每一条帧摘要都会在类型上不同，
 * 而 `assertSame` 报红时看起来像"值不一样"，真正的原因在编码选项。
 *
 * 所以判据写成 `assertSame($原始, 解回来的)`：它同时管住"值"和"类型"，
 * 而不是"两个数看起来相等"。
 *
 * ## 另一半是"编不出来就报错"
 *
 * `NAN` / `INF` 会让 `json_encode` 返回 `false`。不抛错的话发出去的是空串，
 * 对端报"格式错误" —— 报错位置离原因隔了一条管道。
 */
final class WireTest extends TestCase
{
    public function testFloatAndIntSurviveTheRoundTripAsDistinctTypes(): void
    {
        $message = ['wall' => 16.0, 'id' => 16, 'zero' => 0.0];

        $back = Wire::decode(Wire::encode($message));

        $this->assertSame(16.0, $back['wall']);
        $this->assertSame(16, $back['id']);
        $this->assertSame(0.0, $back['zero']);
        $this->assertSame($message, $back);
    }

    /**
     * 真实形状：一帧摘要在管道上必须逐字节可逆。
     *
     * 取 `Query::frames()` 的真实字段，因为"缩写一个玩具结构"很容易正好绕开
     * 会出问题的那几个（整数值浮点、嵌套的段耗时映射）。
     */
    public function testFrameSummaryShapeIsIdentity(): void
    {
        $frame = [
            'id' => 2,
            't' => 16.0,
            'cost' => 40.5,
            'rebuilt' => 360,
            'causes' => 360,
            'stageMs' => ['settle' => 31.12, 'layout' => 8.0, 'paint' => 0.0],
            'unattributedMs' => 0.0,
            'settleSkipped' => false,
            'paintSkipped' => false,
        ];

        $this->assertSame($frame, Wire::decode(Wire::encode($frame)));
    }

    public function testTextAndEmptyContainersSurvive(): void
    {
        $message = ['path' => 'PageShell > Card > 表格', 'empty' => [], 'map' => ['a' => 1, 'b' => null]];

        $this->assertSame($message, Wire::decode(Wire::encode($message)));
    }

    /** 数字键的映射（`digest.frames` 就是帧号 ⇒ 哈希）不能变成字符串键。 */
    public function testIntegerKeyedMapKeepsIntegerKeys(): void
    {
        $back = Wire::decode(Wire::encode(['frames' => [1 => 'a', 2 => 'b']]));

        $this->assertSame([1 => 'a', 2 => 'b'], $back['frames']);
    }

    public function testUnencodableValueFailsAtEncodeTime(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/编不成 JSON/');

        Wire::encode(['cost' => \INF]);
    }

    public function testMalformedLineIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        Wire::decode('{"id":1,"method":');
    }

    /** 消息顶层必须是对象：`[...]` 会被解成 list，"第几条"这类键就会静默丢失。 */
    public function testTopLevelListIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/对象/');

        Wire::decode('[1,2,3]');
    }
}
