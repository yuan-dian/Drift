<?php
declare(strict_types=1);

namespace App\Showcase\Pages\Painters;

use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\CanvasCapabilities;
use Drift\Rendering\Color;
use Drift\Rendering\ImageHandle;
use Drift\Rendering\Paint;
use Drift\Rendering\Path;
use Drift\Rendering\Shadow;
use Drift\Widget\CustomPainter;

/**
 * P4 画家：把 19 op 的 Optional / Extended 复合 op 当真实消费者用一遍
 * （"没人调的分支等价于未实现"，所以 P4 的每个 op 都要有一个真的绘制出口）。
 *
 * - `drawShadow`：卡片背后的软阴影（几何 + 偏移 + 模糊 + 浓度）
 * - `drawPath`：填充三角形（折线下填色现在能画了，不再等 P4）
 * - `pushLayer` / `popLayer`：半透明合成层
 * - `drawImage`：渐变 swatch（内存 RGBA，行优先 4 字节）
 * - `pushClipPath`：圆形路径裁剪（后续矩形只落在形状内）
 *
 * ★ `drawShadow` / `drawImage` 是 Optional 层 ⇒ `paint()` 开头查一次
 *   `capabilities()`，没有对应位就跳过那一笔（`05` §2.5 的
 *   `optional-op-guard`：demo 页可以演示 Optional op，但不能假设后端有）。
 */
final class P4Painter extends CustomPainter
{
    /**
     * 生成一张 2D 渐变 swatch（行优先 RGBA），证明 drawImage 的字节串能过桥回画。
     *
     * ★★ 数组攒 + implode：`$rgba .= …` 是二进制串（每像素 4 字节、含 \0 与高位），
     *   AOT 下循环内累加会写坏多字节串 ⇒ 图像乱码 / 崩在无关代码（纪律 2）。
     */
    private function makeSwatch(int $w, int $h): ImageHandle
    {
        $rows = [];
        for ($y = 0; $y < $h; $y++) {
            $px = [];
            for ($x = 0; $x < $w; $x++) {
                $t = $x / \max(1, $w - 1);
                $r = (int) (255.0 * $t);
                $g = (int) (120.0 + 80.0 * (1.0 - $t));
                $b = (int) (255.0 * (1.0 - $t));
                $px[] = \chr($r) . \chr($g) . \chr($b) . "\xFF";
            }
            $rows[] = \implode('', $px);
        }
        return ImageHandle::fromRgba(\implode('', $rows), $w, $h);
    }

    public function paint(Canvas $canvas, Size $size): void
    {
        // ★ Optional op 先查能力位（`05` §2.5 的 optional-op-guard）：画家拿到的
        //   画布可能是软光栅（preview / golden / smoke 全走它）⇒ 没实现 Optional
        //   op 的后端会响亮失败。demo 页也不例外 —— 查一次，没有就跳过那一笔。
        $caps = $canvas->capabilities();

        // 软阴影：卡片背后（drawShadow，alpha = 浓度，不是额外乘算）
        if (($caps & CanvasCapabilities::CAP_SHADOW) !== 0) {
            $canvas->drawShadow(new Shadow(new Rect(10.0, 8.0, 130.0, 60.0), new Color(0, 0, 0, 60), 8.0, 3.0, 4.0, 6.0));
        }

        // 填充三角形（drawPath，P4 新增；折线下填色现在能画）
        $tri = Path::create()
            ->moveTo(18.0, 14.0)
            ->lineTo(62.0, 14.0)
            ->lineTo(40.0, 58.0)
            ->close();
        $canvas->drawPath($tri, Paint::fill(new Color(40, 140, 220, 255)));

        // 合成层：半透明叠一层（pushLayer + popLayer）
        $canvas->pushLayer(0.5);
        $canvas->drawRect(new Rect(76.0, 16.0, 56.0, 46.0), Paint::fill(new Color(220, 60, 60, 255)));
        $canvas->popLayer();

        // 图像：渐变 swatch（drawImage，内存 RGBA 字节串过桥）
        if (($caps & CanvasCapabilities::CAP_IMAGE) !== 0) {
            $canvas->drawImage($this->makeSwatch(48, 48), null, new Rect(144.0, 15.0, 48.0, 48.0));
        }

        // 路径裁剪：绿色横条只落在下方菱形内（pushClipPath + popClip）
        $clip = Path::create()
            ->moveTo(210.0, 78.0)
            ->quadTo(240.0, 48.0, 270.0, 78.0)
            ->quadTo(240.0, 108.0, 210.0, 78.0)
            ->close();
        $canvas->pushClipPath($clip);
        $canvas->drawRect(new Rect(206.0, 40.0, 68.0, 80.0), Paint::fill(new Color(120, 200, 120, 255)));
        $canvas->popClip();
    }
}
