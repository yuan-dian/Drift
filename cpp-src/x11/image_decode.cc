/**
 * Skia codec 解码实现。见 image_decode.h 的取舍与契约。
 *
 * ★ 通道序（R/B 不能互换）：`getPixels()` 的目标 info 用 kRGBA_8888 + kUnpremul，
 *   得到的字节就是逐像素 R,G,B,A —— 与 `ImageHandle::fromRgba()` 及
 *   `drift_x11_present()` 的口径完全一致。判据用写死期望色的 rgb 象限图，
 *   别只比"两个尺寸解码结果一致"（那一招对 R/B 互换全绿，Windows 踩过）。
 */

#include "image_decode.h"

#include "include/core/SkData.h"
#include "include/core/SkImageInfo.h"
#include "include/core/SkPixmap.h"
#include "include/codec/SkCodec.h"

#include <algorithm>
#include <cmath>
#include <cstring>
#include <vector>

namespace drift {
namespace x11 {

bool decodeImageToRgba(const std::string &path,
                       unsigned int maxWidth,
                       unsigned int &outWidth,
                       unsigned int &outHeight,
                       std::string &outRgba)
{
    if (path.empty()) {
        return false;
    }

    // MakeFromFileName 自己读全文件（含二进制）；失败 = 文件不存在 / 不可读。
    sk_sp<SkData> data = SkData::MakeFromFileName(path.c_str());
    if (data == nullptr || data->size() == 0) {
        return false;
    }

    std::unique_ptr<SkCodec> codec = SkCodec::MakeFromData(data);
    if (codec == nullptr) {
        return false;   // 格式不支持 / 数据损坏 —— 预期内，返回 false 不抛
    }

    const SkISize encoded = codec->getInfo().dimensions();
    if (encoded.width() <= 0 || encoded.height() <= 0) {
        return false;
    }

    // 解码期缩放（内存纪律，见头注释）：scale ≤ 1，绝不放大。
    // ★ 目标尺寸自己算（精确命中 maxWidth），不用 getScaledDimensions ——
    //   那个 API 只承诺"codec 原生支持的最接近尺寸"，实测 8×8 PNG 传 0.5 会
    //   原样返回 8×8 ⇒ maxWidth 静默失效。契约要的是"原图更宽就缩到上限"，
    //   不能让 codec 的实现粒度决定我们兑不兑现。
    SkISize dims = encoded;
    if (maxWidth > 0 && (unsigned int)encoded.width() > maxWidth) {
        const int targetW = (int)maxWidth;
        const int targetH = std::max(1, (int)std::lround(
            (double)encoded.height() * (double)targetW / (double)encoded.width()));
        dims = SkISize::Make(targetW, targetH);
    }

    // kUnpremul + kRGBA_8888 ⇒ getPixels 直出逐像素 RGBA，行紧挨无填充。
    const SkImageInfo info = SkImageInfo::Make(
        dims.width(), dims.height(), kRGBA_8888_SkColorType, kUnpremul_SkAlphaType);
    const size_t rowBytes = (size_t)dims.width() * 4u;
    const size_t total = rowBytes * (size_t)dims.height();
    std::vector<uint8_t> pixels(total);
    SkCodec::Result result = codec->getPixels(info, pixels.data(), rowBytes);

    if (result == SkCodec::kInvalidScale && dims != encoded) {
        // ★ 回退：codec 不接受这个精确缩放（小图 / 某些格式粒度粗）⇒
        //   先解原尺寸，再用最近邻缩到目标。牺牲一点内存换"契约必兑现"——
        //   这条路径只在 codec 拒绝缩放时走；大图通常被上面的直缩分支接住。
        const SkImageInfo fullInfo = SkImageInfo::Make(
            encoded.width(), encoded.height(), kRGBA_8888_SkColorType, kUnpremul_SkAlphaType);
        const size_t fullRow = (size_t)encoded.width() * 4u;
        std::vector<uint8_t> full((size_t)encoded.height() * fullRow);
        if (codec->getPixels(fullInfo, full.data(), fullRow) != SkCodec::kSuccess) {
            return false;
        }
        for (int y = 0; y < dims.height(); ++y) {
            const int sy = (int)((double)y * encoded.height() / dims.height());
            uint8_t *dst = pixels.data() + (size_t)y * rowBytes;
            const uint8_t *srcRow = full.data() + (size_t)sy * fullRow;
            for (int x = 0; x < dims.width(); ++x) {
                const int sx = (int)((double)x * encoded.width() / dims.width());
                std::memcpy(dst + (size_t)x * 4u, srcRow + (size_t)sx * 4u, 4u);
            }
        }
        result = SkCodec::kSuccess;
    }
    if (result != SkCodec::kSuccess) {
        return false;   // kIncompleteInput 等预期内失败
    }

    // assign(const char*, size) 保留全部字节（含 \0）—— 与 present 过 ABI 同手法。
    outRgba.assign(reinterpret_cast<const char *>(pixels.data()), total);
    outWidth = (unsigned int)dims.width();
    outHeight = (unsigned int)dims.height();
    return true;
}

}   // namespace x11
}   // namespace drift
