/**
 * 用 **Skia codec** 把一张图片解码成 RGBA —— `Drift\Rendering\ImageDecoder` 的 Linux 实现。
 *
 * ## 为什么是 Skia codec，而不是 GD / 纯 PHP / 另引解码库
 *
 * | 方案 | 体积 | 格式 | 结论 |
 * |---|---|---|---|
 * | 纯 PHP `PngDecoder` | 0 | **只 PNG** | 已有，但 jpg/webp 无解（AI 出图多是 jpg） |
 * | 引 libjpeg/libpng/libwebp 手写 | 0（已在 Skia 包里） | jpg/png/webp | 要各写一套 API + 缩放 + 通道序，重复 Skia 已有能力 |
 * | **Skia `SkCodec`** | **0**（codec 编进 libskia.a，解码库已在链接表） | jpg/png/webp/gif/bmp | **本仓的选择** |
 *
 * ★ 与 Windows 版（`win32/image_decode.h`，用 WIC）是同一职责的**平台实现**：
 *   输出契约完全一致（逐像素 RGBA / 自上而下 / 无行填充 / `w*h*4` 字节），
 *   所以上层 `ImageDecoder::decode()` 不做任何平台分支。
 *
 * ## 为什么本文件不 include phpx（与 win32/image_decode.h 同纪律）
 *   这里只认 `std::string` 与 Skia 类型，UTF-8 路径直通（X11 API 原生 UTF-8，
 *   无 Windows 的 UTF-16 转换负担）。php 类型换算留在 `drift_x11.cc` 的 ABI 层。
 *
 * @param path      UTF-8 路径
 * @param maxWidth  目标宽度上限；`0` = 原尺寸。`>0` 且原图更宽时在**解码期**等比缩小
 *                  （内存纪律：1792×2390 的图按原尺寸解是 17MB，预览只显示 560 宽）
 * @param outWidth/outHeight/outRgba 输出（成功时填充）
 * @return 成功 `true`；失败 `false`（文件不存在 / 格式不支持 / 解码失败）
 */
#ifndef DRIFT_X11_IMAGE_DECODE_H
#define DRIFT_X11_IMAGE_DECODE_H

#include <string>

namespace drift {
namespace x11 {

bool decodeImageToRgba(const std::string &path,
                       unsigned int maxWidth,
                       unsigned int &outWidth,
                       unsigned int &outHeight,
                       std::string &outRgba);

}   // namespace x11
}   // namespace drift

#endif   // DRIFT_X11_IMAGE_DECODE_H
