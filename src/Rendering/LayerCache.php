<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 一个盒的层缓存：把绘制命令录成一份 `DisplayList`，没变就只重放，跳过子树的 `paint()`。
 *
 * 录制画布是具体画布实现，`src/Layout` 不能提它的类型名（防软光栅 / Skia 双轨）；
 * 回放到调用方给的那张 canvas ⇒ 两边走同一份命令，不存在"某条路坏了而测试全绿"。
 *
 * @internal 唯一的消费者是 `RenderBox::paintOrReplay()`
 */
final class LayerCache
{
    private ?DisplayList $list = null;

    /** 正在录的那一张（`beginRecord()` 与 `endRecord()` 之间非空） */
    private ?RecordingCanvas $recording = null;

    private int $epoch = -1;

    private float $w = -1.0;

    private float $h = -1.0;

    /**
     * 丢掉缓存，下一次 `beginRecord()` 必然重录。
     *
     * ★ 调用方是 `RenderBox::invalidateEnclosingLayer()`：**合成槽（alpha / 平移）
     *   是录进命令流里的值**，只改槽不动 `layoutEpoch` ⇒ 缓存会一直重放旧值，
     *   画面变成死的（`AnimatedOpacity` 在 `ScrollView` 里就是这个症状：
     *   值在滑、屏不动，直到下一次 rebuild 才"突然"到位）。
     */
    public function invalidate(): void
    {
        $this->list = null;
        $this->epoch = -1;
    }

    /**
     * 命中 ⇒ 重放到 `$canvas` 并返回 `null`；否则返回一张**录制画布**，
     * 调用方往它上面画完后必须调 `endRecord()`。
     */
    public function beginRecord(Canvas $canvas, int $epoch, float $w, float $h): ?Canvas
    {
        $cached = $this->list;
        if ($cached !== null && $this->epoch === $epoch && $this->w === $w && $this->h === $h) {
            $cached->replay($canvas);

            return null;
        }

        $rec = new RecordingCanvas(new DisplayList(), $canvas->capabilities());
        $this->recording = $rec;

        return $rec;
    }

    /**
     * 收下刚录的那份命令，并**立刻回放出画**。
     *
     * ★ 录与放同一次调用里完成 ⇒ 开启边界不会让首帧变黑，也不会有"缓存还没
     *   填上"的中间态需要额外判据。
     */
    public function endRecord(Canvas $canvas, int $epoch, float $w, float $h): void
    {
        $rec = $this->recording;
        if ($rec === null) {
            return;
        }
        $list = $rec->displayList();
        $list->assertBalanced();
        $this->list = $list;
        $this->recording = null;
        $this->epoch = $epoch;
        $this->w = $w;
        $this->h = $h;
        $list->replay($canvas);
    }
}
