<?php
declare(strict_types=1);

namespace Drift\DevTools\Inspect;

use Drift\Debug\Session\FrameRecord;
use Drift\Debug\Session\Recorder;
use Drift\Geometry\BoxConstraints;
use Drift\Rendering\FrameStage;
use Drift\Geometry\Rect;
use Drift\Geometry\Scale;
use Drift\Rendering\Canvas;
use Drift\Rendering\ColorToken;
use Drift\Rendering\Paint;
use Drift\Text\Paragraph;
use Drift\Text\TextMetricsProvider;
use Drift\Text\TextRole;
use Drift\Theme\ThemeData;
use Drift\Widget\Element;

/**
 * 真机 HUD：一眼回答"现在这帧贵不贵、选中的是谁、它占多少"。
 *
 * ## 数字必须与 `stats()` 同口径
 *
 * `paint()` 跑在**帧中间**，此刻正在录的帧还没有 `costMs`。HUD 取
 * `Recorder::stats()` —— 它读的是**上一完成帧**（`frames` 只在 `endFrame()` 落账）。
 * 帧号也跟着取 `lastFrameId()`，不是正在录的那个：把两者混用，HUD 上就会出现
 * "帧号是新的、耗时是旧的"，而看的人无从分辨。
 *
 * `hudValues()` 因此既是绘制输入，也是判据：测试拿它与 `stats()` 逐值比，
 * 与 `BoxLayers::of()` 逐值比。渲染与数据只有一份。
 *
 * ## 为什么只三行
 *
 * 第四行本该是规则发现计数，但 `Facts::gather()` + `Brief::project()` 每帧跑会
 * 把规则引擎整个拖进热路径，而现有记录里没有轻量计数入口。真机 HUD 的职责是
 * "报警"，完整列表在 Web —— 这一行先空着，比每帧重算一份可信。
 *
 * @see Inspector::paint()
 */
final class Hud
{
    private const float PAD_X = 8.0;

    private const float PAD_Y = 6.0;

    private const float GAP = 2.0;

    private const float MARGIN = 12.0;

    /** 单行最大宽度：再宽就换行，HUD 不该横跨半个窗口 */
    private const float MAX_LINE = 380.0;

    /**
     * 这一帧 HUD 要展示的数值。`null` = 没有可展示的选中节点。
     *
     * @return array{
     *     frame: int,
     *     costMs: float,
     *     rebuilt: int,
     *     node: int|null,
     *     type: string|null,
     *     width: float|null,
     *     height: float|null,
     *     x: float|null,
     *     y: float|null,
     *     parentNode: int|null,
     *     parentType: string|null,
     *     constraint: string|null,
     *     stage: string|null,
     *     path: string|null,
     *     visibility: string,
     *     rebuiltNode: bool,
     *     padding: array{l: float, t: float, r: float, b: float}|null,
     *     border: float|null,
     *     margin: array{l: float, t: float, r: float, b: float}|null,
     *     picking: bool,
     * }
     */
    public static function values(
        Recorder $recorder,
        ?Element $selected,
        bool $picking = false,
        ?Rect $viewport = null,
        ?string $path = null,
    ): array
    {
        $stats = $recorder->stats();
        $frame = $recorder->lastFrameId();
        $record = $recorder->frame($frame);
        $values = [
            'frame' => $frame,
            'costMs' => (float) ($stats['lastCostMs'] ?? 0.0),
            'rebuilt' => (int) ($stats['lastRebuilt'] ?? 0),
            'node' => null,
            'type' => null,
            'width' => null,
            'height' => null,
            'x' => null,
            'y' => null,
            'parentNode' => null,
            'parentType' => null,
            'constraint' => null,
            'stage' => null,
            'path' => null,
            'visibility' => 'visible',
            'rebuiltNode' => false,
            'padding' => null,
            'border' => null,
            'margin' => null,
            'picking' => $picking,
        ];

        $values['stage'] = self::stageText($record);

        if ($selected === null) {
            return $values;
        }

        $values['node'] = $selected->debugId;
        $values['type'] = self::shortType($selected);
        $values['path'] = $path;

        $box = $selected->effectiveBox();
        $layers = $box === null ? null : BoxLayers::of($box);
        if ($layers === null || $box === null) {
            // Offstage / 未布局：没有盒，但身份与"为什么没有"仍要说。
            $values['visibility'] = 'no box';

            return $values;
        }

        [$x, $y] = \Drift\Layout\RenderBox::absoluteOrigin($box);
        $parent = $selected->parent();

        $values['width'] = (float) $layers['border']['w'];
        $values['height'] = (float) $layers['border']['h'];
        $values['x'] = $x;
        $values['y'] = $y;
        $values['parentNode'] = $parent?->debugId;
        $values['parentType'] = $parent === null ? null : self::shortType($parent);
        $values['constraint'] = self::constraintText($box->constraints());
        $values['rebuiltNode'] = $record?->rebuilt($selected->debugId) ?? false;
        $values['padding'] = $layers['padding'];
        $values['border'] = (float) $layers['borderWidth'];
        $values['margin'] = $layers['margin'];

        if ($viewport !== null) {
            $rect = new Rect(
                $layers['border']['x'],
                $layers['border']['y'],
                $layers['border']['w'],
                $layers['border']['h'],
            );
            if ($rect->intersect($viewport)->isEmpty()) {
                $values['visibility'] = 'outside';
            }
        }

        return $values;
    }

    /**
     * 把数值摊成给人看的行。空数组 = 没东西可画（没有选中）。
     *
     * @param array<string, mixed> $values {@see self::values()}
     * @return list<string>
     */
    public static function lines(array $values): array
    {
        if (($values['type'] ?? null) === null) {
            return [];
        }

        $lines = [];
        if (($values['picking'] ?? false) === true) {
            $lines[] = 'PICK · 点击选中 · ↑↓ 换层（Esc 退出）';
        }

        // 身份：类型在前、节点号在后（读的人先认控件，#号只是给 CLI 用的地址）。
        // 面包屑回答"它在界面的哪里"，比裸 #号有用 —— 路径取不到时不编。
        $title = \sprintf('%s #%d', (string) $values['type'], (int) $values['node']);
        $path = (string) ($values['path'] ?? '');
        if ($path !== '') {
            $title .= '  in ' . $path;
        }
        $lines[] = $title;

        if (($values['width'] ?? null) !== null) {
            // 尺寸与坐标都标单位；@ 补"屏幕"二字，免得读成相对父级。
            $lines[] = \sprintf(
                '%s × %s px · 屏幕(%s, %s)',
                self::num((float) $values['width']),
                self::num((float) $values['height']),
                self::num((float) $values['x']),
                self::num((float) $values['y']),
            );

            // 帧耗时给参照系：16.7ms 是 60fps 的预算，单看 2.7ms 不知道贵不贵。
            $cost = (float) $values['costMs'];
            $lines[] = \sprintf(
                '帧 %d · %sms（60fps 预算 16.7）· %s',
                (int) $values['frame'],
                self::num($cost),
                ($values['rebuiltNode'] ?? false) ? '本帧重绘' : '复用缓存',
            );
            if (($values['stage'] ?? null) !== null) {
                $lines[] = (string) $values['stage'];
            }

            // 谁把它定成这个尺寸：父节点 + 父给的约束，一行说尽。
            $where = [];
            if (($values['parentNode'] ?? null) !== null) {
                $where[] = \sprintf(
                    '父 %s #%d',
                    (string) ($values['parentType'] ?? ''),
                    (int) $values['parentNode'],
                );
            }
            if (($values['constraint'] ?? null) !== null) {
                $where[] = (string) $values['constraint'];
            }
            if ($where !== []) {
                $lines[] = \implode(' · ', $where);
            }

            // 0 值不占位：全零说"没有间距"，有哪层只说哪层。
            $box = [];
            $pad = self::edges($values['padding']);
            if ($pad !== '0') {
                $box[] = '内边距 ' . $pad;
            }
            $border = (float) ($values['border'] ?? 0.0);
            if ($border > 0.0) {
                $box[] = '边框 ' . self::num($border);
            }
            $margin = self::edges($values['margin']);
            if ($margin !== '0') {
                $box[] = '外边距 ' . $margin;
            }
            $lines[] = $box === [] ? '无间距' : \implode(' · ', $box);
        }

        // 看不见的时候，"为什么看不见"比尺寸更该占据这行。
        $visibility = (string) ($values['visibility'] ?? 'visible');
        if ($visibility !== 'visible') {
            $lines[] = $visibility === 'no box'
                ? '⚠ no box（Offstage / 未布局）'
                : '⚠ outside viewport';
        }

        return $lines;
    }

    /**
     * 面板落在哪：优先右下，撞上被检查的盒就换角，四角全撞取重叠最小的。
     *
     * ★ 为什么不做"折叠"：折叠的用途是少挡一点，而这已经被换角解决；彻底不想看时
     *   `inspect --off=hud` 本来就关得掉。自动折叠会在用户没要求时悄悄减少信息。
     *
     * @param Rect|null $target 被检查盒的屏幕矩形（`null` = 没有选中 / 没有盒）
     */
    public static function place(Rect $viewport, float $w, float $h, ?Rect $target): Rect
    {
        // 窗口比 HUD 还小时贴左上，不画到视口外 —— 负坐标在硬件裁剪下会整块消失。
        $right = \max(self::MARGIN, $viewport->width - $w - self::MARGIN);
        $bottom = \max(self::MARGIN, $viewport->height - $h - self::MARGIN);
        $home = new Rect($right, $bottom, $w, $h);
        if ($target === null) {
            return $home;
        }

        $corners = [[$right, $bottom], [$right, self::MARGIN], [self::MARGIN, $bottom], [self::MARGIN, self::MARGIN]];
        $best = $home;
        $bestOverlap = \INF;
        foreach ($corners as [$x, $y]) {
            $candidate = new Rect($x, $y, $w, $h);
            $overlap = self::overlap($candidate, $target);
            if ($overlap <= 0.0) {
                return $candidate;
            }
            if ($overlap < $bestOverlap) {
                $bestOverlap = $overlap;
                $best = $candidate;
            }
        }

        return $best;
    }

    /** 两个矩形的交面积；不相交为 0。 */
    private static function overlap(Rect $a, Rect $b): float
    {
        $hit = $a->intersect($b);

        return $hit->isEmpty() ? 0.0 : $hit->width * $hit->height;
    }

    /**
     * 实底面板。返回"真的画了"——没有选中时一律 `false`，让记账停在
     * 上一次成功，与 `box-model` 同一套语义。
     *
     * @param array<string, mixed> $values {@see self::values()}
     */
    public static function paint(
        Canvas $canvas,
        Rect $viewport,
        array $values,
        ThemeData $theme,
        TextMetricsProvider $metrics,
    ): bool {
        $lines = self::lines($values);
        if ($lines === []) {
            return false;
        }

        $style = $theme->textStyle(TextRole::Caption);
        $scale = Scale::of(1.0);

        /** @var list<Paragraph> $paragraphs */
        $paragraphs = [];
        $textWidth = 0.0;
        $textHeight = 0.0;
        foreach ($lines as $i => $line) {
            $paragraph = $metrics->layout($line, $style, self::MAX_LINE, $scale);
            $paragraphs[] = $paragraph;
            $textWidth = \max($textWidth, $paragraph->width);
            $textHeight += $paragraph->height + ($i > 0 ? self::GAP : 0.0);
        }

        $w = $textWidth + self::PAD_X * 2.0;
        $h = $textHeight + self::PAD_Y * 2.0;
        // 目标盒外扩 6px：面板贴着环线也等于挡住判断"框在哪"的那条边。
        $target = ($values['x'] ?? null) === null || ($values['width'] ?? null) === null
            ? null
            : new Rect(
                (float) $values['x'] - 6.0,
                (float) $values['y'] - 6.0,
                (float) $values['width'] + 12.0,
                (float) $values['height'] + 12.0,
            );
        $panel = self::place($viewport, $w, $h, $target);
        $x = $panel->left;
        $y = $panel->top;

        // 实底才读得清：半透明面板压在内容上，数字随背景深浅时隐时现。
        $canvas->drawRect($panel, Paint::fill($theme->color(ColorToken::Pane)->withAlpha(230)), 6.0);

        $picking = ($values['picking'] ?? false) === true;
        $warn = Paint::fill($theme->color(ColorToken::Warning));
        $danger = Paint::fill($theme->color(ColorToken::Danger));
        $strong = Paint::fill($theme->color(ColorToken::Text));
        $muted = Paint::fill($theme->color(ColorToken::Muted));
        // 有 PICK 行时它排第 0、标题退到第 1；没有时标题就是第 0。
        $title = $picking ? 1 : 0;

        $cursor = $y + self::PAD_Y;
        foreach ($paragraphs as $i => $paragraph) {
            if ($i > 0) {
                $cursor += self::GAP;
            }
            // `⚠` 是"为什么看不见"的唯一一行，必须比其余行更刺眼。
            $paint = \str_starts_with($paragraph->text, '⚠')
                ? $danger
                : ($i === 0 && $picking ? $warn : ($i === $title ? $strong : $muted));
            $canvas->drawParagraph($paragraph, $paint, $x + self::PAD_X, $cursor);
            $cursor += $paragraph->height;
        }

        return true;
    }

    private static function shortType(Element $element): string
    {
        return \basename(\str_replace('\\', '/', $element->widget()::class));
    }

    /**
     * 父给的约束，翻成人话：`w tight 608` 说"宽锁定 608"，`0..∞` 说"宽不限"。
     *
     * 只列**有信息的一侧**：min=max 才是被锁死，min=0 且 max=∞ 就是没约束 ——
     * 把 `宽 0..∞` 写出来跟没写一样，还多占一行让人以为有限制。
     */
    private static function constraintText(?BoxConstraints $c): ?string
    {
        if ($c === null) {
            return null;
        }

        $parts = [];
        $w = self::rangeText($c->minWidth, $c->maxWidth, '宽');
        if ($w !== null) {
            $parts[] = $w;
        }
        $h = self::rangeText($c->minHeight, $c->maxHeight, '高');
        if ($h !== null) {
            $parts[] = $h;
        }

        return $parts === [] ? '尺寸由内容决定' : \implode(' · ', $parts);
    }

    /** 紧 → "宽锁定 N"；有上限 → "宽最多 N"；无约束 → null（不占位）。 */
    private static function rangeText(float $min, float $max, string $label): ?string
    {
        if ($min == $max) {
            return $label . '锁定 ' . self::num($min);
        }
        if ($min <= 0.0 && !\is_finite($max)) {
            return null;
        }
        if ($min <= 0.0) {
            return $label . '最多 ' . self::num($max);
        }
        if (!\is_finite($max)) {
            return $label . '至少 ' . self::num($min);
        }

        return $label . ' ' . self::num($min) . '~' . self::num($max);
    }

    /**
     * 从帧的累计账里挑渲染段，翻成一行人话；没有值得显示的段就 null。
     *
     * 读 `stageNs`（Frame::run + 壳的累计账），不是 `stages`（壳记的执行段名）。
     * 后者只有 pump*，而决定"这帧为什么贵"的 settle/layout/paint 全在前者里 ——
     * 按执行顺序取前三个非零段，屏幕上就会永远显示三行 0 的 pump 噪声。
     */
    public static function stageText(?FrameRecord $record): ?string
    {
        if ($record === null) {
            return null;
        }

        $parts = [];
        foreach (self::COST_STAGES as $name) {
            $ns = (int) ($record->stageNs[$name] ?? 0);
            // 0.05ms 以下不显示：低于一帧 0.3% 的噪声，写成 `0` 只会让人以为是空数据。
            if ($ns < 50_000) {
                continue;
            }
            $parts[] = self::stageLabel($name) . ' ' . self::num((float) $ns / 1e6) . 'ms';
        }

        return $parts === [] ? null : \implode(' · ', $parts);
    }

    /**
     * 段名 → 人话标签。硬编码三段的中文名是刻意的：这是**展示层的词汇表**，
     * 与 `FrameStage` 的唯一名字表一一对应，改段名时这里必须跟着改（lint 无此
     * 约束，故在注释里点明）；换成动态取 `FrameStage` 常量反而会把 `pumpIo`
     * 那种内部名直接漏到屏幕上。
     */
    private const array COST_STAGES = [
        FrameStage::LAYOUT,
        FrameStage::PAINT,
        FrameStage::SETTLE,
    ];

    private static function stageLabel(string $name): string
    {
        return match ($name) {
            FrameStage::LAYOUT => '布局',
            FrameStage::PAINT => '绘制',
            FrameStage::SETTLE => '整理',
            default => $name,
        };
    }

    /** 四边相同给一个数，否则 `l/t/r/b` —— 省得读到 `padding 12` 却以为四边都是 12 */
    private static function edges(mixed $edges): string
    {
        if (!\is_array($edges)) {
            return '0';
        }

        $l = (float) ($edges['l'] ?? 0.0);
        $t = (float) ($edges['t'] ?? 0.0);
        $r = (float) ($edges['r'] ?? 0.0);
        $b = (float) ($edges['b'] ?? 0.0);
        if ($l == $t && $t == $r && $r == $b) {
            return self::num($l);
        }

        return self::num($l) . '/' . self::num($t) . '/' . self::num($r) . '/' . self::num($b);
    }

    /** 一位小数，去掉尾随 `.0`：`3.2` / `12`，不出现 `12.0` */
    private static function num(float $value): string
    {
        $text = \number_format($value, 1, '.', '');

        return \str_ends_with($text, '.0') ? \substr($text, 0, -2) : $text;
    }
}
