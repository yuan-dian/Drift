<?php
declare(strict_types=1);

namespace Drift\DevTools\Inspect;

use Drift\Debug\Session\Recorder;
use Drift\Geometry\BoxConstraints;
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
     *     visibility: string,
     *     rebuiltNode: bool,
     *     padding: array{l: float, t: float, r: float, b: float}|null,
     *     border: float|null,
     *     margin: array{l: float, t: float, r: float, b: float}|null,
     *     picking: bool,
     * }
     */
    public static function values(Recorder $recorder, ?Element $selected, bool $picking = false, ?Rect $viewport = null): array
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
            'visibility' => 'visible',
            'rebuiltNode' => false,
            'padding' => null,
            'border' => null,
            'margin' => null,
            'picking' => $picking,
        ];

        // 分段按 `stages` 的**执行顺序**取，不硬编码段名：显示器不该维护第二份段表。
        if ($record !== null && $record->stages !== []) {
            $parts = [];
            foreach ($record->stages as $name) {
                $ns = (int) ($record->stageNs[$name] ?? 0);
                if ($ns <= 0) {
                    continue;
                }
                $parts[] = $name . ' ' . self::num((float) $ns / 1e6);
                if (\count($parts) >= 3) {
                    break;
                }
            }
            if ($parts !== []) {
                $values['stage'] = \implode(' / ', $parts);
            }
        }

        if ($selected === null) {
            return $values;
        }

        $values['node'] = $selected->debugId;
        $values['type'] = self::shortType($selected);

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

        $lines[] = \sprintf('#%d  %s', (int) $values['node'], (string) $values['type']);

        if (($values['width'] ?? null) !== null) {
            $lines[] = \sprintf(
                '%s × %s  @ %s, %s',
                self::num((float) $values['width']),
                self::num((float) $values['height']),
                self::num((float) $values['x']),
                self::num((float) $values['y']),
            );
            $lines[] = \sprintf(
                'frame %d · %sms · %s',
                (int) $values['frame'],
                self::num((float) $values['costMs']),
                ($values['rebuiltNode'] ?? false) ? 'rebuilt' : 'kept',
            );
            if (($values['stage'] ?? null) !== null) {
                $lines[] = (string) $values['stage'];
            }

            // 约束与父级同一行：只报 `w tight 608` 而不报"谁给的"，读者还得再查一次。
            $where = [];
            if (($values['parentNode'] ?? null) !== null) {
                $where[] = \sprintf(
                    'parent #%d %s',
                    (int) $values['parentNode'],
                    (string) ($values['parentType'] ?? ''),
                );
            }
            if (($values['constraint'] ?? null) !== null) {
                $where[] = (string) $values['constraint'];
            }
            if ($where !== []) {
                $lines[] = \implode(' → ', $where);
            }

            // 0 值不占位：全零说"没有间距"，有哪层只说哪层。
            $box = [];
            $pad = self::edges($values['padding']);
            if ($pad !== '0') {
                $box[] = 'pad ' . $pad;
            }
            $border = (float) ($values['border'] ?? 0.0);
            if ($border > 0.0) {
                $box[] = 'border ' . self::num($border);
            }
            $margin = self::edges($values['margin']);
            if ($margin !== '0') {
                $box[] = 'margin ' . $margin;
            }
            $lines[] = $box === [] ? 'spacing none' : \implode(' · ', $box);
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
     * 右下角实底面板。返回"真的画了"——没有选中时一律 `false`，让记账停在
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
        // 窗口比 HUD 还小时贴左上，不画到视口外 —— 负坐标在硬件裁剪下会整块消失。
        $x = \max(self::MARGIN, $viewport->width - $w - self::MARGIN);
        $y = \max(self::MARGIN, $viewport->height - $h - self::MARGIN);

        // 实底才读得清：半透明面板压在内容上，数字随背景深浅时隐时现。
        $canvas->drawRect(
            new Rect($x, $y, $w, $h),
            Paint::fill($theme->color(ColorToken::Pane)->withAlpha(230)),
            6.0,
        );

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

    /** 父给的约束：紧的一侧写 `tight N`，松的一侧写区间。排查"谁把我的宽定死了"靠它。 */
    private static function constraintText(?BoxConstraints $c): ?string
    {
        if ($c === null) {
            return null;
        }

        return 'w ' . self::range($c->minWidth, $c->maxWidth)
            . ' · h ' . self::range($c->minHeight, $c->maxHeight);
    }

    private static function range(float $min, float $max): string
    {
        if ($min == $max) {
            return 'tight ' . self::num($min);
        }

        return self::num($min) . '..' . (\is_finite($max) ? self::num($max) : '∞');
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
