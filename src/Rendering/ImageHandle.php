<?php
declare(strict_types=1);

namespace Drift\Rendering;

use Drift\Errors;

/**
 * 图像句柄（19 op 的 `IMAGE`，Optional 层，P4 实现后端）。
 *
 * 现在只承载**内存 RGBA**：`drawImage` 没落地之前，它存在的意义是
 * ① 让 `Canvas::drawImage()` 的签名有真实类型可依；② 给"图片从哪来"
 * （将来：SkImage / PNG 解码 / 图集）留出一个换实现不换调用点的位置。
 *
 * ★ 像素格式与两个后端的 `toRgba()` 一致：行优先 RGBA，每像素 4 字节 ——
 *   跨进程/跨后端传递图像时不需要再来一次 swizzle。
 * ★ **校验长度而不复制**：构造时断言 `strlen(rgba) === w*h*4`（不等长出
 *   的图片在绘制时会整行错位，是最难查的一类像素 bug），之后持有同一串。
 */
final class ImageHandle
{
    private function __construct(
        private readonly string $rgba,
        public readonly int $width,
        public readonly int $height,
    ) {
        if ($width <= 0 || $height <= 0) {
            Errors::report(sprintf('ImageHandle：尺寸必须为正，收到 %d×%d', $width, $height));
        }
        if (strlen($rgba) !== $width * $height * 4) {
            Errors::report(sprintf(
                'ImageHandle：像素数不对，%d×%d 需要 %d 字节，收到 %d 字节',
                $width,
                $height,
                $width * $height * 4,
                strlen($rgba),
            ));
        }
    }

    public static function fromRgba(string $rgba, int $width, int $height): self
    {
        return new self($rgba, $width, $height);
    }

    public function rgba(): string
    {
        return $this->rgba;
    }

    public function isEmpty(): bool
    {
        return $this->rgba === '';
    }
}
