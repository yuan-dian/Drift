<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 绘制命令即数据（§7.2 裁决 2）—— G3 在渲染侧的落点。
 *
 * `paint()` 的产物不是"立即画到屏上"，而是录成命令序列，由 surface 执行。
 * 收益：可重放（golden）、可快照（devtools）、可跨进程（将来的 Inspector）。
 *
 * ★ **生产路径也走录制**，不是只在 dev 录制。理由（§7.2）：① §14.1 第 2 条已经
 *   承诺"渲染是纯函数 ⇒ 录制成 DisplayList"；② §20 第 1 条"量具双向自检"的核心是
 *   "量具与生产走同一条路"；③ GUI 仓本来"每帧全画"，没有可损失的跳过绘制优化。
 *
 * ★ **缓冲复用：每帧零分配**（§7.2 的配套契约）。命令写在**已有的槽位**上，
 *   `reset()` 只把长度归零、不重建数组 —— 否则"先记录再画"会比"直接画"更慢。
 *
 * @api
 */
final class DisplayList
{
    public const OP_SAVE = 1;
    public const OP_RESTORE = 2;
    public const OP_TRANSLATE = 3;
    public const OP_PUSH_CLIP = 4;
    public const OP_POP_CLIP = 5;
    public const OP_DRAW_RECT = 6;
    /** 段落绘制：`[paragraph, paint, x, y]` —— 位图在回放时才产生，见 `Canvas::drawParagraph()` */
    public const OP_DRAW_PARAGRAPH = 7;
    /** 19 op 回植（P0 立 opcode，P1/P3/P4 分批实现后端，见 `CanvasCapabilities` 分层表） */
    public const OP_DRAW_ELLIPSE = 8;   // [rect, paint]                      Core
    public const OP_DRAW_LINE = 9;     // [x1, y1, x2, y2, paint]           Core
    public const OP_DRAW_PATH = 10;    // [path, paint]                     Optional
    public const OP_DRAW_IMAGE = 11;   // [image, ?src, dst]                Optional
    public const OP_DRAW_SHADOW = 12;  // [shadow]                          Optional
    public const OP_LAYER_PUSH = 13;   // [opacity]                         Optional
    public const OP_LAYER_POP = 14;    // []                                Optional
    public const OP_PUSH_CLIP_PATH = 15; // [path]                          Extended

    /**
     * 每条命令占的槽位数：`[op, a, b, c, d, ref]`。
     *
     * ★ 7-op 时代 5 槽够（最多 4 个操作数：`drawParagraph` 的
     *   paragraph/paint/x/y）。19 op 的 `OP_DRAW_LINE` 要 5 个
     *   （x1/y1/x2/y2/paint）⇒ 统一升到 6 槽。代价是所有命令每条多一个槽
     *   （`estimatedBytes` 的公式同步变大）；收益是 record/replay 的寻址
     *   保持「一条命令一段连续槽位」，不为一种 op 特例化。
     */
    private const SLOTS = 6;

    /** @var array<int, mixed> 扁平命令缓冲 —— 复用，不每帧重建 */
    private array $buffer = [];
    private int $length = 0;
    private int $saveDepth = 0;
    private int $clipDepth = 0;

    /**
     * 层深（W1/L3-3 起纳入 W8 配平）。
     *
     * ★ 之前"不查"（`Canvas.php` 的 docblock 明写不覆盖层深）⇒ 不配对的
     *   `pushLayer` 让后续所有绘制都带一个没人弹掉的乘数，画面整体变透而
     *   无人知晓。层的语义归 CustomPaint 契约，**配平归 W8**，两件事。
     */
    private int $layerDepth = 0;

    /**
     * 复用缓冲：只归零长度，槽位保留。
     */
    public function reset(): void
    {
        $this->length = 0;
        $this->saveDepth = 0;
        $this->clipDepth = 0;
        $this->layerDepth = 0;
    }

    /**
     * 录制一条命令。
     *
     * @param mixed $a
     * @param mixed $b
     * @param mixed $c
     * @param mixed $ref
     */
    /**
     * @param mixed $a
     * @param mixed $b
     * @param mixed $c
     * @param mixed $d
     * @param mixed $ref
     */
    public function record(int $op, $a = null, $b = null, $c = null, $d = null, $ref = null): void
    {
        $base = $this->length * self::SLOTS;
        $this->buffer[$base] = $op;
        $this->buffer[$base + 1] = $a;
        $this->buffer[$base + 2] = $b;
        $this->buffer[$base + 3] = $c;
        $this->buffer[$base + 4] = $d;
        $this->buffer[$base + 5] = $ref;
        $this->length++;

        if ($op === self::OP_SAVE) {
            $this->saveDepth++;
        } elseif ($op === self::OP_RESTORE) {
            $this->saveDepth--;
        } elseif ($op === self::OP_PUSH_CLIP) {
            $this->clipDepth++;
        } elseif ($op === self::OP_PUSH_CLIP_PATH) {
            // ★ W1/L3-2 补：`pushClipPath` 与 `pushClip` 共用同一条裁剪栈
            //   （`Canvas` 接口契约，弹栈都用 `popClip()`）⇒ W8 的 `clipDepth`
            //   必须同口径计深。漏了这一支时，"`pushClipPath` 后没 `popClip`"
            //   的 `clipDepth` 仍是 0 ⇒ `assertBalanced()` 抓不到 ⇒ 后续全部
            //   被裁而无人知晓（`05` §10 #5）。
            $this->clipDepth++;
        } elseif ($op === self::OP_POP_CLIP) {
            $this->clipDepth--;
        } elseif ($op === self::OP_LAYER_PUSH) {
            $this->layerDepth++;
        } elseif ($op === self::OP_LAYER_POP) {
            $this->layerDepth--;
        }
    }

    public function count(): int
    {
        return $this->length;
    }

    public function saveDepth(): int
    {
        return $this->saveDepth;
    }

    public function clipDepth(): int
    {
        return $this->clipDepth;
    }

    public function opAt(int $index): int
    {
        return (int) $this->buffer[$index * self::SLOTS];
    }

    /** @return mixed 第 $slot 个操作数（0 起，0 是 opcode 之后的第一项） */
    public function argAt(int $index, int $slot)
    {
        return $this->buffer[$index * self::SLOTS + 1 + $slot];
    }

    /** 估算占用（供性能预算与 devtools） */
    public function estimatedBytes(): int
    {
        return $this->length * self::SLOTS * 16;
    }

    /**
     * W8：录制结束时命令栈深度必须为 0。
     *
     * 不配对 ⇒ 缓冲复用会把上一帧未闭合的栈带到下一帧（§7.2）。
     */
    public function assertBalanced(): void
    {
        if ($this->saveDepth !== 0 || $this->clipDepth !== 0 || $this->layerDepth !== 0) {
            Errors::report(sprintf(
                'DisplayList 录制未闭合：save/restore 差 %d、pushClip/popClip 差 %d、pushLayer/popLayer 差 %d（W8）',
                $this->saveDepth,
                $this->clipDepth,
                $this->layerDepth
            ));
        }
    }

    /** 把命令流回放到任意画布（surface 的 present 就是这一步） */
    public function replay(Canvas $canvas): void
    {
        for ($i = 0; $i < $this->length; $i++) {
            $base = $i * self::SLOTS;
            $op = $this->buffer[$base];

            if ($op === self::OP_SAVE) {
                $canvas->save();
            } elseif ($op === self::OP_RESTORE) {
                $canvas->restore();
            } elseif ($op === self::OP_TRANSLATE) {
                $canvas->translate((float) $this->buffer[$base + 1], (float) $this->buffer[$base + 2]);
            } elseif ($op === self::OP_PUSH_CLIP) {
                $canvas->pushClip($this->buffer[$base + 1]);
            } elseif ($op === self::OP_POP_CLIP) {
                $canvas->popClip();
            } elseif ($op === self::OP_DRAW_RECT) {
                $canvas->drawRect(
                    $this->buffer[$base + 1],
                    $this->buffer[$base + 2],
                    (float) $this->buffer[$base + 3]
                );
            } elseif ($op === self::OP_DRAW_PARAGRAPH) {
                $canvas->drawParagraph(
                    $this->buffer[$base + 1],
                    $this->buffer[$base + 2],
                    (float) $this->buffer[$base + 3],
                    (float) $this->buffer[$base + 4]
                );
            } elseif ($op === self::OP_DRAW_ELLIPSE) {
                $canvas->drawEllipse(
                    $this->buffer[$base + 1],
                    $this->buffer[$base + 2],
                );
            } elseif ($op === self::OP_DRAW_LINE) {
                $canvas->drawLine(
                    (float) $this->buffer[$base + 1],
                    (float) $this->buffer[$base + 2],
                    (float) $this->buffer[$base + 3],
                    (float) $this->buffer[$base + 4],
                    $this->buffer[$base + 5],
                );
            } elseif ($op === self::OP_DRAW_PATH) {
                $canvas->drawPath(
                    $this->buffer[$base + 1],
                    $this->buffer[$base + 2],
                );
            } elseif ($op === self::OP_DRAW_IMAGE) {
                $canvas->drawImage(
                    $this->buffer[$base + 1],
                    $this->buffer[$base + 2],
                    $this->buffer[$base + 3],
                );
            } elseif ($op === self::OP_DRAW_SHADOW) {
                $canvas->drawShadow($this->buffer[$base + 1]);
            } elseif ($op === self::OP_LAYER_PUSH) {
                $canvas->pushLayer((float) $this->buffer[$base + 1]);
            } elseif ($op === self::OP_LAYER_POP) {
                $canvas->popLayer();
            } elseif ($op === self::OP_PUSH_CLIP_PATH) {
                $canvas->pushClipPath($this->buffer[$base + 1]);
            }
        }
    }
}
