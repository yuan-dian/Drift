<?php
declare(strict_types=1);

namespace Drift\DevTools\Inspect;

use Drift\Debug\Session\Recorder;
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
    private const float MAX_LINE = 320.0;

    /**
     * 这一帧 HUD 要展示的数值。`null` = 没有可展示的选中节点。
     *
     * @return array{
     *     frame: int,
     *     costMs: float,
     *     rebuilt: int,
     *     type: string|null,
     *     width: float|null,
     *     height: float|null,
     *     padding: array{l: float, t: float, r: float, b: float}|null,
     *     border: float|null,
     *     margin: array{l: float, t: float, r: float, b: float}|null,
     * }
     */
    public static function values(Recorder $recorder, ?Element $selected): array
    {
        $stats = $recorder->stats();
        $values = [
            'frame' => $recorder->lastFrameId(),
            'costMs' => (float) ($stats['lastCostMs'] ?? 0.0),
            'rebuilt' => (int) ($stats['lastRebuilt'] ?? 0),
            'type' => null,
            'width' => null,
            'height' => null,
            'padding' => null,
            'border' => null,
            'margin' => null,
        ];

        $box = $selected?->effectiveBox();
        $layers = $box === null ? null : BoxLayers::of($box);
        if ($layers === null || $selected === null) {
            return $values;
        }

        $values['type'] = self::shortType($selected);
        $values['width'] = (float) $layers['border']['w'];
        $values['height'] = (float) $layers['border']['h'];
        $values['padding'] = $layers['padding'];
        $values['border'] = (float) $layers['borderWidth'];
        $values['margin'] = $layers['margin'];

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

        return [
            \sprintf(
                '#%d  %sms  %d rebuilt',
                (int) $values['frame'],
                self::num((float) $values['costMs']),
                (int) $values['rebuilt'],
            ),
            \sprintf(
                '%s  %sx%s',
                (string) $values['type'],
                self::num((float) $values['width']),
                self::num((float) $values['height']),
            ),
            \sprintf(
                'pad %s | border %s | margin %s',
                self::edges($values['padding']),
                self::num((float) $values['border']),
                self::edges($values['margin']),
            ),
        ];
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

        $paint = Paint::fill($theme->textColor(TextRole::Caption));
        $cursor = $y + self::PAD_Y;
        foreach ($paragraphs as $i => $paragraph) {
            if ($i > 0) {
                $cursor += self::GAP;
            }
            $canvas->drawParagraph($paragraph, $paint, $x + self::PAD_X, $cursor);
            $cursor += $paragraph->height;
        }

        return true;
    }

    private static function shortType(Element $element): string
    {
        return \basename(\str_replace('\\', '/', $element->widget()::class));
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
