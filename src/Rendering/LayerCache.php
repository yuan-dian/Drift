<?php
declare(strict_types=1);

namespace Drift\Rendering;

/**
 * 一个盒的**层缓存**（A 批② `RepaintBoundary`）：把它的绘制命令录成一份
 * `DisplayList`，没变就只重放，跳过整棵子树的 `paint()`。
 *
 * ★ 为什么住 `Rendering` 而不是塞进 `RenderBox`：录制画布是**具体画布实现**，
 *   `backend-branch` 规则禁止 `src/Layout` 提它的类型名（防软光栅 / Skia 双轨）。
 *   而 `Rendering` 是这些实现的家 ⇒ 缓冲逻辑放这里，布局层只留"要不要缓存"
 *   这一个决定，不必为一条 `new` 给整个 `RenderBox` 开文件级豁免。
 *
 * ★ 为什么不是双轨：`RecordingCanvas` 只录命令、不碰任何后端；录完的
 *   `DisplayList` 回放到**调用方给的那张 canvas**（真机是录制面、测试是软光栅）
 *   ⇒ 两边走的是同一份命令，不存在"某条路坏了而测试全绿"。
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
        $this->list = $list;
        $this->recording = null;
        $this->epoch = $epoch;
        $this->w = $w;
        $this->h = $h;
        $list->replay($canvas);
    }
}
