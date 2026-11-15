<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Feature\Rendering;

use Drift\Dev\Tests\Support\SmokeTestCase;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\DisplayList;
use Drift\Rendering\LayerCache;
use Drift\Rendering\RasterCanvas;
use Drift\Rendering\RecordingCanvas;

/**
 * 层缓存的命中判据必须含回放端能力位。
 *
 * ★ `CustomPainter` 按 `capabilities()` 决定发不发 Optional op，录制下来的命令流
 *   因此是「按当时的回放端」选定的。同一份缓存换到另一能力的画布上若仍命中，
 *   回放端要么拿到不该有的 op（响亮失败），要么丢了该有的那一笔。
 */
final class LayerCacheCapabilityTest extends SmokeTestCase
{
    public function testCapabilityChangeForcesReRecord(): void
    {
        $cache = new LayerCache();

        $full = CanvasCapabilities::ALL_MASK;
        $sinkA = new RecordingCanvas(new DisplayList(), $full);
        check(
            'LayerCache · 首帧返回录制画布',
            $cache->beginRecord($sinkA, 1, 10.0, 10.0) !== null,
        );
        $cache->endRecord($sinkA, 1, 10.0, 10.0);

        $same = new RecordingCanvas(new DisplayList(), $full);
        check(
            'LayerCache · 同能力 / 同 epoch / 同尺寸 ⇒ 命中（不许误失效）',
            $cache->beginRecord($same, 1, 10.0, 10.0) === null,
        );

        $other = new RecordingCanvas(new DisplayList(), RasterCanvas::supportedCapabilities());
        check(
            'LayerCache · 回放端能力变了 ⇒ 必须重录，不许回放旧命令流',
            $cache->beginRecord($other, 1, 10.0, 10.0) !== null,
        );
        $cache->endRecord($other, 1, 10.0, 10.0);

        $back = new RecordingCanvas(new DisplayList(), $full);
        check(
            'LayerCache · 再换回原能力 ⇒ 仍要重录（键是双向的）',
            $cache->beginRecord($back, 1, 10.0, 10.0) !== null,
        );
        $cache->endRecord($back, 1, 10.0, 10.0);

        $this->flushChecks();
    }
}
