<?php
declare(strict_types=1);

namespace Drift\Dev\Tests\Verify;

use Drift\Geometry\Matrix2D;
use Drift\Geometry\Rect;
use Drift\Geometry\Size;
use Drift\Rendering\Canvas;
use Drift\Rendering\Color;
use Drift\Rendering\Paint;
use Drift\Widget\CustomPaint;
use Drift\Widget\CustomPainter;
use Drift\Widget\Widget;

/**
 * IR 快照夹具：一段**固定**的绘制命令，覆盖 4 类 IR 层缺陷各自的观测点。
 *
 * ★ 为什么是 `CustomPainter` 而不是一张真实页面：真实页面会随视觉迭代而漂，
 *   基线就成了「改一页红一次」的噪声源。这里只要**结构稳定、覆盖面够**，
 *   命令流就是 IR 契约的最小锚点；真实页面的逐页快照是 V2-1 的后续量（S3 之后）。
 *
 * ★ 四类观测点与缺陷题库 I1–I4 一一对应：
 *   ① op 顺序 —— `save()` 必须包住所有绘制、`restore()` 在最后；
 *   ② 裁剪    —— `pushClip()` 必须配 `popClip()`（漏弹 ⇒ clipDepth 失衡）；
 *   ③ 两色    —— 红先绿后，色值与顺序都是判据；
 *   ④ 变换分叉 —— 纯平移走 `OP_TRANSLATE` 快路，真变换走 `OP_TRANSFORM`。
 *
 * ★ 不画文字：段落要加载字体（TtfFace），会让快照依赖字体文件与度量实现，
 *   而 IR 快照要的是「命令流形状」，不是「字体排得对不对」—— 后者归像素 golden。
 *
 * @internal 验证夹具，不进公共 API
 */
final class IrFixturePainter extends CustomPainter
{
    public function paint(Canvas $canvas, Size $size): void
    {
        // ① op 顺序：save 必须在所有绘制之前、restore 在最后。
        $canvas->save();

        // ② 裁剪：pushClip 必须配 popClip（漏弹 ⇒ clipDepth 失衡 + 缺 POP_CLIP）。
        $canvas->pushClip(new Rect(0.0, 0.0, 300.0, 200.0));

        // ③ 两色：红先绿后；色值与顺序都是判据（传反 ⇒ 两条 DRAW_RECT 的色互换）。
        $canvas->drawRect(new Rect(10.0, 10.0, 100.0, 60.0), Paint::fill(Color::fromRgb(0xC0, 0x10, 0x20)));
        $canvas->drawRect(new Rect(20.0, 80.0, 120.0, 60.0), Paint::fill(Color::fromRgb(0x10, 0xC0, 0x30)));

        // ④ 变换分叉：纯平移走 OP_TRANSLATE 快路，真变换走 OP_TRANSFORM。
        $canvas->translate(5.0, 7.0);
        $canvas->transform(Matrix2D::translation(3.0, 4.0));   // isTranslationOnly ⇒ OP_TRANSLATE
        $canvas->transform(Matrix2D::rotation(15.0));          // 真变换 ⇒ OP_TRANSFORM

        $canvas->drawLine(0.0, 0.0, 50.0, 50.0, Paint::stroke(Color::fromRgb(0x00, 0x00, 0x00), 1.0));

        $canvas->popClip();
        $canvas->restore();
    }
}

/**
 * 夹具的 Widget 入口 —— 交给 `FrameTaxApp` 的根工厂。
 *
 * 尺寸写死 1200×800，与 `PageFrame` / `--pages` 同口径；不读父约束的意外分支。
 *
 * @internal 验证夹具，不进公共 API
 */
final class IrFixture
{
    public static function widget(): Widget
    {
        return new CustomPaint(new IrFixturePainter(), 1200.0, 800.0);
    }
}
