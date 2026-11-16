<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Rendering\DashPattern;
use Drift\Rendering\DisplayList;
use Drift\Rendering\Gradient;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\Paint;
use Drift\Rendering\PaintStyle;
use Drift\Rendering\Path;
use Drift\Text\Paragraph;

/**
 * 已录制命令流的**绘制指纹** —— 证明「AOT 产物画出的东西与解释器逐命令相同」。
 *
 * ★ 为什么几何指纹不够（2026-11-04）：几何只覆盖 `RenderBox` 的宽/高/位置。
 *   颜色、画笔、op 顺序、裁剪范围、**段落排版内部的行几何**，它一条都看不见。
 *   而 `int / int`（`docs/reference/aot-constraints.md` §1.2）是**静默**的 —— 它落在颜色混合或
 *   文本度量上时，几何树一个字节都不会变。绘制指纹补的正是这一层。
 *
 * ★ 它是"像素 diff"的可定位前像：像素只会说"整页不同"，命令流能说
 *   "第 87 条 `PUSH_CLIP` 的矩形：1200×40 / 1200×44"。
 *
 * ★★ **相等按值，不按身份**。槽里是对象引用，直接比 `===` 会把
 *   "同一支笔的不同实例"（`Paint::stroke()` 没有 interning）误报成差异。
 *   `Paint` / `Shadow` / `Gradient` / `DashPattern` / `Paragraph` / `Rect` /
 *   `Matrix2D` 都是不可变值对象，逐字段比；`Path` 可变但命令表是纯 float；
 *   `ImageHandle` 比像素摘要。**误报率低的判据才活得久**（§37.9）。
 *
 * ★ 未知槽类型**不是错误，但会让判据静默失效** —— 新 op 加进来而这里没跟上时，
 *   两侧都序列化成 `?类名`，仍然"相等"。所以 `of()` 把未知类型吐出来，
 *   由探针单判一条（`P④`）：宁可显性红，也不要安静地少覆盖一类命令。
 *
 * @internal 量具专用，不进公共 API
 */
final class PaintFingerprint
{
    /**
     * opcode → 名字。**键用 `DisplayList` 的常量**，值只有这一处 ——
     * 复制一份字面量表就是下一次漂移的起点（§37.5 ②）。
     */
    private const OP_NAMES = [
        DisplayList::OP_SAVE => 'SAVE',
        DisplayList::OP_RESTORE => 'RESTORE',
        DisplayList::OP_TRANSLATE => 'TRANSLATE',
        DisplayList::OP_PUSH_CLIP => 'PUSH_CLIP',
        DisplayList::OP_POP_CLIP => 'POP_CLIP',
        DisplayList::OP_DRAW_RECT => 'DRAW_RECT',
        DisplayList::OP_DRAW_PARAGRAPH => 'DRAW_PARAGRAPH',
        DisplayList::OP_DRAW_ELLIPSE => 'DRAW_ELLIPSE',
        DisplayList::OP_DRAW_LINE => 'DRAW_LINE',
        DisplayList::OP_DRAW_PATH => 'DRAW_PATH',
        DisplayList::OP_DRAW_IMAGE => 'DRAW_IMAGE',
        DisplayList::OP_DRAW_SHADOW => 'DRAW_SHADOW',
        DisplayList::OP_LAYER_PUSH => 'LAYER_PUSH',
        DisplayList::OP_LAYER_POP => 'LAYER_POP',
        DisplayList::OP_PUSH_CLIP_PATH => 'PUSH_CLIP_PATH',
        DisplayList::OP_BLUR_BACKDROP => 'BLUR_BACKDROP',
        DisplayList::OP_TRANSFORM => 'TRANSFORM',
    ];

    /** 7 个真正落笔的 op —— 它们是"页面画了东西"的证据 */
    private const DRAW_OPS = [
        DisplayList::OP_DRAW_RECT,
        DisplayList::OP_DRAW_PARAGRAPH,
        DisplayList::OP_DRAW_ELLIPSE,
        DisplayList::OP_DRAW_LINE,
        DisplayList::OP_DRAW_PATH,
        DisplayList::OP_DRAW_IMAGE,
        DisplayList::OP_DRAW_SHADOW,
    ];

    /** 每条命令的槽数（`DisplayList::SLOTS` 是私有，这里按契约取 5 个操作数） */
    private const ARG_SLOTS = 5;

    /** @var list<string> 本次序列化遇到的未知槽类型（去重） */
    private static array $unknown = [];

    /**
     * @return array{hash: string, cmds: int, draws: int, clips: int, unknown: list<string>}
     */
    public static function of(?DisplayList $list): array
    {
        self::$unknown = [];

        if ($list === null) {
            return ['hash' => '-', 'cmds' => 0, 'draws' => 0, 'clips' => 0, 'unknown' => []];
        }

        $parts = [];
        $draws = 0;
        $clips = 0;
        $n = $list->count();
        for ($i = 0; $i < $n; $i++) {
            $op = $list->opAt($i);
            if (\in_array($op, self::DRAW_OPS, true)) {
                $draws++;
            } elseif ($op === DisplayList::OP_PUSH_CLIP || $op === DisplayList::OP_PUSH_CLIP_PATH) {
                $clips++;
            }
            $parts[] = self::line($i, $list);
        }

        return [
            'hash' => \md5(\implode("\n", $parts)),
            'cmds' => $n,
            'draws' => $draws,
            'clips' => $clips,
            'unknown' => self::$unknown,
        ];
    }

    /**
     * 逐命令明细（**定位差异用，不是判据**）。
     *
     * ★ 行首带 `#i`：命令流没有"深度"这种线索，序号就是唯一的坐标。
     *   命令是**有序**的 —— 一条 `PUSH_CLIP` 错了会让它之后的一整段都不同，
     *   所以定位层要报的是"从第几条开始不同、连续多少条"，不是"哪一条最特殊"。
     *
     * @return list<string>
     */
    public static function detail(?DisplayList $list): array
    {
        self::$unknown = [];

        if ($list === null) {
            return [];
        }

        $out = [];
        $n = $list->count();
        for ($i = 0; $i < $n; $i++) {
            $out[] = self::line($i, $list);
        }

        return $out;
    }

    /**
     * 一行 TSV —— `page` + hash + 三个旁证列（命令数 / 落笔数 / 裁剪数）。
     *
     * ★ 旁证列不是装饰：**两棵空命令流的 md5 会相同**（都是空串的 md5）。
     *   几何那边靠 `nodes` 下界兜住，这边靠 `draws` 下界兜住。
     *
     * @param array{page: string, hash: string, cmds: int, draws: int, clips: int} $row
     */
    public static function tsvLine(array $row): string
    {
        return $row['page'] . "\t" . $row['hash'] . "\t" . (string) $row['cmds']
            . "\t" . (string) $row['draws'] . "\t" . (string) $row['clips'];
    }

    // ─────────────────────────────────────────────────────────────
    // 序列化（两侧跑同一份）
    // ─────────────────────────────────────────────────────────────

    private static function line(int $i, DisplayList $list): string
    {
        $op = $list->opAt($i);
        // ★ 未知 opcode 显示成 `OP18` 而不是崩：新 op 加进来时，差异会浮现在
        //   diff 里（而不是让整条判据炸掉，连带看不出别的问题）。
        $name = self::OP_NAMES[$op] ?? ('OP' . (string) $op);

        // 尾部空槽不打印（`drawRect` 只有 2 个操作数），但**中间**的空槽必须占位：
        // `drawImage($img, null, $dst)` 与 `drawImage($img, $dst, null)` 是两条不同的命令。
        $args = [];
        for ($k = 0; $k < self::ARG_SLOTS; $k++) {
            $args[] = self::arg($list->argAt($i, $k));
        }
        $last = self::ARG_SLOTS - 1;
        while ($last >= 0 && $args[$last] === '-') {
            $last--;
        }

        $s = '#' . (string) $i . ' ' . $name;
        for ($k = 0; $k <= $last; $k++) {
            $s .= ' ' . $args[$k];
        }

        return $s;
    }

    /** @param mixed $v */
    private static function arg($v): string
    {
        if ($v === null) {
            return '-';
        }
        if (\is_float($v)) {
            return \sprintf('%.3f', $v);
        }
        if (\is_int($v)) {
            return (string) $v;
        }
        if (\is_bool($v)) {
            return $v ? 'T' : 'F';
        }
        if (\is_string($v)) {
            return '"' . $v . '"';
        }
        if ($v instanceof Rect) {
            return \sprintf('R:%.3f,%.3f,%.3f,%.3f', $v->left, $v->top, $v->width, $v->height);
        }
        if ($v instanceof Paint) {
            return self::paint($v);
        }
        if ($v instanceof Path) {
            return self::path($v);
        }
        if ($v instanceof ImageHandle) {
            return 'I:' . (string) $v->width . 'x' . (string) $v->height
                . ':' . \substr(\md5($v->rgba()), 0, 8);
        }
        if ($v instanceof Paragraph) {
            return self::paragraph($v);
        }
        if ($v instanceof Matrix2D) {
            return \sprintf('M:%.3f,%.3f,%.3f,%.3f,%.3f,%.3f', $v->a, $v->b, $v->c, $v->d, $v->tx, $v->ty);
        }

        $cls = \get_class($v);
        if (!\in_array($cls, self::$unknown, true)) {
            self::$unknown[] = $cls;
        }

        return '?' . $cls;
    }

    private static function paint(Paint $p): string
    {
        // ★ 用 `===` 而不是 `$p->style->value`：enum 的 `->value` 在 AOT 下没有
        //   先例，而 `===` 比较是纯指针比较，两个 case 全覆盖。
        $s = 'P:' . \sprintf('%08x', $p->color->toArgb())
            . ',' . ($p->style === PaintStyle::Stroke ? 'S' : 'F')
            . ',' . \sprintf('%.3f', $p->strokeWidth);

        if ($p->gradient !== null) {
            $g = $p->gradient;
            $s .= ',G:' . ($g->radial ? 'rad' : 'lin')
                . \sprintf(':%.3f,%.3f,%.3f,%.3f', $g->x1, $g->y1, $g->x2, $g->y2)
                . ($g->relative ? ':rel' : ':abs');
            $k = 0;
            foreach ($g->colors as $c) {
                $s .= ':' . \sprintf('%08x', $c->toArgb());
                if ($g->stops !== []) {
                    $s .= '@' . \sprintf('%.3f', $g->stops[$k]);
                }
                $k++;
            }
        }

        if ($p->dash !== null) {
            /** @var DashPattern $d */
            $d = $p->dash;
            $s .= ',D:' . \sprintf('%.3f/%.3f/%.3f', $d->on, $d->off, $d->phase);
        }

        return $s;
    }

    private static function path(Path $p): string
    {
        $s = 'Pa:' . (string) $p->commandCount();
        foreach ($p->commands() as $c) {
            $s .= \sprintf('|%d,%.3f,%.3f,%.3f,%.3f', $c[0], $c[1], $c[2], $c[3], $c[4]);
        }

        return $s;
    }

    /**
     * 段落：文本摘要 + 尺寸 + 样式键 + **逐行几何**。
     *
     * ★ 逐行几何（`top` / `width` / `offsetX`）是这里唯一覆盖到"文本排版内部"的地方 ——
     *   `RenderBox` 只看得见段落盒的外框，行怎么断、对齐偏多少，几何指纹一个都不管。
     *   而 `int / int` 落在度量上时，外框可能恰好不变而行宽已经错了。
     *
     * ★ 不展开 `GlyphRun`：那是每字形的 x/字宽，一条命令能到上千个数字，
     *   指纹会变成"任何浮点末位差异都红"的假阳性工厂。行级是精度与噪声的折中。
     */
    private static function paragraph(Paragraph $t): string
    {
        $s = 'T:' . \substr(\md5($t->text), 0, 8)
            . ':' . (string) \count($t->lines) . 'L'
            . \sprintf(':%.3fx%.3f', $t->width, $t->height)
            . ':' . \substr($t->style->cacheKey(), 0, 8);

        foreach ($t->lines as $ln) {
            $s .= \sprintf('/%.3f,%.3f,%.3f', $ln->top, $ln->width, $ln->offsetX);
        }

        return $s;
    }
}
