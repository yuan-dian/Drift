#pragma once

#include <string>

namespace drift {
namespace win32 {

/**
 * 用 **WIC**（Windows Imaging Component）把一张图片解码成 RGBA。
 *
 * ## 为什么是 WIC，而不是 GD / 纯 PHP
 *
 * | 方案 | 体积 | 格式 | 结论 |
 * |---|---|---|---|
 * | 启 `gd` 扩展 | +~1MB 且多一个 dll 依赖 | jpg/png/gif/webp | 部署包多一份运行时面 |
 * | 纯 PHP 写基线 JPEG | 0 | 只基线 JPEG，渐进式不行 | 1080p 要 100~300ms，工作量大 |
 * | **WIC** | **0**（系统组件，静态链接几十 KB） | jpg/png/gif/webp/heif/tiff | **本仓的选择** |
 *
 * ★ 输出固定 `GUID_WICPixelFormat32bppRGBA` —— 正好是 `ImageHandle::fromRgba()`
 *   要的形状（**逐像素 RGBA、自上而下、无行填充**），中间不需要任何转换。
 *
 * ## 为什么参数是 `std::wstring` 而不是 `String`
 *
 * 本文件在 `cpp-src/win32/` 下，**不 include phpx**（同 `io_wake.h` 的纪律：
 * `core/` 与 `win32/` 都不认识 PHP 类型）。UTF-8 → UTF-16 的转换留在扩展层
 * （`drift_win32.cc` 的 `toWide()`）。
 *
 * @param path     UTF-16 路径（Windows 原生路径）
 * @param outWidth 解出的宽（像素）
 * @param outHeight 解出的高（像素）
 * @param outRgba  解出的像素（`w * h * 4` 字节）
 *
 * @return 成功 `true`；失败 `false`（文件不存在 / 格式不支持 / 内存不足 / COM 失败）
 */
bool decodeImageToRgba(const std::wstring &path, unsigned int &outWidth, unsigned int &outHeight, std::string &outRgba);

}  // namespace win32
}  // namespace drift
