<?php
declare(strict_types=1);

/**
 * N1（泵帧分离）实测：空闲 / 换树 / 动画 / 同值反复写 四种场景下，**整帧 paint 的次数**。
 *
 * 用法：`php tools/frame-paint-probe.php`
 *
 * ★ 这是"泵帧分离"收益的**唯一直接量具**。判据不是"看着不卡"，而是
 *   `Pipeline::current()->paintSkipCount()` —— 空闲窗口的 60 帧里应该 59 帧以上整段跳过。
 * ★ 后三组是**对照组**，缺了它们"永远不画"也能让第一组好看：
 *   换树必画 / 动画必画 / 同值不画。
 * ★ 2026-10-30 实测（320×240，静态树）：空闲 60 帧 paint=0 skip=60；
 *   换根 1 帧 paint=1；动画 10 帧 paint=10；同值 10 帧 paint=0。
 */

require __DIR__ . '/autoload.php';

use Drift\Core\Pipeline;
use Drift\App\Frame;
use Drift\Dev\Smoke\App\EchoApplication;
use Drift\Dev\Smoke\Frame\SurfaceProbe;
use Drift\Geometry\BoxConstraints;
use Drift\Geometry\Size;
use Drift\Widget\Column;
use Drift\Widget\Text;

$tight = static fn(): BoxConstraints => BoxConstraints::tight(new Size(320.0, 240.0));

$app = new EchoApplication();
$surface = new SurfaceProbe();
$surface->resize(320, 240, 1.0);
$app->attach($surface, $tight());

$paintBefore = Pipeline::current()->paintRunCount();
$skipBefore = Pipeline::current()->paintSkipCount();
$framesBefore = $surface->frames;

// 模拟 1 秒 @60Hz：平台每 16ms 叫一次 frame()，树是静态的
for ($i = 1; $i <= 60; $i++) {
    $app->frame($i * 16.0, $tight());
}

$paint = Pipeline::current()->paintRunCount() - $paintBefore;
$skip = Pipeline::current()->paintSkipCount() - $skipBefore;
$drawn = $surface->frames - $framesBefore;

printf("空闲 60 帧：paint=%d  skip=%d  表面收到帧=%d\n", $paint, $skip, $drawn);

// 对照组 A：树变了（换根）⇒ 必须画
$paintBefore = Pipeline::current()->paintRunCount();
$app->root()?->update(Column::make()->children([Text::make('改')]));
$app->frame(1000.0, $tight());
printf("换根后 1 帧：paint=%d（期望 1）\n", Pipeline::current()->paintRunCount() - $paintBefore);

// 对照组 B：合成参数在动（动画主力通道）⇒ 每帧都画
$box = $app->root()?->effectiveBox();
$paintBefore = Pipeline::current()->paintRunCount();
for ($i = 1; $i <= 10; $i++) {
    $box?->setCompose(1.0 - $i * 0.05);
    $app->frame(1000.0 + $i * 16.0, $tight());
}
printf("动画 10 帧：paint=%d（期望 10）\n", Pipeline::current()->paintRunCount() - $paintBefore);

// 对照组 C：同值反复写 ⇒ 不该画（静止画面不许重录）
$paintBefore = Pipeline::current()->paintRunCount();
for ($i = 1; $i <= 10; $i++) {
    $box?->setCompose(0.5);
    $app->frame(2000.0 + $i * 16.0, $tight());
}
printf("同值 10 帧：paint=%d（期望 0）\n", Pipeline::current()->paintRunCount() - $paintBefore);
