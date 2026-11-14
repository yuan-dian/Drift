<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 绘制命令缓冲：`RecordingCanvas` 把 op 录进这里，`replay()` 回放到任意画布。
 *
 * 生产主树由 `Frame` 直接画，不经过这里；录制用于 `LayerCache` 重绘边界、
 * 测试夹具、预览与 golden。
 *
 * ★ 缓冲复用：命令写在已有槽位上，`reset()` 只归零长度、不重建数组，否则
 *   "先记录再画"会比直接画更慢。
 *
 * @api
 */
final class DisplayList
{
    public const int OP_SAVE = 1;
    public const int OP_RESTORE = 2;
    public const int OP_TRANSLATE = 3;
    public const int OP_PUSH_CLIP = 4;
    public const int OP_POP_CLIP = 5;
    public const int OP_DRAW_RECT = 6;
    /** 段落绘制：`[paragraph, paint, x, y]` —— 位图在回放时才产生 */
    public const int OP_DRAW_PARAGRAPH = 7;
    public const int OP_DRAW_ELLIPSE = 8;   // [rect, paint]
    public const int OP_DRAW_LINE = 9;     // [x1, y1, x2, y2, paint]
    public const int OP_DRAW_PATH = 10;    // [path, paint]
    public const int OP_DRAW_IMAGE = 11;   // [image, ?src, dst]
    public const int OP_DRAW_SHADOW = 12;  // [shadow]
    public const int OP_LAYER_PUSH = 13;   // [opacity]
    public const int OP_LAYER_POP = 14;    // []
    public const int OP_PUSH_CLIP_PATH = 15; // [path]
    public const int OP_BLUR_BACKDROP = 16;  // [sigma, rect]
    /**
     * 仿射变换：`[matrix]` 走引用槽，不拆成 6 个 float。
     *
     * ★ 不拆的理由：`SLOTS = 6` 只有 5 个操作数槽，6 个数放不下；扩槽会让所有
     *   命令每条多两个槽。矩阵与 `Rect` / `Path` / `Paint` 同类的"对象进槽"，
     *   且 `Matrix2D` 不可变，重放期不会被改。纯平移仍录 `OP_TRANSLATE`。
     */
    public const int OP_TRANSFORM = 17;    // [matrix]

    /**
     * 每条命令占的槽位数：`[op, a, b, c, d, ref]`。
     *
     * ★ 统一 6 槽是因为 `OP_DRAW_LINE` 要 5 个操作数；不为一种 op 特例化，
     *   record/replay 的寻址才能保持「一条命令一段连续槽位」。
     */
    private const int SLOTS = 6;

    /** @var array<int, mixed> 扁平命令缓冲 —— 复用，不每帧重建 */
    private array $buffer = [];
    private int $length = 0;
    private int $saveDepth = 0;
    private int $clipDepth = 0;

    /** 层深：不配对的 pushLayer 会让后续绘制整体变透而无人知晓。 */
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
     * @param mixed $d
     * @param mixed $ref
     */
    public function record(int $op, $a = null, $b = null, $c = null, $d = null, $ref = null): void
    {
        // OP_* 必须连续（1..OP_TRANSFORM）；新增时追加常量并同步 replay() 分支，
        // 判据见 DisplayListOpCoverageTest。未知 op 会让 replay() 静默丢像素。
        if ($op < self::OP_SAVE || $op > self::OP_TRANSFORM) {
            Errors::report(sprintf(
                'DisplayList::record() 收到未知 op %d —— 新增 OP_* 后必须同步 record() 白名单与 replay() 分支',
                $op,
            ));
        }

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
            // pushClipPath 与 pushClip 共用裁剪栈，弹栈都用 popClip() ⇒ 必须同口径计深，
            // 否则漏 popClip 时 assertBalanced() 抓不到，后续全被裁而无人知晓。
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

    /** 录制结束时命令栈深度必须为 0，否则缓冲复用会把未闭合状态带进下一次回放。 */
    public function assertBalanced(): void
    {
        if ($this->saveDepth !== 0 || $this->clipDepth !== 0 || $this->layerDepth !== 0) {
            Errors::report(sprintf(
                'DisplayList 录制未闭合：save/restore 差 %d、pushClip/popClip 差 %d、pushLayer/popLayer 差 %d',
                $this->saveDepth,
                $this->clipDepth,
                $this->layerDepth
            ));
        }
    }

    /** 把命令流回放到任意画布 */
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
            } elseif ($op === self::OP_BLUR_BACKDROP) {
                // 一次性操作，不动任何深度计数（不是层栈）。
                $canvas->blurBackdrop(
                    (float) $this->buffer[$base + 1],
                    $this->buffer[$base + 2],
                );
            } elseif ($op === self::OP_TRANSFORM) {
                $canvas->transform($this->buffer[$base + 1]);
            } else {
                Errors::report(sprintf(
                    'DisplayList::replay() 遇到未处理的 op %d —— 新增 OP_* 后必须补 replay() 分支',
                    (int) $op,
                ));
            }
        }
    }
}
