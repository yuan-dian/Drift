<?php
/**
 * 按下态的**解释器侧**序列 —— 与 `probes/frame_tax_aot/app/main.php` 的
 * `pressSection()` 逐行同构，两侧输出应逐字相同。
 *
 * ```text
 * php tools/press-sequence.php                            # 解释器侧
 * PROBE_SECTION=press ./frame_tax_aot_probe.exe           # AOT 侧（构建后）
 * ```
 *
 * ★ 存在的理由：真机探针（`tools/probe-press.py`）撞到「连续两次按下之后按钮
 *   不再响应」，而这条序列在 AOT 与解释器下**都正常** ⇒ 问题不在状态机，
 *   也不在 AOT 语义，在**事件序列**里。有这两份对表，下次再撞到同类现象
 *   不必重跑一整条链去排除框架本身。
 */
require __DIR__ . '/autoload.php';

use Drift\Dev\Bench\FrameTaxApp;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Input\PointerEvent;
use Drift\Layout\RenderButton;
use Drift\Widget\Button;
use Drift\Widget\ButtonVariant;
use Drift\Widget\Column;
use Drift\Widget\Widget;

$tight = BoxConstraints::tight(new Size(240.0, 120.0));
$app = new FrameTaxApp(static function (): Widget {
    return Column::make()->paddingAll(20.0)->children([
        Button::make('按下我')->variant(ButtonVariant::Default),
    ]);
});
$probe = new SurfaceProbe();
$probe->resize(240, 120, 1.0);
$app->attach($probe, $tight);
$app->frame(0.0, $tight);

$box = $app->root()?->findBoxOf(RenderButton::class);
if (!$box instanceof RenderButton) {
    exit("按钮盒没找到\n");
}

$clock = 1000.0;
$row = static function (string $label) use ($app, $probe, $box, $tight, &$clock): void {
    for ($i = 1; $i <= 40; $i++) {
        $app->frame($clock + $i * 16.0, $tight);
    }
    $clock += 640.0;

    $raster = $probe->raster();
    $c = $raster?->pixelAt(60, 30);
    $rgb = $c === null ? 'null' : ((string) $c->r) . ',' . ((string) $c->g) . ',' . ((string) $c->b);

    printf(
        "%-10s pressed=%d hover=%d press=%.3f hoverP=%.3f rgb=%s\n",
        $label,
        $box->pressed() ? 1 : 0,
        $box->hovered() ? 1 : 0,
        $box->pressProgress(),
        $box->hoverProgress(),
        $rgb,
    );
};

$row('init');
$app->dispatchInput(PointerEvent::move(40.0, 30.0));
$row('move');
$app->dispatchInput(PointerEvent::down(40.0, 30.0));
$row('down1');
$app->dispatchInput(PointerEvent::up(40.0, 30.0));
$row('up1');
$app->dispatchInput(PointerEvent::down(40.0, 30.0));
$row('down2');
$app->dispatchInput(PointerEvent::up(40.0, 30.0));
$row('up2');
$app->dispatchInput(PointerEvent::move(200.0, 100.0));
$row('away');
$app->dispatchInput(PointerEvent::move(40.0, 30.0));
$row('back');
