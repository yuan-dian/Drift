<?php
declare(strict_types=1);

namespace Drift\Dev\Bench;

use Drift\Debug\Session\GeometryCapture;
use Drift\Debug\Session\RecordOptions;
use Drift\Debug\Session\Recorder;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Rendering\SurfaceProfile;
use Drift\Widget\Column;
use Drift\Widget\Row;
use Drift\Widget\ScrollView;
use Drift\Widget\Text;
use Drift\Widget\Widget;

/**
 * 录制器 on/off 的同一场景量具。
 *
 * 四档共用同一棵合成树、同一视口、同一帧脚本；每档新开应用，避免上一档的
 * 节点表 / 缓存混进下一档。主数逐帧采样取中位，off 档是基线。
 *
 * @internal 量具专用，不进公共 API
 */
final class RecorderOverhead
{
    public const int DEFAULT_ROWS = 500;
    public const int DEFAULT_FRAMES = 30;
    /** 常驻档新增成本相对 60fps 帧预算的上限；不以微基准 off 档为分母。 */
    public const float FRAME_BUDGET_US = 16666.67;
    public const float FRAMES_ONLY_BUDGET_PCT = 2.0;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function measure(int $rows = self::DEFAULT_ROWS, int $frames = self::DEFAULT_FRAMES): array
    {
        $modes = [
            'off' => null,
            'frames' => RecordOptions::framesOnly(),
            'geometry' => RecordOptions::of(geometry: GeometryCapture::Changed, network: false, bodies: false),
            'full' => RecordOptions::full(),
        ];
        $out = [];
        $baseline = null;
        SurfaceProfile::$on = false;
        SurfaceProfile::reset();

        foreach ($modes as $name => $options) {
            if ($options !== null) {
                Recorder::install($options);
            }

            try {
                $app = new FrameTaxApp(FrameTaxMeasure::syntheticRoot($rows));
                $surface = new SurfaceProbe();
                $width = 800.0;
                $height = 600.0;
                $surface->resize((int) $width, (int) $height, 1.0);
                $constraints = BoxConstraints::tight(new Size($width, $height));

                // 标签每帧变：等价树会被判等复用，静止帧量不出真实应用的帧税。
                $rootAt = static function (int $i) use ($rows): Widget {
                    $list = [];
                    for ($r = 0; $r < $rows; ++$r) {
                        $list[] = Row::make()->children([
                            Text::make('R' . $i . '-' . $r . 'A'),
                            Text::make('R' . $i . '-' . $r . 'B'),
                            Text::make('R' . $i . '-' . $r . 'C'),
                        ]);
                    }

                    return ScrollView::make(Column::make()->children($list));
                };
                $rootEl = $app->root();
                $drive = static function (int $i) use ($app, $rootEl, $rootAt, $constraints): void {
                    $rootEl?->update($rootAt($i));
                    $app->frame($i * 16.0, $constraints);
                };

                $app->attach($surface, $constraints);
                for ($i = 1; $i <= 10; ++$i) {
                    $drive($i);
                }

                $samples = [];
                for ($i = 11; $i <= 10 + $frames; ++$i) {
                    $t = \hrtime(true);
                    $drive($i);
                    $samples[] = (\hrtime(true) - $t) / 1000.0;
                }

                $median = Samples::of($samples)->median;
                $baseline ??= $median;
                $out[$name] = [
                    'rows' => $rows,
                    'frames' => $frames,
                    'medianUs' => \round($median, 2),
                    'overheadPct' => \round(($median - $baseline) / \max($baseline, 0.0001) * 100.0, 2),
                    'budgetPct' => \round(($median - $baseline) / self::FRAME_BUDGET_US * 100.0, 3),
                    'recordedFrames' => Recorder::current()?->stats()['frames'] ?? 0,
                    'geometryFrames' => Recorder::current()?->geometryCoverage()['frames'] ?? 0,
                    'networkRequests' => \count(Recorder::current()?->networkRows() ?? []),
                ];
            } finally {
                Recorder::uninstall();
            }
        }

        return $out;
    }
}
