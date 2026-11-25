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

    private float $w = -1.0;

    private float $h = -1.0;

    /** Optional op 集合是录制时按回放端 capabilities() 定的，换端就必须重录。 */
    private int $caps = -1;

    /** 上一次 `beginRecord()` 的结论：hit / first / invalidated / resized / capabilities。 */
    private string $miss = 'first';

    /**
     * 丢掉缓存，下一次 `beginRecord()` 必然重录。
     *
     * ★ 调用方是 `RenderBox::markPaintChanged()` / `invalidateEnclosingLayer()`：
     *   **凡是录进命令流的东西**（视觉维、合成槽、子树重排）变了都必须显式调它，
     *   否则缓存一直重放旧值，画面变成死的（`AnimatedOpacity` 在 `ScrollView` 里
     *   就是这个症状：值在滑、屏不动，直到下一次 rebuild 才"突然"到位）。
     *   键里不再有全局 epoch —— 失效的责任完全落在各写点的标脏上。
     */
    public function invalidate(): void
    {
        $this->list = null;
        $this->caps = -1;
        $this->miss = 'invalidated';
    }

    /**
     * 命中 ⇒ 重放到 `$canvas` 并返回 `null`；否则返回一张**录制画布**，
     * 调用方往它上面画完后必须调 `endRecord()`。
     *
     * ★ 命中判据含回放端能力位：换端不重录会把按旧端能力选定的命令流
     *   直接回放给新端（Optional op 可能在回放时响亮失败）。
     */
    public function beginRecord(Canvas $canvas, float $w, float $h): ?Canvas
    {
        $caps = $canvas->capabilities();
        $cached = $this->list;
        if ($cached !== null && $this->w === $w && $this->h === $h && $this->caps === $caps) {
            $this->miss = 'hit';
            $cached->replay($canvas);

            return null;
        }

        if ($cached === null) {
            // `invalidated` 是写点主动丢缓存；其余（含首帧）都还没有可复用的命令流。
            if ($this->miss !== 'invalidated') {
                $this->miss = 'first';
            }
        } elseif ($this->w !== $w || $this->h !== $h) {
            $this->miss = 'resized';
        } else {
            $this->miss = 'capabilities';
        }

        $rec = new RecordingCanvas(new DisplayList(), $caps);
        $this->recording = $rec;

        return $rec;
    }

    /** 上一次 `beginRecord()` 的结论；`RenderBox` 只在观察者存在时读它。 */
    public function lastMissReason(): string
    {
        return $this->miss;
    }

    /**
     * 收下刚录的那份命令，并**立刻回放出画**。
     *
     * ★ 录与放同一次调用里完成 ⇒ 开启边界不会让首帧变黑，也不会有"缓存还没
     *   填上"的中间态需要额外判据。
     */
    public function endRecord(Canvas $canvas, float $w, float $h): void
    {
        $rec = $this->recording;
        if ($rec === null) {
            return;
        }
        $list = $rec->displayList();
        $list->assertBalanced();
        $this->list = $list;
        $this->recording = null;
        $this->w = $w;
        $this->h = $h;
        $this->caps = $canvas->capabilities();
        $list->replay($canvas);
    }
}
